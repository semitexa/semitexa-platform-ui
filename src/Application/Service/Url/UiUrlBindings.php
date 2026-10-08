<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Url;

use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Attribute\UiUrl;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/**
 * A component's `#[UiUrl]` bindings: query value → prop (validated) on a page
 * load, prop → `url` effect after a re-render. Declarations are checked once
 * per class; a bad one fails loudly rather than binding something odd.
 */
final class UiUrlBindings
{
    private const IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/';

    /** What UiPatchValidator accepts for one url param, in bytes. */
    public const MAX_VALUE_BYTES = 512;

    /** @var array<string, list<UiUrl>> class → bindings (worker-lifetime cache of reflection) */
    private static array $byClass = [];

    /** @return list<UiUrl> */
    public static function forComponent(string $componentName): array
    {
        $metadata = UiComponentRegistry::get($componentName);

        return $metadata === null ? [] : self::forClass($metadata->class);
    }

    /** @return list<UiUrl> */
    public static function forClass(string $class): array
    {
        if (isset(self::$byClass[$class])) {
            return self::$byClass[$class];
        }
        $bindings = [];
        $keys = [];
        foreach ((new \ReflectionClass($class))->getAttributes(UiUrl::class) as $attribute) {
            $binding = $attribute->newInstance();
            self::assertDeclaration($class, $binding);
            if (isset($keys[$binding->key()])) {
                throw new \LogicException(sprintf('%s binds the query key "%s" twice.', $class, $binding->key()));
            }
            $keys[$binding->key()] = true;
            $bindings[] = $binding;
        }

        return self::$byClass[$class] = $bindings;
    }

    /**
     * The query string → props, value by value; an invalid value is ignored.
     *
     * @param array<array-key, mixed> $props
     * @param array<array-key, mixed> $query
     * @return array<array-key, mixed>
     */
    public static function restore(string $componentName, array $props, array $query): array
    {
        foreach (self::forComponent($componentName) as $binding) {
            if (!array_key_exists($binding->key(), $query)) {
                continue;
            }
            $value = self::coerce($binding, $query[$binding->key()]);
            if ($value !== null) {
                $props[$binding->prop] = $value;
            }
        }

        return $props;
    }

    /**
     * A re-render changed bound props → one `url` effect carrying them.
     *
     * @param array<array-key, mixed> $before the signed props
     * @param array<array-key, mixed> $after  the props it re-rendered with
     */
    public static function effectFor(string $componentName, string $instanceId, array $before, array $after): ?UiResponsePatch
    {
        $params = [];
        $push = false;
        foreach (self::forComponent($componentName) as $binding) {
            $old = $before[$binding->prop] ?? null;
            $new = $after[$binding->prop] ?? null;
            if ($old === $new) {
                continue;
            }
            $params[$binding->key()] = self::toQuery($binding, $new);
            $push = $push || $binding->history === UiUrl::HISTORY_PUSH;
        }

        return $params === [] ? null : UiResponsePatch::url($instanceId, $params, $push);
    }

    /** The validated value, or null when the raw value does not qualify. */
    public static function coerce(UiUrl $binding, mixed $raw): string|int|bool|null
    {
        if (!is_string($raw)) {
            return null; // ?q[]=… and friends
        }
        switch ($binding->type) {
            case 'int':
                if (preg_match('/\A-?\d{1,18}\z/', $raw) !== 1) {
                    return null;
                }
                $int = (int) $raw;
                if (($binding->min !== null && $int < $binding->min) || ($binding->max !== null && $int > $binding->max)) {
                    return null;
                }
                return $int;
            case 'bool':
                return match ($raw) {
                    '1', 'true' => true,
                    '0', 'false' => false,
                    default => null,
                };
            default:
                if (mb_strlen($raw) > $binding->maxLength || preg_match('//u', $raw) !== 1) {
                    return null;
                }
                if ($binding->values !== null && !in_array($raw, $binding->values, true)) {
                    return null;
                }
                return $raw;
        }
    }

    /** null = leave the key out of the URL. */
    private static function toQuery(UiUrl $binding, mixed $value): ?string
    {
        if ($value === null || $value === $binding->except || !is_scalar($value)) {
            return null;
        }
        $string = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        // maxLength counts characters, the url patch is limited in bytes:
        // cap both, cutting on a UTF-8 boundary.
        return $string === '' ? null : mb_strcut(mb_substr($string, 0, $binding->maxLength), 0, self::MAX_VALUE_BYTES);
    }

    private static function assertDeclaration(string $class, UiUrl $binding): void
    {
        $problem = match (true) {
            preg_match(self::IDENTIFIER, $binding->prop) !== 1 => 'prop must be an identifier',
            preg_match(self::IDENTIFIER, $binding->key()) !== 1 => '`as` must be an identifier',
            !in_array($binding->history, [UiUrl::HISTORY_REPLACE, UiUrl::HISTORY_PUSH], true) => 'history must be "replace" or "push"',
            !in_array($binding->type, UiUrl::TYPES, true) => 'type must be string, int or bool',
            $binding->maxLength < 1 || $binding->maxLength > 512 => 'maxLength must be 1..512',
            default => null,
        };
        if ($problem !== null) {
            throw new \LogicException(sprintf('#[UiUrl(prop: "%s")] on %s: %s.', $binding->prop, $class, $problem));
        }
    }
}
