<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\Ssr\Application\Service\UiEvent\UiSseSessionState;

/**
 * @deprecated Use {@see UiSseSessionState} instead. Kept at the old FQCN so
 *             consumers holding this name keep working; scheduled for removal
 *             once they have moved.
 *
 * The implementation moved to `semitexa/ssr` because ssr's deferred-render
 * pipeline is its principal caller and could previously only reach it through
 * `class_exists()` guards — ssr cannot require platform-ui, since platform-ui
 * already requires ssr and the reverse edge would close a cycle.
 *
 * Every method here forwards. There is no state of its own: the underlying
 * coroutine-local key is unchanged, so this shim and {@see UiSseSessionState}
 * read and write the same id. Mixing the two in one request is therefore safe,
 * which is what makes a gradual migration possible at all.
 */
final class PlatformUiSseSessionState
{
    /** @deprecated Use {@see UiSseSessionState::SAFE_ID_PATTERN}. */
    public const SAFE_ID_PATTERN = UiSseSessionState::SAFE_ID_PATTERN;

    /** @deprecated Use {@see UiSseSessionState::current()}. */
    public static function current(): ?string
    {
        return UiSseSessionState::current();
    }

    /** @deprecated Use {@see UiSseSessionState::mintIfAbsent()}. */
    public static function mintIfAbsent(): string
    {
        return UiSseSessionState::mintIfAbsent();
    }

    /** @deprecated Use {@see UiSseSessionState::reset()}. */
    public static function reset(): void
    {
        UiSseSessionState::reset();
    }

    /** @deprecated Use {@see UiSseSessionState::restore()}. */
    public static function restore(string $id): void
    {
        UiSseSessionState::restore($id);
    }

    /** @deprecated Use {@see UiSseSessionState::setForTesting()}. */
    public static function setForTesting(string $id): void
    {
        UiSseSessionState::setForTesting($id);
    }
}
