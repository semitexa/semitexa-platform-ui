<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState;
use Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * Integration coverage for the page-level SSE binding seam.
 *
 * The Twig layer is tested through {@see FormComponentRenderTest} (it
 * stubs `ui_event_manifest` directly to keep that harness independent
 * of the production helper); this suite drives the production
 * {@see UiEventManifestBuilder} + {@see PlatformUiSseSessionState}
 * pair end-to-end so a regression in the sub-claim threading surfaces
 * here rather than only in the JS asset pin or the browser smoke
 * test.
 */
final class PlatformUiSseSessionPageBindingTest extends TestCase
{
    private ?string $previousSecret = null;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-sse-session-page-binding');
        putenv('APP_ENV=dev');

        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(FieldComponent::class),
        );

        PlatformUiSseSessionState::reset();
    }

    protected function tearDown(): void
    {
        PlatformUiSseSessionState::reset();
        UiComponentRegistry::reset();
        if ($this->previousSecret === null) {
            putenv('APP_SECRET');
        } else {
            putenv('APP_SECRET=' . $this->previousSecret);
        }
        if ($this->previousEnv === null) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->previousEnv);
        }
    }

    #[Test]
    public function manifest_built_after_mint_carries_same_sub_claim_as_state_holder(): void
    {
        $id = PlatformUiSseSessionState::mintIfAbsent();

        $metadata = UiComponentRegistry::get('platform.field');
        self::assertNotNull($metadata);

        // Mirror the Twig extension's call site: forward the holder's
        // current id straight through. If a future refactor stops
        // threading it, this assertion fails before the JS / browser
        // smoke layer notices.
        $manifest = (new UiEventManifestBuilder())->build(
            metadata: $metadata,
            instanceId: 'uci_page_binding_001',
            subscriberChannelId: PlatformUiSseSessionState::current(),
        );

        $claims = SignedContext::verify($manifest->entries[0]->signedContext);
        self::assertNotNull($claims);
        self::assertSame($id, $claims['sub']);
    }

    #[Test]
    public function multiple_components_in_one_render_share_one_sub_claim(): void
    {
        PlatformUiSseSessionState::mintIfAbsent();
        $sub = PlatformUiSseSessionState::current();

        $metadata = UiComponentRegistry::get('platform.field');
        self::assertNotNull($metadata);

        // Two manifests rendered within the same "render" must reuse
        // the same session id — that's what makes one EventSource on
        // the client enough to receive every component's patches.
        $a = (new UiEventManifestBuilder())->build(
            metadata: $metadata,
            instanceId: 'uci_share_a_001',
            subscriberChannelId: PlatformUiSseSessionState::current(),
        );
        $b = (new UiEventManifestBuilder())->build(
            metadata: $metadata,
            instanceId: 'uci_share_b_002',
            subscriberChannelId: PlatformUiSseSessionState::current(),
        );

        $claimsA = SignedContext::verify($a->entries[0]->signedContext);
        $claimsB = SignedContext::verify($b->entries[0]->signedContext);

        self::assertNotNull($claimsA);
        self::assertNotNull($claimsB);
        self::assertSame($sub, $claimsA['sub']);
        self::assertSame($sub, $claimsB['sub']);
    }

    #[Test]
    public function manifest_without_minted_session_omits_sub_claim(): void
    {
        // No mint call → current() is null → builder is invoked with
        // null sub → claim is omitted. Old pages keep producing
        // sub-less ctxs and the dispatcher falls back to inline
        // patches, which is the backward-compatible path the spec
        // requires to remain.
        self::assertNull(PlatformUiSseSessionState::current());

        $metadata = UiComponentRegistry::get('platform.field');
        self::assertNotNull($metadata);
        $manifest = (new UiEventManifestBuilder())->build(
            metadata: $metadata,
            instanceId: 'uci_no_session_001',
            subscriberChannelId: PlatformUiSseSessionState::current(),
        );

        $claims = SignedContext::verify($manifest->entries[0]->signedContext);
        self::assertNotNull($claims);
        self::assertArrayNotHasKey('sub', $claims);
    }

    #[Test]
    public function reset_between_requests_invalidates_prior_session_id(): void
    {
        $first = PlatformUiSseSessionState::mintIfAbsent();
        PlatformUiSseSessionState::reset();
        $second = PlatformUiSseSessionState::mintIfAbsent();

        // The reset is what stops a Swoole worker from reusing an
        // earlier request's channel id for a later request — that
        // would let request B publish patches into request A's
        // already-closed stream (or, worse, into a different user's
        // stream if a long-lived subscriber survived).
        self::assertNotSame($first, $second);
    }
}
