<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\PlatformUi\Domain\Model\Collaboration\FormFieldEditOrder;

/**
 * The edit marks of a draft: per field, the highest sequence stored from each
 * source ({@see \Semitexa\PlatformUi\Domain\Contract\FormCollabOrderedDraftStoreInterface}).
 * Pure, so both stores order edits by the same rule.
 *
 * Per FIELD, not per source alone: a page sends different fields side by side,
 * and a lower number for another field is not an older edit of this one.
 */
final class FormFieldEditMarks
{
    /**
     * Sources remembered per field; the one written to longest ago goes first.
     * A page forgotten here is ordered by arrival again, as every page was.
     */
    public const SOURCES_PER_FIELD = 16;

    /**
     * Where edit `$sequence` of `$field` from `$source` stands, and the marks
     * once it is applied (unchanged otherwise).
     *
     * @param array<string, array<string, int>> $marks field → source → highest sequence stored
     * @return array{0: FormFieldEditOrder, 1: array<string, array<string, int>>}
     */
    public static function place(array $marks, string $field, string $source, int $sequence): array
    {
        $stored = $marks[$field][$source] ?? 0;
        if ($sequence < $stored) {
            return [FormFieldEditOrder::Superseded, $marks];
        }
        if ($sequence === $stored) {
            return [FormFieldEditOrder::Repeated, $marks];
        }

        $ofField = $marks[$field] ?? [];
        unset($ofField[$source]); // written last: moves to the end
        $ofField[$source] = $sequence;
        $marks[$field] = array_slice($ofField, -self::SOURCES_PER_FIELD, null, true);

        return [FormFieldEditOrder::Applied, $marks];
    }

    /**
     * One page of one participant as a source key. The participant comes from
     * the verified session and the runtime id from the request body, so a
     * page can only ever be compared with what the same participant sent.
     *
     * @return non-empty-string
     */
    public static function source(string $actorId, string $runtimeId): string
    {
        return substr(hash('sha256', $actorId . "\0" . $runtimeId), 0, 24);
    }

    /**
     * Marks as read back from storage: anything that is not field → source →
     * positive int is dropped.
     *
     * @return array<string, array<string, int>>
     */
    public static function sanitize(mixed $raw): array
    {
        $marks = [];
        foreach (is_array($raw) ? $raw : [] as $field => $sources) {
            foreach (is_array($sources) ? $sources : [] as $source => $sequence) {
                if (is_int($sequence) && $sequence > 0) {
                    $marks[(string) $field][(string) $source] = $sequence;
                }
            }
        }

        return $marks;
    }
}
