<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Component;

use ReflectionClass;
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
 * Registry of Platform UI components — classes that combine #[AsComponent]
 * with one or more #[UiPart] / #[UiSlot] declarations.
 *
 * Rendering still flows through SSR's ComponentRegistry / ComponentRenderer
 * (every Platform UI component is also a regular AsComponent). This registry
 * only exposes the composition layer for introspection and tests.
 *
 * Mirrors the static-with-explicit-DI shape of UiPrimitiveRegistry so the
 * boot listener can wire both registries together.
 */
final class UiComponentRegistry
{
    private const EVENT_NAME_PATTERN = '/\A[a-z][a-z0-9:_-]*\z/';

    /** @var array<string, UiComponentMetadata> */
    private static array $byName = [];

    /** @var array<string, UiExternalHandlerMetadata> Flat key `<componentName>.<partName>.<eventName>` → external binding. */
    private static array $externalBindings = [];

    /** @var array<string, list<UiExternalHandlerMetadata>> Grouped by component name, declaration order preserved. */
    private static array $externalBindingsByComponent = [];

    private static bool $initialized = false;
    private static ?ClassDiscovery $classDiscovery = null;
    private static ?UiComponentMetadataFactory $factory = null;

    public static function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        self::$classDiscovery = $classDiscovery;
    }

    public static function setFactory(UiComponentMetadataFactory $factory): void
    {
        self::$factory = $factory;
    }

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }
        if (self::$classDiscovery === null) {
            self::$initialized = true;
            return;
        }

        $factory = self::$factory ?? new UiComponentMetadataFactory();

        // A Platform UI component is any class that declares at least one
        // #[UiPart] or #[UiSlot]. Components without parts/slots remain
        // plain SSR components and stay out of this registry.
        $candidates = array_unique(array_merge(
            self::$classDiscovery->findClassesWithAttribute(UiPart::class),
            self::$classDiscovery->findClassesWithAttribute(UiSlot::class),
        ));

        foreach ($candidates as $class) {
            self::registerInternal($factory->fromClass($class));
        }

        // Class-level #[HandlesUiEvent] discovery runs only after every
        // component has been registered, since each binding must validate
        // against its component's already-declared parts/slots/events.
        foreach (self::$classDiscovery->findClassesWithAttribute(HandlesUiEvent::class) as $handlerClass) {
            self::registerExternalFromClass($handlerClass);
        }

        self::$initialized = true;
    }

    public static function register(UiComponentMetadata $metadata): void
    {
        self::registerInternal($metadata);
    }

    private static function registerInternal(UiComponentMetadata $metadata): void
    {
        if (isset(self::$byName[$metadata->name])) {
            $existing = self::$byName[$metadata->name];
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
        self::$byName[$metadata->name] = $metadata;
    }

    public static function get(string $name): ?UiComponentMetadata
    {
        self::initialize();
        return self::$byName[$name] ?? null;
    }

    public static function has(string $name): bool
    {
        return self::get($name) !== null;
    }

    /** @return list<UiComponentMetadata> */
    public static function all(): array
    {
        self::initialize();
        return array_values(self::$byName);
    }

    public static function getBinding(
        string $componentName,
        string $partName,
        string $eventName,
    ): ?UiOnMetadata {
        return self::get($componentName)?->event($partName, $eventName);
    }

    /** @return list<UiOnMetadata> */
    public static function bindingsFor(string $componentName): array
    {
        $component = self::get($componentName);
        if ($component === null) {
            return [];
        }
        return array_values($component->events);
    }

    public static function registerExternal(UiExternalHandlerMetadata $metadata): void
    {
        self::registerExternalInternal($metadata);
    }

    public static function getExternalBinding(
        string $componentName,
        string $partName,
        string $eventName,
    ): ?UiExternalHandlerMetadata {
        self::initialize();
        return self::$externalBindings[$componentName . '.' . $partName . '.' . $eventName] ?? null;
    }

    /** @return list<UiExternalHandlerMetadata> */
    public static function externalBindingsFor(string $componentName): array
    {
        self::initialize();
        return self::$externalBindingsByComponent[$componentName] ?? [];
    }

    public static function reset(): void
    {
        self::$byName = [];
        self::$externalBindings = [];
        self::$externalBindingsByComponent = [];
        self::$initialized = false;
    }

    /**
     * Reflects one handler class and registers every #[HandlesUiEvent]
     * binding it carries. Validation is strict — any failure throws
     * UiComponentRegistryException so boot fails loud.
     *
     * @param class-string $handlerClass
     */
    public static function registerExternalFromClass(string $handlerClass): void
    {
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
            self::registerExternalInternal(
                self::buildExternalMetadata($handlerClass, $binding),
            );
        }
    }

    private static function buildExternalMetadata(
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

        $componentMetadata = self::findByClass($componentClass);
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

    private static function registerExternalInternal(UiExternalHandlerMetadata $metadata): void
    {
        $component = self::$byName[$metadata->componentName] ?? null;
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
        if (isset(self::$externalBindings[$flatKey])) {
            $existing = self::$externalBindings[$flatKey];
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

        self::$externalBindings[$flatKey] = $metadata;
        self::$externalBindingsByComponent[$metadata->componentName][] = $metadata;
    }

    private static function findByClass(string $componentClass): ?UiComponentMetadata
    {
        foreach (self::$byName as $metadata) {
            if ($metadata->class === $componentClass) {
                return $metadata;
            }
        }
        return null;
    }
}
