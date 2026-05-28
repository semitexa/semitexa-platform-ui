<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Emitter;

use Semitexa\PlatformUi\Domain\Contract\SliceEmitterInterface;
use Semitexa\PlatformUi\Application\Service\Css\Slice\Slice;

final class ToneEmitter implements SliceEmitterInterface
{
    private const TOKEN_MAP = [
        'neutral' => 'var(--ui-text-muted)',
        'brand' => 'var(--ui-accent-brand)',
        'success' => 'var(--ui-state-success)',
        'warning' => 'var(--ui-state-warning)',
        'danger' => 'var(--ui-state-danger)',
    ];

    public function attribute(): string
    {
        return 'sx-tone';
    }

    public function allowedValues(): array
    {
        return array_keys(self::TOKEN_MAP);
    }

    public function emit(string $value): Slice
    {
        if (!isset(self::TOKEN_MAP[$value])) {
            throw new \OutOfBoundsException("Invalid sx-tone value: {$value}");
        }

        return new Slice(
            "sx-tone:{$value}",
            "[sx-tone=\"{$value}\"] { color: " . self::TOKEN_MAP[$value] . "; }",
        );
    }
}
