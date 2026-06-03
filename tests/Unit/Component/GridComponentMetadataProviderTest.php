<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\PlatformUi\Application\Service\Component\GridComponentMetadataProvider;
use Semitexa\PlatformUi\Attribute\AsColumn;
use Semitexa\PlatformUi\Attribute\AsFilter;
use Semitexa\PlatformUi\Attribute\GridFeed;
use Semitexa\PlatformUi\Attribute\WithPagination;

final class GridComponentMetadataProviderTest extends TestCase
{
    #[Test]
    public function supports_returns_true_when_property_has_as_column(): void
    {
        $provider = new GridComponentMetadataProvider();

        self::assertTrue($provider->supports(new ReflectionClass(GridWithColumnsFixture::class)));
    }

    #[Test]
    public function supports_returns_true_when_class_has_with_pagination(): void
    {
        $provider = new GridComponentMetadataProvider();

        self::assertTrue($provider->supports(new ReflectionClass(GridWithPaginationOnlyFixture::class)));
    }

    #[Test]
    public function supports_returns_false_for_plain_class(): void
    {
        $provider = new GridComponentMetadataProvider();

        self::assertFalse($provider->supports(new ReflectionClass(PlainComponentFixture::class)));
    }

    #[Test]
    public function resolves_columns_in_declaration_order(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithColumnsFixture::class));

        self::assertSame(
            [
                ['key' => 'submittedAt', 'label' => 'Submitted', 'sortable' => true, 'type' => 'datetime'],
                ['key' => 'email', 'label' => 'Email', 'sortable' => false, 'type' => 'text'],
            ],
            $props['columns'],
        );
    }

    #[Test]
    public function resolves_badge_and_link_config_only_for_rich_columns(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithRichColumnsFixture::class));

        // The badge column carries its variant map; the link column carries its
        // href template; the plain column keeps the EXACT 4-key shape (no
        // badge/href keys) — the additive non-regression guarantee.
        self::assertSame(
            [
                ['key' => 'title', 'label' => 'Title', 'sortable' => false, 'type' => 'link', 'href' => '/articles/{id}'],
                ['key' => 'status', 'label' => 'Status', 'sortable' => false, 'type' => 'badge', 'badge' => ['draft' => 'mute', 'published' => 'ok']],
                ['key' => 'slug', 'label' => 'Slug', 'sortable' => false, 'type' => 'mono'],
            ],
            $props['columns'],
        );
    }

    #[Test]
    public function badge_map_is_rejected_on_a_non_badge_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/badge map is only valid on a type: "badge"/');

        /** @phpstan-ignore-next-line — exercising the ctor guard */
        new AsColumn(label: 'X', type: 'text', badge: ['a' => 'ok']);
    }

    #[Test]
    public function badge_type_requires_a_non_empty_map(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type "badge" requires a non-empty/');

        new AsColumn(label: 'X', type: 'badge');
    }

    #[Test]
    public function href_is_rejected_on_a_non_link_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/href is only valid on a type: "link"/');

        /** @phpstan-ignore-next-line — exercising the ctor guard */
        new AsColumn(label: 'X', type: 'text', href: '/x/{id}');
    }

    #[Test]
    public function link_type_requires_an_href(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type "link" requires an href template/');

        new AsColumn(label: 'X', type: 'link');
    }

    #[Test]
    public function link_href_must_be_site_relative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/href must be a site-relative path/');

        // Protocol-relative // is rejected (so are absolute URLs / schemes).
        new AsColumn(label: 'X', type: 'link', href: '//evil.example/{id}');
    }

    #[Test]
    public function link_href_rejects_javascript_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/href must be a site-relative path/');

        new AsColumn(label: 'X', type: 'link', href: 'javascript:alert(1)');
    }

    #[Test]
    public function resolves_filters_in_declaration_order(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithFiltersFixture::class));

        self::assertSame(
            [
                ['field' => 'q', 'type' => 'search', 'placeholder' => 'Search…', 'label' => 'Search'],
                ['field' => 'action', 'type' => 'select', 'placeholder' => '', 'label' => 'Action'],
            ],
            $props['filters'],
        );
    }

    #[Test]
    public function pagination_falls_back_to_defaults_when_attribute_absent(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithColumnsFixture::class));

        self::assertSame(
            [
                'defaultLimit'       => 25,
                'limitOptions'       => [10, 25, 50],
                'mode'               => 'cursor',
                'windowSize'         => 5,
                'autoCountThreshold' => 1000,
            ],
            $props['pagination'],
        );
    }

    #[Test]
    public function pagination_reads_attribute_when_present(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithPaginationOnlyFixture::class));

        // No mode declared → cursor defaults preserved (backward compat).
        self::assertSame(
            [
                'defaultLimit'       => 50,
                'limitOptions'       => [25, 50, 100],
                'mode'               => 'cursor',
                'windowSize'         => 5,
                'autoCountThreshold' => 1000,
            ],
            $props['pagination'],
        );
    }

    #[Test]
    public function pagination_surfaces_mode_window_size_and_threshold(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithAutoPaginationFixture::class));

        self::assertSame(
            [
                'defaultLimit'       => 25,
                'limitOptions'       => [10, 25, 50],
                'mode'               => 'auto',
                'windowSize'         => 7,
                'autoCountThreshold' => 500,
            ],
            $props['pagination'],
        );
    }

    #[Test]
    public function pagination_throws_when_default_limit_is_not_in_limit_options(): void
    {
        $provider = new GridComponentMetadataProvider();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/defaultLimit 30 must be in limitOptions/');

        $provider->getProps(new ReflectionClass(BadPaginationFixture::class));
    }

    #[Test]
    public function full_grid_returns_merged_columns_filters_and_pagination(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(FullGridFixture::class));

        self::assertSame(
            [
                ['key' => 'submittedAt', 'label' => 'Submitted', 'sortable' => true, 'type' => 'datetime'],
                ['key' => 'email', 'label' => 'Email', 'sortable' => false, 'type' => 'text'],
            ],
            $props['columns'],
        );
        self::assertSame(
            [
                ['field' => 'q', 'type' => 'search', 'placeholder' => 'Search…', 'label' => ''],
            ],
            $props['filters'],
        );
        self::assertSame(
            [
                'defaultLimit'       => 25,
                'limitOptions'       => [10, 25, 50, 100],
                'mode'               => 'cursor',
                'windowSize'         => 5,
                'autoCountThreshold' => 1000,
            ],
            $props['pagination'],
        );
    }

    #[Test]
    public function default_sort_is_null_when_no_column_declares_it(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithColumnsFixture::class));

        self::assertArrayHasKey('defaultSort', $props);
        self::assertNull($props['defaultSort']);
    }

    #[Test]
    public function default_sort_resolves_declared_column_token(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridWithDefaultSortFixture::class));

        self::assertSame('submittedAt_desc', $props['defaultSort']);
    }

    #[Test]
    public function default_sort_throws_when_more_than_one_column_declares_it(): void
    {
        $provider = new GridComponentMetadataProvider();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('more than one #[AsColumn(defaultSort:)]');

        $provider->getProps(new ReflectionClass(GridWithTwoDefaultSortsFixture::class));
    }

    #[Test]
    public function as_column_rejects_invalid_default_sort_direction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('defaultSort must be "asc" or "desc"');

        new AsColumn(label: 'Submitted', sortable: true, defaultSort: 'descending');
    }

    #[Test]
    public function as_column_rejects_default_sort_on_non_sortable_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('defaultSort requires sortable: true');

        new AsColumn(label: 'Submitted', defaultSort: 'desc');
    }

    // ---- live-on-events: liveOn declaration + metadata thread (C1/C2) -------

    #[Test]
    public function grid_feed_emits_empty_live_on_by_default(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridFeedNoLiveOnFixture::class));

        self::assertSame([], $props['gridFeed']['liveOn']);
    }

    #[Test]
    public function grid_feed_threads_declared_live_on_scopes_into_metadata(): void
    {
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridLiveOnWindowedFixture::class));

        self::assertSame(
            [
                'route' => '/grid/feed',
                'provider' => null,
                'mutations' => [],
                'mode' => 'sse',
                'liveOn' => ['ui_playground_leads', 'ui_playground_audit'],
            ],
            $props['gridFeed'],
        );
    }

    #[Test]
    public function grid_feed_accepts_live_on_on_an_auto_paginated_sse_grid(): void
    {
        // Leads' shape: auto pagination + SSE feed. Auto is windowed-capable,
        // so a declared liveOn must NOT boot-fail.
        $provider = new GridComponentMetadataProvider();

        $props = $provider->getProps(new ReflectionClass(GridLiveOnAutoFixture::class));

        self::assertSame(['ui_playground_leads'], $props['gridFeed']['liveOn']);
        self::assertSame('auto', $props['pagination']['mode']);
    }

    // ---- live-on-events: structural boot-fail guards (C3) -------------------

    #[Test]
    public function live_on_boot_fails_on_a_cursor_grid(): void
    {
        $provider = new GridComponentMetadataProvider();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cursor\/keyset-paginated.*WINDOWED-only/s');

        $provider->getProps(new ReflectionClass(GridLiveOnCursorFixture::class));
    }

    #[Test]
    public function live_on_boot_fails_when_pagination_attribute_is_absent(): void
    {
        // No #[WithPagination] resolves to the cursor default → also invalid.
        $provider = new GridComponentMetadataProvider();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cursor\/keyset-paginated/');

        $provider->getProps(new ReflectionClass(GridLiveOnNoPaginationFixture::class));
    }

    #[Test]
    public function live_on_boot_fails_on_a_plain_feed(): void
    {
        $provider = new GridComponentMetadataProvider();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/plain pull feed never holds/');

        $provider->getProps(new ReflectionClass(GridLiveOnPlainFixture::class));
    }

    #[Test]
    public function grid_feed_rejects_a_non_string_live_on_entry_at_declaration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('liveOn entries must be non-empty scope-key strings');

        /** @phpstan-ignore-next-line — exercising the ctor guard on a bad shape */
        new GridFeed(route: '/grid/feed', liveOn: ['ok', '']);
    }
}

