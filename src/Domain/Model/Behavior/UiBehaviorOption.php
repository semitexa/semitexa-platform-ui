<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Behavior;

/**
 * A single typed option of a UI behavior, declared inside
 * #[AsUiBehavior(options: [...])].
 *
 * This is the ONE source of truth for an option: it drives (a) the client-side
 * typed coercion of the `ui-<alias>="key: val"` DSL, (b) server introspection
 * (platform-ui:catalog), (c) the grammar value validator, (d) AI scaffolding
 * hints, and (e) generated showcase controls.
 *
 * The value object is intentionally provider-agnostic — it carries the contract,
 * never behaviour.
 */
final readonly class UiBehaviorOption
{
    /**
     * @param list<string> $values allowed values when $type is Enum (empty otherwise)
     */
    public function __construct(
        public string $name,
        public UiOptionType $type = UiOptionType::String,
        public bool|int|float|string|null $default = null,
        public array $values = [],
        public ?string $description = null,
    ) {}

    /**
     * The client descriptor row for this option — the minimal shape shipped to
     * the browser so behavior-runtime.js can coerce the DSL without guessing.
     *
     * @return array{name: string, type: string, default: bool|int|float|string|null, values: list<string>}
     */
    public function toDescriptor(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'default' => $this->default,
            'values' => $this->values,
        ];
    }
}
