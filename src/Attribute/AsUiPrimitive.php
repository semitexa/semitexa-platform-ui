<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;
use Semitexa\PlatformUi\Domain\Model\Primitive\UiPrimitiveEvent;

/**
 * Declares a class as a Semitexa UI primitive.
 *
 * The primitive has two identities:
 *   - $name: canonical registry/manifest/signed-context/debug identity (e.g. "platform.button")
 *   - $ui:   short CSS/markup alias (e.g. "button"). If null, derived from the last
 *            dot-segment of $name.
 *
 * These two are never collapsed: validation, handler resolution, and signed context
 * use $name only; $ui is a markup hook.
 *
 * @see UiPrimitiveEvent for event metadata.
 */
#[Capability(
    id: 'ui.primitive',
    summary: 'A named UI primitive with its own template, script and style, addressable from markup by a short alias.',
    useWhen: 'A basic element (button, input, badge) should look and behave the same everywhere.',
    avoidWhen: 'The element is used once in one template and has no shared identity.',
    replaces: [
        're-declaring the same markup and classes in every template',
    ],
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsUiPrimitive
{
    public function __construct(
        public string $name,
        public ?string $ui = null,
        public ?string $template = null,
        public ?string $script = null,
        public ?string $style = null,
        /** @var list<UiPrimitiveEvent> */
        public array $events = [],
    ) {}
}
