<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\PlatformUi\Application\Component\Builtin\CollaborativeFormComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionAuthorizerInterface;
use Semitexa\PlatformUi\Attribute\HandlesUiEvent;
use Semitexa\PlatformUi\Domain\Contract\FormCollabDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormCollabOrderedDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormCollaboratorLabelResolverInterface;
use Semitexa\PlatformUi\Domain\Contract\FormLockStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormPresenceStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\UiEventHandlerInterface;
use Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionForbiddenException;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormFieldEditOrder;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventError;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
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
 *   - only the field name + value + version come from the request body, and
 *     an edit's sequence and runtime id, which order that actor's own edits.
 *
 * Semantic events handled (`{part}.{event}`): `field.edit`, `form.save`,
 * `lock.acquire`, `lock.release`, `lock.heartbeat`, `presence.ping`, and
 * `manifest.renew`, which trades the manifest's renewal token for fresh event
 * tokens ({@see CollabManifestBuilder::renewed()}).
 */
#[AsService]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'field', event: 'edit')]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'presence', event: 'ping')]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'lock', event: 'acquire')]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'lock', event: 'release')]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'lock', event: 'heartbeat')]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'form', event: 'save')]
#[HandlesUiEvent(component: CollaborativeFormComponent::class, part: 'manifest', event: 'renew')]
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

    /** Asked, at a renewal, about every event whose context is signed again. */
    #[InjectAsReadonly]
    protected UiInteractionAuthorizerInterface $authorizer;

    /** Asked for the app's optional {@see FormCollaboratorLabelResolverInterface}. */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /** The reason a save from a runtime that predates the draft epoch gets. */
    public const OUTDATED_CLIENT = 'collab_client_outdated';

    /** The reason a renewal gets once the visitor is not who the form was drawn for. */
    public const RENEW_SUBJECT_CHANGED = 'collab_renew_subject_changed';

    /** The reason a renewal gets (403) once the authorizer denies an event of the form. */
    public const RENEW_FORBIDDEN = 'collab_renew_forbidden';

    /**
     * The reason a field edit gets when the draft already holds a newer edit
     * of that field from the same page. An answer, not a failure: the newer
     * value is stored, and the page does not send this one again.
     */
    public const EDIT_SUPERSEDED = 'field_edit_superseded';

    /** A label is shown in a chip: one line, no control or bidi characters, this long at most. */
    public const LABEL_MAX_LENGTH = 64;

    /** @var (\Closure(): ?object)|null where the signed-in user is read from; null = the auth context */
    private ?\Closure $identitySource = null;

    private ?FormCollaboratorLabelResolverInterface $labelResolver = null;

    /**
     * Test seam: the signed-in user (in place of the auth context) and the
     * app's label resolver (in place of the container lookup).
     *
     * @param (\Closure(): ?object)|null $identitySource
     */
    public function withIdentity(?\Closure $identitySource, ?FormCollaboratorLabelResolverInterface $labelResolver = null): self
    {
        $this->identitySource = $identitySource;
        $this->labelResolver = $labelResolver;
        return $this;
    }

    /** Test seam — production path uses property injection. */
    public function withAuthorizer(UiInteractionAuthorizerInterface $authorizer): self
    {
        $this->authorizer = $authorizer;
        return $this;
    }

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
        $scope = is_array($cfg) ? self::stringOrEmpty($cfg['scope'] ?? '') : '';
        if (!FormDocumentScope::isValid($scope)) {
            return self::error('invalid_scope', 'The collaborative document scope is missing or malformed.');
        }

        $mode = FormCollaborationMode::tryFrom(is_array($cfg) ? self::stringOrEmpty($cfg['mode'] ?? '') : '')
            ?? FormCollaborationMode::default();

        // The managed-field allow-list rides the SIGNED cfg; write events
        // (field.edit / form.save) are constrained to it so a valid token can't
        // inject arbitrary field names into the shared draft.
        $allowedFields = self::allowedFields($cfg);

        // Outside the catch-all below: its refusal is a 403 of its own.
        if ($context->semanticEvent === 'manifest.renew') {
            return $this->onManifestRenew($context->signedClaims, $mode);
        }

        [$actorId, $actorLabel] = $this->resolveActor($context);
        $request = is_array($context->request) ? $context->request : [];

        try {
            return match ($context->semanticEvent) {
                'field.edit'      => $this->onFieldEdit($scope, $mode, $request, $actorId, $actorLabel, $allowedFields),
                'form.save'       => $this->onFormSave($scope, $request, $actorId, $allowedFields),
                'lock.acquire'    => $this->onLockAcquire($scope, $request, $actorId, $actorLabel),
                'lock.release'    => $this->onLockRelease($scope, $request, $actorId),
                'lock.heartbeat'  => $this->onLockHeartbeat($scope, $request, $actorId),
                'presence.ping'   => $this->onPresencePing($scope, $request, $actorId, $actorLabel),
                default           => self::error('unknown_collab_event', 'Unsupported collaborative event.'),
            };
        } catch (FormDraftVersionConflictException $e) {
            // The base a retry needs comes from one read: the later one replaces
            // both fields, and the conflict's own stand when it fails (review,
            // platform-ui#92: a union kept the earlier version beside the later epoch).
            return self::error('form_draft_version_conflict', $e->getMessage(), array_replace($e->getErrorContext(), $this->currentBase($scope)));
        } catch (\Throwable $e) {
            // A genuine failure of a collaborative WRITE (draft apply/merge, lock,
            // presence) — NOT the typed optimistic conflict above. The client is
            // told generically (internals never leak), but the server must not
            // swallow it silently: log structured so a real persist/store failure
            // is diagnosable instead of surfacing only as a client-side error.
            StaticLoggerBridge::error('platform-ui', 'Collaborative form event failed', [
                'event'     => $context->semanticEvent,
                'scope'     => $scope,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            return self::error('collab_error', 'The collaborative action could not be completed.');
        }
    }

    /**
     * What a save would have to be based on now: sent with a conflict so
     * "Keep mine" can retry without waiting for a frame.
     *
     * @return array{currentEpoch?: string, currentVersion?: int}
     */
    private function currentBase(string $scope): array
    {
        try {
            $current = $this->draftStore->load($scope);
        } catch (\Throwable) {
            return [];
        }

        return ['currentEpoch' => $current->epoch ?? '', 'currentVersion' => $current->version ?? 0];
    }

    /**
     * Live per-field edit (Shared / FieldLock; tolerated as autosave in
     * Optimistic). Gated by the mode policy against the current lock state.
     *
     * @param array<string, mixed> $request
     * @param list<string> $allowedFields the signed managed-field allow-list
     */
    private function onFieldEdit(string $scope, FormCollaborationMode $mode, array $request, string $actorId, string $actorLabel, array $allowedFields): UiEventResponse
    {
        $field = self::stringOrEmpty($request['field'] ?? '');
        if ($field === '') {
            return self::error('missing_field', 'A field name is required for a field edit.');
        }
        // Reject any field name not on the signed allow-list — the request body is
        // untrusted, so an arbitrary key must never reach the shared draft.
        if (!in_array($field, $allowedFields, true)) {
            return self::error('unknown_field', 'This field is not part of the managed form.', ['field' => $field]);
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
        $value = self::scalarOrNull($request['value'] ?? null);
        $order = $this->mergeEdit($scope, $field, $value, $actorId, $request);
        if ($order === FormFieldEditOrder::Superseded) {
            return self::error(self::EDIT_SUPERSEDED, 'A newer edit of this field is already stored.', ['field' => $field]);
        }
        if ($order === FormFieldEditOrder::Applied) {
            $this->scopeInvalidator->touch($scope);
        }

        // Repeated: the edit sent again after its answer was lost. The draft
        // has it; writing it once more could replace what someone typed since.
        return self::ack();
    }

    /**
     * Write the edit, in the order it was MADE when the page says which that
     * is: `seq` counts the values a page sent and `runtime` names the page.
     * The order they arrive in is not it: a request the page gave up on is
     * still processed, and the two a hidden page sends race each other.
     *
     * Both come from the untrusted body, so they only ever order the edits
     * of the actor the session names: the key is the actor AND the runtime.
     * A lock holder is the person, not the tab; two tabs of one person
     * count separately, and neither can refuse the other's newer edit.
     * Edits of different people stay in arrival order.
     *
     * Without both (a page from before the sequence), or with a store that
     * keeps no marks, the edit is merged as it arrives.
     *
     * @param array<string, mixed> $request
     */
    private function mergeEdit(string $scope, string $field, int|float|string|bool|null $value, string $actorId, array $request): FormFieldEditOrder
    {
        $sequence = $request['seq'] ?? null;
        $runtime = $request['runtime'] ?? null;
        if (
            $this->draftStore instanceof FormCollabOrderedDraftStoreInterface
            && is_int($sequence) && $sequence > 0
            && is_string($runtime) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $runtime) === 1
        ) {
            return $this->draftStore->mergeFieldInOrder($scope, $field, $value, $actorId, FormFieldEditMarks::source($actorId, $runtime), $sequence);
        }

        $this->draftStore->mergeFields($scope, [$field => $value], $actorId);

        return FormFieldEditOrder::Applied;
    }

    /**
     * Optimistic full save under the version guard. A stale version throws
     * {@see FormDraftVersionConflictException}, mapped to a typed error above.
     *
     * @param array<string, mixed> $request
     * @param list<string> $allowedFields the signed managed-field allow-list
     */
    private function onFormSave(string $scope, array $request, string $actorId, array $allowedFields): UiEventResponse
    {
        $values = is_array($request['values'] ?? null) ? self::sanitizeValues($request['values']) : [];
        // Drop any submitted key not on the signed allow-list before it reaches the
        // shared draft — the request body is untrusted.
        $values = array_intersect_key($values, array_flip($allowedFields));
        $version = $request['version'] ?? 0;
        $expectedVersion = is_scalar($version) ? (int) $version : 0;

        // A save names the draft it was based on (see below). One that names
        // none comes from a runtime older than that (a tab left open across a
        // deploy): its version number proves nothing, and it cannot show a
        // conflict it could resolve. It is told to reload.
        $basedOn = $request['epoch'] ?? null;
        if (!is_string($basedOn)) {
            return self::error(self::OUTDATED_CLIENT, 'This page is out of date. Reload to continue.');
        }

        // Stamp the STABLE per-participant id (not the display label) as the
        // draft origin, identical to onFieldEdit: it is the echo-suppression
        // coordinate the client compares against its own `self`. Passing the
        // label here made anonymous savers ("Guest") never match their own
        // `self` (the instance id), so the re-projected snapshot failed to
        // suppress the author's optimistic echo.
        //
        // The version restarts at 1 with every new draft (a discard, an
        // expiry), so the number alone can match a draft the client never
        // saw: the store checks the epoch on the row it writes.
        $saved = $this->draftStore->apply($scope, $values, $expectedVersion, $actorId, $basedOn);
        $this->scopeInvalidator->touch($scope);

        // The ack says what the next save is based on. The feed says it too,
        // but a client whose stream is down would otherwise conflict with its
        // own first save for ever.
        return new UiEventResponse(frontend: ['collab' => ['epoch' => $saved->epoch, 'version' => $saved->version]]);
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
        $role = is_scalar($request['role'] ?? null) ? (string) $request['role'] : 'editor';
        // Read the draft BEFORE this heartbeat counts as presence. A page sends
        // its first ping and opens its feed at once; when the ping won, the
        // draft somebody abandoned days ago looked attended again and the feed
        // wrote it into the newcomer's inputs. The read is where it expires.
        $this->draftStore->load($scope);
        $this->presenceStore->ping($scope, $actorId, $actorLabel, $role);
        $this->scopeInvalidator->touch($scope);

        return self::ack();
    }

    /**
     * Fresh event tokens for a form whose own have expired. The renewal token
     * was verified on the way here (signature, TTL, session and tenant), and
     * the application's authorizer was asked as for any event; what is left is
     * the visitor: a page signed out of, or into another account, since it was
     * drawn is reloaded instead. The tokens are signed from the signed claims
     * alone, and nothing is written.
     *
     * The authorizer was asked about the renewal; it is asked here about what
     * the renewal hands out. The renewal token lives a day and the tokens it
     * replaces five minutes: without this, somebody whose access to the record
     * ended while the tab was open kept editing and locking for the rest of
     * the day. Every event of the mode is asked as if it were being sent now
     * (the scope is in `config`), so the application's rule for edits governs
     * renewals with no rule of its own. One denial refuses the renewal (403);
     * so does a handler with no authorizer to ask.
     *
     * @param array<string, mixed> $claims
     */
    private function onManifestRenew(array $claims, FormCollaborationMode $mode): UiEventResponse
    {
        $who = $claims['who'] ?? null;
        if (!is_string($who) || !hash_equals($who, $this->currentUserId())) {
            return self::error(self::RENEW_SUBJECT_CHANGED, 'This form was opened by another visitor. Reload to continue.');
        }
        if (!$this->mayStill($claims, $mode)) {
            throw new UiInteractionForbiddenException(
                self::RENEW_FORBIDDEN,
                'Authorization policy no longer allows editing this form. Reload to continue.',
            );
        }
        $renewed = (new CollabManifestBuilder())->renewed($claims);
        if ($renewed === null) {
            return self::error('collab_renew_invalid', 'This form cannot be renewed. Reload to continue.');
        }

        return new UiEventResponse(frontend: ['collab' => ['renewed' => $renewed]]);
    }

    /**
     * Whether the authorizer allows, now, every event the renewal would sign
     * a token for.
     *
     * @param array<string, mixed> $claims verified renewal claims
     */
    private function mayStill(array $claims, FormCollaborationMode $mode): bool
    {
        $componentName = self::stringOrEmpty($claims['c'] ?? '');
        $component = UiComponentRegistry::get($componentName);
        if (!isset($this->authorizer) || $component === null) {
            return false;
        }
        /** @var array<string, mixed> $config the signed cfg: scope, mode, fields */
        $config = is_array($claims['cfg'] ?? null) ? $claims['cfg'] : [];
        $now = time();
        foreach (CollabManifestBuilder::semanticEventsFor($mode) as [$part, $event]) {
            $binding = UiComponentRegistry::getExternalBinding($componentName, $part, $event);
            if ($binding === null) {
                return false;
            }
            $asked = new UiInteractionEvent(
                componentName: $componentName,
                instanceId: self::stringOrEmpty($claims['i'] ?? ''),
                partName: $part,
                eventName: $event,
                updatesPath: null,
                payload: [],
                issuedAt: $now,
                expiresAt: $now,
                claims: ['c' => $componentName, 'i' => $claims['i'] ?? '', 'p' => $part, 'e' => $event, 'cfg' => $config],
                config: $config,
            );
            if (!$this->authorizer->authorizeExternal($asked, $component, $binding)) {
                return false;
            }
        }

        return true;
    }

    /** The signed-in user's id, '' for a guest. */
    private function currentUserId(): string
    {
        $user = $this->currentUser();

        return $user !== null && method_exists($user, 'getId') ? self::stringOrEmpty($user->getId()) : '';
    }

    /**
     * The authenticated actor as [id, label]. Identity comes from the verified
     * auth context (never the body); falls back to the per-render instance id
     * for anonymous editors so distinct tabs still get distinct presence/lock
     * identities. Read defensively so a missing auth surface degrades.
     *
     * The label is what the other editors read in the chip and in "Locked by":
     * the app's {@see FormCollaboratorLabelResolverInterface} when it binds one,
     * else the user object's own `getDisplayName()` / `getName()`, else the id.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveActor(UiEventContext $context): array
    {
        $user = $this->currentUser();
        if ($user !== null && method_exists($user, 'getId')) {
            $id = self::stringOrEmpty($user->getId());
            if ($id !== '') {
                return [$id, $this->labelOf($user, $id)];
            }
        }

        $instance = self::stringOrEmpty($context->signedClaims['i'] ?? '');
        $id = $instance !== '' ? $instance : 'anonymous';

        return [$id, 'Guest'];
    }

    private function currentUser(): ?object
    {
        if ($this->identitySource !== null) {
            return ($this->identitySource)();
        }

        // semitexa/auth is not a dependency of this package: without it every
        // editor is a guest.
        $store = '\Semitexa\Auth\Context\AuthContextStore';
        if (!class_exists($store)) {
            return null;
        }
        $user = $store::getUser();

        return is_object($user) ? $user : null;
    }

    private function labelOf(object $user, string $id): string
    {
        // A resolver or a user model that throws must not take presence and
        // locking down with it: the next source is asked instead.
        $sources = [
            fn (): mixed => $this->labelResolver()?->labelFor($id),
            static fn (): mixed => method_exists($user, 'getDisplayName') ? $user->getDisplayName() : null,
            static fn (): mixed => method_exists($user, 'getName') ? $user->getName() : null,
        ];
        foreach ($sources as $source) {
            try {
                $label = self::cleanLabel(self::stringOrEmpty($source()));
            } catch (\Throwable $e) {
                StaticLoggerBridge::error('platform-ui', 'Collaborative form label source failed', [
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
                continue;
            }
            if ($label !== '') {
                return $label;
            }
        }

        return $id;
    }

    private function labelResolver(): ?FormCollaboratorLabelResolverInterface
    {
        if ($this->labelResolver !== null) {
            return $this->labelResolver;
        }
        if (!isset($this->container) || !$this->container->has(FormCollaboratorLabelResolverInterface::class)) {
            return null;
        }
        $resolver = $this->container->get(FormCollaboratorLabelResolverInterface::class);

        return $resolver instanceof FormCollaboratorLabelResolverInterface ? $resolver : null;
    }

    /**
     * A name as one line of plain text. Control and format characters go (a
     * line break, or a right-to-left override that would let a name read as
     * someone else's), whitespace collapses, and the rest is cut to
     * {@see LABEL_MAX_LENGTH}. The two joiners stay: U+200D builds emoji
     * sequences and U+200C is spelling in Persian and several Indic scripts.
     * A label of nothing but joiners is no label. Markup is NOT stripped or
     * encoded here: a label is data, and the runtime writes it with
     * textContent / setAttribute, where `<b>` is three characters. Encoding it
     * here too would show `&lt;b&gt;`.
     */
    private static function cleanLabel(string $label): string
    {
        $label = preg_replace('/(?:(?![\x{200C}\x{200D}])[\p{C}\s])+/u', ' ', $label);
        if ($label === null) {
            return ''; // not valid UTF-8
        }
        $label = trim(mb_substr(trim($label), 0, self::LABEL_MAX_LENGTH));

        return trim($label, "\u{200C}\u{200D} ") === '' ? '' : $label;
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /** @param array<string, mixed> $request */
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

    /**
     * The trusted managed-field allow-list carried on the signed cfg. Used to
     * constrain write events to the server-owned field set.
     *
     * @param mixed $cfg the signed `cfg` claim
     * @return list<string>
     */
    private static function allowedFields(mixed $cfg): array
    {
        $fields = is_array($cfg) && is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];
        $out = [];
        foreach ($fields as $field) {
            if (is_string($field) && $field !== '') {
                $out[] = $field;
            }
        }

        return $out;
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
