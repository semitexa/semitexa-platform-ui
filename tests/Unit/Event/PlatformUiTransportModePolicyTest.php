<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiTransportMode;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiTransportModePolicy;
use Semitexa\PlatformUi\Domain\Exception\UiTransportModeException;

/**
 * Resolution precedence + safety invariants for the canonical SSE
 * transport mode policy.
 *
 *   1. explicit page/component option wins
 *   2. env default SEMITEXA_UI_TRANSPORT_MODE is honoured next
 *   3. hard fallback is drain — the public/guest-safe default
 *
 * Invalid explicit AND invalid env both fail fast. Public/guest
 * safety hinges on no silent drift into `live`, so the policy refuses
 * to guess.
 */
final class PlatformUiTransportModePolicyTest extends TestCase
{
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $prev = getenv(PlatformUiTransportModePolicy::ENV_VAR_NAME);
        $this->previousEnv = $prev === false ? null : $prev;
        putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME);
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === null) {
            putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME);
        } else {
            putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME . '=' . $this->previousEnv);
        }
    }

    #[Test]
    public function default_when_no_explicit_and_no_env_is_drain(): void
    {
        $policy = new PlatformUiTransportModePolicy();
        self::assertSame(PlatformUiTransportMode::Drain, $policy->resolve(null));
    }

    #[Test]
    public function explicit_drain_resolves_drain(): void
    {
        $policy = new PlatformUiTransportModePolicy();
        self::assertSame(PlatformUiTransportMode::Drain, $policy->resolve('drain'));
    }

    #[Test]
    public function explicit_live_resolves_live(): void
    {
        $policy = new PlatformUiTransportModePolicy();
        self::assertSame(PlatformUiTransportMode::Live, $policy->resolve('live'));
    }

    #[Test]
    public function explicit_unknown_value_fails_fast(): void
    {
        $this->expectException(UiTransportModeException::class);
        $this->expectExceptionMessage('"hot"');
        (new PlatformUiTransportModePolicy())->resolve('hot');
    }

    #[Test]
    public function explicit_uppercase_drain_fails_fast(): void
    {
        // No case folding — `'DRAIN'` and `'drain'` are not the same
        // wire value. A deployment that ships `'DRAIN'` is a typo we
        // want to surface, not silently absorb.
        $this->expectException(UiTransportModeException::class);
        (new PlatformUiTransportModePolicy())->resolve('DRAIN');
    }

    #[Test]
    public function explicit_empty_string_fails_fast(): void
    {
        // The Twig helper passes `null` when the caller supplied no
        // mode; an empty string came from somewhere else (typo,
        // mis-cast). Treat it as an explicit unknown.
        $this->expectException(UiTransportModeException::class);
        (new PlatformUiTransportModePolicy())->resolve('');
    }

    #[Test]
    public function env_drain_used_when_explicit_null(): void
    {
        putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME . '=drain');
        $policy = new PlatformUiTransportModePolicy();
        self::assertSame(PlatformUiTransportMode::Drain, $policy->resolve(null));
    }

    #[Test]
    public function env_live_used_when_explicit_null(): void
    {
        putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME . '=live');
        $policy = new PlatformUiTransportModePolicy();
        self::assertSame(PlatformUiTransportMode::Live, $policy->resolve(null));
    }

    #[Test]
    public function explicit_overrides_env(): void
    {
        // Internal deployment defaults to live via env, but a
        // specific page wants to downgrade to drain for that render
        // (e.g. embedded in a public iframe). The explicit value must
        // win, otherwise the env default would silently widen the
        // surface back to live.
        putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME . '=live');
        $policy = new PlatformUiTransportModePolicy();
        self::assertSame(PlatformUiTransportMode::Drain, $policy->resolve('drain'));
    }

    #[Test]
    public function env_unknown_value_fails_fast(): void
    {
        // A misconfigured env value MUST NOT silently fall back to
        // drain — that would mask deployment errors. Same fail-fast
        // posture as an explicit unknown.
        putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME . '=hot');
        $this->expectException(UiTransportModeException::class);
        $this->expectExceptionMessage(PlatformUiTransportModePolicy::ENV_VAR_NAME);
        (new PlatformUiTransportModePolicy())->resolve(null);
    }

    #[Test]
    public function env_empty_value_falls_through_to_default(): void
    {
        // `putenv('NAME=')` leaves getenv returning `''` — semantics
        // are "set but blank", which the framework convention treats
        // as "unset" (see SSE_PUBLIC_ANONYMOUS reading in
        // vendor/semitexa/ssr's AsyncResourceSseServer).
        putenv(PlatformUiTransportModePolicy::ENV_VAR_NAME . '=');
        $policy = new PlatformUiTransportModePolicy();
        self::assertSame(PlatformUiTransportMode::Drain, $policy->resolve(null));
    }
}
