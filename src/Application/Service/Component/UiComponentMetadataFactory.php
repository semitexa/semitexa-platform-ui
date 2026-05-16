<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Component;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Attribute\ProvidesUiPart;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiOnMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiPartMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiPartProviderMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiSlotMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiValuePath;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * Reads composition metadata off a class:
 *   - identity from SSR's #[AsComponent] (name);
 *   - parts from #[UiPart] (one per part, FQCN must resolve to a class
 *     marked with #[AsUiPrimitive]);
 *   - slots from #[UiSlot].
 *
 * Validation rules:
 *   - Class must declare exactly one #[AsComponent] (delegated to SSR).
 *   - Part names match the slot/identifier pattern; unique within the
 *     component.
 *   - Slot names match the same pattern; unique within the component.
 *   - UiPart::uses must be a class-string that exists and carries
 *     #[AsUiPrimitive].
 */
final class UiComponentMetadataFactory
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_\-]*$/i';

    /**
     * @param class-string $class
     */
    public function fromClass(string $class): UiComponentMetadata
    {
        $reflection = new ReflectionClass($class);

        $componentAttrs = $reflection->getAttributes(AsComponent::class);
        if ($componentAttrs === []) {
            throw new UiComponentRegistryException(sprintf(
                'Class %s is not marked with #[AsComponent].',
                $class,
            ));
        }
        if (count($componentAttrs) > 1) {
            throw new UiComponentRegistryException(sprintf(
                'Class %s declares #[AsComponent] more than once.',
                $class,
            ));
        }

        /** @var AsComponent $component */
        $component = $componentAttrs[0]->newInstance();
        $componentName = trim($component->name);
        if ($componentName === '') {
            throw new UiComponentRegistryException(sprintf(
                'Component %s declares an empty name.',
                $class,
            ));
        }

        $parts = [];
        foreach ($reflection->getAttributes(UiPart::class) as $attr) {
            /** @var UiPart $part */
            $part = $attr->newInstance();
            $name = trim($part->name);

            if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s declares invalid part name "%s".',
                    $class,
                    $part->name,
                ));
            }
            if (isset($parts[$name])) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s declares part "%s" more than once.',
                    $class,
                    $name,
                ));
            }

            $uses = trim($part->uses);
            if ($uses === '') {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s part "%s" declares an empty uses.',
                    $class,
                    $name,
                ));
            }
            if (!class_exists($uses)) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s part "%s" references missing class %s.',
                    $class,
                    $name,
                    $uses,
                ));
            }
            $primitiveAttrs = (new ReflectionClass($uses))->getAttributes(AsUiPrimitive::class);
            if ($primitiveAttrs === []) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s part "%s" target %s is not marked with #[AsUiPrimitive].',
                    $class,
                    $name,
                    $uses,
                ));
            }
            /** @var AsUiPrimitive $primitiveAttr */
            $primitiveAttr = $primitiveAttrs[0]->newInstance();
            $primitiveName = trim($primitiveAttr->name);
            if ($primitiveName === '') {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s part "%s" target %s declares an empty #[AsUiPrimitive] name.',
                    $class,
                    $name,
                    $uses,
                ));
            }

            $bindPath = null;
            if ($part->bind !== null) {
                $rawBind = trim($part->bind);
                if ($rawBind === '') {
                    throw new UiComponentRegistryException(sprintf(
                        'Component %s part "%s" declares an empty bind path.',
                        $class,
                        $name,
                    ));
                }
                try {
                    $bindPath = UiValuePath::parse($rawBind);
                } catch (UiComponentRegistryException $e) {
                    throw new UiComponentRegistryException(sprintf(
                        'Component %s part "%s" declares invalid bind: %s',
                        $class,
                        $name,
                        $e->getMessage(),
                    ), 0, $e);
                }
            }

            $parts[$name] = new UiPartMetadata(
                name: $name,
                uses: $uses,
                primitiveName: $primitiveName,
                defaults: $part->defaults,
                bind: $bindPath,
            );
        }

        $slots = [];
        foreach ($reflection->getAttributes(UiSlot::class) as $attr) {
            /** @var UiSlot $slot */
            $slot = $attr->newInstance();
            $name = trim($slot->name);

            if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s declares invalid slot name "%s".',
                    $class,
                    $slot->name,
                ));
            }
            if (isset($slots[$name])) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s declares slot "%s" more than once.',
                    $class,
                    $name,
                ));
            }

            $slots[$name] = new UiSlotMetadata(
                name: $name,
                description: $slot->description,
            );
        }

        $providers = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $providerAttrs = $method->getAttributes(ProvidesUiPart::class);
            if ($providerAttrs === []) {
                continue;
            }
            if (count($providerAttrs) > 1) {
                throw new UiComponentRegistryException(sprintf(
                    'Method %s::%s declares #[ProvidesUiPart] more than once.',
                    $class,
                    $method->getName(),
                ));
            }

            /** @var ProvidesUiPart $provider */
            $provider = $providerAttrs[0]->newInstance();
            $partName = trim($provider->part);

            if ($partName === '') {
                throw new UiComponentRegistryException(sprintf(
                    'Method %s::%s declares #[ProvidesUiPart] with empty part name.',
                    $class,
                    $method->getName(),
                ));
            }
            if (!isset($parts[$partName])) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s method %s declares #[ProvidesUiPart(part: "%s")] but no #[UiPart(name: "%s")] is declared on the same component.',
                    $class,
                    $method->getName(),
                    $partName,
                    $partName,
                ));
            }
            if (isset($providers[$partName])) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s declares more than one #[ProvidesUiPart(part: "%s")] (already on %s, now on %s).',
                    $class,
                    $partName,
                    $providers[$partName]->method,
                    $method->getName(),
                ));
            }
            if ($method->isStatic()) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s::%s with #[ProvidesUiPart] must not be static.',
                    $class,
                    $method->getName(),
                ));
            }
            if ($method->isAbstract()) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s::%s with #[ProvidesUiPart] must not be abstract.',
                    $class,
                    $method->getName(),
                ));
            }

            $returnType = $method->getReturnType();
            if ($returnType instanceof ReflectionNamedType && $returnType->getName() !== 'array') {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s::%s with #[ProvidesUiPart(part: "%s")] must declare return type `array`, got `%s`.',
                    $class,
                    $method->getName(),
                    $partName,
                    $returnType->getName(),
                ));
            }

            $providers[$partName] = new UiPartProviderMetadata(
                part: $partName,
                class: $class,
                method: $method->getName(),
            );
        }

        $events = $this->collectEvents($reflection, $class, $componentName, $parts);

        return new UiComponentMetadata(
            class: $class,
            name: $componentName,
            parts: $parts,
            slots: $slots,
            providers: $providers,
            events: $events,
        );
    }

    /**
     * Scan public, non-static, non-abstract methods for #[UiOn] and produce
     * validated UiOnMetadata records. Validation rules:
     *   - one #[UiOn] per method (no repeats);
     *   - method must not be static or abstract;
     *   - referenced part must exist in $parts;
     *   - event name must match the lowercase identifier pattern;
     *   - updates path, when provided, must parse as UiValuePath;
     *   - when the target part has a bind path and updates is omitted,
     *     inherit the bind path as the updates path;
     *   - when both are present and they differ, strict-mismatch error;
     *   - (part, event) pairs are unique within a component.
     *
     * @param ReflectionClass<object>           $reflection
     * @param class-string                      $class
     * @param array<string, UiPartMetadata>     $parts
     * @return array<string, UiOnMetadata>
     */
    private function collectEvents(
        ReflectionClass $reflection,
        string $class,
        string $componentName,
        array $parts,
    ): array {
        $events = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attrs = $method->getAttributes(UiOn::class);
            if ($attrs === []) {
                continue;
            }
            if (count($attrs) > 1) {
                throw new UiComponentRegistryException(sprintf(
                    'Method %s::%s declares #[UiOn] more than once.',
                    $class,
                    $method->getName(),
                ));
            }

            /** @var UiOn $on */
            $on = $attrs[0]->newInstance();

            if ($method->isStatic()) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s::%s with #[UiOn] must not be static.',
                    $class,
                    $method->getName(),
                ));
            }
            if ($method->isAbstract()) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s::%s with #[UiOn] must not be abstract.',
                    $class,
                    $method->getName(),
                ));
            }

            $partName = trim($on->part);
            if ($partName === '' || !isset($parts[$partName])) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s method %s declares #[UiOn(part: "%s", …)] but no #[UiPart(name: "%s")] is declared on the same component.',
                    $class,
                    $method->getName(),
                    $on->part,
                    $on->part,
                ));
            }

            $eventName = trim($on->event);
            if ($eventName === '' || preg_match('/\A[a-z][a-z0-9:_-]*\z/', $eventName) !== 1) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s method %s declares #[UiOn(event: "%s", …)] with an invalid event name. Expected lowercase identifier matching /^[a-z][a-z0-9:_-]*$/ — no spaces, no Twig delimiters, no JavaScript.',
                    $class,
                    $method->getName(),
                    $on->event,
                ));
            }

            $part = $parts[$partName];
            $explicitUpdates = $on->updates !== null ? trim($on->updates) : null;
            $updatesPath = null;

            if ($explicitUpdates !== null && $explicitUpdates !== '') {
                try {
                    $updatesPath = UiValuePath::parse($explicitUpdates);
                } catch (UiComponentRegistryException $e) {
                    throw new UiComponentRegistryException(sprintf(
                        'Component %s method %s declares #[UiOn(updates: "%s", …)] with invalid path: %s',
                        $class,
                        $method->getName(),
                        $on->updates,
                        $e->getMessage(),
                    ), 0, $e);
                }

                if ($part->bind !== null && (string) $part->bind !== (string) $updatesPath) {
                    throw new UiComponentRegistryException(sprintf(
                        'Component %s method %s declares #[UiOn(part: "%s", updates: "%s")] but part "%s" is bound to "%s". In this slice the updates path must match the part bind path (or be omitted to inherit it).',
                        $class,
                        $method->getName(),
                        $partName,
                        (string) $updatesPath,
                        $partName,
                        (string) $part->bind,
                    ));
                }
            } elseif ($part->bind !== null) {
                $updatesPath = $part->bind;
            }

            $key = $partName . '.' . $eventName;
            if (isset($events[$key])) {
                throw new UiComponentRegistryException(sprintf(
                    'Component %s declares more than one #[UiOn(part: "%s", event: "%s")] (already on %s, now on %s).',
                    $class,
                    $partName,
                    $eventName,
                    $events[$key]->methodName,
                    $method->getName(),
                ));
            }

            $events[$key] = new UiOnMetadata(
                componentName: $componentName,
                class: $class,
                partName: $partName,
                eventName: $eventName,
                updatesPath: $updatesPath,
                methodName: $method->getName(),
            );
        }

        return $events;
    }
}
