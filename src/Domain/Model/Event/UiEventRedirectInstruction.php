<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Server-issued redirect instruction emitted as part of a {@see UiEventResponse}
 * (technical-design.md §12.8).
 *
 * The frontend runtime navigates the user agent to `$url`. `$replace` controls
 * whether the redirect uses `location.replace()` (no history entry) or a
 * regular push.
 */
final readonly class UiEventRedirectInstruction
{
    public function __construct(
        public string $url,
        public bool $replace = false,
    ) {}
}
