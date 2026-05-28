<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\SkinGen\Llm;

final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
    ) {
    }
}
