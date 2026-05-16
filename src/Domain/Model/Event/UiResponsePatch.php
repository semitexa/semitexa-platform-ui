<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Immutable response-patch instruction returned by a #[UiOn] handler.
 *
 * Patches are safe by construction:
 *   - `$op` is one of a small allow-listed verb set;
 *   - `$target` references the component instance + (optional) part + (optional)
 *     `data-ui-patch-target` name. NO arbitrary CSS selectors. NO `document`
 *     / `body` / `html` targets;
 *   - `$value` is scalar (string/int/float/bool/null) when the op carries a value;
 *   - `$attribute`, when set, must be an allow-listed HTML attribute name.
 *
 * The full validation lives in `UiPatchValidator`; this DTO is just the
 * transport shape — readonly, no behavior beyond the JSON projection.
 *
 * Targeting addresses (all scoped to a single component instance):
 *   { instance }                  → component root
 *   { instance, part }            → [data-ui-part="<part>"] inside the root
 *   { instance, name }            → [data-ui-patch-target="<name>"] inside the root
 */
final readonly class UiResponsePatch
{
    public const OP_SET_TEXT      = 'setText';
    public const OP_SET_VALUE     = 'setValue';
    public const OP_SET_ATTRIBUTE = 'setAttribute';

    /** @var list<string> */
    public const ALLOWED_OPS = [
        self::OP_SET_TEXT,
        self::OP_SET_VALUE,
        self::OP_SET_ATTRIBUTE,
    ];

    /** Attribute names accepted by setAttribute. Tight allow-list. */
    public const ALLOWED_ATTRIBUTES = [
        'aria-invalid',
        'aria-describedby',
        'data-state',
        'ui-state',
    ];

    public function __construct(
        public string $op,
        public string $targetInstance,
        public ?string $targetPart,
        public ?string $targetName,
        public mixed $value = null,
        public ?string $attribute = null,
    ) {}

    /**
     * Plain-array projection used by the dispatch handler for JSON encoding.
     * Compact keys keep the wire payload small.
     *
     * @return array<string, mixed>
     */
    public function toJsonShape(): array
    {
        $target = ['instance' => $this->targetInstance];
        if ($this->targetPart !== null) {
            $target['part'] = $this->targetPart;
        }
        if ($this->targetName !== null) {
            $target['name'] = $this->targetName;
        }

        $out = [
            'op' => $this->op,
            'target' => $target,
        ];

        if ($this->attribute !== null) {
            $out['attribute'] = $this->attribute;
        }

        if ($this->op !== self::OP_SET_ATTRIBUTE || $this->value !== null || $this->attribute !== null) {
            // setText/setValue always serialise `value`; setAttribute can also
            // carry a value (the new attribute value).
            $out['value'] = $this->value;
        }

        return $out;
    }
}
