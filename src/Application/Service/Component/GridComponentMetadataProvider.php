<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Component;

use ReflectionClass;
use Semitexa\PlatformUi\Attribute\AsColumn;
use Semitexa\PlatformUi\Attribute\AsFilter;
use Semitexa\PlatformUi\Attribute\GridFeed;
use Semitexa\PlatformUi\Attribute\WithPagination;
use Semitexa\Ssr\Attribute\AsComponentMetadataProvider;
use Semitexa\Ssr\Domain\Contract\ComponentMetadataProviderInterface;

/**
 * Contributes grid-shaped props (`columns`, `filters`, `pagination`)
 * to any component class that uses #[AsColumn] / #[AsFilter] /
 * #[WithPagination] attributes.
 *
 * Discovered and invoked at boot by the SSR
 * ComponentMetadataProviderRegistry — must stay stateless and
 * side-effect-free.
 */
#[AsComponentMetadataProvider(priority: 0)]
final class GridComponentMetadataProvider implements ComponentMetadataProviderInterface
{
    /** @inheritDoc */
    public function supports(ReflectionClass $componentClass): bool
    {
        foreach ($componentClass->getProperties() as $prop) {
            if ($prop->getAttributes(AsColumn::class) !== []) {
                return true;
            }
        }
        return $componentClass->getAttributes(WithPagination::class) !== [];
    }

    /** @inheritDoc */
    public function getProps(ReflectionClass $componentClass): array
    {
        $gridFeed = $this->resolveGridFeed($componentClass);
        $pagination = $this->resolvePagination($componentClass);

        // Cross-attribute structural invariant for the live-on-events mode:
        // a non-empty #[GridFeed(liveOn:)] is only valid on a windowed + SSE
        // grid. Enforced here (where both the feed and the pagination are
        // resolved, and the class name is available) — boot-fail, not a
        // silent runtime no-op.
        $this->assertLiveOnConstraints($componentClass, $gridFeed, $pagination);

        return [
            'columns' => $this->resolveColumns($componentClass),
            'filters' => $this->resolveFilters($componentClass),
            'pagination' => $pagination,
            'gridFeed' => $gridFeed,
            'defaultSort' => $this->resolveDefaultSort($componentClass),
        ];
    }

    /**
     * LIVE-ON-EVENTS structural invariant (v1). A grid may declare a non-empty
     * `#[GridFeed(liveOn: [...])]` ONLY when it can actually carry a live
     * re-run subscription. Two declaration-time conditions make a live
     * subscription IMPOSSIBLE, so the invalid config boot-fails HERE (naming
     * the grid) rather than silently never firing at runtime — the invalid
     * config is impossible by construction:
     *
     *   1. WINDOWED-ONLY. Live re-run is proven only for WINDOWED/offset
     *      pagination (leads, declared `mode: auto`). A CURSOR/keyset window
     *      shifts on insert, so "re-run the held view" is ambiguous — cursor
     *      grids are out of scope for `liveOn` v1 (grid-live-on-events-design
     *      §7). The keyset signal is `WithPagination::mode === 'cursor'`, which
     *      is ALSO the no-`#[WithPagination]` default ({@see resolvePagination}):
     *      a live grid must declare an offset/count/auto pagination mode.
     *   2. SSE TRANSPORT. `liveOn` pushes a re-run onto a held-open
     *      `EventSource`; a {@see GridFeed::MODE_PLAIN} pull feed never holds a
     *      stream, so a live subscription could never fire — `liveOn` on a
     *      plain feed is invalid.
     *
     * This phase only makes the invalid config impossible; the subscribe /
     * publish wiring lands in later phases.
     *
     * @param ReflectionClass<object> $class
     * @param array{route: string, provider: ?string, mutations: list<array{label: string, route: string, method: string}>, mode: string, liveOn: list<string>}|null $gridFeed
     * @param array{defaultLimit: int, limitOptions: list<int>, mode: string, windowSize: int, autoCountThreshold: int} $pagination
     */
    private function assertLiveOnConstraints(ReflectionClass $class, ?array $gridFeed, array $pagination): void
    {
        if ($gridFeed === null || $gridFeed['liveOn'] === []) {
            // Default OFF — no live scopes → static grid (today's behaviour).
            return;
        }

        if ($pagination['mode'] === WithPagination::MODE_CURSOR) {
            throw new \InvalidArgumentException(sprintf(
                'Grid %s declares #[GridFeed(liveOn: [%s])] but is cursor/keyset-'
                . 'paginated (WithPagination mode "%s"). Live re-run is WINDOWED-'
                . 'only in v1: a cursor window shifts on insert, so a live grid '
                . 'must declare an offset/count/auto pagination mode.',
                $class->getName(),
                implode(', ', $gridFeed['liveOn']),
                $pagination['mode'],
            ));
        }

        if ($gridFeed['mode'] === GridFeed::MODE_PLAIN) {
            throw new \InvalidArgumentException(sprintf(
                'Grid %s declares #[GridFeed(liveOn: [%s])] with feed mode "%s". '
                . 'liveOn pushes a live re-run onto a held-open SSE stream; a '
                . 'plain pull feed never holds one, so a live grid must declare '
                . 'GridFeed mode "%s".',
                $class->getName(),
                implode(', ', $gridFeed['liveOn']),
                GridFeed::MODE_PLAIN,
                GridFeed::MODE_SSE,
            ));
        }
    }

