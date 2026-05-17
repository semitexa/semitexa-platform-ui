<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit\Action;

use Semitexa\PlatformUi\Application\Service\Submit\UiFormDatabaseDemoSubmissionRepositoryInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionRecord;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;

/**
 * First database-backed demo submit action.
 *
 * Identical sanitisation contract as
 * {@see PlatformDemoStoreContactAction} (the cache-backed sibling) —
 * same `contact_*` allow-list, same trim-then-drop semantics, same
 * generated `uifs_<16hex>` id — but stores through the database
 * repository instead of the cache repository.
 *
 * Demo storage, not a real CRM:
 *   - default repository is
 *     {@see Db\MySQL\Repository\UiFormDemoSubmissionDbRepository}
 *     (ORM-backed, table `platform_ui_demo_submissions`).
 *   - no email, no external API, no redirect, no search/export, no
 *     admin UI, no account creation.
 *
 * Safety pipeline reached BEFORE `handle()` runs (unchanged from the
 * cache-backed action):
 *
 *   1. signed-ctx HMAC verification;
 *   2. dispatchId replay claim;
 *   3. dispatcher-level UiInteractionAuthorizerInterface;
 *   4. authoritative server-side field validation;
 *   5. action registry resolution by signed name;
 *   6. UiFormSubmitActionAuthorizerInterface allowed the attempt;
 *   7. UiFormSubmitSecurityPolicyInterface verified + CONSUMED the
 *      one-time CSRF token.
 *
 * Only after all seven gates pass do we touch the database. Invalid
 * submits, replays, authorizer denials, CSRF failures, payload
 * smuggling, and tampered ctx all skip storage cleanly — verified
 * by the dispatch test matrix.
 */
final class PlatformDemoStoreContactDbAction implements UiFormSubmitActionInterface
{
    public const NAME = 'platform.demo.storeContactDb';
    public const MESSAGE = 'Demo submission saved to the database. No external side effects were performed.';

    /**
     * Server-owned allow-list — matches the cache-backed sibling so
     * both demo actions are interchangeable from the playground's
     * perspective.
     *
     * @var list<string>
     */
    public const ALLOWED_FIELDS = [
        'contact_name',
        'contact_message',
        'contact_topic',
    ];

    public function __construct(
        private readonly UiFormDatabaseDemoSubmissionRepositoryInterface $repository,
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

        // Debug carries ONLY identifiers + counts. The explicit
        // `storage: database` lets operators correlate which sink
        // received the record without leaking class FQCNs or row
        // contents.
        return UiFormSubmitActionResult::accepted(
            message: self::MESSAGE,
            debug: [
                'stored'           => true,
                'submissionId'     => $storedId,
                'storage'          => 'database',
                'storedFieldCount' => count($sanitised),
            ],
        );
    }
}
