<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Contract;

use InvalidArgumentException;

/**
 * Literal fixture data, never executable provider or handler code.
 *
 * `template` names a Twig file shipped with the package (an `@namespace/…`
 * path) for entries whose example IS markup — a behavior is a contract on
 * attributes, so its worked example is a snippet, not props. The file is
 * rendered with the example's props as context; it is reviewed code like any
 * template, which is why it is a path and never an inline string.
 */
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
        public ?string $template = null,
    ) {
        if ($template !== null && (preg_match('#\A@[a-z0-9-]+/[A-Za-z0-9_./-]+\.twig\z#', $template) !== 1 || str_contains($template, '..'))) {
            throw new InvalidArgumentException('An example template is a namespaced package Twig path, e.g. @platform-ui/examples/dropdown.html.twig.');
        }
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
        $out = ['name' => $this->name, 'label' => $this->label, 'props' => (object) $this->props, 'slots' => (object) $this->slots];
        if ($this->template !== null) {
            $out['template'] = $this->template;
        }
        return $out;
    }
}
