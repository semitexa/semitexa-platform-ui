<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Catalog;

/**
 * The Twig that renders a catalog example, ready to copy.
 *
 * A contract's example is data: its props and slots. Printed by name alone it
 * told a reader that an example exists and not how to call the thing, which sent
 * them to the template or to hand-written markup. JSON is valid in a Twig hash, so
 * the example's own data is the call, and it cannot drift from the contract.
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
        $printed = ["'" . $name . "'"];
        foreach ($arguments as $argument) {
            $printed[] = $argument === []
                ? '{}'
                : json_encode($argument, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return '{{ ' . $function . '(' . implode(', ', $printed) . ') }}';
    }
}
