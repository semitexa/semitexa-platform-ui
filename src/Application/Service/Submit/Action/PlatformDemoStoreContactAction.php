<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit\Action;

use Semitexa\PlatformUi\Application\Service\Submit\UiFormDemoSubmissionRepositoryInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionRecord;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;

/**
 * First persistent demo submit action.
 *
 * Stores a sanitised {@see UiFormDemoSubmissionRecord} through
 * {@see UiFormDemoSubmissionRepositoryInterface} after the full
 * safety pipeline has cleared:
 *
 *   1. signed-ctx verification (HMAC);
 *   2. dispatchId replay guard;
 *   3. dispatcher-level UiInteractionAuthorizerInterface;
 *   4. authoritative server-side field validation;
 *   5. UiFormSubmitActionRegistryInterface resolved this class by
 *      signed action name;
 *   6. UiFormSubmitActionAuthorizerInterface allowed the attempt;
 *   7. UiFormSubmitSecurityPolicyInterface verified + CONSUMED the
 *      one-time CSRF token (default
 *      CacheBackedUiFormSubmitSecurityPolicy).
 *
 * Only after all seven gates pass does `handle()` run; therefore
 * invalid submits, replay attempts, authorizer denials, and CSRF
 * failures never reach storage.
 *
 * Sanitisation contract:
 *
 *   - The action allow-lists ONLY the three contact-shaped field
 *     names ({@see ALLOWED_FIELDS}). Anything else in the snapshot
 *     is silently dropped — even though `UiFormPayloadSnapshot`
 *     already sanitises the wire shape, an action-side allow-list
 *     is the right place to bound what gets persisted.
 *   - String values are trimmed once (whitespace stripping is
 *     idempotent + safe). Length bounds already enforced by the
 *     signed rule list on the field definitions.
 *   - Non-scalar values are dropped (the snapshot extractor would
 *     have already rejected them but defence in depth).
 *
 * Demo-grade limits (documented in primitives.md):
 *
 *   - No email, no external API, no redirect, no real CRM.
 *   - Storage is the cache-backed demo repository (24h TTL by
 *     default).
 *   - Response debug carries `submissionId` + `stored: true` but
 *     NEVER raw values.
 */
final class PlatformDemoStoreContactAction implements UiFormSubmitActionInterface
{
    public const NAME = 'platform.demo.storeContact';
    public const MESSAGE = 'Demo submission saved. No external side effects were performed.';

    /**
     * Server-owned allow-list. The action will silently drop any
     * snapshot key not present here — the playground demo uses
     * exactly these three.
     *
     * @var list<string>
     */
    public const ALLOWED_FIELDS = [
        'contact_name',
        'contact_message',
        'contact_topic',
    ];

    public function __construct(
        private readonly UiFormDemoSubmissionRepositoryInterface $repository,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult
    {
        $sanitised = [];
        foreach (self::ALLOWED_FIELDS as $field) {
            if (!array_key_exists($field, $context->values)) {
                continue;
            }
            $raw = $context->values[$field];
            if ($raw === null) {
                continue;
            }
            if (!is_scalar($raw)) {
                continue;
            }
            $trimmed = trim((string) $raw);
            if ($trimmed === '') {
                continue;
            }
            $sanitised[$field] = $trimmed;
        }

        $record = new UiFormDemoSubmissionRecord(
            id:             'uifs_' . bin2hex(random_bytes(8)),
            formInstanceId: $context->formInstanceId,
            actionName:     self::NAME,
            submittedAt:    time(),
            values:         $sanitised,
        );
        $storedId = $this->repository->save($record);

        // Debug intentionally carries ONLY ids + counts. No raw
        // values, no record contents — the same log-safety guarantee
        // the rest of the submit pipeline maintains.
        return UiFormSubmitActionResult::accepted(
            message: self::MESSAGE,
            debug: [
                'stored'            => true,
                'submissionId'      => $storedId,
                'storedFieldCount'  => count($sanitised),
            ],
        );
    }
}
