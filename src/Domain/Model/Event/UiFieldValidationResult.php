<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Minimal server-side field validation result.
 *
 * Intentionally NOT a generic validation framework — this slice only
 * needs enough shape to express the FieldComponent demo behaviour:
 *
 *   - valid value with an optional positive message;
 *   - invalid value with a required diagnostic message;
 *   - a deterministic translation from result → existing
 *     UiResponsePatch list, so handlers can return
 *     UiInteractionResult::patch($result->toPatches(...)) without
 *     re-deriving DOM details.
 *
 * The class is intentionally final + readonly + factory-only so future
 * slices can layer richer state on top (multi-field, rules DSL, async)
 * without breaking this surface.
 *
 * Patch wire shape is the existing UiResponsePatch — no new DOM
 * mutation engine, no new patch ops.
 */
final readonly class UiFieldValidationResult
{
    public const STATE_VALID = 'valid';
    public const STATE_INVALID = 'invalid';

    /** Patch target name resolved on the rendered field's component instance. */
    public const VALIDATION_TARGET_NAME = 'validation-message';

    /** The input UiPart name FieldComponent declares — patches scope to it. */
    public const INPUT_PART_NAME = 'input';

    private function __construct(
        public string  $state,
        public ?string $message,
    ) {}

    public function isValid(): bool
    {
        return $this->state === self::STATE_VALID;
    }

    /**
     * Build a "valid" result. The message is optional — the playground
     * demo passes 'Looks good.' so the rendered validation-message
     * target shows positive feedback, but production handlers may want
     * an empty message and rely solely on the ui-state attribute.
     */
    public static function valid(?string $message = null): self
    {
        return new self(self::STATE_VALID, $message);
    }

    /**
     * Build an "invalid" result. The message is required (empty
     * string would defeat the point); a short diagnostic that can be
     * surfaced to a screen reader is the contract.
     */
    public static function invalid(string $message): self
    {
        if ($message === '') {
            throw new \InvalidArgumentException('UiFieldValidationResult::invalid() requires a non-empty message.');
        }
        return new self(self::STATE_INVALID, $message);
    }

    /**
     * Translate the result into the existing UiResponsePatch list.
     *
     * Patch shape:
     *   - setAttribute on the input UiPart:
     *       attribute = aria-invalid, value = 'true' or null (removal)
     *       attribute = ui-state,     value = 'valid' or 'invalid'
     *   - setText on the validation-message name target with the message.
     *     The setText is only emitted when there is a message to show —
     *     callers that pass null for valid results stay silent.
     *
     * All targets pin to $instanceId, so UiPatchValidator's instance
     * binding test still passes when the dispatcher runs the patches
     * through it.
     *
     * @return list<UiResponsePatch>
     */
    public function toPatches(string $instanceId): array
    {
        $patches = [];

        $patches[] = new UiResponsePatch(
            op: UiResponsePatch::OP_SET_ATTRIBUTE,
            targetInstance: $instanceId,
            targetPart: self::INPUT_PART_NAME,
            targetName: null,
            value: $this->isValid() ? null : 'true',
            attribute: 'aria-invalid',
        );

        $patches[] = new UiResponsePatch(
            op: UiResponsePatch::OP_SET_ATTRIBUTE,
            targetInstance: $instanceId,
            targetPart: self::INPUT_PART_NAME,
            targetName: null,
            value: $this->state, // 'valid' or 'invalid'
            attribute: 'ui-state',
        );

        if ($this->message !== null) {
            $patches[] = new UiResponsePatch(
                op: UiResponsePatch::OP_SET_TEXT,
                targetInstance: $instanceId,
                targetPart: null,
                targetName: self::VALIDATION_TARGET_NAME,
                value: $this->message,
            );
        }

        return $patches;
    }
}
