<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

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
 *   - `feedCtx` carries the trusted `cfg.scope/mode` the `/__ui/form-doc` feed
 *     verifies (read trust);
 *   - each per-event token (`events['field.edit']`, `events['presence.ping']`,
 *     …) carries the `(c, p, e)` triple the `/__ui/event` dispatcher routes by
 *     PLUS the same `cfg`, so the inbound handler reads scope/mode from a signed
 *     claim, never the spoofable body (write trust). One token per semantic
 *     event because the dispatcher resolves the `#[HandlesUiEvent]` binding from
 *     the signed `p`/`e` — they cannot share a blob.
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
    public const FEED_URL = '/__ui/form-doc';
    public const EVENT_URL = '/__ui/event';
    public const HEARTBEAT_MS = 15000;

    /**
     * @param list<string> $fields the field names the runtime manages (emit + apply)
     * @return array<string, mixed> the JSON shape `form-collab-runtime.js` consumes
     */
    public function build(
        string $componentName,
        string $instanceId,
        string $formKey,
        string $recordId,
        string $mode,
        array $fields,
        ?int $ttlSeconds = null,
    ): array {
        $resolvedMode = FormCollaborationMode::tryFrom($mode) ?? FormCollaborationMode::default();
        $scope = FormDocumentScope::forRecord($formKey, $recordId);
        $fieldNames = array_values(array_map(static fn ($f): string => (string) $f, $fields));
        $cfg = ['scope' => $scope, 'mode' => $resolvedMode->value];

        // Read-side token: cfg + the managed field names so the projector can
        // resolve per-field locks (FieldLock) from a TRUSTED list — the field set
        // rides the signed token, never a spoofable request body. The feed ignores
        // p/e and verifies scope/mode/fields.
        $feedCtx = SignedContext::sign(
            ['i' => $instanceId, 'cfg' => $cfg + ['fields' => $fieldNames]],
            $ttlSeconds,
        );

        // Write-side tokens: one per (part, event) the mode uses, each routable.
        // The managed field list rides the SIGNED cfg here too (not just the
        // read-side feedCtx): the handler enforces field.edit / form.save keys
        // against this trusted allow-list, so a valid write token can't be used to
        // inject arbitrary field names into the shared draft.
        $writeCfg = $cfg + ['fields' => $fieldNames];
        $events = [];
        foreach (self::semanticEventsFor($resolvedMode) as [$part, $event]) {
            $events[$part . '.' . $event] = SignedContext::sign([
                'c'   => $componentName,
                'i'   => $instanceId,
                'p'   => $part,
                'e'   => $event,
                'cfg' => $writeCfg,
            ], $ttlSeconds);
        }

        return [
            'v'           => self::SCHEMA_VERSION,
            'i'           => $instanceId,
            'scope'       => $scope,
            'mode'        => $resolvedMode->value,
            'self'        => self::resolveSelf($instanceId),
            'feedUrl'     => self::FEED_URL,
            'feedCtx'     => $feedCtx,
            'eventUrl'    => self::EVENT_URL,
            'events'      => $events,
            'fields'      => array_values(array_map(static fn ($f): string => (string) $f, $fields)),
            'heartbeatMs' => self::HEARTBEAT_MS,
        ];
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
        $store = '\Semitexa\Auth\Context\AuthContextStore';
        if (class_exists($store) && method_exists($store, 'getUser')) {
            /** @var object|null $user */
            $user = $store::getUser();
            if (is_object($user) && method_exists($user, 'getId')) {
                $id = (string) $user->getId();
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return $instanceId !== '' ? $instanceId : 'anonymous';
    }
}
