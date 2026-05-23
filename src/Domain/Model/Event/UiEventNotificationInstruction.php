<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Server-issued toast/notification instruction emitted as part of a
 * {@see UiEventResponse} (technical-design.md §12.8).
 *
 * `$level` is intentionally narrow (info / success / warning / error) so the
 * frontend renderer can map straight to a visual variant without a parser.
 */
final readonly class UiEventNotificationInstruction
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_SUCCESS = 'success';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    /** @var list<string> */
    public const ALLOWED_LEVELS = [
        self::LEVEL_INFO,
        self::LEVEL_SUCCESS,
        self::LEVEL_WARNING,
        self::LEVEL_ERROR,
    ];

    public function __construct(
        public string $message,
        public string $level = self::LEVEL_INFO,
        public ?string $title = null,
    ) {
        if (!in_array($level, self::ALLOWED_LEVELS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'UiEventNotificationInstruction level "%s" is not allowed; expected one of: %s.',
                $level,
                implode(', ', self::ALLOWED_LEVELS),
            ));
        }
    }
}
