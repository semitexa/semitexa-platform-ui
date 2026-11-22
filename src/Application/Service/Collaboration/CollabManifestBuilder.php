<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\PlatformUi\Application\Service\Refresh\UiRefreshFinisher;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Domain\Model\FormDocumentScope;

/**
 * Collaborative Form Data · Phase 3 (Shared mode) — mints the signed collab
 * manifest a collaborative form emits into the DOM for `form-collab-runtime.js`.
 *
 * The READ + WRITE trust surface in one place. The browser runtime never names
 * a scope or a handler — it relays opaque {@see SignedContext} tokens this
 * builder mints:
 *   - `feedCtx` carries the trusted `cfg.scope/mode` the form-document feed
 *     (`feed`, subscribed through HUG onto the page's KISS stream) verifies
 *     (read trust);
 *   - each per-event token (`events['field.edit']`, `events['presence.ping']`,
 *     …) carries the `(c, p, e)` triple the HUG (`POST /__semitexa_hug`) dispatcher routes by
 *     PLUS the same `cfg`, so the inbound handler reads scope/mode from a signed
 *     claim, never the spoofable body (write trust). One token per semantic
 *     event because the dispatcher resolves the `#[HandlesUiEvent]` binding from
 *     the signed `p`/`e` — they cannot share a blob.
 *
 * RENEWAL: those tokens live {@see SignedContext::DEFAULT_TTL_SECONDS} and a
 * form is edited for longer. `renew` is one more event token — `(c, 'manifest',
 * 'renew')` with the same `cfg`, who the page was drawn for (`who`) and how
 * long its tokens live (`ttl`) — signed for a day, like a component's refresh
 * token ({@see UiRefreshFinisher}). Sent through HUG it is answered with
 * {@see self::renewed()}: the same tokens signed again, from the SIGNED claims
 * alone. It routes to the renewal and nowhere else, so it never stands in for
 * an event; an expired event token is still refused, by itself it renews
 * nothing; and {@see SignedContext} binds `renew` to the session and tenant it
 * was minted for, as it binds every token here.
 *
 * `self` is the stable per-participant id (auth user id, else the per-render
 * instance id) — the echo-suppression coordinate the runtime compares against a
 * snapshot's `origin`, resolved the SAME way the inbound handler resolves the
 * actor so the two always agree.
 *
 * Pure value assembly (mirrors {@see \Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder}):
 * instantiated inline by the Twig helper, no container deps, trivially testable.
 */
final class CollabManifestBuilder
{
    public const SCHEMA_VERSION = 1;
    /** The form-document feed's route name; HUG subscribes it onto KISS. */
    public const FEED = 'platform-ui.form-doc';
    public const HEARTBEAT_MS = 15000;
    /** The (part, event) the renewal token routes to. */
    public const RENEW_PART = 'manifest';
    public const RENEW_EVENT = 'renew';
    /** A form left open all day can still be saved; after that it is reloaded. */
    public const RENEW_TTL_SECONDS = UiRefreshFinisher::TOKEN_TTL_SECONDS;

    /**
     * @param array<array-key, mixed> $fields the field names the runtime manages (emit + apply)
     * @return array{v: int, i: string, scope: string, mode: string, self: string, feed: string, feedCtx: string, events: array<string, string>, renew: string, fields: list<string>, heartbeatMs: int, saved: string} the JSON shape `form-collab-runtime.js` consumes
     */
    public function build(
        string $componentName,
        string $instanceId,
        string $formKey,
        string $recordId,
        string $mode,
        array $fields,
        ?int $ttlSeconds = null,
        ?FormCollabSaveNotices $notices = null,
    ): array {
        $resolvedMode = FormCollaborationMode::tryFrom($mode) ?? FormCollaborationMode::default();
        $scope = FormDocumentScope::forRecord($formKey, $recordId);
        $fieldNames = array_values(array_map(static fn (mixed $f): string => is_scalar($f) ? (string) $f : '', $fields));
        $cfg = ['scope' => $scope, 'mode' => $resolvedMode->value];

        // The managed field list rides the SIGNED cfg of every token.
        $signedCfg = $cfg + ['fields' => $fieldNames];
        $contexts = self::contexts($componentName, $instanceId, $resolvedMode, $signedCfg, $ttlSeconds);

        $renewClaims = [
            'c'   => $componentName,
            'i'   => $instanceId,
            'p'   => self::RENEW_PART,
            'e'   => self::RENEW_EVENT,
            'cfg' => $signedCfg,
            'who' => self::currentUserId(),
        ];
        if ($ttlSeconds !== null) {
            $renewClaims['ttl'] = $ttlSeconds;
        }

        return [
            'v'           => self::SCHEMA_VERSION,
            'i'           => $instanceId,
            'scope'       => $scope,
            'mode'        => $resolvedMode->value,
            'self'        => self::resolveSelf($instanceId),
            'feed'        => self::FEED,
            'feedCtx'     => $contexts['feedCtx'],
            'events'      => $contexts['events'],
            // Traded for fresh `feedCtx` + `events` once those expire.
            'renew'       => SignedContext::sign($renewClaims, max(self::RENEW_TTL_SECONDS, $ttlSeconds ?? 0)),
            'fields'      => $fieldNames,
            'heartbeatMs' => self::HEARTBEAT_MS,
            // The save notice current while this page was rendered: what the
            // page shows already includes that save, so the runtime starts
            // from it and reacts only to a later one. '' = none, or not known.
            'saved'       => self::currentNoticeId($notices, $scope),
        ];
    }

