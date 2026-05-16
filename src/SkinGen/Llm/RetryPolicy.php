<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\SkinGen\Llm;

final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
    ) {
    }
}
