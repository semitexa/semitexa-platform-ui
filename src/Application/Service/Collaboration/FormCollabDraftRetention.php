<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\WorkerState;
use Semitexa\PlatformUi\Domain\Contract\FormPresenceStoreInterface;

/**
 * When a collaborative form's shared draft stops being an edit in progress.
 *
 * A draft is an overlay of unsaved edits on top of the record the page
 * rendered. Nothing used to end it, so an edit somebody typed and walked away
 * from was written into the inputs of whoever opened the form next, days
 * later, and their save put it into the record.
 *
 * The rule: a draft is abandoned once nobody has WRITTEN to it and nobody has
 * been PRESENT in its document for the whole window. Both, because either
 * alone loses work or keeps junk: an editor reading the form for an hour
 * writes nothing but heartbeats every 15 s, and an editor whose laptop slept
 * heartbeats nothing but did write. The draft stores ask this on every read
 * and write, and drop an abandoned draft before answering.
 *
 * The window is 30 minutes by default (`PLATFORM_UI_COLLAB_DRAFT_IDLE_MINUTES`):
 *   - far above the 30 s presence TTL, so a reload, a dropped connection or a
 *     closed laptop lid does not cost a partner the edits they are waiting on;
 *   - about one idle session, so "I'll finish after lunch" may find the draft
 *     gone, and an edit from yesterday always does.
 * 0 turns expiry off (a draft then ends only by discard()). The ceiling is the
 * day the presence store remembers a document's last heartbeat for.
 *
 * The alternative, seeding every draft from the persisted record so that it is
 * never a partial overlay, was not taken: the component does not know the
 * record (the app renders the inputs), and a full copy of a record that
 * changes elsewhere goes stale exactly as a partial one does.
 *
 * WHEN PRESENCE IS NOT KNOWN THE DRAFT STAYS. The presence store answers null
 * for a cache that was flushed and for a read that failed (Redis outages are
 * swallowed into a miss), and neither says nobody is there: one failed read
 * while a partner's feed projected would otherwise delete the draft somebody
 * is typing into. So a missing mark is no evidence, with one exception: a
 * draft not written for longer than the store remembers a heartbeat at all
 * ({@see FormPresenceStoreInterface::LAST_SEEN_MEMORY_SECONDS}, a day) could
 * only have an aged-out mark, and goes. The cost of this side: after a cache
 * flush an abandoned draft younger than a day outlives its window, until it is
 * a day old or someone's discard() ends it. An edit from days ago never
 * returns.
 *
 * Heartbeat marks live in the cache the presence store uses, which must be a
 * SHARED one (redis). With a per-worker array cache each worker sees only the
 * heartbeats it served, a mark is missing on the others, and by the rule above
 * their drafts are kept up to a day: late, never early.
 *
 * A sentinel key saying "the cache has been up since T" would trust a missing
 * mark sooner, but takes two reads to decide, and a failure of the one that
 * matters, with the other answering, deletes the attended draft again.
 */
#[AsService]
final class FormCollabDraftRetention
{
    public const DEFAULT_IDLE_MINUTES = 30;

    #[Config(env: 'PLATFORM_UI_COLLAB_DRAFT_IDLE_MINUTES', default: self::DEFAULT_IDLE_MINUTES)]
    protected int $idleMinutes = self::DEFAULT_IDLE_MINUTES;

    #[InjectAsReadonly]
    protected FormPresenceStoreInterface $presenceStore;

    /** @var (\Closure(): int)|null */
    #[WorkerState('Test seam; production never calls with(), so the wall clock is read on each call.')]
    private ?\Closure $clock = null;

    /**
     * Test seam — production path uses property injection.
     *
     * @param (\Closure(): int)|null $clock unix time; null keeps the wall clock
     */
    public function with(FormPresenceStoreInterface $presenceStore, ?int $idleMinutes = null, ?\Closure $clock = null): self
    {
        $this->presenceStore = $presenceStore;
        $this->idleMinutes = $idleMinutes ?? $this->idleMinutes;
        $this->clock = $clock;

        return $this;
    }

    /** The window in seconds; 0 when expiry is off. */
    public function idleSeconds(): int
    {
        return max(0, min($this->idleMinutes * 60, FormPresenceStoreInterface::LAST_SEEN_MEMORY_SECONDS));
    }

    /**
     * Is the draft of `$scopeKey`, last written at `$draftUpdatedAt` (unix
     * time), abandoned? The last write is checked first: a draft in use never
     * costs a presence read.
     */
    public function isAbandoned(string $scopeKey, int $draftUpdatedAt): bool
    {
        $window = $this->idleSeconds();
        if ($window === 0) {
            return false;
        }

        $now = $this->clock !== null ? ($this->clock)() : time();
        $cutoff = $now - $window;
        if ($draftUpdatedAt >= $cutoff) {
            return false;
        }

        try {
            $lastSeenAt = $this->presenceStore->lastSeenAt($scopeKey);
        } catch (\Throwable) {
            $lastSeenAt = null;
        }
        if ($lastSeenAt !== null) {
            return $lastSeenAt < $cutoff;
        }

        // Not known. Only a draft older than any mark can live says it for sure.
        return $draftUpdatedAt < $now - FormPresenceStoreInterface::LAST_SEEN_MEMORY_SECONDS;
    }
}
