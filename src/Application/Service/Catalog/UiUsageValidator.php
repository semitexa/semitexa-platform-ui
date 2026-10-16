<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Catalog;

use InvalidArgumentException;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Contract\UiCatalogItem;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\Unary\NegUnary;
use Twig\Node\Node;
use Twig\Source;

/**
 * Checks what templates pass to `component()` and `primitive()` against the
 * contract of what they call: a prop the contract does not declare, a value
 * outside its type or enum, a required prop left out, a slot the component
 * does not have.
 *
 * Read from the parsed template, never by rendering it: no provider, handler
 * or data source runs. Only literal arguments can be checked; a value built
 * at render time (a variable, a filter, a spread) is counted as unresolved and
 * left to the renderer, and a props map that is not a literal is not checked
 * for missing required props either, since it may well carry them.
 * Entries without a contract (untyped components) are not checked.
 */
final class UiUsageValidator
{
    private const CALLS = ['component' => 'component', 'primitive' => 'primitive'];

    private int $checked = 0;

    private int $unresolved = 0;

    /** @var list<array{code: string, path: string, expected: string, message: string, entry: string, file: string, line: int}> */
    private array $issues = [];

    /** @var array<string, UiCatalogItem>|null built once: the projector rebuilds every item per find() */
    private ?array $entries = null;

    public function __construct(private readonly UiCatalogProjector $catalog)
    {
    }

    /**
     * @return array{issues: list<array{code: string, path: string, expected: string, message: string, entry: string, file: string, line: int}>, checked: int, unresolved: int, unparsed: bool}
     */
    public function validate(Environment $twig, Source $source): array
    {
        $this->issues = [];
        $this->checked = $this->unresolved = 0;
        try {
            $module = $twig->parse($twig->tokenize($source));
        } catch (SyntaxError) {
            // lint:templates owns syntax; a template that does not parse here
            // is reported as unchecked, not as clean.
            return ['issues' => [], 'checked' => 0, 'unresolved' => 0, 'unparsed' => true];
        }
        $this->walk($module, $source->getPath() !== '' ? $source->getPath() : $source->getName());

        return ['issues' => $this->issues, 'checked' => $this->checked, 'unresolved' => $this->unresolved, 'unparsed' => false];
    }

    private function walk(Node $node, string $file): void
    {
        if ($node instanceof FunctionExpression && isset(self::CALLS[(string) $node->getAttribute('name')])) {
            $this->call($node, (string) $node->getAttribute('name'), $file);
        }
        foreach ($node as $child) {
            $this->walk($child, $file);
        }
    }

    private function call(FunctionExpression $call, string $function, string $file): void
    {
        $arguments = $call->getNode('arguments');
        $name = $this->argument($arguments, 0, 'name');
        if (!$name instanceof ConstantExpression || !is_string($name->getAttribute('value'))) {
            return;
        }
        $item = $this->entry((string) $name->getAttribute('value'), $function);
        if ($item === null || $item->contract === null) {
            return;
        }
        $this->checked++;
        $line = $call->getTemplateLine();
        $entry = $item->name();

        $props = $this->argument($arguments, 1, 'props');
        if ($props instanceof ArrayExpression) {
            $this->props($props, $item->contract->props, $entry, $file, $line, $item->contract->extraProps !== null);
        } elseif ($props !== null) {
            $this->unresolved++;
        }

        $slots = $function === 'component' ? $this->argument($arguments, 2, 'slots') : null;
        if ($slots instanceof ArrayExpression && $item->metadata instanceof UiComponentMetadata) {
            foreach ($slots->getKeyValuePairs() as $pair) {
                $key = $pair['key'];
                if (!$key instanceof ConstantExpression) {
                    $this->unresolved++;
                    continue;
                }
                $slot = (string) $key->getAttribute('value');
                if (!isset($item->metadata->slots[$slot])) {
                    $this->issue('unknown_slot', "slots.{$slot}", 'one of: ' . (implode(', ', array_keys($item->metadata->slots)) ?: 'no slots'), "{$entry} has no slot named {$slot}.", $entry, $file, $line);
                }
            }
        }
    }

