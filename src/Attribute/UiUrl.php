<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/**
 * Binds a component prop to the address bar.
 *
 *     #[AsComponent(name: 'blog.search', template: '…')]
 *     #[UiUrl(prop: 'query', as: 'q')]
 *     #[UiUrl(prop: 'page', type: 'int', min: 1, except: 1, history: 'push')]
 *     final class SearchComponent { … }
 *
 * On a page load the value comes from the query string — attacker input, so it
 * is validated (type, allow-list, length, range) and an invalid value leaves the
 * prop as the caller rendered it. After an interaction re-renders the component
 * with a different value, the address bar follows: `replace` rewrites the
 * current history entry, `push` adds one (Back then returns to the old value).
 * A value equal to `except` (or null) is left out of the URL.
 */
#[Capability(
    id: 'ui.url-state',
    summary: 'Keeps a component prop in the query string: restored and validated on page load, written back (push or replace) when an interaction changes it.',
    useWhen: 'A search term, filter, tab or page number should survive a reload and be shareable as a link.',
    avoidWhen: 'The value is private or large - URL state is visible, logged and limited in length.',
    replaces: [
        'reading $_GET in a page handler and threading it into component props by hand',
        'history.pushState calls in page JavaScript',
    ],
    seeAlso: 'ui.event-intent',
)]
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class UiUrl
{
    public const HISTORY_REPLACE = 'replace';
    public const HISTORY_PUSH = 'push';
    public const TYPES = ['string', 'int', 'bool'];

    /**
     * @param list<string>|null $values allow-list (string props)
     */
    public function __construct(
        public string $prop,
        public ?string $as = null,
        public string $history = self::HISTORY_REPLACE,
        public string $type = 'string',
        public ?array $values = null,
        public int $maxLength = 200,
        public ?int $min = null,
        public ?int $max = null,
        public string|int|bool|null $except = null,
    ) {}

    /** The query-string key. */
    public function key(): string
    {
        return $this->as ?? $this->prop;
    }
}
