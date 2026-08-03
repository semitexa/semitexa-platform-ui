<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Component;

use ReflectionClass;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Attribute\HandlesUiEvent;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Domain\Contract\UiEventHandlerInterface;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiExternalHandlerMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiOnMetadata;

/**
 * The Platform UI component catalog: which classes declare #[UiPart]/#[UiSlot],
 * and which handlers bind to their events.
 *
 * Container-managed, so a consumer injects this and gets a fully wired catalog.
 * {@see UiComponentRegistry} remains as a static shim over it for callers that
 * cannot receive an injection.
 *
 * Extracted by ep-kill-static-facades. The registry had TWO wired slots plus an
 * initialize(), and that three-call incantation was written out verbatim in two
 * places — BootPlatformUiRegistryListener for the worker and CatalogCommand for
 * the CLI. Two callers having to remember the same three calls, in order, is the
 * failure mode the epic exists to remove: miss one and the registry is silently
 * half-built.
 */
#[AsService]
final class UiComponentCatalog
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    /**
     * Not injected: UiComponentMetadataFactory carries no container binding —
     * every caller has always built it with `new`. Left as a settable slot with
     * a default so the catalog works whether or not anyone wires one.
     */
    protected UiComponentMetadataFactory $factory;

    private const EVENT_NAME_PATTERN = '/\A[a-z][a-z0-9:_-]*\z/';

    /** @var array<string, UiComponentMetadata> */
    private array $byName = [];

    /** @var array<string, UiExternalHandlerMetadata> Flat key `<componentName>.<partName>.<eventName>` → external binding. */
    private array $externalBindings = [];

    /** @var array<string, list<UiExternalHandlerMetadata>> Grouped by component name, declaration order preserved. */
    private array $externalBindingsByComponent = [];

    private bool $initialized = false;
    /** Set while initialize() runs, so the external-binding entry points can call it safely. */
    private bool $initializing = false;

    public function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        $this->classDiscovery = $classDiscovery;
    }

    public function setFactory(UiComponentMetadataFactory $factory): void
    {
        $this->factory = $factory;
    }

    public function initialize(): void
    {
        // $initializing keeps the discovery pass re-entrant-safe: it calls
        // registerExternalFromClass() itself, and that method now initializes on
        // entry so a caller registering a binding before first read gets a
        // populated catalog rather than a bogus "component not registered".
        if ($this->initialized || $this->initializing) {
            return;
        }
        // No ClassDiscovery wired yet? Honour any register()ed entries but skip
        // attribute discovery — WITHOUT latching $initialized, so a later
        // initialize() after the boot listener wires ClassDiscovery can still
        // run the discovery pass. Latching here is a silent failure: every read
        // calls initialize() lazily, so one early query would freeze the catalog
        // empty for the worker's whole life, and empty raises no error.
        if (!isset($this->classDiscovery)) {
            return;
        }

        $this->initializing = true;

        try {
            $this->runDiscovery();
        } finally {
            // Cleared even on a throw: a failed pass must not leave the catalog
            // permanently refusing to initialize.
            $this->initializing = false;
        }
    }

    private function runDiscovery(): void
    {
        $factory = $this->factory ?? new UiComponentMetadataFactory();

        // A Platform UI component is any class that declares at least one
        // #[UiPart] or #[UiSlot]. Components without parts/slots remain
        // plain SSR components and stay out of this registry.
        $candidates = array_unique(array_merge(
            $this->classDiscovery->findClassesWithAttribute(UiPart::class),
            $this->classDiscovery->findClassesWithAttribute(UiSlot::class),
        ));

        foreach ($candidates as $class) {
            $this->registerInternal($factory->fromClass($class));
        }

        // Class-level #[HandlesUiEvent] discovery runs only after every
        // component has been registered, since each binding must validate
        // against its component's already-declared parts/slots/events.
        foreach ($this->classDiscovery->findClassesWithAttribute(HandlesUiEvent::class) as $handlerClass) {
            $this->registerExternalFromClass($handlerClass);
        }

        $this->initialized = true;
    }

    public function register(UiComponentMetadata $metadata): void
    {
        $this->registerInternal($metadata);
    }

    private function registerInternal(UiComponentMetadata $metadata): void
    {
        if (isset($this->byName[$metadata->name])) {
            $existing = $this->byName[$metadata->name];
            if ($existing->class === $metadata->class) {
                return;
            }
            throw new UiComponentRegistryException(sprintf(
                'Duplicate UI component name "%s" — declared by %s and %s.',
                $metadata->name,
                $existing->class,
                $metadata->class,
            ));
        }
        $this->byName[$metadata->name] = $metadata;
    }

    public function get(string $name): ?UiComponentMetadata
    {
        $this->initialize();
        return $this->byName[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /** @return list<UiComponentMetadata> */
    public function all(): array
    {
        $this->initialize();
        return array_values($this->byName);
    }

    public function getBinding(
        string $componentName,
        string $partName,
        string $eventName,
    ): ?UiOnMetadata {
        return $this->get($componentName)?->event($partName, $eventName);
    }

    /** @return list<UiOnMetadata> */
    public function bindingsFor(string $componentName): array
    {
        $component = $this->get($componentName);
        if ($component === null) {
            return [];
        }
        return array_values($component->events);
    }

    public function registerExternal(UiExternalHandlerMetadata $metadata): void
    {
        // Without this, a binding registered before the first read reports its
        // target component as missing when discovery would have registered it.
        $this->initialize();
        $this->registerExternalInternal($metadata);
    }

    public function getExternalBinding(
        string $componentName,
        string $partName,
        string $eventName,
    ): ?UiExternalHandlerMetadata {
        $this->initialize();
        return $this->externalBindings[$componentName . '.' . $partName . '.' . $eventName] ?? null;
    }

    /** @return list<UiExternalHandlerMetadata> */
    public function externalBindingsFor(string $componentName): array
    {
        $this->initialize();
        return $this->externalBindingsByComponent[$componentName] ?? [];
    }

    public function reset(): void
    {
        $this->byName = [];
        $this->externalBindings = [];
        $this->externalBindingsByComponent = [];
        $this->initialized = false;
    }

    /**
     * Reflects one handler class and registers every #[HandlesUiEvent]
     * binding it carries. Validation is strict — any failure throws
     * UiComponentRegistryException so boot fails loud.
     *
     * @param class-string $handlerClass
     */
    public function registerExternalFromClass(string $handlerClass): void
    {
        // A no-op when called from inside the discovery pass, which is what the
        // $initializing guard is for.
        $this->initialize();

        if (!class_exists($handlerClass)) {
            throw new UiComponentRegistryException(sprintf(
                'Handler class %s declared #[HandlesUiEvent] but the class itself could not be loaded.',
                $handlerClass,
            ));
        }
        if (!is_subclass_of($handlerClass, UiEventHandlerInterface::class)) {
            throw new UiComponentRegistryException(sprintf(
                'Handler class %s declares #[HandlesUiEvent] but does not implement %s.',
                $handlerClass,
                UiEventHandlerInterface::class,
            ));
        }

        $reflection = new ReflectionClass($handlerClass);
        foreach ($reflection->getAttributes(HandlesUiEvent::class) as $attr) {
            /** @var HandlesUiEvent $binding */
            $binding = $attr->newInstance();
            $this->registerExternalInternal(
                $this->buildExternalMetadata($handlerClass, $binding),
            );
        }
    }

    private function buildExternalMetadata(
        string $handlerClass,
        HandlesUiEvent $binding,
    ): UiExternalHandlerMetadata {
        $componentClass = $binding->component;
        if (!class_exists($componentClass)) {
            throw new UiComponentRegistryException(sprintf(
                'Handler %s declares #[HandlesUiEvent(component: %s, …)] but that class does not exist.',
                $handlerClass,
                $componentClass,
            ));
        }

        $componentMetadata = $this->findByClass($componentClass);
        if ($componentMetadata === null) {
            throw new UiComponentRegistryException(sprintf(
                'Handler %s declares #[HandlesUiEvent(component: %s, …)] but that class is not a registered Platform UI component (missing #[AsComponent] + #[UiPart]/#[UiSlot]).',
                $handlerClass,
                $componentClass,
            ));
        }

        $partName = trim($binding->part);
        if ($partName === '' || (!isset($componentMetadata->parts[$partName]) && !isset($componentMetadata->slots[$partName]))) {
            throw new UiComponentRegistryException(sprintf(
                'Handler %s declares #[HandlesUiEvent(component: %s, part: "%s", …)] but no #[UiPart] or #[UiSlot] of that name is declared on the component.',
                $handlerClass,
                $componentClass,
                $binding->part,
            ));
        }

        $eventName = trim($binding->event);
        if ($eventName === '' || preg_match(self::EVENT_NAME_PATTERN, $eventName) !== 1) {
            throw new UiComponentRegistryException(sprintf(
                'Handler %s declares #[HandlesUiEvent(component: %s, part: "%s", event: "%s")] with an invalid event name. Expected lowercase identifier matching /^[a-z][a-z0-9:_-]*$/ — no spaces, no Twig delimiters, no JavaScript.',
                $handlerClass,
                $componentClass,
                $partName,
                $binding->event,
            ));
        }

        if ($binding->payload !== null && !class_exists($binding->payload)) {
            throw new UiComponentRegistryException(sprintf(
                'Handler %s declares #[HandlesUiEvent(component: %s, payload: %s)] but that payload class does not exist.',
                $handlerClass,
                $componentClass,
                $binding->payload,
            ));
        }

        return new UiExternalHandlerMetadata(
            componentName: $componentMetadata->name,
            componentClass: $componentClass,
            partName: $partName,
            eventName: $eventName,
            handlerClass: $handlerClass,
            payloadClass: $binding->payload,
        );
    }

    private function registerExternalInternal(UiExternalHandlerMetadata $metadata): void
    {
        $component = $this->byName[$metadata->componentName] ?? null;
        if ($component === null) {
            throw new UiComponentRegistryException(sprintf(
                'Handler %s targets component "%s" which is not registered.',
                $metadata->handlerClass,
                $metadata->componentName,
            ));
        }

        $methodLevel = $component->event($metadata->partName, $metadata->eventName);
        if ($methodLevel !== null) {
            throw new UiComponentRegistryException(sprintf(
                'Handler %s declares #[HandlesUiEvent(component: %s, part: "%s", event: "%s")] but that (part, event) pair is already bound to method %s::%s via #[UiOn]. Each (component, part, event) triple may have at most one binding.',
                $metadata->handlerClass,
                $metadata->componentClass,
                $metadata->partName,
                $metadata->eventName,
                $methodLevel->class,
                $methodLevel->methodName,
            ));
        }

        $flatKey = $metadata->componentName . '.' . $metadata->key();
        if (isset($this->externalBindings[$flatKey])) {
            $existing = $this->externalBindings[$flatKey];
            if ($existing->handlerClass === $metadata->handlerClass) {
                return;
            }
            throw new UiComponentRegistryException(sprintf(
                'Duplicate #[HandlesUiEvent] binding for component "%s" part "%s" event "%s" — declared by both %s and %s.',
                $metadata->componentName,
                $metadata->partName,
                $metadata->eventName,
                $existing->handlerClass,
                $metadata->handlerClass,
            ));
        }

        $this->externalBindings[$flatKey] = $metadata;
        $this->externalBindingsByComponent[$metadata->componentName][] = $metadata;
    }

    private function findByClass(string $componentClass): ?UiComponentMetadata
    {
        foreach ($this->byName as $metadata) {
            if ($metadata->class === $componentClass) {
                return $metadata;
            }
        }
        return null;
    }
}
