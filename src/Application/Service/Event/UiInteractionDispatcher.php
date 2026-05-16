<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use ReflectionClass;
use Semitexa\Core\Environment;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;
use Semitexa\PlatformUi\Application\Service\Validation\UsesUiFieldRuleRegistry;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionBadRequestException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionConfigurationException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionConflictException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionForbiddenException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionNotFoundException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Component\UiValuePath;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Throwable;

/**
 * Server-side dispatcher.
 *
 * Hardened ordering (top → bottom). Earlier steps fail closed and avoid
 * touching state the later steps rely on:
 *
 *   1. Required-field checks    — `ctx` and `dispatchId` are present.
 *   2. dispatchId format        — opaque opaque ASCII identifier, length-bounded.
 *   3. Payload field guard      — reject routing-flavored / patch-flavored
 *                                 / dispatch-id smuggling keys inside `payload`.
 *   4. SignedContext::verify    — invalid / expired → 403. NO replay
 *                                 storage is written yet.
 *   5. Registry resolution      — component → part → event → updates-path
 *                                 compatibility against the signed claims.
 *   6. Replay guard claim       — sha256(ctx) + ':' + dispatchId. TTL
 *                                 bounded by (exp - now) so a claim never
 *                                 outlives the ctx that authorised it.
 *                                 Already-claimed → 409 duplicate_dispatch.
 *   7. Authorization hook       — UiInteractionAuthorizerInterface. False
 *                                 return → 403 interaction_forbidden; the
 *                                 handler is never invoked, no patches
 *                                 are returned.
 *   8. Handler invocation       — only now is the #[UiOn] method called.
 *   9. Patch validation         — patches must target the signed instance.
 *
 * The signed ctx is the ONLY source of (component, instance, part,
 * event, updates) identity. dispatchId is the ONLY thing that
 * distinguishes legitimate repeat dispatches from duplicates — the
 * signed ctx itself is reusable across multiple legitimate user actions
 * within its TTL.
 */
