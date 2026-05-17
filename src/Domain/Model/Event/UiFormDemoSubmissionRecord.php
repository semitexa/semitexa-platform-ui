<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * One record persisted by the demo "store contact" submit action.
 *
 * Deliberately narrow: ONLY the allow-listed sanitised values, a
 * generated id, the form instance the submission came from, the
 * action name, and a Unix submitted-at timestamp.
 *
 * Excluded by construction:
 *
 *   - the raw SignedContext blob;
 *   - the raw / hashed CSRF token (id or value);
 *   - the dispatchId (already replay-protected);
 *   - the request payload / debug map;
 *   - any caller-provided field name outside the action's allow-list.
 *
 * The id shape is `uifs_<16hex>` — UI form submission, matches the
 * other `<prefix>_<hex>` shapes in this package (uci_, frm_, uicsrf_)
 * for greppable operator logs.
 *
 * Demo cache record: this class is the only thing the demo repository
 * stores; the repository's `find($id)` returns this same shape so
 * tests can assert exact contents.
 */
final readonly class UiFormDemoSubmissionRecord
{
    /**
     * @param array<string, scalar|null> $values Already trimmed +
     *        allow-listed by the action. The repository is not the
     *        sanitisation layer; it stores what it's given verbatim.
     */
    public function __construct(
        public string $id,
        public string $formInstanceId,
        public string $actionName,
        public int    $submittedAt,
        public array  $values,
    ) {}
}
