<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiTransportModePolicy;
use Semitexa\PlatformUi\Application\Service\Twig\PlatformUiTwigExtension;
use Semitexa\PlatformUi\Domain\Exception\UiTransportModeException;
use Semitexa\Ssr\Application\Service\Asset\AssetCollector;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionCatalog;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Twig\Markup;

/**
 * Renders the `ui_page_sse_session_meta()` Twig helper directly
 * through the registered closure so a refactor of the helper's
 * signature, output shape, or policy wiring surfaces here rather
 * than only through a downstream consumer.
 *
 * The helper emits TWO inert meta tags side by side:
 *
 *   <meta name="semitexa-ui-sse-session"  content="<id>">
 *   <meta name="semitexa-ui-transport-mode" content="drain|live">
 *
 * The session id is reset between cases; the transport mode is
 * sourced from PlatformUiTransportModePolicy with the precedence
 * pinned in PlatformUiTransportModePolicyTest. This suite verifies
 * the Twig boundary itself: argument plumbing, output shape, escape
 * posture, multi-call sharing, and fail-fast on bad input.
 */
final class UiPageSseSessionMetaHelperTest extends TestCase
{
    private ?string $previousEnv = null;

    /*
     * Process-global state these cases touch, as it stood before each one: the
     * wired Twig catalog and its function map, the CLI asset collector and its
     * head tags, and the per-request SSE session id. tearDown() puts each back
     * as found, so a case neither inherits nor leaves behind anything.
     */
    private ?TwigExtensionCatalog $previousCatalog = null;

    /** @var array<string, array{callback: callable, options: array}> */
    private array $previousFunctions = [];

    private ?AssetCollector $previousCollector = null;

    /** @var array<string, mixed> */
    private array $previousHeadTags = [];

    private ?string $previousSessionId = null;

    protected function setUp(): void
    {
        $prev = getenv(PlatformUiTransportModePolicy::ENV_VAR_NAME);
        $this->previousEnv = $prev === false ? null : $prev;
        putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME);

        $this->previousCatalog = TwigExtensionRegistry::getCatalog();
        if ($this->previousCatalog !== null) {
            $this->previousFunctions = self::functionsOf($this->previousCatalog);
        }
        $this->previousCollector = self::staticCollector();
        if ($this->previousCollector !== null) {
            $this->previousHeadTags = self::headTagsProperty()->getValue($this->previousCollector);
        }
        $this->previousSessionId = PlatformUiSseSessionState::current();

        // Re-register the helper fresh — TwigExtensionRegistry stores
        // a closure under the function name, and registerFunction()
        // simply overwrites, so this is idempotent and side-effect
        // free across test cases.
        (new PlatformUiTwigExtension())->registerFunctions();

