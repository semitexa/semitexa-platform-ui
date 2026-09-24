<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Contract;

use InvalidArgumentException;

/** Literal fixture data, never executable provider or handler code. */
final readonly class UiExample
{
    /** @var array<string, string> */
    public array $slots;

    /**
     * @param array<string, mixed> $props
     * @param array<array-key, mixed> $slots what an attribute author can write,
     *        which PHP does not check; verified below to be string => string
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $props = [],
        array $slots = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9-]*\z/', $name) !== 1 || trim($label) === '') {
            throw new InvalidArgumentException('UI examples need a stable name and a readable label.');
        }
        $checked = [];
        foreach ($slots as $slot => $text) {
            if (!is_string($slot) || !is_string($text)) {
                throw new InvalidArgumentException('Example slots contain literal text.');
            }
            $checked[$slot] = $text;
        }
        $this->slots = $checked;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'label' => $this->label, 'props' => (object) $this->props, 'slots' => (object) $this->slots];
    }
}
