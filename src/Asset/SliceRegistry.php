<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Asset;

final class SliceRegistry
{
    /** @var array<string, true> slice-id => true (for O(1) dedupe) */
    private array $grammarSlices = [];

    /** @var array<string, true> primitive-id => true */
    private array $primitives = [];

    public function registerGrammar(string $attribute, string $value): void
    {
        $this->grammarSlices["{$attribute}:{$value}"] = true;
    }

    public function registerSlice(string $sliceId): void
    {
        $this->grammarSlices[$sliceId] = true;
    }

    public function registerPrimitive(string $primitiveId): void
    {
        $this->primitives[$primitiveId] = true;
    }

    /** @return list<string> */
    public function grammarSliceIds(): array
    {
        return array_keys($this->grammarSlices);
    }

    /** @return list<string> */
    public function primitiveIds(): array
    {
        return array_keys($this->primitives);
    }

    public function reset(): void
    {
        $this->grammarSlices = [];
        $this->primitives = [];
    }

    public function isEmpty(): bool
    {
        return $this->grammarSlices === [] && $this->primitives === [];
    }
}
