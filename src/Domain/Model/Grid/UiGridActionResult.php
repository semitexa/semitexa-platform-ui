<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Grid;

/**
 * What a grid action answers: a message for the visitor (a toast), whether it
 * succeeded, and how many rows it touched. The grid clears its selection on
 * success; the rows themselves refresh live from the write.
 */
final readonly class UiGridActionResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        public int $affected = 0,
    ) {}

    public static function done(string $message, int $affected = 0): self
    {
        return new self(true, $message, $affected);
    }

    public static function refused(string $message): self
    {
        return new self(false, $message);
    }
}
