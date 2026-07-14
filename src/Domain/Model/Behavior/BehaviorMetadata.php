<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Behavior;

/**
 * Immutable discovered-behavior metadata.
 *
 * Built from #[AsUiBehavior] by the registry's discovery pass. A behavior is the
 * third UI tier: a client-only interaction (dropdown, modal, tabs…) declared
 * server-side so it is discoverable, introspectable, and its ESM $script is
 * collected as a CSP-safe asset.
 *
 * Identity model mirrors PrimitiveMetadata:
 *   - canonical $name (e.g. "platform.dropdown") — internal identity.
 *   - $ui alias       (e.g. "dropdown")          — the value used in
 *                                                  `ui-behavior="dropdown"` markup.
 */
final readonly class BehaviorMetadata
{
    /**
     * @param list<UiBehaviorOption> $options
     * @param list<string>           $a11y     declared, *tested* a11y capabilities
     * @param list<string>           $requires primitive/component names this behavior expects in its subtree
     */
    public function __construct(
        public string $class,
        public string $name,
        public string $ui,
        public ?string $script,
        public array $options,
        public array $a11y,
        public array $requires,
    ) {}

    public function option(string $name): ?UiBehaviorOption
    {
        foreach ($this->options as $option) {
            if ($option->name === $name) {
                return $option;
            }
        }

        return null;
    }

    public function declaresA11y(string $capability): bool
    {
        return in_array($capability, $this->a11y, true);
    }

    /**
     * The client descriptor — the minimal per-behavior shape shipped to the
     * browser (option coercion table). Emitted per-page for used behaviors only,
     * mirroring the CSS-slice registry.
     *
     * @return array{ui: string, options: list<array{name: string, type: string, default: bool|int|float|string|null, values: list<string>}>}
     */
    public function toDescriptor(): array
    {
        return [
            'ui' => $this->ui,
            'options' => array_map(
                static fn (UiBehaviorOption $o): array => $o->toDescriptor(),
                $this->options,
            ),
        ];
    }

    /**
     * Plain-array view for debug / introspection (platform-ui:catalog).
     * Server-side only.
     *
     * @return array{
     *     class: string,
     *     name: string,
     *     ui: string,
     *     script: ?string,
     *     options: list<array{name: string, type: string, default: bool|int|float|string|null, values: list<string>, description: ?string}>,
     *     a11y: list<string>,
     *     requires: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'name' => $this->name,
            'ui' => $this->ui,
            'script' => $this->script,
            'options' => array_map(
                static fn (UiBehaviorOption $o): array => [
                    'name' => $o->name,
                    'type' => $o->type->value,
                    'default' => $o->default,
                    'values' => $o->values,
                    'description' => $o->description,
                ],
                $this->options,
            ),
            'a11y' => $this->a11y,
            'requires' => $this->requires,
        ];
    }
}
