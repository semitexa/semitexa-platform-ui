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
     * @param array<mixed> $props    attribute arguments: element types are
     * @param array<mixed> $examples not checked by PHP, so they are checked here
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
            // An example is emitted next to props_schema as a worked instance
            // of it; one the schema would reject teaches the wrong thing.
            try {
                UiProp::validateObject(array_values($this->props), $example->props, 'props');
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("UI example {$example->name}: {$e->getMessage()}", 0, $e);
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
     * An example's props as JSON should see them; see UiProp::normalize().
     *
     * @return array<string, mixed>
     */
    public function exampleArray(UiExample $example): array
    {
        return array_replace($example->toArray(), ['props' => UiProp::normalizeObject(array_values($this->props), $example->props)]);
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
                $defaults[$prop->name] = $prop->normalize($prop->default);
            }
        }
        return $defaults;
    }
}
