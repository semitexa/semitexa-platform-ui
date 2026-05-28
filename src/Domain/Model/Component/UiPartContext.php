<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Component;

/**
 * Typed read-only context handed to a {@see \Semitexa\PlatformUi\Domain\Contract\UiPartDataProviderInterface}
 * when resolving props for a single {@see UiPartMetadata} declaration.
 *
 * Carries the rendered component instance, the part name being resolved, and
 * the originating request (full Request object on the sync render path, or a
 * snapshot array on the deferred / SSE path — same dual shape used by
 * {@see \Semitexa\Ssr\Domain\Model\DataProviderContext}).
 *
 * The context is intentionally narrow: providers are read-side collaborators
 * (technical-design.md §6.7) and have no business reading mutable framework
 * state or holding references they could later mutate.
 */
final readonly class UiPartContext
{
    /**
     * @param object|array<string, mixed>|null $request
     */
    public function __construct(
        public object $componentInstance,
        public string $partName,
        public object|array|null $request = null,
    ) {}
}
