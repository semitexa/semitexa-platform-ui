<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

/**
 * Raised when the diagnostic listing receives a cursor it cannot
 * safely decode — malformed base64, bad JSON, missing fields,
 * unsafe id shape, etc.
 *
 * The handler catches this and renders a safe HTTP 400 state. The
 * `reasonCode` is a short stable token; the public message is
 * fixed. Neither field ever echoes the bad cursor back — leaking
 * the cursor would just give an attacker a free crash signal on
 * their next attempt and tells operators nothing they could not
 * see in the access log.
 */
final class UiFormDemoSubmissionCursorException extends \RuntimeException
{
    public function __construct(
        string $message = 'Pagination cursor is invalid.',
        public readonly string $reasonCode = 'invalid_cursor',
    ) {
        parent::__construct($message);
    }
}
