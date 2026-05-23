<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Component;

/**
 * Typed read-only context passed to the optional `mount(UiComponentContext)`
 * lifecycle hook on a `#[AsComponent]` class (technical-design.md §3.4 + §9
 * step 10).
 *
 * Carries the inputs the framework prepared for the component instance —
 * caller-supplied props, resolved bindings, and the originating request
 * (full Request object on the sync render path, or a snapshot array on the
 * deferred / SSE path, mirroring the same dual shape used by
 * {@see \Semitexa\Ssr\Domain\Model\DataProviderContext}).
 *
 * `input()` is the documented lookup surface (see design example:
 * `$context->input('value')`). Direct property access is also fine for the
 * `request` field, which carries no key-based lookup semantics.
 */
final readonly class UiComponentContext
{
    /**
     * @param array<string, mixed>             $inputs
     * @param object|array<string, mixed>|null $request
     */
    public function __construct(
        public array $inputs = [],
        public object|array|null $request = null,
    ) {}

    public function input(string $key): mixed
    {
        return $this->inputs[$key] ?? null;
    }

    public function hasInput(string $key): bool
    {
        return array_key_exists($key, $this->inputs);
    }
}
