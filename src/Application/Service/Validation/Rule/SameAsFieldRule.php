<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * Rule id: `sameAsField`. Cross-field comparator.
 *
 * Parameters (positional, all scalars):
 *
 *   0: string $siblingFieldName — the other field's name (must match
 *      the safe identifier shape `[A-Za-z_][A-Za-z0-9_-]*`; the
 *      registry pins the shape at resolve time so a bad shape never
 *      reaches this constructor in production).
 *   1: string $customMessage    — optional. Overrides the default
 *      "Values must match." diagnostic when the values diverge.
 *
 * Behaviour:
 *
 *   - Comparison is string-equality on the trimmed scalar projection
 *     of each side. `int 1` and `string "1"` compare equal — JSON
 *     transport can stringify scalars, so a strict-typed compare
 *     would surprise callers.
 *   - When BOTH sides trim to empty, the rule passes (the field is
 *     "consistent with the sibling"). Pair with `required` if an
 *     empty value should fail.
 *   - When the current value is non-empty but the sibling is missing
 *     from the snapshot entirely, the rule fails with
 *     "Please complete the related field first." — sentinel diagnostic
 *     so the user knows where to start. (Custom message does NOT
 *     override this one; it only fires on the mismatch case.)
 *   - When values differ, the rule fails with `$customMessage` if
 *     provided, otherwise the default.
 *
 * Trust boundary:
 *
 *   The sibling value comes from `$context->formValue($name)`, which
 *   is populated by the dispatcher from the *sanitised*
 *   `payload.form.values` snapshot the client submitted. Snapshot
 *   values are UX-feedback only — they MUST NOT be used as
 *   authoritative state. Rules in this slice (sync, pure) cannot
 *   tell the difference, but the future real-submit pipeline must
 *   revalidate against the trusted server-side state before
 *   persisting anything.
 */
final class SameAsFieldRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'sameAsField';

    public const DEFAULT_MISMATCH_MESSAGE = 'Values must match.';
    public const MISSING_SIBLING_MESSAGE  = 'Please complete the related field first.';

    /** Pinned at registry-resolve time; pinned again here as defence in depth. */
    private const SAFE_IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_-]*\z/';

    public function __construct(
        private readonly string $siblingFieldName,
        private readonly string $mismatchMessage = self::DEFAULT_MISMATCH_MESSAGE,
    ) {
        if (preg_match(self::SAFE_IDENTIFIER, $this->siblingFieldName) !== 1) {
            throw new \InvalidArgumentException(
                'SameAsFieldRule requires a safe identifier as its first parameter.',
            );
        }
        if ($this->mismatchMessage === '') {
            throw new \InvalidArgumentException(
                'SameAsFieldRule custom message must be a non-empty string.',
            );
        }
    }

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        $currentString = self::scalarTrim($value);
        $sibling = $context->formValue($this->siblingFieldName);
        $siblingPresent = array_key_exists($this->siblingFieldName, $context->formValues);

        if ($currentString === '' && !$siblingPresent) {
            // Both sides effectively empty (current trimmed empty AND
            // sibling not in snapshot) → pass; let `required` handle
            // empties.
            return null;
        }

        if (!$siblingPresent) {
            // Current is non-empty but the snapshot does not carry the
            // sibling. UX-friendly diagnostic that doesn't leak the
            // sibling field name to the user — the message is generic
            // by design.
            return UiFieldValidationResult::invalid(self::MISSING_SIBLING_MESSAGE);
        }

        $siblingString = self::scalarTrim($sibling);
        if ($currentString === '' && $siblingString === '') {
            return null;
        }

        if ($currentString === $siblingString) {
            return null;
        }

        return UiFieldValidationResult::invalid($this->mismatchMessage);
    }

    private static function scalarTrim(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return trim((string) $value);
    }
}
