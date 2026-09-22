<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Contract;

use InvalidArgumentException;

/** Literal fixture data, never executable provider or handler code. */
final readonly class UiExample
{
    /**
     * @param array<string, mixed> $props
     * @param array<string, string> $slots
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $props = [],
        public array $slots = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9-]*\z/', $name) !== 1 || trim($label) === '') {
            throw new InvalidArgumentException('UI examples need a stable name and a readable label.');
        }
        foreach ($slots as $slot => $text) {
            if (!is_string($slot) || !is_string($text)) {
                throw new InvalidArgumentException('Example slots contain literal text.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'label' => $this->label, 'props' => (object) $this->props, 'slots' => (object) $this->slots];
    }
}
