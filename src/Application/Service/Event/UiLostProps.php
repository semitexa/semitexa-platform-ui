<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/**
 * Props that could not be kept for a later re-render: objects, or more than a
 * signed context holds with no shared store to put them in. Redrawn without
 * them a list came back empty with no error at all, so the names ride the
 * context and a re-render that would miss one is refused instead.
 */
final class UiLostProps
{
    public const CLAIM = 'pl';

    /**
     * @param array<array-key, mixed> $props
     * @return list<string> the names to remember; none when nothing was given
     */
    public static function namesOf(array $props): array
    {
        $names = array_map('strval', array_keys($props));

        return array_values(array_filter($names, static fn (string $name): bool => $name !== 'instanceId'));
    }

    /**
     * The props a rerender patch passes, once it is sure nothing is lost.
     *
     * @param array<string, mixed> $claims
     * @return array<array-key, mixed>
     *
     * @throws UiInteractionUnprocessableException rerender_props_lost
     */
    public static function overridesFor(UiResponsePatch $patch, array $claims, string $componentName): array
    {
        $overrides = is_array($patch->args['props'] ?? null) ? $patch->args['props'] : [];
        // A data provider draws them again by itself.
        if (is_string($claims['dp'] ?? null) || !is_array($claims[self::CLAIM] ?? null)) {
            return $overrides;
        }
        $missing = array_values(array_diff($claims[self::CLAIM], array_map('strval', array_keys($overrides))));
        if ($missing === []) {
            return $overrides;
        }

        throw new UiInteractionUnprocessableException(
            'rerender_props_lost',
            sprintf(
                '%s cannot be re-rendered: its props %s held objects or did not fit, so this request cannot restore them. '
                . 'Pass them to rerender(), keep them as plain data, or give the component a data provider.',
                $componentName,
                implode(', ', $missing),
            ),
        );
    }
}
