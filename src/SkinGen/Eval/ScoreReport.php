<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\SkinGen\Eval;

final readonly class ScoreReport
{
    /** @param list<array{label: string, pass: bool, detail: string}> $checks */
    public function __construct(
        public int $passed,
        public int $total,
        public array $checks,
    ) {
    }

    public function hit(): bool
    {
        return $this->total > 0 && $this->passed === $this->total;
    }

    public function ratio(): float
    {
        return $this->total === 0 ? 0.0 : $this->passed / $this->total;
    }
}
