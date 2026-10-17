<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Workbench;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Application\Service\Catalog\UiCatalogProjector;
use Semitexa\PlatformUi\Domain\Model\Contract\UiCatalogItem;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Theme\Discovery\SkinDiscovery;

/**
 * Turns the UI catalog into what the Workbench page renders.
 *
 * Nothing here is written for the Workbench alone: every entry, prop and
 * example comes from the same #[AsUiContract] the CLI catalog and the AI
 * prompt read, so a component appears here the moment it declares one, and a
 * preview can never drift from the contract it illustrates.
 *
 * An example is rendered by the page through the real runtime — primitive(),
 * component() or the example's own template — never by a Workbench copy of it.
 */
#[AsService]
final class WorkbenchViewBuilder
{
    public const KINDS = ['primitive' => 'Primitives', 'component' => 'Components', 'behavior' => 'Behaviors'];

    #[InjectAsReadonly]
    protected UiCatalogProjector $catalog;

    /** Not a container service; built the way the theme's own boot code builds it, once per worker. */
    private ?SkinDiscovery $skinDiscovery = null;

    /**
     * Installed skins and the one to preview under. An unknown slug previews
     * under the page's own skin rather than failing.
     *
     * @return array{slugs: list<string>, current: string, url: ?string}
     */
    public function skins(string $requested): array
    {
        $this->skinDiscovery ??= new SkinDiscovery(ProjectRoot::get());
        $slugs = $this->skinDiscovery->availableSlugs();
        sort($slugs);
        $entry = $requested === '' ? null : $this->skinDiscovery->find($requested);
        return ['slugs' => $slugs, 'current' => $entry === null ? '' : $entry->slug, 'url' => $entry?->tokensUrl];
    }

    /**
     * @return array{groups: list<array{kind: string, label: string, entries: list<array<string, mixed>>}>, total: int, previewable: int}
     */
    public function index(): array
    {
        $groups = [];
        $total = $previewable = 0;
        foreach (self::KINDS as $kind => $label) {
            $entries = [];
            foreach ($this->catalog->items($kind) as $item) {
                $examples = $item->contract === null ? 0 : count($item->contract->examples);
                $total++;
                $previewable += $examples > 0 ? 1 : 0;
                $entries[] = [
                    'name' => $item->name(),
                    'short' => self::shortName($item->name()),
                    'summary' => $item->contract->summary ?? '',
                    'typed' => $item->contract !== null,
                    'examples' => $examples,
                    'broken' => $item->contract === null ? 0 : count($item->contract->brokenExamples),
                ];
            }
            usort($entries, static fn (array $a, array $b): int => strcmp($a['short'], $b['short']));
            $groups[] = ['kind' => $kind, 'label' => $label, 'entries' => $entries];
        }
        return ['groups' => $groups, 'total' => $total, 'previewable' => $previewable];
    }

    /**
     * @param array<string, string> $try prop values typed into the Try panel, as the query carried them
     * @return array{name: string, short: string, kind: string, summary: mixed, class: string, source: mixed, template: ?string, props: list<array<string, mixed>>, slots: array<mixed>, a11y: mixed, examples: list<array<string, mixed>>, broken_examples: mixed, try: array<string, mixed>|null}|null
     */
    public function entry(string $name, array $try = []): ?array
    {
        $item = $this->catalog->find($name);
        if ($item === null) {
            return null;
        }
        $described = $this->catalog->describe($item);

        $examples = [];
        foreach ($item->contract->examples ?? [] as $example) {
            $examples[] = $this->example($item, $example);
        }

        return [
            'name' => $item->name(),
            'short' => self::shortName($item->name()),
            'kind' => $item->kind,
            'summary' => $described['summary'],
            'class' => $item->className(),
            'source' => $described['source'],
            'template' => $item->template,
            'props' => $this->props($described),
            'slots' => (array) $described['slots'],
            'a11y' => $described['a11y'] ?? [],
            'examples' => $examples,
            'broken_examples' => $described['broken_examples'] ?? [],
            'try' => $this->tryPanel($item, $try),
            'structure' => self::structure($described),
        ];
    }

