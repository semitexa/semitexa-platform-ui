<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Typed error attached to a {@see UiEventResponse} with status `Error`
 * (technical-design.md §12.8 + §7.6.2).
 *
 * `$code` is a stable, operator-safe symbolic identifier (e.g.
 * `'rate_limited'`, `'unprocessable'`). `$message` is a short operator-safe
 * description — never a stack trace, never a raw exception message. Optional
 * `$details` carries a small bag for renderers (field hints, retry hints).
 */
final readonly class UiEventError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $details = [],
    ) {}
}
