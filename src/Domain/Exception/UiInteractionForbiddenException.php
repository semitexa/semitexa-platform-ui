<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

final class UiInteractionForbiddenException extends UiInteractionException
{
    public function __construct(string $reason, string $message)
    {
        parent::__construct(403, $reason, $message);
    }
}
