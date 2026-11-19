<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

final class UiInteractionUnprocessableException extends UiInteractionException
{
    /**
     * @param array<string, mixed> $details the handler's {@see \Semitexa\PlatformUi\Domain\Model\Event\UiEventError::$details}:
     *        a small bag the client renders or acts on (which field, who holds
     *        the lock, the version to retry on). Sent to the browser as given.
     */
    public function __construct(string $reason, string $message, public readonly array $details = [])
    {
        parent::__construct(422, $reason, $message);
    }
}
