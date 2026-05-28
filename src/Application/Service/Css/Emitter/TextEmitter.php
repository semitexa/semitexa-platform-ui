<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Emitter;

use Semitexa\PlatformUi\Contract\SliceEmitter;
use Semitexa\PlatformUi\Application\Service\Css\Slice\Slice;

final class TextEmitter implements SliceEmitter
{
    private const VALUES = ['body', 'muted', 'title', 'label'];

    public function attribute(): string
    {
        return 'ui-text';
    }

    public function allowedValues(): array
    {
        return self::VALUES;
    }

    public function emit(string $value): Slice
    {
        $css = match ($value) {
            'body' => "[ui-text=\"body\"] { font-size: 0.9375rem; line-height: 1.5; color: var(--ui-text-primary); }",
            'muted' => "[ui-text=\"muted\"] { font-size: 0.9375rem; line-height: 1.5; color: var(--ui-text-muted); }",
            'title' => "[ui-text=\"title\"] { font-size: 1.25rem; line-height: 1.3; font-weight: 600; color: var(--ui-text-primary); letter-spacing: -0.01em; }",
            'label' => "[ui-text=\"label\"] { font-size: 0.8125rem; line-height: 1.4; font-weight: 500; color: var(--ui-text-primary); }",
            default => throw new \OutOfBoundsException("Invalid ui-text value: {$value}"),
        };

        return new Slice("ui-text:{$value}", $css);
    }
}
