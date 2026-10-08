<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\State;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\PlatformUi\Attribute\UiState;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextSecret;

/**
 * Component state on the server (ep-platform-live-state · tk-ls-component-state).
 *
 * An instance's props used to ride every one of its signed contexts (`pr`):
 * a copy per bound event, at most 4 KB, dropped silently above that — and the
 * instance could then no longer be drawn again. Now, when a component keeps
 * state (#[UiState]) or its props are larger than a small inline limit, they
 * are saved in the shared store and the context carries a reference (`st`):
 * an HMAC of the visitor's session and the instance id, so it is the same key
 * on every re-render of the instance and nobody can name another visitor's.
 *
 * Concurrency: every event reads the state as last saved, so events that
 * follow one another count on each other. Two events of one instance handled
 * at the very same moment on two workers both read the same state and the
 * later write wins — the cache has no compare-and-set.
 *
 * Small props of a stateless component stay inline: no store round trip for
 * the common case. Without a shared store (an `array` cache), everything stays
 * inline, as before.
 */
final class UiComponentStates
{
    /** Props up to this many bytes (JSON) of a stateless component ride the context inline. */
    public const INLINE_LIMIT_BYTES = 1024;

    /** The largest state kept. */
    public const MAX_BYTES = 65536;

    /** How long state outlives its last render: a page left open all day stays live. */
    public const TTL_SECONDS = 43200;

    /** @var (\Closure(): (UiComponentStateStoreInterface|null))|null worker-lifetime, not request state */
    private static ?\Closure $store = null;

    /** @var array<class-string, list<string>> worker-lifetime cache of #[UiState] property names */
    private static array $stateProperties = [];

    public static function resolveFrom(ContainerInterface $container): void
    {
        self::$store = static function () use ($container): ?UiComponentStateStoreInterface {
            try {
                $store = RequestScopedContainer::forCurrentExecution($container)->get(UiComponentStateStoreInterface::class);
            } catch (ContainerExceptionInterface) {
                // No store bound: props ride the context inline.
                return null;
            } catch (\Throwable $e) {
                // Anything else is a defect, not "no store": say so, then
                // fall back to inline props rather than fail the render.
                StaticLoggerBridge::error('platform_ui', 'UI component state store could not be resolved', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                return null;
            }

            return $store instanceof UiComponentStateStoreInterface ? $store : null;
        };
    }

    /** Test seam: a fixed store (null clears). */
    public static function use(?UiComponentStateStoreInterface $store): void
    {
        self::$store = $store === null ? null : static fn (): UiComponentStateStoreInterface => $store;
    }

    public static function reset(): void
    {
        self::$store = null;
        self::$stateProperties = [];
    }

    /**
     * Save $props as $instanceId's state when it should be kept on the server
     * — the component has #[UiState], or the props are too large to ride a
     * context — and return the key a context carries instead of them; null
     * when they stay inline (or cannot be kept: no store, no session).
     *
     * @param array<string, mixed> $props
     */
    public static function persist(string $componentClass, string $instanceId, array $props): ?string
    {
        unset($props['instanceId']);
        $json = json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            return null;
        }
        $stateful = self::propertiesOf($componentClass) !== [];
        if (!$stateful && strlen($json) <= self::INLINE_LIMIT_BYTES) {
            return null;
        }
        $store = self::store();
        $key = self::keyFor($instanceId);
        // A store only this worker sees would lose the state to an event that
        // reaches another one: without a shared store the props ride the
        // context inline as before (#[UiState] still reads them from there).
        if ($store === null || $key === null || !$store->isShared()) {
            return null;
        }
        $store->put($key, $props, self::TTL_SECONDS);

        return $key;
    }

    /**
     * The state saved under $key for $instanceId, or null when it is gone
     * (expired) or the key is not this visitor's for this instance.
     *
     * @return array<string, mixed>|null
     */
    public static function load(string $key, string $instanceId): ?array
    {
        $expected = self::keyFor($instanceId);
        $store = self::store();
        if ($expected === null || $store === null || !hash_equals($expected, $key)) {
            return null;
        }

        return $store->get($key);
    }