    /**
     * A component's parts and slots as a tree: each part with the primitive it
     * renders, what it binds and who provides it, and the events wired to it;
     * then the slots. Events on no declared part hang off the component itself.
     * Read from the catalog description, so it is the registry's own answer.
     *
     * @param array<string, mixed> $described
     * @return array{parts: list<array{name: string, primitive: string, bind: string, provider: string, events: list<array{event: string, handler: string, transport: string}>}>, slots: list<array{name: string, description: string}>, events: list<array{event: string, handler: string, transport: string}>}|null
     */
    private static function structure(array $described): ?array
    {
        $parts = (array) ($described['parts'] ?? []);
        $slots = (array) ($described['slots'] ?? []);
        if ($parts === [] && $slots === []) {
            return null;
        }
        $byPart = [];
        foreach ((array) ($described['events'] ?? []) as $event) {
            $event = (array) $event;
            $byPart[is_string($event['part'] ?? null) ? $event['part'] : ''][] = [
                'event' => is_string($event['event'] ?? null) ? $event['event'] : '',
                'handler' => is_string($event['handler'] ?? null) ? $event['handler'] : '',
                'transport' => is_string($event['transport'] ?? null) ? $event['transport'] : '',
            ];
        }
        $tree = ['parts' => [], 'slots' => [], 'events' => []];
        foreach ($parts as $name => $part) {
            $part = (array) $part;
            $tree['parts'][] = [
                'name' => (string) $name,
                'primitive' => is_string($part['primitive'] ?? null) ? $part['primitive'] : '',
                'bind' => is_string($part['bind'] ?? null) ? $part['bind'] : '',
                'provider' => is_string($part['provider'] ?? null) ? $part['provider'] : '',
                'events' => $byPart[(string) $name] ?? [],
            ];
            unset($byPart[(string) $name]);
        }
        foreach ($slots as $name => $slot) {
            $slot = (array) $slot;
            $tree['slots'][] = ['name' => (string) $name, 'description' => is_string($slot['description'] ?? null) ? $slot['description'] : ''];
        }
        foreach ($byPart as $events) {
            array_push($tree['events'], ...$events);
        }

        return $tree;
    }

    /**
     * The Try panel: a control per scalar prop, and the entry rendered with
     * the values typed into them on top of its first example — through the
     * same SSR path as the examples. Values are checked against the contract
     * first, so a wrong one shows the validator's message, which names the
     * prop's path, instead of reaching the renderer.
     *
     * @param array<string, string> $try
     * @return array{controls: list<array{name: string, type: string, values: list<string>, value: string, required: bool}>, example: array<string, mixed>|null, error: string}|null
     */
    private function tryPanel(UiCatalogItem $item, array $try): ?array
    {
        $contract = $item->contract;
        if ($contract === null || !in_array($item->kind, ['component', 'primitive'], true)) {
            return null;
        }
        $controls = [];
        foreach ($contract->props as $prop) {
            if (!in_array($prop->type, [UiPropType::String, UiPropType::Boolean, UiPropType::Integer, UiPropType::Number, UiPropType::Scalar], true) || $prop->sensitive) {
                continue;
            }
            $controls[] = [
                'name' => $prop->name,
                'type' => $prop->type === UiPropType::Boolean ? 'boolean' : $prop->type->value,
                'values' => array_values(array_map(static fn (int|float|string|bool $v): string => (string) $v, array_filter($prop->values, 'is_scalar'))),
                'value' => $try[$prop->name] ?? '',
                'required' => $prop->required,
            ];
        }
        if ($controls === []) {
            return null;
        }

        $first = $contract->examples === [] ? null : array_values($contract->examples)[0];
        if ($try === []) {
            return ['controls' => $controls, 'example' => null, 'error' => ''];
        }
        $props = $first !== null && $first->template === null ? $first->props : [];
        foreach ($contract->props as $prop) {
            if (!array_key_exists($prop->name, $try)) {
                continue;
            }
            // An emptied control means "not set": the example's own value or the default.
            if ($try[$prop->name] === '') {
                continue;
            }
            $props[$prop->name] = self::typed($prop, $try[$prop->name]);
        }
        try {
            UiProp::validateObject(array_values($contract->props), $props, 'props', open: $contract->extraProps !== null);
        } catch (\InvalidArgumentException $e) {
            return ['controls' => $controls, 'example' => null, 'error' => $e->getMessage()];
        }

        return [
            'controls' => $controls,
            'example' => $this->example($item, new UiExample('try', 'Try it', $props, $first === null ? [] : $first->slots)),
            'error' => '',
        ];
    }

