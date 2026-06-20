<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Application\Component\Builtin\CollaborativeFormComponent;
use Semitexa\PlatformUi\Attribute\HandlesUiEvent;
use Semitexa\PlatformUi\Domain\Contract\FormCollabDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormLockStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormPresenceStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\UiEventHandlerInterface;
use Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventError;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;
use Semitexa\Ssr\Domain\Contract\ScopeInvalidatorInterface;
use Semitexa\Ssr\Domain\Model\FormDocumentScope;

/**
 * Collaborative Form Data · Phase 3 — the inbound command handler for live
 * collaborative-form events. The "command" half of the CQRS split: it mutates
 * the draft / presence / lock stores and then TOUCHES the document scope; the
 * resulting state re-projection rides the document feed's Track-R re-run
 * ({@see \Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseDocumentFeedHandler}),
 * exactly as a grid mutation re-projects through its collection feed. The
 * handler therefore returns a thin ack (or a typed error) — never the new
 * state, which every editor receives over SSE.
 *
 * Wired to the dispatcher via `#[HandlesUiEvent]` bindings on a collaborative
 * form component (added with the client runtime in a later phase, when the
 * component + template + signed manifest exist). The trust boundary:
 *   - `scope` and `mode` are read from the SIGNED `cfg` claim (the page minted
 *     them, HMAC-protected) — never from the spoofable request body;
 *   - the actor identity comes from the verified auth context, never the body;
 *   - only the field name + value + version come from the request body.
 *
 * Semantic events handled (`{part}.{event}`): `field.edit`, `form.save`,
 * `lock.acquire`, `lock.release`, `lock.heartbeat`, `presence.ping`.
 */
#[AsService]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'field', event: 'edit')]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'presence', event: 'ping')]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'lock', event: 'acquire')]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'lock', event: 'release')]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'lock', event: 'heartbeat')]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'form', event: 'save')]
final class FormCollaborationEventHandler implements UiEventHandlerInterface
{
    #[InjectAsReadonly]
    protected FormCollabDraftStoreInterface $draftStore;

    #[InjectAsReadonly]
    protected FormPresenceStoreInterface $presenceStore;

    #[InjectAsReadonly]
    protected FormLockStoreInterface $lockStore;

    #[InjectAsReadonly]
    protected ScopeInvalidatorInterface $scopeInvalidator;

    /** Test seam — production path uses property injection. */
    public function withCollaborationDeps(
        FormCollabDraftStoreInterface $draftStore,
        FormPresenceStoreInterface $presenceStore,
        FormLockStoreInterface $lockStore,
        ScopeInvalidatorInterface $scopeInvalidator,
    ): self {
        $this->draftStore = $draftStore;
        $this->presenceStore = $presenceStore;
        $this->lockStore = $lockStore;
        $this->scopeInvalidator = $scopeInvalidator;
        return $this;
    }

    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        $cfg = $context->signedClaims['cfg'] ?? [];
        $scope = is_array($cfg) ? (string) ($cfg['scope'] ?? '') : '';
        if (!FormDocumentScope::isValid($scope)) {
            return self::error('invalid_scope', 'The collaborative document scope is missing or malformed.');
        }

        $mode = FormCollaborationMode::tryFrom(is_array($cfg) ? (string) ($cfg['mode'] ?? '') : '')
            ?? FormCollaborationMode::default();

        [$actorId, $actorLabel] = self::resolveActor($context);
        $request = is_array($context->request) ? $context->request : [];

