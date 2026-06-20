<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Request;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Domain\Contract\DynamicallyScopedFeedInterface;
use Semitexa\Ssr\Domain\Contract\SseDocumentFeedPayloadInterface;
use Semitexa\Ssr\Domain\Model\FormDocumentScope;

/**
 * Collaborative Form Data · Phase 3 (Shared mode) — `GET|POST /__ui/form-doc`,
 * the held-open SSE READ feed for ONE collaborative document. The object-valued
 * sibling of the pings collection feed: where that serves `{data:[...], meta}`
 * keyed by a static table scope, this serves `{data:{...}, meta}` keyed by the
 * per-record `formdoc:{formKey}:{recordId}` scope, re-projecting the live shared
 * draft + presence roster every time the inbound handler touches the document.
 *
 * TRUST BOUNDARY — the symmetric READ half of the `/__ui/event` write trust
 * model. The watched document scope (and its mode) MUST NOT come from a
 * spoofable query param, or any client could subscribe to any document's draft.
 * They ride a SIGNED context token (`?ctx=sc1.…`, the same {@see SignedContext}
 * blob the form component mints for its write events), verified HMAC-side here;
 * the trusted `cfg.scope` / `cfg.mode` claims are the only source of the watched
 * scope. A missing / forged / expired token resolves to NO cfg → an empty
 * dynamic scope (no live subscription) and the handler denies the connect.
 *
 * A document feed has NO view-change surface (no `q`/`sort`/`filter`/paging — a
 * record is not a list), so it declares no `#[LiveFilterParam]` fields and an
 * empty {@see toViewParams()}: the stream simply lives and re-projects on scope
 * touch. `streamId` / `httpRequest` are transport metadata only, exactly as on
 * the collection feed.
 */
#[AsPublicPayload(
    path: '/__ui/form-doc',
    methods: ['GET', 'POST'],
    responseWith: JsonResourceResponse::class,
    renderProfile: RenderProfile::Json,
    transport: TransportType::Sse,
    sseGateModel: SseGateModel::BearerSession,
)]
final class FormDocumentFeedPayload implements SseDocumentFeedPayloadInterface, DynamicallyScopedFeedInterface
{
    /**
     * The signed context token (`sc1.<claims>.<mac>`) carrying the trusted
     * `cfg.scope` + `cfg.mode` for the document being watched. The ONLY source
     * of the watched scope — never a raw scope param.
     */
    private ?string $ctx = null;

    /** The held-open stream's delivery coordinate (transport metadata, never a filter). */
    private ?string $streamId = null;

    private ?Request $httpRequest = null;

    /**
     * Memoized verified cfg claim. `false` = not yet resolved; `null` =
     * verification failed (forged / missing / expired); array = trusted cfg.
     *
     * @var array<string, mixed>|null|false
     */
    private array|null|false $cfgCache = false;

    public function setCtx(string $ctx): void
    {
        $this->ctx = self::trimToNull($ctx);
        $this->cfgCache = false;
    }

    public function getCtx(): string
    {
        return $this->ctx ?? '';
    }

    public function setHttpRequest(Request $request): void
    {
        $this->httpRequest = $request;
    }

    public function getHttpRequest(): ?Request
    {
        return $this->httpRequest;
    }

    public function setStreamId(?string $streamId): void
    {
        $this->streamId = self::trimToNull($streamId);
    }

    public function getStreamId(): ?string
    {
        return $this->streamId;
    }

    /**
     * The trusted, HMAC-verified `cfg` claim from the signed context token, or
     * null when the token is missing / forged / expired. Memoized so the
     * envelope build and {@see dynamicWatchScopes()} agree within one request.
     *
     * @return array<string, mixed>|null
     */
    public function verifiedCfg(): ?array
    {
        if ($this->cfgCache !== false) {
            return $this->cfgCache;
        }

        $token = $this->ctx;
        if ($token === null) {
            return $this->cfgCache = null;
        }

        $claims = SignedContext::verify($token);
        $cfg = is_array($claims) && is_array($claims['cfg'] ?? null) ? $claims['cfg'] : null;

        return $this->cfgCache = $cfg;
    }

    /** The trusted document scope from the signed cfg, or '' when unverified. */
    public function scope(): string
    {
        $cfg = $this->verifiedCfg();

        return is_array($cfg) ? (string) ($cfg['scope'] ?? '') : '';
    }

    /** The resolved collaboration mode from the signed cfg (defaulted when absent). */
    public function mode(): FormCollaborationMode
    {
        $cfg = $this->verifiedCfg();
        $raw = is_array($cfg) ? (string) ($cfg['mode'] ?? '') : '';

        return FormCollaborationMode::tryFrom($raw) ?? FormCollaborationMode::default();
    }

    /**
     * The managed field names from the SIGNED cfg — the trusted list the
     * projector resolves per-field locks for (FieldLock). Empty when absent.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        $cfg = $this->verifiedCfg();
        $raw = is_array($cfg) ? ($cfg['fields'] ?? null) : null;
        if (!is_array($raw)) {
            return [];
        }

        $fields = [];
        foreach ($raw as $field) {
            if (is_string($field) && $field !== '') {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * The per-record invalidation scope, resolved from the SIGNED token at
     * request time (it cannot ride a class-level `#[WatchScopes]`). An invalid
     * scope / unverified token contributes nothing — the stream then watches
     * no scope and the handler denies the connect.
     *
     * @return list<string>
     */
    public function dynamicWatchScopes(): array
    {
        $scope = $this->scope();

        return FormDocumentScope::isValid($scope) ? [$scope] : [];
    }

    /**
     * A document feed has no view-change params — a record is not a list. The
     * empty map means a re-hydrate intake overrides nothing (there are no
     * `#[LiveFilterParam]` fields), which is exactly correct.
     *
     * @return array<string, mixed>
     */
    public function toViewParams(): array
    {
        return [];
    }

    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
