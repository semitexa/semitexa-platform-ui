<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive;

use ReflectionClass;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Exception\PrimitiveRegistryException;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;
use Semitexa\PlatformUi\Domain\Model\Primitive\UiPrimitiveEvent;

/**
 * Reads #[AsUiPrimitive] off a class and produces an immutable PrimitiveMetadata.
 *
 * Validates: non-empty name, non-empty ui alias, alias derivation from $name's
 * last dot-segment when omitted, declared event uniqueness.
 */
final class UiPrimitiveMetadataFactory
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_.\-]*$/i';
    private const UI_PATTERN = '/^[a-z][a-z0-9_\-]*$/i';
    private const EVENT_PATTERN = '/^[a-z][a-z0-9_:\-]*$/i';

    /**
     * @param class-string $class
     */
    public function fromClass(string $class): PrimitiveMetadata
    {
        $reflection = new ReflectionClass($class);
        $attrs = $reflection->getAttributes(AsUiPrimitive::class);

        if ($attrs === []) {
            throw new PrimitiveRegistryException(sprintf(
                'Class %s is not marked with #[AsUiPrimitive].',
                $class,
            ));
        }

        if (count($attrs) > 1) {
            throw new PrimitiveRegistryException(sprintf(
                'Class %s declares #[AsUiPrimitive] more than once.',
                $class,
            ));
        }

        /** @var AsUiPrimitive $attr */
        $attr = $attrs[0]->newInstance();

        return $this->fromAttribute($class, $attr);
    }

    public function fromAttribute(string $class, AsUiPrimitive $attr): PrimitiveMetadata
    {
        $name = trim($attr->name);
        if ($name === '') {
            throw new PrimitiveRegistryException(sprintf(
                'Primitive %s declares an empty name.',
                $class,
            ));
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new PrimitiveRegistryException(sprintf(
                'Primitive %s declares invalid name "%s" — must match %s.',
                $class,
                $name,
                self::NAME_PATTERN,
            ));
        }

        $ui = $attr->ui !== null ? trim($attr->ui) : null;
        if ($ui === '' || $ui === null) {
            $ui = self::deriveUiFromName($name);
        }

        if (preg_match(self::UI_PATTERN, $ui) !== 1) {
            throw new PrimitiveRegistryException(sprintf(
                'Primitive %s declares invalid ui alias "%s" — must match %s.',
                $class,
                $ui,
                self::UI_PATTERN,
            ));
        }

        $template = self::stringOrNull($attr->template);
        $script = self::stringOrNull($attr->script);
        $style = self::stringOrNull($attr->style);

        $events = [];
        $seenEventNames = [];
        foreach ($attr->events as $index => $event) {
            if (!$event instanceof UiPrimitiveEvent) {
                throw new PrimitiveRegistryException(sprintf(
                    'Primitive %s event #%d must be an instance of %s.',
                    $class,
                    $index,
                    UiPrimitiveEvent::class,
                ));
            }

            $eventName = trim($event->name);
            if ($eventName === '') {
                throw new PrimitiveRegistryException(sprintf(
                    'Primitive %s declares an event with empty name (index %d).',
                    $class,
                    $index,
                ));
            }

            if (preg_match(self::EVENT_PATTERN, $eventName) !== 1) {
                throw new PrimitiveRegistryException(sprintf(
                    'Primitive %s declares invalid event name "%s" — must match %s.',
                    $class,
                    $eventName,
                    self::EVENT_PATTERN,
                ));
            }

            if (isset($seenEventNames[$eventName])) {
                throw new PrimitiveRegistryException(sprintf(
                    'Primitive %s declares event "%s" more than once.',
                    $class,
                    $eventName,
                ));
            }
            $seenEventNames[$eventName] = true;

            if ($event->native !== null && trim($event->native) === '') {
                throw new PrimitiveRegistryException(sprintf(
                    'Primitive %s event "%s" declares an empty native DOM event.',
                    $class,
                    $eventName,
                ));
            }

            $events[] = $event;
        }

        return new PrimitiveMetadata(
            class: $class,
            name: $name,
            ui: $ui,
            template: $template,
            script: $script,
            style: $style,
            events: $events,
        );
    }

    public static function deriveUiFromName(string $name): string
    {
        $pos = strrpos($name, '.');
        $tail = $pos === false ? $name : substr($name, $pos + 1);

        return $tail === '' ? $name : $tail;
    }

    private static function stringOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