    /**
     * Verified claims with the state a context names (`st`) put where the
     * props would have ridden (`pr`): from here on everything downstream —
     * props(), a re-render — reads it as the instance's props.
     *
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     * @throws UiInteractionUnprocessableException state_expired, when it is gone
     */
    public static function intoClaims(array $claims, string $instanceId): array
    {
        if (!is_string($claims['st'] ?? null)) {
            return $claims;
        }
        $state = self::load($claims['st'], $instanceId);
        if ($state === null) {
            throw new UiInteractionUnprocessableException('state_expired', 'This view has expired. Reload the page.');
        }
        $claims['pr'] = $state;

        return $claims;
    }

    /**
     * A handler that changed #[UiState] gets the re-render it implies: added
     * when it asked for none, merged into the one it asked for (its own props
     * win). The re-render saves the state.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function withChanges(mixed $raw, string $instanceId, array $before, array $after): mixed
    {
        if ($before === $after) {
            return $raw;
        }
        $result = $raw instanceof UiInteractionResult ? $raw : UiInteractionResult::patch([], is_array($raw) ? $raw : []);
        $patches = [];
        $merged = false;
        foreach ($result->patches as $patch) {
            if ($patch instanceof UiResponsePatch && $patch->op === UiResponsePatch::OP_RERENDER && $patch->targetInstance === $instanceId) {
                $own = is_array($patch->args['props'] ?? null) ? $patch->args['props'] : [];
                $patch = UiResponsePatch::rerender($instanceId, array_merge($after, $own));
                $merged = true;
            }
            $patches[] = $patch;
        }
        if (!$merged) {
            array_unshift($patches, UiResponsePatch::rerender($instanceId, $after));
        }

        return UiInteractionResult::patch($patches, $result->debug)->dispatching(...$result->domainEvents);
    }

    /**
     * The #[UiState] property names of a component class, in declaration order.
     *
     * @return list<string>
     */
    public static function propertiesOf(string $componentClass): array
    {
        if ($componentClass === '' || !class_exists($componentClass)) {
            return [];
        }
        if (!isset(self::$stateProperties[$componentClass])) {
            $names = [];
            foreach ((new \ReflectionClass($componentClass))->getProperties() as $property) {
                if ($property->getAttributes(UiState::class) !== [] && $property->isPublic() && !$property->isStatic()) {
                    $names[] = $property->getName();
                }
            }
            self::$stateProperties[$componentClass] = $names;
        }

        return self::$stateProperties[$componentClass];
    }

    /**
     * Set a component's #[UiState] properties from its props; what each one
     * holds afterwards.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    public static function hydrate(object $component, array $props): array
    {
        foreach (self::propertiesOf($component::class) as $name) {
            if (!array_key_exists($name, $props)) {
                continue;
            }
            // A value the declared type cannot hold (a null saved from an
            // uninitialised property, 'abc' for an int) is skipped: the
            // property keeps its default instead of failing every later event.
            try {
                $component->{$name} = self::coerce(new \ReflectionProperty($component, $name), $props[$name]);
            } catch (\TypeError) {
                continue;
            }
        }

        return self::snapshot($component);
    }

    /** @return array<string, mixed> each #[UiState] property's value now */
    public static function snapshot(object $component): array
    {
        $values = [];
        foreach (self::propertiesOf($component::class) as $name) {
            $property = new \ReflectionProperty($component, $name);
            $values[$name] = $property->isInitialized($component) ? $property->getValue($component) : null;
        }

        return $values;
    }

    /** A JSON value back into the property's declared scalar type; anything else as it is. */
    private static function coerce(\ReflectionProperty $property, mixed $value): mixed
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $value === null) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL),
            'string' => is_scalar($value) ? (string) $value : $value,
            default => $value,
        };
    }

    private static function keyFor(string $instanceId): ?string
    {
        $binding = SignedContextBinding::current();
        if ($binding === null || $binding['s'] === '' || $instanceId === '') {
            return null;
        }

        return substr(hash_hmac('sha256', 'ui-state:' . $binding['s'] . ':' . $binding['t'] . ':' . $instanceId, SignedContextSecret::resolve()), 0, 40);
    }

    private static function store(): ?UiComponentStateStoreInterface
    {
        return self::$store !== null ? (self::$store)() : null;
    }
}
