<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Renders the platform.grid template through a minimal Twig
 * environment. The grid template only needs `slot()` and
 * `ui_component_instance()` — it has no event handlers, no part
 * resolution, no primitives. This keeps the test fixture small.
 *
 * The test exercises the SHAPE of the rendered HTML (data attrs,
 * inline JSON bundle, table cells, hidden refresh marker) — not
 * any runtime behaviour. The JS runtime has its own static-assert
 * test.
 */
final class GridComponentRenderTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/resources/twig', 'platform-ui');
        $this->twig = new TwigEnvironment($loader, [
            'cache' => false,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        $this->twig->addFunction(new TwigFunction(
            'slot',
            static function (array $context, string $name): Markup {
                $slots = $context['_slots'] ?? [];
                $value = is_array($slots) ? ($slots[$name] ?? null) : null;
                return new Markup($value === null ? '' : (string) $value, 'UTF-8');
            },
            ['needs_context' => true, 'is_safe' => ['html']],
        ));

        $this->twig->addFunction(new TwigFunction(
            'ui_component_instance',
            // Stable id so test assertions can pin the value when
            // the caller does not supply an explicit instanceId.
            static fn (): string => 'uci_fixedtestinstance',
        ));
    }

    private function render(array $props, array $slots = []): string
    {
        $template = $this->twig->load('@platform-ui/components/runtime/grid.html.twig');
        return $template->render(array_merge($props, ['_slots' => $slots]));
    }

    private function fullProps(): array
    {
        return [
            'gridId'     => 'platform.grid.test',
            'instanceId' => 'uci_0123456789abcdef',
            'dataUrl'    => '/test/data',
            'sseUrl'     => '/__ui/stream?token=test',
            'refreshMarker' => 'lead-grid-refresh-marker',
            'columns' => [
                ['key' => 'submittedAt', 'label' => 'Submitted'],
                ['key' => 'id',          'label' => 'ID',         'style' => 'font-family:mono;'],
                ['key' => 'leadName',    'label' => 'Name'],
            ],
            'initialRows' => [
                ['submittedAt' => '2026-05-17 12:00:00', 'id' => 'uilead_aaa', 'leadName' => 'Ada'],
                ['submittedAt' => '2026-05-17 11:00:00', 'id' => 'uilead_bbb', 'leadName' => 'Bea'],
            ],
            'initialPagination' => ['limit' => 25, 'hasMore' => true, 'nextCursor' => 'CURSORX'],
            'initialQuery'      => 'ada',
            'initialAction'     => 'ui-playground.lead.store',
            'pageFallbackUrl'   => '/test/page',
            'emptyMessage'      => 'No rows.',
        ];
    }

    // ----------------------------------------------------------------
    // Root + data attributes
    // ----------------------------------------------------------------

    #[Test]
    public function renders_grid_root_with_data_ui_grid_attribute(): void
    {
        $html = $this->render($this->fullProps());
        self::assertStringContainsString('data-ui-component="platform.grid"', $html);
        self::assertStringContainsString('data-ui-grid="platform.grid.test"', $html);
    }

    #[Test]
    public function renders_caller_supplied_instance_id(): void
    {
        $html = $this->render($this->fullProps());
        self::assertStringContainsString('data-ui-component-instance-id="uci_0123456789abcdef"', $html);
    }

    #[Test]
    public function falls_back_to_minted_instance_id_when_omitted(): void
    {
        $props = $this->fullProps();
        unset($props['instanceId']);
        $html = $this->render($props);
        self::assertStringContainsString('data-ui-component-instance-id="uci_fixedtestinstance"', $html);
    }

    #[Test]
    public function renders_data_url_attribute(): void
    {
        $html = $this->render($this->fullProps());
        self::assertStringContainsString('data-ui-grid-data-url="/test/data"', $html);
    }

    #[Test]
    public function renders_sse_url_attribute_when_supplied(): void
    {
        $html = $this->render($this->fullProps());
        self::assertStringContainsString('data-ui-grid-sse-url="/__ui/stream?token=test"', $html);
    }

    #[Test]
    public function omits_sse_url_attribute_when_null(): void
    {
        $props = $this->fullProps();
        $props['sseUrl'] = null;
        $html = $this->render($props);
        self::assertStringNotContainsString('data-ui-grid-sse-url=', $html);
    }

    #[Test]
    public function renders_refresh_marker_name(): void
    {
        $html = $this->render($this->fullProps());
        self::assertStringContainsString('data-ui-grid-refresh-marker="lead-grid-refresh-marker"', $html);
        self::assertStringContainsString('data-ui-patch-target="lead-grid-refresh-marker"', $html);
    }

    #[Test]
    public function renders_default_refresh_marker_when_omitted(): void
    {
        $props = $this->fullProps();
        unset($props['refreshMarker']);
        $html = $this->render($props);
        self::assertStringContainsString('data-ui-grid-refresh-marker="grid-refresh-marker"', $html);
        self::assertStringContainsString('data-ui-patch-target="grid-refresh-marker"', $html);
    }

    // ----------------------------------------------------------------
    // Columns + initial rows
    // ----------------------------------------------------------------

    #[Test]
    public function renders_column_headers_in_order(): void
    {
        $html = $this->render($this->fullProps());
        $submittedPos = strpos($html, '>Submitted<');
        $idPos        = strpos($html, '>ID<');
        $namePos      = strpos($html, '>Name<');
        self::assertNotFalse($submittedPos);
        self::assertNotFalse($idPos);
        self::assertNotFalse($namePos);
        self::assertLessThan($idPos,   $submittedPos);
        self::assertLessThan($namePos, $idPos);
    }

    #[Test]
    public function renders_initial_rows_via_attribute_lookup(): void
    {
        $html = $this->render($this->fullProps());
        self::assertStringContainsString('uilead_aaa', $html);
        self::assertStringContainsString('uilead_bbb', $html);
        self::assertStringContainsString('Ada', $html);
        self::assertStringContainsString('Bea', $html);
    }

    #[Test]
    public function renders_data_ui_grid_tbody_hook(): void
    {
        $html = $this->render($this->fullProps());
        self::assertStringContainsString('data-ui-grid-tbody', $html);
    }

    // ----------------------------------------------------------------
    // Slots
    // ----------------------------------------------------------------

    #[Test]
    public function renders_filter_slot_content(): void
    {
        $html = $this->render($this->fullProps(), [
            'filters' => '<form data-ui-grid-form method="get" action="/test/page">FILTER_MARKER</form>',
        ]);
        self::assertStringContainsString('FILTER_MARKER', $html);
        self::assertStringContainsString('data-ui-grid-form', $html);
    }

    #[Test]
    public function renders_footer_slot_content(): void
    {
        $html = $this->render($this->fullProps(), [
            'footer' => '<p>FOOTER_MARKER</p>',
        ]);
        self::assertStringContainsString('FOOTER_MARKER', $html);
    }

    #[Test]
    public function renders_warning_slot_content(): void
    {
        $html = $this->render($this->fullProps(), [
            'warning' => '<aside>WARNING_MARKER</aside>',
        ]);
        self::assertStringContainsString('WARNING_MARKER', $html);
    }

    // ----------------------------------------------------------------
    // Inline JSON bundle
    // ----------------------------------------------------------------

    #[Test]
    public function renders_inline_json_bundle_with_columns(): void
    {
        $html = $this->render($this->fullProps());
        self::assertStringContainsString('data-ui-grid-bundle="platform.grid.test"', $html);
        // Twig's json_encode filter escapes slashes by default, so
        // the bundle reads `"dataUrl":"\/test\/data"`. Both forms
        // are valid JSON; the runtime's JSON.parse accepts either.
        self::assertStringContainsString('"gridId":"platform.grid.test"', $html);
        self::assertTrue(
            str_contains($html, '"dataUrl":"/test/data"')
                || str_contains($html, '"dataUrl":"\/test\/data"'),
            'inline bundle must carry the dataUrl key (slash-escaped or not)',
        );
        self::assertStringContainsString('"refreshMarker":"lead-grid-refresh-marker"', $html);
        self::assertStringContainsString('"submittedAt"', $html);
        self::assertStringContainsString('"leadName"', $html);
    }

    // ----------------------------------------------------------------
    // Pagination state
    // ----------------------------------------------------------------

    #[Test]
    public function renders_pagination_pieces_with_has_more(): void
    {
        $html = $this->render($this->fullProps());
        self::assertStringContainsString('data-ui-grid-pagination-text', $html);
        self::assertStringContainsString('data-ui-grid-pagination-size', $html);
        self::assertStringContainsString('data-ui-grid-pagination-count', $html);
        // Next link wrapper is visible (no hidden) because hasMore = true.
        // Element-agnostic regex — the wrapper may be <p>, <span>, or
        // any other inline-flow tag that holds the data attribute.
        self::assertMatchesRegularExpression('/<[a-z]+[^>]*data-ui-grid-next-wrap(?![^>]*hidden)/', $html);
        self::assertStringContainsString('data-ui-grid-next', $html);
    }

    #[Test]
    public function next_wrap_hidden_when_no_more_pages(): void
    {
        $props = $this->fullProps();
        $props['initialPagination'] = ['limit' => 25, 'hasMore' => false, 'nextCursor' => null];
        $html = $this->render($props);
        self::assertMatchesRegularExpression('/<[a-z]+[^>]*data-ui-grid-next-wrap[^>]*hidden/', $html);
    }

    #[Test]
    public function renders_default_pagination_window_size_attribute(): void
    {
        // Default behavior: when no paginationWindowSize prop is
        // passed, the server-rendered root carries the documented
        // default (7) so the runtime can read it without falling
        // back to its own constant.
        $html = $this->render($this->fullProps());
        self::assertMatchesRegularExpression(
            '/data-ui-grid-pagination-window-size="7"/',
            $html,
            'The grid root must carry data-ui-grid-pagination-window-size="7" by default.',
        );
    }

    #[Test]
    public function caller_pagination_window_size_is_emitted_as_a_data_attribute(): void
    {
        $props = $this->fullProps();
        $props['paginationWindowSize'] = 5;
        $html = $this->render($props);
        self::assertMatchesRegularExpression(
            '/data-ui-grid-pagination-window-size="5"/',
            $html,
            'A caller-supplied paginationWindowSize must be emitted verbatim onto the grid root.',
        );
    }

    #[Test]
    public function pagination_window_size_is_clamped_to_documented_bounds(): void
    {
        // Defence in depth — the runtime also clamps, but the server
        // must clamp first so a hostile downstream cannot smuggle a
        // huge value into the rendered DOM.
        $tooHigh = $this->fullProps();
        $tooHigh['paginationWindowSize'] = 999;
        self::assertMatchesRegularExpression(
            '/data-ui-grid-pagination-window-size="25"/',
            $this->render($tooHigh),
            'paginationWindowSize must clamp to 25 on the high end.',
        );

        $zero = $this->fullProps();
        $zero['paginationWindowSize'] = 0;
        self::assertMatchesRegularExpression(
            '/data-ui-grid-pagination-window-size="1"/',
            $this->render($zero),
            'paginationWindowSize must clamp to 1 on the low end.',
        );

        $negative = $this->fullProps();
        $negative['paginationWindowSize'] = -10;
        self::assertMatchesRegularExpression(
            '/data-ui-grid-pagination-window-size="1"/',
            $this->render($negative),
            'Negative paginationWindowSize must clamp to 1.',
        );
    }

    #[Test]
    public function renders_pagination_nav_with_prev_and_indicator(): void
    {
        // New pagination footer: Previous button (hidden on first
        // paint until the runtime grows the history stack), a
        // visited-page-buttons container (also runtime-populated),
        // and a static "Page 1" indicator (overwritten by the
        // runtime on every reload).
        $html = $this->render($this->fullProps());
        self::assertMatchesRegularExpression(
            '/<nav[^>]*data-ui-grid-pagination/',
            $html,
            'Grid must render a <nav data-ui-grid-pagination> footer.',
        );
        self::assertMatchesRegularExpression(
            '/<button[^>]*data-ui-grid-prev[^>]*hidden/',
            $html,
            'Previous button must be present and start hidden on first paint.',
        );
        self::assertMatchesRegularExpression(
            '/data-ui-grid-pages/',
            $html,
            'Pagination footer must include the visited-page-buttons container.',
        );
        self::assertMatchesRegularExpression(
            '/data-ui-grid-page-indicator[^>]*>Page 1</',
            $html,
            'Pagination footer must include a "Page 1" indicator the runtime can overwrite.',
        );
    }

    #[Test]
    public function next_link_fallback_href_preserves_filter_state(): void
    {
        $html = $this->render($this->fullProps());
        // Twig autoescape renders `&` as `&amp;` inside `href=`,
        // which is the correct HTML — the browser still parses
        // the four params as cursor / limit / q / action. We check
        // for the underlying token sequence rather than a literal
        // raw `&`.
        self::assertStringContainsString('href="/test/page?cursor=CURSORX&amp;limit=25&amp;q=ada&amp;action=ui-playground.lead.store"', $html);
    }

    // ----------------------------------------------------------------
    // Empty / full visibility
    // ----------------------------------------------------------------

    #[Test]
    public function empty_state_visible_when_no_rows(): void
    {
        $props = $this->fullProps();
        $props['initialRows'] = [];
        $html = $this->render($props);
        // The empty section MUST NOT carry `hidden`.
        self::assertMatchesRegularExpression('/<section[^>]*data-ui-grid-empty(?![^>]*hidden)/', $html);
        // The table wrap section MUST carry `hidden`.
        self::assertMatchesRegularExpression('/<section[^>]*data-ui-grid-table-wrap[^>]*hidden/', $html);
    }

    #[Test]
    public function empty_state_hidden_when_rows_present(): void
    {
        $html = $this->render($this->fullProps());
        self::assertMatchesRegularExpression('/<section[^>]*data-ui-grid-empty[^>]*hidden/', $html);
        self::assertMatchesRegularExpression('/<section[^>]*data-ui-grid-table-wrap(?![^>]*hidden)/', $html);
    }

    // ----------------------------------------------------------------
    // Safety pins
    // ----------------------------------------------------------------

    #[Test]
    public function rendered_markup_carries_no_raw_innerHTML_or_eval_strings(): void
    {
        $html = $this->render($this->fullProps());
        self::assertStringNotContainsString('innerHTML',  $html);
        self::assertStringNotContainsString('eval(',      $html);
        self::assertStringNotContainsString('Function(',  $html);
    }

    #[Test]
    public function hidden_refresh_marker_target_is_present_for_sse_patches(): void
    {
        $html = $this->render($this->fullProps());
        // Marker MUST be hidden + carry data-ui-patch-target so the
        // existing safe applier finds it and the SSE refresh signal
        // dispatches a `patch-applied` event the runtime can react
        // to without mutating any visible UI.
        self::assertMatchesRegularExpression('/<span[^>]*data-ui-patch-target="lead-grid-refresh-marker"[^>]*hidden/', $html);
    }
}