    /**
     * Resolve the grid's DECLARED default sort token from the single
     * `#[AsColumn(defaultSort: 'asc'|'desc')]` column, if any. Returns the
     * `${propertyName}_${direction}` token (e.g. `submittedAt_desc`) — the
     * exact shape the shell's `${key}_asc`/`${key}_desc` sort-toggle
     * convention already uses — so the declared default seeds the initial
     * `sort` with no template prop. Null when no column declares a default
     * sort. Throws when more than one does: a grid has exactly one default
     * sort (mirrors the cross-field validation in resolvePagination()).
     *
     * @param ReflectionClass<object> $class
     */
    private function resolveDefaultSort(ReflectionClass $class): ?string
    {
        $token = null;
        foreach ($class->getProperties() as $prop) {
            $attrs = $prop->getAttributes(AsColumn::class);
            if ($attrs === []) {
                continue;
            }
            /** @var AsColumn $attr */
            $attr = $attrs[0]->newInstance();
            if ($attr->defaultSort === null) {
                continue;
            }
            if ($token !== null) {
                throw new \InvalidArgumentException(sprintf(
                    'Grid %s declares more than one #[AsColumn(defaultSort:)]; '
                    . 'a grid has exactly one default sort.',
                    $class->getName(),
                ));
            }
            $token = $prop->getName() . '_' . $attr->defaultSort;
        }
        return $token;
    }