final class PlainComponentFixture
{
    public string $title = '';
}

final class GridWithColumnsFixture
{
    #[AsColumn(label: 'Submitted', sortable: true, type: 'datetime')]
    public string $submittedAt = '';

    #[AsColumn(label: 'Email')]
    public string $email = '';
}

final class GridWithRichColumnsFixture
{
    #[AsColumn(label: 'Title', type: 'link', href: '/articles/{id}')]
    public string $title = '';

    #[AsColumn(label: 'Status', type: 'badge', badge: ['draft' => 'mute', 'published' => 'ok'])]
    public string $status = '';

    #[AsColumn(label: 'Slug', type: 'mono')]
    public string $slug = '';
}

final class GridWithFiltersFixture
{
    #[AsFilter(type: 'search', placeholder: 'Search…', label: 'Search')]
    public string $q = '';

    #[AsFilter(type: 'select', label: 'Action')]
    public string $action = '';
}

#[WithPagination(defaultLimit: 50, limitOptions: [25, 50, 100])]
final class GridWithPaginationOnlyFixture
{
}

#[WithPagination(defaultLimit: 25, limitOptions: [10, 25, 50], mode: 'auto', windowSize: 7, autoCountThreshold: 500)]
final class GridWithAutoPaginationFixture
{
}

