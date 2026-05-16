<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

final class UiInteractionBadRequestException extends UiInteractionException
{
    public function __construct(string $reason, string $message)
    {
        parent::__construct(400, $reason, $message);
    }
}