    /**
     * Reflect the optional `#[GridFeed]` declaration. A grid that declares a
     * feed is driven by the shared held-open SSE runtime (`grid-runtime.js`
     * feed mode) instead of the canonical `/__ui/dispatch` + KISS model; the
     * returned shape is baked into the `platform.grid` JSON bundle so the
     * runtime can discover the feed route + declared mutations with no
     * grid-specific literals. Returns null when the class declares no feed
     * (the default — inventory / submissions keep the dispatch model).
     *
     * @param ReflectionClass<object> $class
     * @return array{route: string, provider: ?string, mutations: list<array{label: string, route: string, method: string}>, mode: string, liveOn: list<string>}|null
     */
    private function resolveGridFeed(ReflectionClass $class): ?array
    {
        $attrs = $class->getAttributes(GridFeed::class);
        if ($attrs === []) {
            return null;
        }
        /** @var GridFeed $attr */
        $attr = $attrs[0]->newInstance();

        $mutations = [];
        foreach ($attr->mutations as $mutation) {
            $label = $mutation['label'] ?? null;
            $route = $mutation['route'] ?? null;
            if (!is_string($label) || $label === '' || !is_string($route) || $route === '') {
                continue;
            }
            $method = $mutation['method'] ?? 'POST';
            $mutations[] = [
                'label' => $label,
                'route' => $route,
                'method' => is_string($method) && $method !== '' ? strtoupper($method) : 'POST',
            ];
        }

        return [
            'route' => $attr->route,
            'provider' => $attr->provider,
            'mutations' => $mutations,
            'mode' => $attr->mode,
            // The declared live-on-events scope keys (Phase 1: declaration +
            // metadata only). Phase 2 sources AbstractGridStreamFeedHandler's
            // SubscriptionRecord::$scopeKeys from this list (replacing the
            // hardcoded gridStreamWatchedScopeKey()); default [] stays static.
            'liveOn' => $attr->liveOn,
        ];
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<array{key: string, label: string, sortable: bool, type: string, badge?: array<string, string>, href?: string}>
     */
    private function resolveColumns(ReflectionClass $class): array
    {
        $columns = [];
        foreach ($class->getProperties() as $prop) {
            $attrs = $prop->getAttributes(AsColumn::class);
            if ($attrs === []) {
                continue;
            }
            /** @var AsColumn $attr */
            $attr = $attrs[0]->newInstance();
            $column = [
                'key' => $prop->getName(),
                'label' => $attr->label,
                'sortable' => $attr->sortable,
                'type' => $attr->type,
            ];
            // Rich-cell config is ADDED ONLY when declared, so plain
            // (text/mono/date/number) columns keep the exact 4-key shape they
            // had before this enhancement — their bundle/providerProps payload
            // stays byte-identical (the non-regression guarantee). The
            // attribute constructor already pinned the invariants
            // (badge ⇒ type badge, href ⇒ type link), so no re-validation here.
            if ($attr->badge !== null) {
                $column['badge'] = $attr->badge;
            }
            if ($attr->href !== null) {
                $column['href'] = $attr->href;
            }
            $columns[] = $column;
        }
        return $columns;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<array{field: string, type: string, placeholder: string, label: string}>
     */
    private function resolveFilters(ReflectionClass $class): array
    {
        $filters = [];
        foreach ($class->getProperties() as $prop) {
            $attrs = $prop->getAttributes(AsFilter::class);
            if ($attrs === []) {
                continue;
            }
            /** @var AsFilter $attr */
            $attr = $attrs[0]->newInstance();
            $filters[] = [
                'field' => $prop->getName(),
                'type' => $attr->type,
                'placeholder' => $attr->placeholder,
                'label' => $attr->label,
            ];
        }
        return $filters;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return array{
     *     defaultLimit: int, limitOptions: list<int>, mode: string,
     *     windowSize: int, autoCountThreshold: int
     * }
     */
    private function resolvePagination(ReflectionClass $class): array
    {
        $attrs = $class->getAttributes(WithPagination::class);
        if ($attrs === []) {
            // No attribute → cursor-mode defaults, matching the prior
            // implicit behaviour for grids that only declare columns.
            return [
                'defaultLimit'       => 25,
                'limitOptions'       => [10, 25, 50],
                'mode'               => WithPagination::MODE_CURSOR,
                'windowSize'         => 5,
                'autoCountThreshold' => 1000,
            ];
        }
        /** @var WithPagination $attr */
        $attr = $attrs[0]->newInstance();
        if (!in_array($attr->defaultLimit, $attr->limitOptions, true)) {
            throw new \InvalidArgumentException(sprintf(
                'WithPagination on %s: defaultLimit %d must be in limitOptions [%s].',
                $class->getName(),
                $attr->defaultLimit,
                implode(', ', $attr->limitOptions),
            ));
        }
        // Single-field invariants (mode enum, odd windowSize >= 3,
        // non-negative threshold) are enforced in the attribute's
        // constructor; they have already fired by the time newInstance()
        // returns above.
        return [
            'defaultLimit'       => $attr->defaultLimit,
            'limitOptions'       => $attr->limitOptions,
            'mode'               => $attr->mode,
            'windowSize'         => $attr->windowSize,
            'autoCountThreshold' => $attr->autoCountThreshold,
        ];
    }
}
