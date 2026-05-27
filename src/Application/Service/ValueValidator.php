<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service;

use Semitexa\PlatformUi\Css\Slice\SliceCatalog;

final class ValueValidator
{
    public function __construct(
        private readonly SliceCatalog $catalog,
    ) {
    }

    public function isValid(string $attribute, string $value): bool
    {
        $emitter = $this->catalog->emitter($attribute);
        return $emitter !== null && in_array($value, $emitter->allowedValues(), true);
    }

    /** @return list<string> */
    public function suggest(string $attribute): array
    {
        $emitter = $this->catalog->emitter($attribute);
        return $emitter?->allowedValues() ?? [];
    }

    public function assert(string $attribute, string $value): void
    {
        if ($this->isValid($attribute, $value)) {
            return;
        }

        $suggestions = $this->suggest($attribute);
        $hint = $suggestions === []
            ? "attribute '{$attribute}' is not part of the grammar"
            : "valid values for '{$attribute}': " . implode(', ', $suggestions);

        throw new \InvalidArgumentException("Invalid grammar: {$attribute}=\"{$value}\" — {$hint}");
    }
}
