<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Palette;

/**
 * One result of a command source: something the visitor can go to.
 * `href` is a same-origin path; `permission`, when set, hides the command from
 * a visitor without it.
 */
final readonly class UiPaletteItem
{
    /** @param list<string> $keywords */
    public function __construct(
        public string $title,
        public string $href,
        public string $group = '',
        public string $subtitle = '',
        public ?string $icon = null,
        public ?string $permission = null,
        public array $keywords = [],
    ) {
        // Control characters too: a browser drops a tab or newline inside a
        // URL, so "/\t/evil.test" would become the protocol-relative "//evil.test".
        if (preg_match('#\A/(?![/\\\\])#', $href) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            throw new \InvalidArgumentException(sprintf('A command goes to a same-origin path, not "%s".', $href));
        }
    }
}
