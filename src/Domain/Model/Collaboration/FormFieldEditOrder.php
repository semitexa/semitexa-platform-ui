<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Collaboration;

/**
 * Where a sequenced field edit stands against what the draft already holds
 * from the same source (one page of one participant) for the same field.
 *
 * @see \Semitexa\PlatformUi\Domain\Contract\FormCollabOrderedDraftStoreInterface
 */
enum FormFieldEditOrder: string
{
    /** Newer than anything stored from this source: written. */
    case Applied = 'applied';

    /** The very edit stored last from this source, arriving again: nothing written. */
    case Repeated = 'repeated';

    /** Older than an edit already stored from this source: refused. */
    case Superseded = 'superseded';
}
