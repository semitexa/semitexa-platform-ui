<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

/**
 * Raised when a FormComponent submit action cannot be resolved at
 * either render time (unknown / unsafe `submitAction` prop) or
 * dispatch time (a signed `cfg.a` whose name does not resolve in the
 * active registry — typically a registry change between render and
 * dispatch).
 *
 * The handler maps this to a safe response without leaking class
 * FQCNs or any implementation detail.
 */
final class UiFormSubmitActionException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $actionName = '')
    {
        parent::__construct($message);
    }
}