    /** @param array<string, UiProp> $declared */
    private function props(ArrayExpression $props, array $declared, string $entry, string $file, int $line, bool $open = false): void
    {
        $given = [];
        $complete = true;
        foreach ($props->getKeyValuePairs() as $pair) {
            $key = $pair['key'];
            if (!$key instanceof ConstantExpression || $pair['value']->hasAttribute('spread') && $pair['value']->getAttribute('spread')) {
                $complete = false;
                $this->unresolved++;
                continue;
            }
            $prop = (string) $key->getAttribute('value');
            $given[$prop] = true;
            if (!isset($declared[$prop])) {
                if ($open) {
                    continue;
                }
                $this->issue('unknown_prop', "props.{$prop}", 'one of: ' . implode(', ', array_keys($declared)), "{$entry} has no prop named {$prop}.", $entry, $file, $line);
                continue;
            }
            [$known, $value] = $this->literal($pair['value']);
            if (!$known) {
                $this->unresolved++;
                continue;
            }
            try {
                $declared[$prop]->validate($value, "props.{$prop}");
            } catch (InvalidArgumentException $e) {
                $this->issue('invalid_value', "props.{$prop}", $this->expected($declared[$prop]), $e->getMessage(), $entry, $file, $line);
            }
        }
        if (!$complete) {
            return;
        }
        foreach ($declared as $name => $prop) {
            if ($prop->required && !isset($given[$name])) {
                $this->issue('missing_required', "props.{$name}", 'a value', "{$entry} needs prop {$name}.", $entry, $file, $line);
            }
        }
    }

    /** @return array{0: bool, 1: mixed} whether the node is a literal, and its value */
    private function literal(Node $node): array
    {
        if ($node instanceof ConstantExpression) {
            return [true, $node->getAttribute('value')];
        }
        if ($node instanceof NegUnary) {
            [$known, $value] = $this->literal($node->getNode('node'));

            return $known && (is_int($value) || is_float($value)) ? [true, -$value] : [false, null];
        }
        if ($node instanceof ArrayExpression) {
            $out = [];
            foreach ($node->getKeyValuePairs() as $pair) {
                [$keyKnown, $key] = $this->literal($pair['key']);
                [$valueKnown, $value] = $this->literal($pair['value']);
                if (!$keyKnown || !$valueKnown || ($pair['value']->hasAttribute('spread') && $pair['value']->getAttribute('spread'))) {
                    return [false, null];
                }
                $out[$key] = $value;
            }

            return [true, $out];
        }

        return [false, null];
    }

    private function expected(UiProp $prop): string
    {
        $schema = $prop->schema();
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            return 'one of: ' . implode(', ', array_map(static fn (mixed $v): string => var_export($v, true), $schema['enum']));
        }

        return is_string($schema['type'] ?? null) ? (string) $schema['type'] : (string) json_encode($schema['type'] ?? 'value');
    }

    private function argument(Node $arguments, int $position, string $name): ?Node
    {
        if ($arguments->hasNode($name)) {
            return $arguments->getNode($name);
        }

        return $arguments->hasNode((string) $position) ? $arguments->getNode((string) $position) : null;
    }

    private function entry(string $name, string $function): ?UiCatalogItem
    {
        if ($this->entries === null) {
            $this->entries = [];
            foreach ($this->catalog->items() as $item) {
                $this->entries[$item->name()] = $item;
            }
        }
        foreach ([$name, 'platform.' . $name] as $candidate) {
            $item = $this->entries[$candidate] ?? null;
            if ($item !== null && $item->kind === $function) {
                return $item;
            }
        }

        return null;
    }

    private function issue(string $code, string $path, string $expected, string $message, string $entry, string $file, int $line): void
    {
        $this->issues[] = ['code' => $code, 'path' => $path, 'expected' => $expected, 'message' => $message, 'entry' => $entry, 'file' => $file, 'line' => $line];
    }
}
