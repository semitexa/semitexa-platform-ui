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
     * Examples that were declared but dropped, by name, with the reason.
     *
     * An invalid example used to throw from here, and contracts are built at
     * registry boot: one bad example in any installed package took down the
     * Workbench, the CLI catalog, or the worker itself. Now the example is left
     * out and named here; lint:ui-contracts fails on it before a deploy.
     *
     * @var array<string, string>
     */
    public array $brokenExamples;

    /**
     * @param array<mixed> $props    attribute arguments: element types are
     * @param array<mixed> $examples not checked by PHP, so they are checked here
     * @param array<string, string> $brokenExamples examples already dropped upstream
     */
    public function __construct(
        public string $summary,
        array $props = [],
        array $examples = [],
        public bool $previewSafe = false,
        /** Open to an agent composing a screen. */
        public bool $agent = false,
        /** The permission a screen containing it needs; null: none. */
        public ?string $permission = null,
        array $brokenExamples = [],
        /** What undeclared props are for; null: there are none. */
        public ?string $extraProps = null,
    ) {
        $this->props = UiProp::index($props);
        $indexed = [];
        foreach ($examples as $position => $example) {
            if (!$example instanceof UiExample) {
                $brokenExamples['#' . $position] = 'is not a UiExample declaration';
                continue;
            }
            if (isset($indexed[$example->name])) {
                $brokenExamples[$example->name] = 'is declared more than once';
                continue;
            }
            // An example is emitted next to props_schema as a worked instance
            // of it; one the schema would reject teaches the wrong thing.
            try {
                UiProp::validateObject(array_values($this->props), $example->props, 'props', open: $extraProps !== null);
            } catch (InvalidArgumentException $e) {
                $brokenExamples[$example->name] = $e->getMessage();
                continue;
            }
            $indexed[$example->name] = $example;
        }
        $this->examples = $indexed;
        $this->brokenExamples = $brokenExamples;
    }

    /** The same contract with one example dropped for a reason found outside it (an unknown slot). */
    public function withBrokenExample(string $name, string $reason): self
    {
        $examples = $this->examples;
        unset($examples[$name]);

        return new self(
            $this->summary,
            array_values($this->props),
            array_values($examples),
            $this->previewSafe,
            $this->agent,
            $this->permission,
            $this->brokenExamples + [$name => $reason],
            $this->extraProps,
        );
    }

    /** For checks that must refuse what the runtime tolerates. */
    public function assertExamplesValid(): void
    {
        if ($this->brokenExamples === []) {
            return;
        }
        $reasons = [];
        foreach ($this->brokenExamples as $name => $reason) {
            $reasons[] = "UI example {$name}: {$reason}";
        }
        throw new InvalidArgumentException(implode('; ', $reasons));
    }

    public function schema(): array
    {
        $schema = ['$schema' => 'https://json-schema.org/draft/2020-12/schema'] + UiProp::objectSchema(array_values($this->props));
        if ($this->extraProps !== null) {
            $schema['additionalProperties'] = ['description' => $this->extraProps];
        }
        return $schema;
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
