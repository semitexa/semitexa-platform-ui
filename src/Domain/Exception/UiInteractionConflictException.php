<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

final class UiInteractionConflictException extends UiInteractionException
{
    public function __construct(string $reason, string $message)
    {
        parent::__construct(409, $reason, $message);
    }
}
