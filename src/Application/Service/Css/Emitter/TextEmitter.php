<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Emitter;

use Semitexa\PlatformUi\Domain\Contract\SliceEmitterInterface;
use Semitexa\PlatformUi\Application\Service\Css\Slice\Slice;

final class TextEmitter implements SliceEmitterInterface
{
    private const VALUES = ['display', 'heading', 'title', 'lead', 'body', 'muted', 'label', 'caption', 'overline'];

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
            'display' => "[ui-text=\"display\"] { font-size: clamp(var(--ui-text-4xl), 5vw, var(--ui-text-6xl)); line-height: var(--ui-text-6xl-leading); font-weight: var(--ui-font-weight-bold); letter-spacing: var(--ui-tracking-tighter); color: var(--ui-text-primary); text-wrap: balance; }",
            'heading' => "[ui-text=\"heading\"] { font-size: var(--ui-text-2xl); line-height: var(--ui-text-2xl-leading); font-weight: var(--ui-font-weight-semibold); letter-spacing: var(--ui-tracking-tight); color: var(--ui-text-primary); text-wrap: balance; }",
            'title' => "[ui-text=\"title\"] { font-size: var(--ui-text-xl); line-height: 1.3; font-weight: var(--ui-font-weight-semibold); color: var(--ui-text-primary); letter-spacing: var(--ui-tracking-tight); }",
            'lead' => "[ui-text=\"lead\"] { font-size: var(--ui-text-lg); line-height: var(--ui-text-lg-leading); color: var(--ui-text-muted); text-wrap: pretty; }",
            'caption' => "[ui-text=\"caption\"] { font-size: var(--ui-text-xs); line-height: var(--ui-text-xs-leading); color: var(--ui-text-muted); }",
            'overline' => "[ui-text=\"overline\"] { font-size: var(--ui-text-xs); line-height: var(--ui-text-xs-leading); font-weight: var(--ui-font-weight-semibold); letter-spacing: var(--ui-tracking-wide); text-transform: uppercase; color: var(--ui-text-muted); }",
            'label' => "[ui-text=\"label\"] { font-size: 0.8125rem; line-height: 1.4; font-weight: 500; color: var(--ui-text-primary); }",
            default => throw new \OutOfBoundsException("Invalid ui-text value: {$value}"),
        };

        return new Slice("ui-text:{$value}", $css);
    }
}
