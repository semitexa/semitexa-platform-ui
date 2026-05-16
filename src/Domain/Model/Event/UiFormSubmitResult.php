<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Server-side outcome of a FormComponent submit dispatch.
 *
 * The result is a *summary*: which signed-config fields ran, which
 * ones passed, which ones failed, and a single message summarising
 * the aggregate state. It carries per-field state + message but
 * NEVER per-field values — submitted scalars are not echoed back
 * out of the dispatch handler in this slice (passwords, tokens,
 * and other sensitive shapes would otherwise leak through `debug`).
 *
 * Projection to patches is intentionally narrow: one `setText` to
 * the form-status target + one `setAttribute` for `ui-state` on the
 * form root. No new patch op, no expansion of the attribute
 * allow-list, no per-field DOM mutation (that's an explicit follow-
 * up slice).
 */
final readonly class UiFormSubmitResult
{
    public const STATE_VALID   = 'valid';
    public const STATE_INVALID = 'invalid';

    /**
     * @param list<array{name: string, state: string, message: ?string}> $fields
     *        Per-field outcome list. `state` is one of `valid` /
     *        `invalid`; `message` is the diagnostic the first
     *        failing rule emitted, null on success.
     */
    public function __construct(
        public bool   $valid,
        public int    $totalCount,
        public int    $validCount,
        public int    $invalidCount,
        public array  $fields,
        public string $message,
    ) {}

    /**
     * Build the summary from a list of per-field validation results.
     *
     * @param list<array{name: string, state: string, message: ?string}> $fields
     */
    public static function fromFieldResults(array $fields): self
    {
        $total   = count($fields);
        $invalid = 0;
        foreach ($fields as $f) {
            if (($f['state'] ?? null) === self::STATE_INVALID) {
                $invalid++;
            }
        }
        $valid       = $total > 0 && $invalid === 0;
        $validCount  = $total - $invalid;
        $message     = self::deriveMessage($total, $invalid);
        return new self(
            valid:        $valid,
            totalCount:   $total,
            validCount:   $validCount,
            invalidCount: $invalid,
            fields:       $fields,
            message:      $message,
        );
    }

    /**
     * Render the summary as the two patches the form-status target
     * and the form root accept.
     *
     * The patches travel through the same dispatcher path as field
     * patches: `UiPatchValidator` will accept them because the op
     * is allow-listed and the target instance equals the signed
     * dispatch instance (the form's component instance id).
     *
     * @return list<UiResponsePatch>
     */
    public function toPatches(string $formInstance): array
    {
        return [
            new UiResponsePatch(
                op: UiResponsePatch::OP_SET_TEXT,
                targetInstance: $formInstance,
                targetPart: null,
                targetName: 'form-status',
                value: $this->message,
            ),
            new UiResponsePatch(
                op: UiResponsePatch::OP_SET_ATTRIBUTE,
                targetInstance: $formInstance,
                targetPart: null,
                targetName: null,
                value: $this->valid ? self::STATE_VALID : self::STATE_INVALID,
                attribute: 'ui-state',
            ),
        ];
    }

    /**
     * Debug projection — safe-to-log shape. Contains COUNTS + per-
     * field state/message, never values. Operators correlating
     * production logs see the *outcome* without the inputs.
     *
     * @return array<string, mixed>
     */
    public function toDebug(): array
    {
        return [
            'valid'        => $this->valid,
            'totalCount'   => $this->totalCount,
            'validCount'   => $this->validCount,
            'invalidCount' => $this->invalidCount,
            'fields'       => $this->fields,
            'message'      => $this->message,
        ];
    }

    private static function deriveMessage(int $total, int $invalid): string
    {
        if ($total === 0) {
            return 'Form has no fields.';
        }
        if ($invalid === 0) {
            return 'Form is valid. Submit accepted.';
        }
        if ($invalid === 1) {
            return '1 field needs attention.';
        }
        return $invalid . ' fields need attention.';
    }
}
