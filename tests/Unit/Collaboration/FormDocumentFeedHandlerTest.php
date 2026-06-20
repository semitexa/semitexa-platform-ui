<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\PlatformUi\Application\Handler\PayloadHandler\FormDocumentFeedHandler;
use Semitexa\PlatformUi\Application\Payload\Request\FormDocumentFeedPayload;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormPresenceStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormDocumentProjector;
use Semitexa\PlatformUi\Application\Service\Collaboration\InMemoryFormCollabDraftStore;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;
use Semitexa\PlatformUi\Tests\Support\ArrayCacheManager;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * Collaborative Form Data · Phase 3 (Shared mode) — the concrete document feed
 * that closes the server broadcast loop: it verifies the SIGNED context token
 * (the same {@see SignedContext} blob the form component mints), projects the
 * document's shared state through {@see FormDocumentProjector}, and renders the
 * canonical `{data, meta}` envelope. A forged / missing token denies the
 * connect (an auth-shaped exception the feed base propagates, never frames).
 *
 * `buildDocumentResponse()` is exercised by reflection — the same seam the SSR
 * base test drives — so the held-open socket machinery stays out of a unit test.
 */
final class FormDocumentFeedHandlerTest extends TestCase
{
    private const SCOPE = 'formdoc:article:42';

    private InMemoryFormCollabDraftStore $draft;
    private FormDocumentProjector $projector;
    private FormDocumentFeedHandler $handler;

    protected function setUp(): void
    {
        $this->draft = new InMemoryFormCollabDraftStore();
        $presence = (new CacheBackedFormPresenceStore())->withCacheManager(new ArrayCacheManager());
        $this->projector = (new FormDocumentProjector())->withStores($this->draft, $presence);
        $this->handler = (new FormDocumentFeedHandler())->withProjector($this->projector);
    }

    /** A valid signed token carrying the trusted cfg.scope + cfg.mode. */
    private function signedCtx(string $scope, string $mode): string
    {
        return SignedContext::sign(['i' => 'actor-A', 'cfg' => ['scope' => $scope, 'mode' => $mode]]);
    }

    /** @return array{data: array<string, mixed>, meta: array<string, mixed>} */
    private function build(FormDocumentFeedPayload $payload): array
    {
        $method = new \ReflectionMethod(FormDocumentFeedHandler::class, 'buildDocumentResponse');
        $method->setAccessible(true);
        /** @var JsonResourceResponse $response */
        $response = $method->invoke($this->handler, $payload, new JsonResourceResponse());

        return json_decode($response->getContent(), true);
    }

    #[Test]
    public function a_verified_token_projects_the_shared_document_envelope(): void
    {
        $this->draft->mergeFields(self::SCOPE, ['title' => 'Hi'], 'actor-A');

        $payload = new FormDocumentFeedPayload();
        $payload->setCtx($this->signedCtx(self::SCOPE, 'shared'));

        $envelope = $this->build($payload);

        self::assertSame('Hi', $envelope['data']['values']['title']);
        self::assertSame(1, $envelope['data']['version']);
        self::assertSame('actor-A', $envelope['data']['origin']);
        self::assertSame('shared', $envelope['meta']['mode']);
    }

    #[Test]
    public function a_missing_token_denies_the_connect(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->build(new FormDocumentFeedPayload());
    }

    #[Test]
    public function a_forged_token_denies_the_connect(): void
    {
        $payload = new FormDocumentFeedPayload();
        $payload->setCtx('sc1.not-a-real.signature');

        $this->expectException(AccessDeniedException::class);
        $this->build($payload);
    }

    #[Test]
    public function the_scope_comes_only_from_the_signed_claim_not_a_spoofable_param(): void
    {
        // A token signed for one document cannot be made to watch another — the
        // scope is the trusted claim, and the payload exposes no raw-scope path.
        $payload = new FormDocumentFeedPayload();
        $payload->setCtx($this->signedCtx(self::SCOPE, 'shared'));

        self::assertSame([self::SCOPE], $payload->dynamicWatchScopes());
        self::assertSame(self::SCOPE, $payload->scope());
        self::assertSame(FormCollaborationMode::Shared, $payload->mode());
    }

    #[Test]
    public function an_unverified_payload_watches_no_scope(): void
    {
        $payload = new FormDocumentFeedPayload();
        $payload->setCtx('garbage');

        self::assertSame([], $payload->dynamicWatchScopes());
        self::assertNull($payload->verifiedCfg());
    }

    #[Test]
    public function the_mode_is_carried_through_from_the_signed_claim(): void
    {
        $payload = new FormDocumentFeedPayload();
        $payload->setCtx($this->signedCtx(self::SCOPE, 'field-lock'));

        self::assertSame(FormCollaborationMode::FieldLock, $payload->mode());
        self::assertSame('field-lock', $this->build($payload)['meta']['mode']);
    }

    #[Test]
    public function the_managed_field_names_are_read_from_the_signed_cfg(): void
    {
        // FieldLock resolves per-field locks for these names; they ride the SIGNED
        // token so the field set cannot be spoofed by a request body.
        $token = SignedContext::sign([
            'i'   => 'actor-A',
            'cfg' => ['scope' => self::SCOPE, 'mode' => 'field-lock', 'fields' => ['title', 'body', '', 42]],
        ]);
        $payload = new FormDocumentFeedPayload();
        $payload->setCtx($token);

        // Blanks / non-strings dropped; the trusted string field names survive.
        self::assertSame(['title', 'body'], $payload->fields());
    }

    #[Test]
    public function fields_default_to_empty_without_a_cfg_fields_claim(): void
    {
        $payload = new FormDocumentFeedPayload();
        $payload->setCtx($this->signedCtx(self::SCOPE, 'shared'));

        self::assertSame([], $payload->fields());
    }
}
