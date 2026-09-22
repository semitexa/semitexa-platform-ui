<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Contract;

use InvalidArgumentException;

final readonly class UiContract
{
    /** @var array<string, UiProp> */
    public array $props;

    /** @var array<string, UiExample> */
    public array $examples;

    /**
     * @param list<UiProp> $props
     * @param list<UiExample> $examples
     */
    public function __construct(
        public string $summary,
        array $props = [],
        array $examples = [],
        public bool $previewSafe = false,
    ) {
        $this->props = UiProp::index($props);
        $indexed = [];
        foreach ($examples as $example) {
            if (!$example instanceof UiExample || isset($indexed[$example->name])) {
                throw new InvalidArgumentException('UI examples must be unique UiExample declarations.');
            }
            $indexed[$example->name] = $example;
        }
        $this->examples = $indexed;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema'] + UiProp::objectSchema(array_values($this->props));
    }

    /**
     * Defaults are projected for authoring, never injected into legacy rendering.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->props as $prop) {
            if ($prop->hasDefault()) {
                $defaults[$prop->name] = $prop->default;
            }
        }
        return $defaults;
    }
}
