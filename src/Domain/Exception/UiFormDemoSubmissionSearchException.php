<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

/**
 * Typed failure for the diagnostic listing's q / action input
 * surface. Mirrors {@see UiFormDemoSubmissionCursorException} —
 * fixed safe message + a stable `reasonCode` the handler can map
 * to a safe HTTP-400 template branch.
 *
 * Reason codes:
 *   - `invalid_search_query`  : q exceeds the bounded length, or
 *                               other shape rejection in the future;
 *   - `invalid_action_filter` : action is not in the allow-list;
 *   - `invalid_sort`          : sort token is not in the allow-list.
 *
 * The message is intentionally a stable, operator-safe constant —
 * it never echoes the bad value back to the page, never names the
 * env / class / column / SQL fragment, and never grows a third
 * "reason" string for the operator to interpret.
 */
final class UiFormDemoSubmissionSearchException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode,
    ) {
        parent::__construct($message);
    }

    public static function invalidQuery(): self
    {
        return new self(
            message:    'Search query is invalid.',
            reasonCode: 'invalid_search_query',
        );
    }

    public static function invalidAction(): self
    {
        return new self(
            message:    'Action filter is invalid.',
            reasonCode: 'invalid_action_filter',
        );
    }

    public static function invalidSort(): self
    {
        return new self(
            message:    'Sort option is invalid.',
            reasonCode: 'invalid_sort',
        );
    }
}