final class UiInteractionDispatcher
{
    /**
     * Format of a per-attempt dispatchId. Bounded to keep replay-store
     * keys small and to refuse pathological inputs. The exact entropy
     * comes from the frontend transport (currently 32 hex chars of
     * crypto-quality randomness).
     */
    private const DISPATCH_ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]{4,127}\z/';

    /**
     * Hard ceiling on the claim TTL even if a signed ctx has a longer
     * remaining lifetime. Keeps replay-store entries bounded.
     */
    private const MAX_REPLAY_TTL_SECONDS = 600;

    /** Floor so we never write a 0/negative TTL entry. */
    private const MIN_REPLAY_TTL_SECONDS = 1;

    /**
     * APP_ENV values treated as production-like for the replay-store
     * guard. Anything else (dev/local/test/staging/etc.) is treated as
     * non-production and skips the hard fail.
     *
     * @var array<string, true>
     */
    private const PRODUCTION_ENV_VALUES = [
        'prod' => true,
        'production' => true,
    ];

    /**
     * Production-like marker. When true, the dispatcher refuses to
     * invoke handlers if the bound replay store is not shared across
     * workers. Resolved at construction time from APP_ENV when the
     * caller passes null; tests pass an explicit boolean to drive both
     * branches deterministically.
     */
    private readonly bool $productionLike;

    /**
     * Active rule registry handed to components that opt in via
     * UsesUiFieldRuleRegistry. Null means "fall back to the static
     * UiFieldRuleRegistry holder", which itself lazily-defaults to
     * DefaultUiFieldRuleRegistry. Production wiring fills this
     * through UiDispatchHandler with the container-bound winner of
     * UiFieldRuleRegistryInterface; tests pass an explicit registry
     * here to drive end-to-end paths.
     */
    private readonly ?UiFieldRuleRegistryInterface $ruleRegistry;

    public function __construct(
        private readonly UiPayloadFieldGuard $payloadGuard = new UiPayloadFieldGuard(),
        private readonly UiPatchValidator $patchValidator = new UiPatchValidator(),
        private readonly UiReplayStoreInterface $replayStore = new InMemoryUiReplayStore(),
        private readonly UiInteractionAuthorizerInterface $authorizer = new AllowAllUiInteractionAuthorizer(),
        ?bool $productionLike = null,
        ?UiFieldRuleRegistryInterface $ruleRegistry = null,
    ) {
        $this->productionLike = $productionLike ?? self::detectProductionLikeFromEnv();
        $this->ruleRegistry = $ruleRegistry;
    }

    private static function detectProductionLikeFromEnv(): bool
    {
        $env = Environment::getEnvValue('APP_ENV', 'prod');
        return isset(self::PRODUCTION_ENV_VALUES[strtolower(trim((string) $env))]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws UiInteractionException
     */
    public function dispatch(string $ctx, string $dispatchId, array $payload): UiInteractionResult
    {
        if ($ctx === '') {
            throw new UiInteractionBadRequestException(
                'missing_ctx',
                'Request body must include a non-empty "ctx" field.',
            );
        }

        if ($dispatchId === '') {
            throw new UiInteractionBadRequestException(
                'missing_dispatch_id',
                'Request body must include a non-empty "dispatchId" field.',
            );
        }

        if (preg_match(self::DISPATCH_ID_PATTERN, $dispatchId) !== 1) {
            throw new UiInteractionBadRequestException(
                'invalid_dispatch_id',
                'The "dispatchId" must be 5–128 characters of [A-Za-z0-9_-] starting with an alphanumeric.',
            );
        }

        // Scrub forbidden routing-flavored keys BEFORE we even verify the
        // signature. A malformed/tampered ctx must still emit a 400 if the
        // payload tries to smuggle a handler — keeps the failure pattern
        // stable for clients regardless of which check fires first.
        $this->payloadGuard->assertSafe($payload);

        $claims = SignedContext::verify($ctx);
        if ($claims === null) {
            throw new UiInteractionForbiddenException(
                'invalid_signed_ctx',
                'Signed context failed verification or is expired.',
            );
        }

        $componentName = $this->stringClaim($claims, 'c');
        $instanceId    = $this->stringClaim($claims, 'i');
        $partName      = $this->stringClaim($claims, 'p');
        $eventName     = $this->stringClaim($claims, 'e');
        $issuedAt      = $this->intClaim($claims, 'iat');
        $expiresAt     = $this->intClaim($claims, 'exp');

        $metadata = UiComponentRegistry::get($componentName);
        if ($metadata === null) {
            throw new UiInteractionNotFoundException(
                'unknown_component',
                'Signed component identity does not match any registered Platform UI component.',
            );
        }

        $part = $metadata->part($partName);
        if ($part === null) {
            throw new UiInteractionNotFoundException(
                'unknown_part',
                'Signed part does not exist on the component.',
            );
        }

        $eventMeta = $metadata->event($partName, $eventName);
        if ($eventMeta === null) {
            throw new UiInteractionNotFoundException(
                'unknown_event',
                'Signed (part, event) pair has no #[UiOn] declaration on the component.',
            );
        }

        $signedUpdates = isset($claims['u']) && is_string($claims['u']) && $claims['u'] !== ''
            ? $claims['u']
            : null;
        $expectedUpdates = $eventMeta->updatesPath !== null
            ? (string) $eventMeta->updatesPath
            : null;

        if ($signedUpdates !== $expectedUpdates) {
            throw new UiInteractionForbiddenException(
                'updates_path_mismatch',
                'Signed updates path does not match the registered #[UiOn] handler.',
            );
        }

        // Runtime configuration guard. In production-like environments
        // a non-shared replay store cannot detect duplicates that land
        // on a different Swoole worker — so we refuse to invoke the
        // handler. The check runs AFTER ctx verification so we never
        // leak a "configuration error" response on tampered ctx (those
        // get the documented 403 first) and BEFORE the replay claim so
        // the unsafe store never accumulates orphaned keys.
        if ($this->productionLike && !$this->replayStore->isShared()) {
            throw new UiInteractionConfigurationException(
                'ui_replay_store_not_shared',
                'UI dispatch replay protection requires a shared cache backend.',
            );
        }

        // Replay guard. Runs AFTER ctx verification so an invalid /
        // expired ctx never writes to the replay store.
        $remaining = $expiresAt - time();
        $ttl = $remaining < self::MIN_REPLAY_TTL_SECONDS
            ? self::MIN_REPLAY_TTL_SECONDS
            : min($remaining, self::MAX_REPLAY_TTL_SECONDS);
        $replayKey = $this->replayKey($ctx, $dispatchId);
        if (!$this->replayStore->claim($replayKey, $ttl)) {
            throw new UiInteractionConflictException(
                'duplicate_dispatch',
                'Dispatch id has already been processed for this signed context.',
            );
        }

        $updatesPath = $expectedUpdates !== null ? UiValuePath::parse($expectedUpdates) : null;

        // Extract the optional `cfg` claim — server-trusted per-event
        // configuration the component signed into the manifest at
        // render time. The HMAC verification above already authenticated
        // it; we just need to defensively coerce its shape.
        $config = [];
        if (isset($claims['cfg']) && is_array($claims['cfg'])) {
            /** @var array<string, mixed> $config */
            $config = $claims['cfg'];
        }

        // Extract the optional client-submitted form value snapshot.
        // The guard above already scrubbed routing-flavored keys
        // anywhere in the payload tree; this extractor enforces the
        // snapshot's own narrow shape (safe-identifier keys, scalar
        // values, bounded count + value length). The result is
        // **UX-feedback input** for cross-field rules — never
        // authoritative state.
        $formValues = (new UiFormPayloadSnapshot())->extract($payload);

        $event = new UiInteractionEvent(
            componentName: $componentName,
            instanceId: $instanceId,
            partName: $partName,
            eventName: $eventName,
            updatesPath: $updatesPath,
            payload: $payload,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            claims: $claims,
            dispatchId: $dispatchId,
            config: $config,
            formValues: $formValues,
        );

        // Authorization hook. Runs AFTER replay claim so a denied attempt
        // still consumes its dispatchId — the client must mint a fresh id
        // before retrying with a different (or richer) credential set.
        if (!$this->authorizer->authorize($event, $metadata, $eventMeta)) {
            throw new UiInteractionForbiddenException(
                'interaction_forbidden',
                'Authorization policy denied this UI interaction.',
            );
        }

        $instance = $this->instantiate($eventMeta->class);

        // Components that opt into rule-registry-aware validation
        // (via UsesUiFieldRuleRegistry) receive the active registry
        // before their handler runs. This is the documented
        // transitional bridge for components that are instantiated
        // by reflection — once Semitexa lands DI-managed component
        // instances, the bridge can drop and components can use
        // #[InjectAsReadonly] directly.
        if ($instance instanceof UsesUiFieldRuleRegistry) {
            $instance = $instance->withFieldRuleRegistry(
                $this->ruleRegistry ?? UiFieldRuleRegistry::getActive(),
            );
        }

        try {
            /** @var mixed $rawResult */
            $rawResult = $instance->{$eventMeta->methodName}($event);
        } catch (UiInteractionException $e) {
            // Handlers may opt to raise a typed UiInteractionException —
            // propagate as-is so the endpoint maps to the chosen HTTP status.
            throw $e;
        } catch (Throwable $e) {
            // Any other handler-side throwable is wrapped as 422 with a
            // safe message — the endpoint MUST NOT leak details.
            throw new UiInteractionUnprocessableException(
                'handler_error',
                'The declared handler threw while processing this event.',
            );
        }

        return $this->normaliseResult(
            $rawResult,
            $instanceId,
            $this->collectSignedAuxInstances($config, $instanceId),
        );
    }

    /**
     * Collect additional instance ids embedded in the verified
     * config claim. Anything that survived HMAC verification AND
     * matches the safe Platform UI instance-id shape is, by
     * construction, server-signed — the patch validator may safely
     * accept patches targeting it alongside the primary signed
     * instance.
     *
     * Generic walk (no FormComponent-specific knowledge in the
     * dispatcher) — recurses into nested arrays/lists.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function collectSignedAuxInstances(array $config, string $primary): array
    {
        $found = [];
        $walk = static function (mixed $node) use (&$walk, &$found): void {
            if (is_string($node)) {
                if (UiInstanceIdGenerator::isSafe($node)) {
                    $found[$node] = true;
                }
                return;
            }
            if (is_array($node)) {
                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };
        $walk($config);
        unset($found[$primary]);
        return array_keys($found);
    }

    private function replayKey(string $ctx, string $dispatchId): string
    {
        return hash('sha256', $ctx) . ':' . $dispatchId;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function stringClaim(array $claims, string $key): string
    {
        $value = $claims[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new UiInteractionUnprocessableException(
                'missing_claim_' . $key,
                'Signed context is missing the required "' . $key . '" claim.',
            );
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function intClaim(array $claims, string $key): int
    {
        $value = $claims[$key] ?? null;
        if (!is_int($value)) {
            throw new UiInteractionUnprocessableException(
                'missing_claim_' . $key,
                'Signed context is missing the required "' . $key . '" claim.',
            );
        }
        return $value;
    }

    private function instantiate(string $class): object
    {
        $reflection = new ReflectionClass($class);
        $ctor = $reflection->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
            throw new UiInteractionUnprocessableException(
                'cannot_instantiate_component',
                'Component class has required constructor parameters — DI-managed components are not supported in this slice.',
            );
        }
        return $reflection->newInstance();
    }

    /**
     * @param list<string> $additionalAllowedInstances
     */
    private function normaliseResult(
        mixed $raw,
        string $signedInstance,
        array $additionalAllowedInstances = [],
    ): UiInteractionResult {
        if ($raw instanceof UiInteractionResult) {
            if ($raw->patches !== []) {
                $this->patchValidator->validateAll(
                    $raw->patches,
                    $signedInstance,
                    $additionalAllowedInstances,
                );
            }
            return $raw;
        }
        if ($raw === null) {
            return UiInteractionResult::ack();
        }
        if (is_array($raw)) {
            return UiInteractionResult::ack($raw);
        }
        throw new UiInteractionUnprocessableException(
            'invalid_handler_return',
            'Handler must return void, an array, or a UiInteractionResult.',
        );
    }
}
