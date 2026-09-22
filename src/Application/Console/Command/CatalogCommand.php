<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Application\Service\Catalog\UiCatalogProjector;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorCatalog;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorRegistry;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentCatalog;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveCatalog;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\PlatformUi\Domain\Model\Behavior\BehaviorMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The single window into the whole Platform UI catalog: every discovered
 * primitive, composed component, and client behavior — with their identities,
 * options, and (for behaviors) declared a11y capabilities.
 *
 * This is the developer-facing "what's available" tool: one command instead of
 * grepping for attributes. It reads the same discovery-seeded registries the
 * renderer/runtime use, so it can never drift from what actually ships. It also
 * powers the (future) generated showcase and AI scaffolding.
 */
#[AsCommand(
    name: 'platform-ui:catalog',
    description: 'List the full UI catalog — primitives, components, and behaviors with their options and a11y.',
)]
final class CatalogCommand extends Command
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected UiCatalogProjector $projector;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Filter: primitive | component | behavior')
            ->addOption('details', null, InputOption::VALUE_NONE, 'Version 2: full contracts, examples, sources and event bindings')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Describe one canonical entry (implies --details)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('details') || $input->getOption('name') !== null) {
            return $this->describe($input, $output);
        }
        $this->seedRegistries();

        $kind = $input->getOption('kind');
        if ($kind !== null && !in_array($kind, ['primitive', 'component', 'behavior'], true)) {
            $output->writeln('<error>Kind must be primitive, component or behavior.</error>');
            return Command::INVALID;
        }
        $wants = static fn (string $k): bool => $kind === null || $kind === $k;

        /** @var list<PrimitiveMetadata> $primitives */
        $primitives = $wants('primitive') ? UiPrimitiveRegistry::all() : [];
        /** @var list<UiComponentMetadata> $components */
        $components = $wants('component') ? UiComponentRegistry::all() : [];
        /** @var list<BehaviorMetadata> $behaviors */
        $behaviors = $wants('behavior') ? UiBehaviorRegistry::all() : [];

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.platform-ui.catalog/v1',
                'counts' => ['primitives' => count($primitives), 'components' => count($components), 'behaviors' => count($behaviors)],
                'primitives' => array_map(static fn (PrimitiveMetadata $p): array => $p->toArray(), $primitives),
                'components' => array_map(self::componentToArray(...), $components),
                'behaviors' => array_map(static fn (BehaviorMetadata $b): array => $b->toArray(), $behaviors),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        if ($primitives !== []) {
            $output->writeln("<info>Primitives</info> (" . count($primitives) . ') — @layer platform-ui.primitives');
            foreach ($primitives as $p) {
                $output->writeln(sprintf('  <comment>%s</comment>  ui="%s"%s', $p->name, $p->ui, $p->events !== [] ? '  events: ' . count($p->events) : ''));
            }
            $output->writeln('');
        }

        if ($components !== []) {
            $output->writeln("<info>Components</info> (" . count($components) . ')');
            foreach ($components as $c) {
                $parts = implode(', ', array_keys($c->parts));
                $slots = implode(', ', array_keys($c->slots));
                $output->writeln(sprintf('  <comment>%s</comment>%s%s', $c->name, $parts !== '' ? "  parts: {$parts}" : '', $slots !== '' ? "  slots: {$slots}" : ''));
            }
            $output->writeln('');
        }

        if ($behaviors !== []) {
            $output->writeln("<info>Behaviors</info> (" . count($behaviors) . ') — declarative ui-behavior="...", client-only');
            foreach ($behaviors as $b) {
                $opts = implode('; ', array_map(static fn ($o): string => $o->name, $b->options));
                $output->writeln(sprintf('  <comment>%s</comment>  ui-behavior="%s"', $b->name, $b->ui));
                if ($opts !== '') {
                    $output->writeln("      options: {$opts}");
                }
                if ($b->a11y !== []) {
                    $output->writeln('      a11y:    ' . implode(', ', $b->a11y));
                }
            }
            $output->writeln('');
        }

        $output->writeln(sprintf(
            '<info>Total:</info> %d primitives, %d components, %d behaviors.',
            count($primitives),
            count($components),
            count($behaviors),
        ));

        return Command::SUCCESS;
    }

    private function describe(InputInterface $input, OutputInterface $output): int
    {
        try {
            $kind = $input->getOption('kind');
            $name = $input->getOption('name');
            $result = $this->projector->envelope(
                is_string($kind) ? $kind : null,
                is_string($name) ? $name : null,
            );
        } catch (\InvalidArgumentException $e) {
            $output->writeln(json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));
            return Command::INVALID;
        }
        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }
        foreach ($result['entries'] as $entry) {
            $source = (array) ($entry['source'] ?? []);
            $output->writeln(sprintf(
                '<info>%s</info> (%s, %s) — %s',
                self::text($entry['name']),
                self::text($entry['kind']),
                self::text($entry['typing']),
                self::text($entry['summary']),
            ));
            $output->writeln(sprintf('  source: %s:%d', self::text($source['file'] ?? ''), self::number($source['line'] ?? 0)));
            $schemaBlock = (array) ($entry['props_schema'] ?? []);
            foreach ((array) ($schemaBlock['properties'] ?? []) as $name => $schema) {
                $output->writeln('  ' . $name . ': ' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
            $output->writeln('  slots: ' . implode(', ', array_keys((array) ($entry['slots'] ?? []))));
            $output->writeln('  examples: ' . implode(', ', array_column((array) ($entry['examples'] ?? []), 'name')));
        }
        return Command::SUCCESS;
    }

    /**
     * Seed the discovery-driven registries so the command works regardless of
     * whether the worker lifecycle listener has run in this process. Idempotent.
     */
    private function seedRegistries(): void
    {
        $primitiveCatalog = new UiPrimitiveCatalog();
        $primitiveCatalog->setClassDiscovery($this->classDiscovery);
        $primitiveCatalog->setFactory(new UiPrimitiveMetadataFactory());
        UiPrimitiveRegistry::setCatalog($primitiveCatalog);
        UiPrimitiveRegistry::initialize();

        // CLI has no worker boot, so the catalog is built here and pushed in.
        $componentCatalog = new UiComponentCatalog();
        $componentCatalog->setClassDiscovery($this->classDiscovery);
        $componentCatalog->setFactory(new UiComponentMetadataFactory());
        UiComponentRegistry::setCatalog($componentCatalog);
        UiComponentRegistry::initialize();

        $behaviorCatalog = new UiBehaviorCatalog();
        $behaviorCatalog->setClassDiscovery($this->classDiscovery);
        $behaviorCatalog->setFactory(new UiBehaviorMetadataFactory());
        UiBehaviorRegistry::setCatalog($behaviorCatalog);
        UiBehaviorRegistry::initialize();
    }

    /**
     * @return array{name: string, parts: list<string>, slots: list<string>, events: int}
     */
    private static function componentToArray(UiComponentMetadata $c): array
    {
        return [
            'name' => $c->name,
            'parts' => array_keys($c->parts),
            'slots' => array_keys($c->slots),
            'events' => count($c->events),
        ];
    }

    /** Catalog entries are mixed by construction; render scalars, skip the rest. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
