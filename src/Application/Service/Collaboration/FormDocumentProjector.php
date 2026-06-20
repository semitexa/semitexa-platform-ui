<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Domain\Contract\FormCollabDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormLockStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormPresenceStoreInterface;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormDocumentSnapshot;

/**
 * Collaborative Form Data · Phase 3 (Shared mode) — the READ half of the
 * CQRS split: it assembles the live shared state of one collaborative document
 * (the merged draft + version + last-writer origin + the presence roster) into
 * a {@see FormDocumentSnapshot}.
 *
 * The inverse of {@see FormCollaborationEventHandler}: where the handler MUTATES
 * the stores and touches the scope, the projector READS the stores and renders.
 * It is the body the (later) concrete document feed handler's
 * `buildDocumentResponse()` calls — every time a field edit / presence ping
 * touches `formdoc:{key}:{id}`, each held-open subscriber re-runs the feed,
 * which re-projects through here and pushes the fresh snapshot. "Presence roster
 * rendered from FormPresenceStore" is exactly this read.
 *
 * Read-only and store-driven (no mutation of its own), so it is trivially
 * unit-testable against the in-memory / cache-backed store fakes. A document
 * with no draft yet projects an empty record at version 0 — a freshly opened
 * collaborative form is a valid, renderable shared state, not an error.
 */
#[AsService]
final class FormDocumentProjector
{
    #[InjectAsReadonly]
    protected FormCollabDraftStoreInterface $draftStore;

    #[InjectAsReadonly]
    protected FormPresenceStoreInterface $presenceStore;

    #[InjectAsReadonly]
    protected FormLockStoreInterface $lockStore;

    /** Test seam — production path uses property injection. */
    public function withStores(
        FormCollabDraftStoreInterface $draftStore,
        FormPresenceStoreInterface $presenceStore,
        ?FormLockStoreInterface $lockStore = null,
    ): self {
        $this->draftStore = $draftStore;
        $this->presenceStore = $presenceStore;
        if ($lockStore !== null) {
            $this->lockStore = $lockStore;
        }
        return $this;
    }

    /**
     * Project the document's current shared state for `$scope` under `$mode`.
     * The draft supplies the merged field values + version + origin; the
     * presence store supplies the live roster; the lock store supplies the live
     * locks (whole-form for FormLock; per-field for FieldLock, for the `$fields`
     * the caller declares). The lock-free modes project no locks.
     *
     * @param list<string> $fields field names to resolve per-field locks for
     *        (FieldLock only); ignored by the other modes.
     */
    public function project(string $scope, FormCollaborationMode $mode, array $fields = []): FormDocumentSnapshot
    {
        $draft = $this->draftStore->load($scope);

        return new FormDocumentSnapshot(
            scopeKey:  $scope,
            values:    $draft?->values ?? [],
            version:   $draft?->version ?? 0,
            origin:    $draft?->updatedBy,
            updatedAt: $draft?->updatedAt ?? 0,
            presence:  $this->presenceStore->roster($scope),
            mode:      $mode,
            locks:     $this->resolveLocks($scope, $mode, $fields),
        );
    }

    /**
     * The live locks for `$scope` under `$mode`: the whole-form lock for FormLock,
     * the per-field locks for FieldLock's declared `$fields`, none otherwise.
     *
     * @param list<string> $fields
     * @return list<\Semitexa\PlatformUi\Domain\Model\Collaboration\FormLock>
     */
    private function resolveLocks(string $scope, FormCollaborationMode $mode, array $fields): array
    {
        if (!isset($this->lockStore) || !$mode->usesLock()) {
            return [];
        }

        $locks = [];
        if ($mode === FormCollaborationMode::FormLock) {
            $whole = $this->lockStore->current($scope, null);
            if ($whole !== null) {
                $locks[] = $whole;
            }

            return $locks;
        }

        // FieldLock: resolve each declared field's lock.
        foreach ($fields as $field) {
            $name = (string) $field;
            if ($name === '') {
                continue;
            }
            $lock = $this->lockStore->current($scope, $name);
            if ($lock !== null) {
                $locks[] = $lock;
            }
        }

        return $locks;
    }
}