    /** A query string value as the prop's type reads it; what does not parse stays a string, for the validator to name. */
    private static function typed(UiProp $prop, string $value): mixed
    {
        return match ($prop->type) {
            UiPropType::Boolean => match ($value) { 'true' => true, 'false' => false, default => $value },
            UiPropType::Integer => preg_match('/\A-?\d{1,18}\z/', $value) === 1 ? (int) $value : $value,
            UiPropType::Number => is_numeric($value) ? $value + 0 : $value,
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $described
     * @return list<array{name: string, type: string, default: string, values: list<string>, description: string, required: bool}>
     */
    private function props(array $described): array
    {
        $schema = $described['props_schema'] ?? null;
        if (!is_array($schema) || !isset($schema['properties'])) {
            return [];
        }
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $out = [];
        foreach ((array) $schema['properties'] as $propName => $prop) {
            $prop = (array) $prop;
            $type = $prop['type'] ?? 'string';
            $out[] = [
                'name' => (string) $propName,
                'type' => is_array($type) ? implode(' | ', array_filter($type, 'is_string')) : (is_string($type) ? $type : 'string'),
                'default' => array_key_exists('default', $prop) ? TwigLiteral::export($prop['default']) : '',
                'values' => array_values(array_map(static fn (int|float|string|bool $v): string => (string) $v, array_filter((array) ($prop['enum'] ?? []), 'is_scalar'))),
                'description' => is_string($prop['description'] ?? null) ? $prop['description'] : '',
                'required' => in_array($propName, $required, true),
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function example(UiCatalogItem $item, UiExample $example): array
    {
        // An id that is also a CSS selector (#uid in ui-behavior-open): only [a-z0-9-].
        $uid = 'wb-' . trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower(self::shortName($item->name()) . '-' . $example->name)), '-');
        $props = $example->props;
        // Example slots are declared as literal text; the runtime renders slots
        // raw, so the Workbench escapes them on the way in.
        $slots = array_map(static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $example->slots);

        $base = ['name' => $example->name, 'label' => $example->label, 'uid' => $uid];

        if ($example->template !== null) {
            $opts = self::optionString($props);
            return $base + [
                'render' => 'template',
                'template' => $example->template,
                'context' => ['opts' => $opts, 'props' => $props, 'uid' => $uid],
                'snippet' => $this->templateSnippet($example->template, $opts, $uid),
            ];
        }

        // A behavior is a contract on markup: without its own template there is
        // nothing to render it with, and it is not a component.
        if ($item->kind === 'behavior') {
            return $base + ['render' => 'none', 'snippet' => ''];
        }

        if ($item->kind === 'primitive') {
            return $base + [
                'render' => 'primitive',
                'props' => $props,
                'snippet' => '{{ primitive(' . TwigLiteral::export($item->name()) . ($props === [] ? '' : ', ' . TwigLiteral::export($props)) . ') }}',
            ];
        }

        $args = TwigLiteral::export($item->name());
        if ($props !== [] || $slots !== []) {
            $args .= ', ' . TwigLiteral::export($props);
        }
        // The copied Twig must render what the stage shows: slots are rendered
        // raw, so it carries the same escaped text the preview was given.
        if ($slots !== []) {
            $args .= ', ' . TwigLiteral::export($slots);
        }
        return $base + [
            'render' => 'component',
            'props' => $props,
            'slots' => $slots,
            'snippet' => '{{ component(' . $args . ') }}',
        ];
    }

    /** @param array<string, mixed> $props */
    private static function optionString(array $props): string
    {
        $pairs = [];
        foreach ($props as $key => $value) {
            $pairs[] = $key . ': ' . match (true) {
                $value === true => 'true',
                $value === false => 'false',
                is_scalar($value) => (string) $value,
                default => '',
            };
        }
        return implode('; ', $pairs);
    }

    /**
     * The example's own template, with this example's values filled in, is the
     * markup a developer copies: it is real Twig (icon() calls stay as calls).
     */
    private function templateSnippet(string $template, string $opts, string $uid): string
    {
        $prefix = '@platform-ui/';
        if (!str_starts_with($template, $prefix)) {
            return "{% include '" . $template . "' %}";
        }
        $file = \dirname(__DIR__, 4) . '/resources/twig/' . substr($template, strlen($prefix));
        $source = is_file($file) ? (string) file_get_contents($file) : '';
        if ($source === '') {
            return "{% include '" . $template . "' %}";
        }
        $source = str_replace(['{{ opts }}', '{{ uid }}'], [$opts, $uid], $source);
        // Option strings that came out empty leave a bare attribute; tidy them.
        return (string) preg_replace('/ ui-[a-z]+=""/', '', trim($source));
    }

    private static function shortName(string $name): string
    {
        return str_starts_with($name, 'platform.') ? substr($name, 9) : $name;
    }
}