    /**
     * The manifest's tokens signed again, from the verified claims of its
     * renewal token: the same component, instance, scope, mode and fields, the
     * events that mode uses, for as long as the first ones lived. Nothing here
     * comes from a request body. Null when the claims are not a renewal
     * token's. The caller has checked `who`; {@see SignedContext::sign()}
     * binds the result to the session and tenant asking, which verification
     * has just matched against the token's.
     *
     * @param array<string, mixed> $claims verified renewal claims
     * @return array{feedCtx: string, events: array<string, string>}|null
     */
    public function renewed(array $claims): ?array
    {
        $componentName = is_string($claims['c'] ?? null) ? $claims['c'] : '';
        $instanceId = is_string($claims['i'] ?? null) ? $claims['i'] : '';
        $cfg = is_array($claims['cfg'] ?? null) ? $claims['cfg'] : [];
        $scope = is_string($cfg['scope'] ?? null) ? $cfg['scope'] : '';
        $mode = FormCollaborationMode::tryFrom(is_string($cfg['mode'] ?? null) ? $cfg['mode'] : '');
        $fields = is_array($cfg['fields'] ?? null) ? array_values($cfg['fields']) : null;
        $ttl = $claims['ttl'] ?? null;
        if (($claims['p'] ?? null) !== self::RENEW_PART || ($claims['e'] ?? null) !== self::RENEW_EVENT
            || $componentName === '' || $instanceId === '' || $mode === null || $fields === null
            || !FormDocumentScope::isValid($scope)
            || array_filter($fields, static fn (mixed $f): bool => !is_string($f)) !== []
            || ($ttl !== null && (!is_int($ttl) || $ttl <= 0))
        ) {
            return null;
        }
        /** @var list<string> $fields */

        return self::contexts(
            $componentName,
            $instanceId,
            $mode,
            ['scope' => $scope, 'mode' => $mode->value, 'fields' => $fields],
            $ttl,
        );
    }

    /**
     * The tokens that expire: the read feed's and one per event of the mode.
     *
     * @param array{scope: string, mode: string, fields: list<string>} $signedCfg
     * @return array{feedCtx: string, events: array<string, string>}
     */
    private static function contexts(
        string $componentName,
        string $instanceId,
        FormCollaborationMode $mode,
        array $signedCfg,
        ?int $ttlSeconds,
    ): array {
        // Read-side token: cfg + the managed field names so the projector can
        // resolve per-field locks (FieldLock) from a TRUSTED list — the field set
        // rides the signed token, never a spoofable request body. The feed ignores
        // p/e and verifies scope/mode/fields.
        $feedCtx = SignedContext::sign(['i' => $instanceId, 'cfg' => $signedCfg], $ttlSeconds);

        // Write-side tokens: one per (part, event) the mode uses, each routable.
        // The managed field list rides the SIGNED cfg here too (not just the
        // read-side feedCtx): the handler enforces field.edit / form.save keys
        // against this trusted allow-list, so a valid write token can't be used to
        // inject arbitrary field names into the shared draft.
        $events = [];
        foreach (self::semanticEventsFor($mode) as [$part, $event]) {
            $events[$part . '.' . $event] = SignedContext::sign([
                'c'   => $componentName,
                'i'   => $instanceId,
                'p'   => $part,
                'e'   => $event,
                'cfg' => $signedCfg,
            ], $ttlSeconds);
        }

        return ['feedCtx' => $feedCtx, 'events' => $events];
    }

    private static function currentNoticeId(?FormCollabSaveNotices $notices, string $scope): string
    {
        try {
            return $notices?->current($scope)['id'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The (part, event) pairs a mode's runtime emits — exactly the semantic
     * events {@see FormCollaborationEventHandler} handles.
     *
     * Optimistic has NO live coupling: it never broadcasts a keystroke, so it
     * gets no `field.edit` token. Instead it commits the whole form at once under
     * a save-time version guard (`form.save`), and still advertises presence so
     * editors see who else holds the document open. The live modes broadcast
     * field edits and ping presence; the lock modes add the lock lifecycle.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function semanticEventsFor(FormCollaborationMode $mode): array
    {
        if ($mode->isOptimistic()) {
            return [
                ['form', 'save'],
                ['presence', 'ping'],
            ];
        }

        $events = [
            ['field', 'edit'],
            ['presence', 'ping'],
        ];

        if ($mode->usesLock()) {
            $events[] = ['lock', 'acquire'];
            $events[] = ['lock', 'release'];
            $events[] = ['lock', 'heartbeat'];
        }

        return $events;
    }

    /**
     * The stable participant id — the authenticated user id when present, else
     * the per-render instance id (so distinct anonymous tabs get distinct
     * identities). Mirrors {@see FormCollaborationEventHandler::resolveActor()}
     * so the manifest's `self` equals the origin the write path stamps.
     */
    private static function resolveSelf(string $instanceId): string
    {
        $id = self::currentUserId();
        if ($id !== '') {
            return $id;
        }

        return $instanceId !== '' ? $instanceId : 'anonymous';
    }

    /**
     * The signed-in user's id, '' for a guest: who a renewal token is minted
     * for. Read where {@see FormCollaborationEventHandler} reads the actor, so
     * the renewal compares like with like.
     */
    private static function currentUserId(): string
    {
        $store = '\Semitexa\Auth\Context\AuthContextStore';
        if (class_exists($store)) {
            /** @var object|null $user */
            $user = $store::getUser();
            if (is_object($user) && method_exists($user, 'getId')) {
                $id = $user->getId();

                return is_scalar($id) || $id instanceof \Stringable ? (string) $id : '';
            }
        }

        return '';
    }
}