#[WithPagination(defaultLimit: 30, limitOptions: [10, 25, 50])]
final class BadPaginationFixture
{
}

#[WithPagination]
final class FullGridFixture
{
    #[AsColumn(label: 'Submitted', sortable: true, type: 'datetime')]
    public string $submittedAt = '';

    #[AsColumn(label: 'Email')]
    public string $email = '';

    #[AsFilter(type: 'search', placeholder: 'Search…')]
    public string $q = '';
}

final class GridWithDefaultSortFixture
{
    #[AsColumn(label: 'Submitted', sortable: true, type: 'datetime', defaultSort: 'desc')]
    public string $submittedAt = '';

    #[AsColumn(label: 'Email')]
    public string $email = '';
}

final class GridWithTwoDefaultSortsFixture
{
    #[AsColumn(label: 'Submitted', sortable: true, type: 'datetime', defaultSort: 'desc')]
    public string $submittedAt = '';

    #[AsColumn(label: 'Email', sortable: true, defaultSort: 'asc')]
    public string $email = '';
}

// ---- live-on-events fixtures -----------------------------------------------

/** SSE feed with NO liveOn → emits liveOn: [] (default OFF), even on cursor. */
#[WithPagination(defaultLimit: 25, limitOptions: [10, 25, 50])]
#[GridFeed(route: '/grid/feed')]
final class GridFeedNoLiveOnFixture
{
    #[AsColumn(label: 'Email')]
    public string $email = '';
}

/** Windowed (offset) + SSE + liveOn → valid; the scopes thread into metadata. */
#[WithPagination(defaultLimit: 25, limitOptions: [10, 25, 50], mode: 'offset')]
#[GridFeed(route: '/grid/feed', liveOn: ['ui_playground_leads', 'ui_playground_audit'])]
final class GridLiveOnWindowedFixture
{
    #[AsColumn(label: 'Email')]
    public string $email = '';
}

/** Leads' shape: auto pagination + SSE + liveOn → valid (auto is windowed-capable). */
#[WithPagination(defaultLimit: 10, limitOptions: [10, 25, 50], mode: 'auto')]
#[GridFeed(route: '/grid/feed', liveOn: ['ui_playground_leads'])]
final class GridLiveOnAutoFixture
{
    #[AsColumn(label: 'Email')]
    public string $email = '';
}

/** Cursor/keyset + liveOn → BOOT-FAIL (windowed-only v1). */
#[WithPagination(defaultLimit: 25, limitOptions: [10, 25, 50], mode: 'cursor')]
#[GridFeed(route: '/grid/feed', liveOn: ['ui_playground_leads'])]
final class GridLiveOnCursorFixture
{
    #[AsColumn(label: 'Email')]
    public string $email = '';
}

/** liveOn with NO #[WithPagination] → cursor default → BOOT-FAIL. */
#[GridFeed(route: '/grid/feed', liveOn: ['ui_playground_leads'])]
final class GridLiveOnNoPaginationFixture
{
    #[AsColumn(label: 'Email')]
    public string $email = '';
}

/** Plain pull feed + liveOn → BOOT-FAIL (liveOn needs a held-open SSE stream). */
#[WithPagination(defaultLimit: 25, limitOptions: [10, 25, 50], mode: 'offset')]
#[GridFeed(route: '/grid/feed', mode: GridFeed::MODE_PLAIN, liveOn: ['ui_playground_leads'])]
final class GridLiveOnPlainFixture
{
    #[AsColumn(label: 'Email')]
    public string $email = '';
}
