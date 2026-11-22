<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Contract;

use Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormFieldEditOrder;

/**
 * A draft store that keeps live field edits of one source in the order they
 * were made, whatever order they arrive in.
 *
 * {@see FormCollabDraftStoreInterface::mergeFields()} keeps whichever edit
 * arrives last, and the network does not deliver in order: a request the page
 * gave up on can be processed after the newer value sent behind it, and the
 * two requests a page sends as it is hidden race each other. So the page
 * numbers the values it sends, and the store keeps, WITH the draft and
 * written in the same step as the value, the highest number it stored per
 * field and source.
 *
 * A source is one page of one participant. Only edits of the same source are
 * compared: two people, or two tabs of one person, count separately and stay
 * ordered by arrival, as before.
 *
 * Optional: the inbound handler falls back to `mergeFields()` (arrival order)
 * for a store that does not implement it.
 */
interface FormCollabOrderedDraftStoreInterface extends FormCollabDraftStoreInterface
{
    /**
     * Merge one field's value unless the draft already holds an edit of
     * `$field` from `$source` numbered `$sequence` or higher. The check and
     * the write are one step: of two concurrent calls the one with the lower
     * number never ends up stored. The marks end with the draft.
     *
     * @param non-empty-string $source opaque, at most 64 bytes
     * @param positive-int $sequence
     * @throws FormDraftVersionConflictException when the draft kept moving under the merge
     */
    public function mergeFieldInOrder(
        string $scopeKey,
        string $field,
        int|float|string|bool|null $value,
        ?string $actor,
        string $source,
        int $sequence,
    ): FormFieldEditOrder;
}
