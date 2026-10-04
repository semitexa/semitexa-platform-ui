<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Workbench;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Application\Service\Catalog\UiCatalogProjector;
use Semitexa\PlatformUi\Domain\Model\Contract\UiCatalogItem;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
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
        return ['slugs' => $slugs, 'current' => $entry?->slug ?? '', 'url' => $entry?->tokensUrl];
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
                ];
            }
            usort($entries, static fn (array $a, array $b): int => strcmp($a['short'], $b['short']));
            $groups[] = ['kind' => $kind, 'label' => $label, 'entries' => $entries];
        }
        return ['groups' => $groups, 'total' => $total, 'previewable' => $previewable];
    }

    /** @return array<string, mixed>|null */
    public function entry(string $name): ?array
    {
        $item = $this->catalog->find($name);
        if ($item === null) {
            return null;
        }
        $described = $this->catalog->describe($item);

        $examples = [];
        foreach ($item->contract?->examples ?? [] as $example) {
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
        ];
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
                'type' => is_array($type) ? implode(' | ', $type) : (string) $type,
                'default' => array_key_exists('default', $prop) ? TwigLiteral::export($prop['default']) : '',
                'values' => array_map('strval', array_values(array_filter((array) ($prop['enum'] ?? []), static fn ($v): bool => $v !== null))),
                'description' => (string) ($prop['description'] ?? ''),
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

        if ($item->kind === 'primitive') {
            return $base + [
                'render' => 'primitive',
                'props' => $props,
                'snippet' => '{{ primitive(' . TwigLiteral::export($item->name()) . ($props === [] ? '' : ', ' . TwigLiteral::export($props)) . ') }}',
            ];
        }

        $args = TwigLiteral::export($item->name());
        if ($props !== [] || $example->slots !== []) {
            $args .= ', ' . TwigLiteral::export($props);
        }
        if ($example->slots !== []) {
            $args .= ', ' . TwigLiteral::export($example->slots);
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
