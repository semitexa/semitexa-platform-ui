<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Catalog;

/**
 * The Twig that renders a catalog example, ready to copy.
 *
 * A contract's example is data: its props and slots. Printed by name alone it
 * told a reader that an example exists and not how to call the thing, which sent
 * them to the template or to hand-written markup. The example's own data becomes
 * the call, so it cannot drift from the contract.
 */
final class UiCallExample
{
    /**
     * @param array<string, mixed> $example As UiContract::exampleArray() gives it.
     */
    public static function for(string $kind, string $name, array $example): string
    {
        if (isset($example['template'])) {
            return ''; // A behavior's example is markup in a file, not a call.
        }

        $props = (array) ($example['props'] ?? []);
        $slots = (array) ($example['slots'] ?? []);

        return match ($kind) {
            'component' => self::call('component', $name, $slots === [] ? [$props] : [$props, $slots]),
            'primitive' => self::call('primitive', $name, [$props]),
            default => '',
        };
    }

    /**
     * @param list<array<array-key, mixed>> $arguments
     */
    private static function call(string $function, string $name, array $arguments): string
    {
        $printed = [self::literal($name)];
        foreach ($arguments as $argument) {
            $printed[] = $argument === [] ? '{}' : self::literal($argument);
        }

        return '{{ ' . $function . '(' . implode(', ', $printed) . ') }}';
    }

    /**
     * A Twig literal, not JSON: a double-quoted Twig string interpolates `#{...}` and
     * knows no `\uXXXX`, so JSON would hand over something else than the example says.
     */
    private static function literal(mixed $value): string
    {
        return match (true) {
            is_string($value) => self::text($value),
            is_array($value) => self::collection($value),
            // A contract's object prop arrives as stdClass; a hash stays a hash when empty.
            is_object($value) => self::object($value),
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => var_export($value, true), // 1.0 stays a float, json_encode would drop the .0
            $value === null => 'null',
            default => (string) json_encode($value, JSON_THROW_ON_ERROR),
        };
    }

    /**
     * Single quotes take everything as it is, except a carriage return: Twig turns
     * line endings in its source into \n, so that one needs an escape, and only a
     * double-quoted string has escapes.
     */
    private static function text(string $value): string
    {
        if (!str_contains($value, "\r")) {
            return "'" . addcslashes($value, "'\\") . "'";
        }

        return '"' . str_replace(['\\', '"', '#', "\r"], ['\\\\', '\\"', '\\#', '\\r'], $value) . '"';
    }

    private static function object(object $value): string
    {
        $fields = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return is_array($fields) && $fields !== [] ? self::hash($fields) : '{}';
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function collection(array $value): string
    {
        if (array_is_list($value)) {
            return '[' . implode(', ', array_map(self::literal(...), $value)) . ']';
        }

        return self::hash($value);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function hash(array $value): string
    {
        $pairs = [];
        foreach ($value as $key => $item) {
            $pairs[] = self::literal((string) $key) . ': ' . self::literal($item);
        }

        return '{' . implode(', ', $pairs) . '}';
    }
}
