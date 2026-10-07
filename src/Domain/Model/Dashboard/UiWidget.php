<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Dashboard;

/**
 * What a dashboard widget shows, in one of three shapes:
 *
 *     UiWidget::stat('Products', '128', delta: '+12%', trend: 'up', series: ['Oct 5' => 3, 'Oct 6' => 5])
 *         ->withLink('/admin/products', 'All products')
 *     UiWidget::list('Recent products', [['title' => 'Mug', 'href' => '/…', 'meta' => '2h ago']])
 *     UiWidget::chart('Products by status', 'bars', [['label' => 'Draft', 'value' => 4], …])
 *
 * The dashboard renders each with the platform's own components (platform.stat,
 * platform.list, platform.chart) inside a card.
 */
final readonly class UiWidget
{
    /** @param array<string, mixed> $props */
    private function __construct(
        public string $kind,
        public string $title,
        public array $props,
        public ?string $href = null,
        public ?string $linkText = null,
    ) {}

    /** Where the widget leads, and what the link says ("All products"). */
    public function withLink(string $href, string $text): self
    {
        return new self($this->kind, $this->title, $this->props, $href, $text);
    }

    /**
     * @param array<int|string, int|float> $series a sparkline under the value, oldest first;
     *        keyed by label ("Oct 6") so a screen reader can read each point
     */
    public static function stat(string $label, string $value, ?string $delta = null, string $trend = 'flat', array $series = [], ?string $caption = null): self
    {
        if (!in_array($trend, ['up', 'down', 'flat'], true)) {
            throw new \InvalidArgumentException(sprintf('A stat trend is up, down or flat, not "%s".', $trend));
        }

        return new self('stat', $label, [
            'label' => $label,
            'value' => $value,
            'delta' => $delta,
            'trend' => $trend,
            'caption' => $caption,
            'points' => array_map(
                static fn (int|float $v, int|string $key): array => ['label' => is_string($key) ? $key : (string) ($key + 1), 'value' => $v],
                array_values($series),
                array_keys($series),
            ),
        ]);
    }

    /** @param list<array{title: string, href?: string, meta?: string, description?: string}> $items */
    public static function list(string $title, array $items, string $empty = 'Nothing yet.'): self
    {
        return new self('list', $title, ['items' => $items, 'empty' => $empty]);
    }

    /** @param list<array{label: string, value: int|float}> $points */
    public static function chart(string $title, string $kind, array $points): self
    {
        if (!in_array($kind, ['line', 'bars'], true)) {
            throw new \InvalidArgumentException(sprintf('A chart is line or bars, not "%s".', $kind));
        }

        return new self('chart', $title, ['kind' => $kind, 'points' => $points]);
    }
}
