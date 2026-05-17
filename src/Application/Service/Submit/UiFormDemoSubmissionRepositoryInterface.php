<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionRecord;

/**
 * Demo-only repository for FormComponent submit submissions saved
 * by {@see Action\PlatformDemoStoreContactAction}.
 *
 * **Demo storage, not a real CRM.** The default implementation is
 * cache-backed with a fixed TTL (24h). The interface intentionally
 * exposes only `save()` + `find()` + the diagnostic pair — no list,
 * no scan, no delete-by-query. Anything richer would justify a real
 * data layer; the demo deliberately does not.
 *
 * Mirrors {@see UiReplayStoreInterface} / {@see UiFormSubmitCsrfTokenStoreInterface}
 * for the diagnostic surface — same `isShared()` + `diagnosticName()`
 * contract so operators can see at a glance whether the repository
 * is single-worker (test / dev) or shared (production).
 *
 * Trust perimeter:
 *
 *   - The repository persists ONLY the {@see UiFormDemoSubmissionRecord}
 *     shape it is given. It never stores tokens, signed-ctx blobs,
 *     dispatchIds, or debug internals. The action is the
 *     sanitisation layer; the repository is a dumb sink.
 *   - `find()` exists for test assertions + future safe diagnostics.
 *     It is NOT exposed via HTTP today. Apps that want a list / export
 *     endpoint must add an explicit handler with its own auth.
 */
interface UiFormDemoSubmissionRepositoryInterface
{
    /**
     * Persist the record. Returns the same id the record carries
     * (idempotent: the caller already generated the id; the
     * repository does not re-generate it). Implementations MAY
     * clamp TTL to bounded values.
     */
    public function save(UiFormDemoSubmissionRecord $record): string;

    /**
     * Read a previously stored record by id. Returns `null` if the
     * record is missing or expired. Used by tests + safe diagnostic
     * paths. NOT used by the dispatch pipeline.
     */
    public function find(string $id): ?UiFormDemoSubmissionRecord;

    /**
     * True when reads / writes are observable across every worker /
     * process sharing this repository. Same semantics as the replay /
     * CSRF stores.
     */
    public function isShared(): bool;

    /**
     * Short human-readable identifier — `"cache-backed (driver=redis)"`,
     * `"in-memory (worker-local)"`, etc. Surfaced in diagnostic
     * payloads only; MUST NOT leak secrets or class FQCNs.
     */
    public function diagnosticName(): string;
}
