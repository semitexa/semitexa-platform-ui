<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Domain\Contract\FormCollabDraftStoreInterface;
use Semitexa\Ssr\Domain\Contract\ScopeInvalidatorInterface;
use Semitexa\Ssr\Domain\Model\FormDocumentScope;

/**
 * What an app calls once it has persisted the record a collaborative form
 * edits: the shared draft is over.
 *
 * In Shared, FormLock and FieldLock mode the save is the app's own POST, which
 * this package never sees. Until the app says so here, the draft keeps the
 * edits that were just saved (and the ones that were not), and writes them
 * into the inputs of the next person who opens the form.
 *
 *     $this->orders->save($order);            // committed
 *     $this->collabDrafts->discardForRecord('order', $order->getId(), [
 *         'title' => $order->getTitle(),
 *         'notes' => $order->getNotes(),
 *     ]);
 *
 * It deletes the draft, leaves a one-time notice for the editors who have the
 * form open ({@see FormCollabSaveNotices}) and touches the document scope, the
 * signal a field edit sends, so the notice reaches them on their stream. No
 * draft exists afterwards: the page a newcomer loads shows the record itself.
 *
 * What an open editor then sees depends on `$persistedValues`:
 *   - given: each field named in it is set to the stored value. A field the
 *     editor is typing in keeps their text, which is sent on as a new edit.
 *     Fields NOT named keep whatever the tab showed, so pass EVERY managed
 *     field (the `fields` the page gave `platform.collab-form`);
 *   - not given: open tabs keep what their inputs show, as any web form does
 *     when the record changes under it, and the page gets the DOM event
 *     `semitexa:collab:reset` (`detail.rebased` false) to act on if it wants.
 * An Optimistic editor with unsaved edits meets the conflict prompt on their
 * next save either way: it was based on a draft that is gone.
 *
 * Collaboration needs a SHARED cache (redis): presence, locks, heartbeat marks
 * and these notices live there. With a per-worker array cache each worker has
 * its own, so a notice reaches only the editors that worker serves.
 */
#[AsService]
final class FormCollaborationDrafts
{
    #[InjectAsReadonly]
    protected FormCollabDraftStoreInterface $draftStore;

    #[InjectAsReadonly]
    protected ScopeInvalidatorInterface $scopeInvalidator;

    #[InjectAsReadonly]
    protected FormCollabSaveNotices $notices;

    /** Test seam — production path uses property injection. */
    public function with(FormCollabDraftStoreInterface $draftStore, ScopeInvalidatorInterface $scopeInvalidator, FormCollabSaveNotices $notices): self
    {
        $this->draftStore = $draftStore;
        $this->scopeInvalidator = $scopeInvalidator;
        $this->notices = $notices;

        return $this;
    }

    /**
     * The record `$recordId` of form `$formKey` (the two props the page gave
     * `platform.collab-form`) was persisted.
     *
     * Call it AFTER the transaction commits: a draft discarded for a save that
     * then rolls back is an edit lost, and editors re-based on values that
     * were never stored. Call it with the request's tenant context: the draft
     * and the scope touch are the current tenant's, so a queue worker or a CLI
     * command must run inside the tenant whose record it saved, or it ends
     * nothing and tells nobody.
     *
     * Pass every managed field's persisted value so the open editors re-base
     * on what was stored; without values they keep their inputs and the page
     * gets `semitexa:collab:reset`.
     *
     * @param array<string, scalar|null> $persistedValues EVERY managed field name → its stored value
     */
    public function discardForRecord(string $formKey, string $recordId, array $persistedValues = []): void
    {
        $this->discard(FormDocumentScope::forRecord($formKey, $recordId), $persistedValues);
    }

    /**
     * The same by scope key (`formdoc:{formKey}:{recordId}` or `form:{instanceId}`),
     * under the same two conditions.
     *
     * @param array<string, scalar|null> $persistedValues EVERY managed field name → its stored value
     */
    public function discard(string $scopeKey, array $persistedValues = []): void
    {
        if (!FormDocumentScope::isValid($scopeKey)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a collaborative document scope.', $scopeKey));
        }

        // The notice first: a frame pushed between the two steps then carries
        // the notice with the dying draft, which the runtime knows to ignore;
        // the other order would show the editors a draft that ended for no
        // reason they were given.
        $this->notices->publish($scopeKey, $persistedValues === [] ? null : $persistedValues);
        $this->draftStore->discard($scopeKey);
        $this->scopeInvalidator->touch($scopeKey);
    }
}