        try {
            return match ($context->semanticEvent) {
                'field.edit'      => $this->onFieldEdit($scope, $mode, $request, $actorId, $actorLabel),
                'form.save'       => $this->onFormSave($scope, $request, $actorId),
                'lock.acquire'    => $this->onLockAcquire($scope, $request, $actorId, $actorLabel),
                'lock.release'    => $this->onLockRelease($scope, $request, $actorId),
                'lock.heartbeat'  => $this->onLockHeartbeat($scope, $request, $actorId),
                'presence.ping'   => $this->onPresencePing($scope, $request, $actorId, $actorLabel),
                default           => self::error('unknown_collab_event', 'Unsupported collaborative event.'),
            };
        } catch (FormDraftVersionConflictException $e) {
            return self::error('form_draft_version_conflict', $e->getMessage(), $e->getErrorContext());
        } catch (\Throwable) {
            return self::error('collab_error', 'The collaborative action could not be completed.');
        }
    }

    /**
     * Live per-field edit (Shared / FieldLock; tolerated as autosave in
     * Optimistic). Gated by the mode policy against the current lock state.
     *
     * @param array<string, mixed> $request
     */
    private function onFieldEdit(string $scope, FormCollaborationMode $mode, array $request, string $actorId, string $actorLabel): UiEventResponse
    {
        $field = (string) ($request['field'] ?? '');
        if ($field === '') {
            return self::error('missing_field', 'A field name is required for a field edit.');
        }

        $formLock = $this->lockStore->current($scope, null);
        $fieldLock = $this->lockStore->current($scope, $field);
        if (!FormCollaborationPolicy::canEditField($mode, $formLock, $fieldLock, $actorId)) {
            $holder = $mode === FormCollaborationMode::FieldLock ? $fieldLock : $formLock;
            return self::error('field_locked', 'This field is being edited by someone else.', [
                'field'       => $field,
                'holderId'    => $holder?->holderId,
                'holderLabel' => $holder?->holderLabel,
            ]);
        }

        // Stamp the STABLE per-participant id (not the display label) as the
        // draft origin: it is the echo-suppression coordinate the client
        // compares against its own `self`. Anonymous editors share the label
        // "Guest" but get distinct ids, so a guest never suppresses another
        // guest's delta. The friendly label still rides presence (presence.ping).
        $this->draftStore->mergeFields($scope, [$field => self::scalarOrNull($request['value'] ?? null)], $actorId);
        $this->scopeInvalidator->touch($scope);

        return self::ack();
    }

    /**
     * Optimistic full save under the version guard. A stale version throws
     * {@see FormDraftVersionConflictException}, mapped to a typed error above.
     *
     * @param array<string, mixed> $request
     */
    private function onFormSave(string $scope, array $request, string $actorId): UiEventResponse
    {
        $values = is_array($request['values'] ?? null) ? self::sanitizeValues($request['values']) : [];
        $expectedVersion = (int) ($request['version'] ?? 0);

        // Stamp the STABLE per-participant id (not the display label) as the
        // draft origin, identical to onFieldEdit: it is the echo-suppression
        // coordinate the client compares against its own `self`. Passing the
        // label here made anonymous savers ("Guest") never match their own
        // `self` (the instance id), so the re-projected snapshot failed to
        // suppress the author's optimistic echo.
        $this->draftStore->apply($scope, $values, $expectedVersion, $actorId);
        $this->scopeInvalidator->touch($scope);

        return self::ack();
    }

    /**
     * Acquire a lock (whole-form when no `field` in the body, per-field
     * otherwise). The new lock state re-projects via the feed; a denial returns
     * a typed error so the client can show "locked by X" immediately.
     *
     * @param array<string, mixed> $request
     */
    private function onLockAcquire(string $scope, array $request, string $actorId, string $actorLabel): UiEventResponse
    {
        $field = self::optionalField($request);
        $outcome = $this->lockStore->acquire($scope, $field, $actorId, $actorLabel);
        $this->scopeInvalidator->touch($scope);

        if (!$outcome->acquired) {
            return self::error('lock_unavailable', 'Locked by another participant.', [
                'field'       => $field,
                'holderId'    => $outcome->holder->holderId,
                'holderLabel' => $outcome->holder->holderLabel,
            ]);
        }

        return self::ack();
    }

    /** @param array<string, mixed> $request */
    private function onLockRelease(string $scope, array $request, string $actorId): UiEventResponse
    {
        $this->lockStore->release($scope, self::optionalField($request), $actorId);
        $this->scopeInvalidator->touch($scope);

        return self::ack();
    }

    /**
     * Renew a held lock. No scope touch — heartbeats are between holder and
     * store and must not churn every subscriber's feed. A lost lock returns a
     * typed error so the client stops editing / re-acquires.
     *
     * @param array<string, mixed> $request
     */
    private function onLockHeartbeat(string $scope, array $request, string $actorId): UiEventResponse
    {
        $held = $this->lockStore->heartbeat($scope, self::optionalField($request), $actorId);

        return $held
            ? self::ack()
            : self::error('lock_lost', 'Your lock expired or was taken over.');
    }

    /**
     * Presence heartbeat. Touches the scope so the roster re-projects to every
     * editor (cadence-bounded by the client heartbeat interval).
     *
     * @param array<string, mixed> $request
     */
    private function onPresencePing(string $scope, array $request, string $actorId, string $actorLabel): UiEventResponse
    {
        $role = (string) ($request['role'] ?? 'editor');
        $this->presenceStore->ping($scope, $actorId, $actorLabel, $role);
        $this->scopeInvalidator->touch($scope);

        return self::ack();
    }

    /**
     * The authenticated actor as [id, label]. Identity comes from the verified
     * auth context (never the body); falls back to the per-render instance id
     * for anonymous editors so distinct tabs still get distinct presence/lock
     * identities. Read defensively so a missing auth surface degrades.
     *
     * @return array{0: string, 1: string}
     */
    private static function resolveActor(UiEventContext $context): array
    {
        $store = '\Semitexa\Auth\Context\AuthContextStore';
        if (class_exists($store) && method_exists($store, 'getUser')) {
            /** @var object|null $user */
            $user = $store::getUser();
            if (is_object($user) && method_exists($user, 'getId')) {
                $id = (string) $user->getId();
                if ($id !== '') {
                    return [$id, $id];
                }
            }
        }

        $instance = (string) ($context->signedClaims['i'] ?? '');
        $id = $instance !== '' ? $instance : 'anonymous';

        return [$id, 'Guest'];
    }

    private static function optionalField(array $request): ?string
    {
        $field = $request['field'] ?? null;

        return is_string($field) && $field !== '' ? $field : null;
    }

    /**
     * @param array<mixed> $values
     * @return array<string, scalar|null>
     */
    private static function sanitizeValues(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $clean[$key] = self::scalarOrNull($value);
            }
        }

        return $clean;
    }

    /** @return scalar|null */
    private static function scalarOrNull(mixed $value): int|float|string|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    private static function ack(): UiEventResponse
    {
        return UiEventResponse::ok();
    }

    /** @param array<string, mixed> $details */
    private static function error(string $code, string $message, array $details = []): UiEventResponse
    {
        return UiEventResponse::error(new UiEventError($code, $message, $details));
    }
}
