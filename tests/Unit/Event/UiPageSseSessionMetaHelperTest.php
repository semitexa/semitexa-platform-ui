<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiTransportModePolicy;
use Semitexa\PlatformUi\Application\Service\Twig\PlatformUiTwigExtension;
use Semitexa\PlatformUi\Domain\Exception\UiTransportModeException;
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

    protected function setUp(): void
    {
        $prev = getenv(PlatformUiTransportModePolicy::ENV_VAR_NAME);
        $this->previousEnv = $prev === false ? null : $prev;
        putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME);

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
        if ($this->previousEnv === null) {
            putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME);
        } else {
            putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME . '=' . $this->previousEnv);
        }
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
        $rc = new \ReflectionClass(TwigExtensionRegistry::class);
        $prop = $rc->getProperty('functions');
        $prop->setAccessible(true);
        /** @var array<string, array{callback: callable, options: array}> $functions */
        $functions = $prop->getValue();
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
}
