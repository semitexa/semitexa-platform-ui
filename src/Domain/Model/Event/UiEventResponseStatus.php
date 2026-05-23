<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Top-level status carried by a {@see UiEventResponse} (technical-design.md
 * §12.8). Distinguishes success, validation-failed (still a 2xx but with a
 * structured field-error map), and error responses.
 */
enum UiEventResponseStatus: string
{
    case Ok = 'ok';
    case Validation = 'validation';
    case Error = 'error';
}
