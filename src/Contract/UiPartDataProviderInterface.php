<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Contract;

use Semitexa\PlatformUi\Domain\Model\Component\UiPartContext;

/**
 * Read-side provider for one {@see \Semitexa\PlatformUi\Domain\Model\Component\UiPartMetadata}.
 *
 * Implementations return structured props/data only — never HTML, never a
 * mutation. Provider output is merged into the part's resolved props during
 * part prop resolution (technical-design.md §6.7).
 *
 * Allowed dependencies: read-model services, query services, repositories in
 * read-only mode, projections, deterministic context-based providers. Write-
 * side application services, command bus calls, and persistence mutations
 * are forbidden — those belong in event/submit/action handlers.
 */
interface UiPartDataProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function provide(UiPartContext $context): array;
}