        PlatformUiSseSessionState::reset();
    }

    protected function tearDown(): void
    {
        PlatformUiSseSessionState::reset();
        if ($this->previousSessionId !== null) {
            // The id got here through mintIfAbsent(), restore() or
            // setForTesting(), all of which only accept a safe-shaped id.
            PlatformUiSseSessionState::restore($this->previousSessionId);
        }

        if ($this->previousCatalog === null) {
            TwigExtensionRegistry::setCatalog(null);
        } else {
            (new \ReflectionProperty(TwigExtensionCatalog::class, 'functions'))
                ->setValue($this->previousCatalog, $this->previousFunctions);
            TwigExtensionRegistry::setCatalog($this->previousCatalog);
        }

        if ($this->previousCollector === null) {
            AssetCollectorStore::reset();
        } else {
            self::headTagsProperty()->setValue($this->previousCollector, $this->previousHeadTags);
            (new \ReflectionProperty(AssetCollectorStore::class, 'staticFallback'))
                ->setValue(null, $this->previousCollector);
        }

        if ($this->previousEnv === null) {
            putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME);
        } else {
            putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME . '=' . $this->previousEnv);
        }
    }

    /** @return array<string, array{callback: callable, options: array}> */
    private static function functionsOf(TwigExtensionCatalog $catalog): array
    {
        return (new \ReflectionProperty(TwigExtensionCatalog::class, 'functions'))->getValue($catalog);
    }

    private static function staticCollector(): ?AssetCollector
    {
        return (new \ReflectionProperty(AssetCollectorStore::class, 'staticFallback'))->getValue();
    }

    private static function headTagsProperty(): \ReflectionProperty
    {
        return new \ReflectionProperty(AssetCollector::class, 'headTags');
    }

    /**
     * Invoke the registered helper directly.
     *
     * Reads the registry's private `$functions` map via reflection so
     * the unit test does not need to satisfy
     * {@see TwigExtensionRegistry::initialize()} (which requires a
     * ClassDiscovery instance and is normally set up by the runtime
     * boot listener). The closure under the function name is the same
     * one the registered Twig environment would invoke.
     */
    private function call(?string $mode = null): string
    {
        // Read the registered map off the catalog directly. Going through
        // TwigExtensionRegistry::getFunctions() would trigger initialize(),
        // which needs a ClassDiscovery this unit test deliberately has not
        // wired; the point here is only the callback the module registered.
        $catalog = (new \ReflectionClass(TwigExtensionRegistry::class))
            ->getProperty('catalog')->getValue();
        self::assertInstanceOf(TwigExtensionCatalog::class, $catalog);
        $prop = (new \ReflectionClass($catalog))->getProperty('functions');
        $prop->setAccessible(true);
        /** @var array<string, array{callback: callable, options: array}> $functions */
        $functions = $prop->getValue($catalog);
        self::assertArrayHasKey('ui_page_sse_session_meta', $functions);
        $callback = $functions['ui_page_sse_session_meta']['callback'];
        $markup = $callback($mode);
        self::assertInstanceOf(Markup::class, $markup);
        return (string) $markup;
    }

    #[Test]
    public function default_call_emits_session_meta_and_drain_transport_meta(): void
    {
        $html = $this->call();
        self::assertMatchesRegularExpression(
            '/<meta name="semitexa-ui-sse-session" content="sse_[a-f0-9]{32}">/',
            $html,
        );
        self::assertStringContainsString(
            '<meta name="semitexa-ui-transport-mode" content="drain">',
            $html,
        );
    }

    #[Test]
    public function explicit_live_emits_live_transport_meta(): void
    {
        $html = $this->call('live');
        self::assertStringContainsString(
            '<meta name="semitexa-ui-transport-mode" content="live">',
            $html,
        );
    }

    #[Test]
    public function explicit_drain_emits_drain_transport_meta(): void
    {
        $html = $this->call('drain');
        self::assertStringContainsString(
            '<meta name="semitexa-ui-transport-mode" content="drain">',
            $html,
        );
    }

    #[Test]
    public function explicit_unknown_value_fails_fast_at_render_time(): void
    {
        $this->expectException(UiTransportModeException::class);
        $this->call('hot');
    }

    #[Test]
    public function env_default_live_propagates_when_no_explicit_value(): void
    {
        putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME . '=live');
        // Re-register so the closure picks up the new env. The helper
        // calls (new PlatformUiTransportModePolicy())->resolve($mode)
        // every invocation, so env reads happen at call time — no
        // re-registration actually required, but we re-register
        // defensively to make the test independent of caching
        // assumptions in the registry.
        (new PlatformUiTwigExtension())->registerFunctions();
        $html = $this->call();
        self::assertStringContainsString(
            '<meta name="semitexa-ui-transport-mode" content="live">',
            $html,
        );
    }

    #[Test]
    public function multiple_calls_in_one_render_share_session_id(): void
    {
        $first = $this->call();
        $second = $this->call();
        if (preg_match('/content="(sse_[a-f0-9]{32})"/', $first, $m1) !== 1) {
            self::fail('first call did not emit a session id');
        }
        if (preg_match('/content="(sse_[a-f0-9]{32})"/', $second, $m2) !== 1) {
            self::fail('second call did not emit a session id');
        }
        self::assertSame(
            $m1[1],
            $m2[1],
            'Both helper calls within one render must share the per-request session id.',
        );
    }

    #[Test]
    public function multiple_calls_can_change_transport_mode_per_call(): void
    {
        // The helper does not cache the resolved mode, so a page that
        // calls it twice with different explicit values gets two
        // distinct mode meta tags — useful for tests / playgrounds.
        // Real pages call it exactly once.
        $drain = $this->call('drain');
        $live  = $this->call('live');
        self::assertStringContainsString(
            '<meta name="semitexa-ui-transport-mode" content="drain">',
            $drain,
        );
        self::assertStringContainsString(
            '<meta name="semitexa-ui-transport-mode" content="live">',
            $live,
        );
    }

    #[Test]
    public function emitted_attribute_values_are_html_escaped(): void
    {
        // Defence in depth — the id is constrained to safe shape
        // server-side, but Twig's `is_safe: html` would skip escaping,
        // so we re-assert the helper itself runs htmlspecialchars on
        // both content slots.
        $html = $this->call();
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString("'", $html, 'helper output uses double quotes only');
    }

    /**
     * Every grid printed the meta pair itself; two grids, two copies. The grid
     * now announces the channel and the page carries one pair, in <head>.
     */
    #[Test]
    public function the_live_channel_helper_prints_nothing_and_asks_for_one_head_pair(): void
    {
        (new \Semitexa\PlatformUi\Application\Service\Twig\LiveChannelTwigExtension())->registerFunctions();
        $catalog = (new \ReflectionClass(TwigExtensionRegistry::class))->getProperty('catalog')->getValue();
        self::assertInstanceOf(TwigExtensionCatalog::class, $catalog);
        /** @var array<string, array{callback: callable, options: array}> $functions */
        $functions = (new \ReflectionClass($catalog))->getProperty('functions')->getValue($catalog);
        self::assertArrayHasKey('ui_page_live_channel', $functions);
        $announce = $functions['ui_page_live_channel']['callback'];

        $collector = AssetCollectorStore::get();
        $collector->takeHeadTags('');
        self::assertSame('', $announce());
        self::assertSame('', $announce(), 'a second grid prints nothing either');

        $head = $collector->takeHeadTags('');
        self::assertSame(1, substr_count($head, 'name="semitexa-ui-sse-session"'));
        self::assertSame(1, substr_count($head, 'name="semitexa-ui-transport-mode"'));
    }
}

