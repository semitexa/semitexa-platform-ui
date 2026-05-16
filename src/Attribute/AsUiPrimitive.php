<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
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
