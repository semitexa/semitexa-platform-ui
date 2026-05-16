<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Component;

/**
 * Immutable discovered metadata for one #[UiPart] declaration.
 *
 * Built by UiComponentMetadataFactory after validation: name and uses are
 * non-empty, name matches the slot identifier pattern, uses points to a
 * class marked with #[AsUiPrimitive] (resolved later through the registry).
 *
 * `$primitiveName` is the canonical name of the target primitive (read off
 * the `#[AsUiPrimitive(name: …)]` attribute on the `uses` class). The
 * `ui_part()` Twig helper uses it to call `PrimitiveRenderer` without
 * having to re-reflect at render time.
 *
 * `$bind` is the parsed UiValuePath when the part declares `bind: '…'` on
 * its attribute; `null` when the part is not bound to a component value.
 */
final readonly class UiPartMetadata
{
    public function __construct(
        public string $name,
        /** @var class-string */
        public string $uses,
        public string $primitiveName,
        /** @var array<string, mixed> */
        public array $defaults,
        public ?UiValuePath $bind = null,
    ) {}
}
