<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;

/**
 * Declares a class as a Semitexa UI behavior — the third UI tier: a client-only,
 * declaratively-bound interaction (dropdown, modal, tabs, tooltip…).
 *
 * A behavior class is a PASSIVE server-side declaration (like #[AsUiPrimitive]).
 * The interaction itself lives in the native-ESM module referenced by $script;
 * this attribute owns only the metadata. Declaring behaviors server-side makes
 * them discoverable, introspectable (platform-ui:catalog), CSP-safe (the $script
 * asset is collected at render, never inlined), grammar-validated (options), and
 * AI-composable.
 *
 * Two identities, never collapsed (mirrors #[AsUiPrimitive]):
 *   - $name: canonical registry/manifest/debug identity (e.g. "platform.dropdown").
 *   - $ui:   the markup alias used in `ui-behavior="dropdown"`. If null, derived
 *            from the last dot-segment of $name.
 *
 * @see UiBehaviorOption for the typed option schema.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsUiBehavior
{
    /**
     * @param list<UiBehaviorOption> $options typed option schema (the `ui-<alias>` DSL contract)
     * @param list<string>           $a11y    declared, tested a11y capabilities (e.g. "focus-trap", "aria-expanded", "esc-dismiss", "arrow-nav")
     * @param list<string>           $requires primitive/component names this behavior expects in its subtree (e.g. "platform.menu")
     */
    public function __construct(
        public string $name,
        public ?string $ui = null,
        public ?string $script = null,
        public array $options = [],
        public array $a11y = [],
        public array $requires = [],
    ) {}
}
