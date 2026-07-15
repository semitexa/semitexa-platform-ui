<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorRegistry;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
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

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Filter: primitive | component | behavior')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->seedRegistries();

        $kind = $input->getOption('kind');
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

    /**
     * Seed the discovery-driven registries so the command works regardless of
     * whether the worker lifecycle listener has run in this process. Idempotent.
     */
    private function seedRegistries(): void
    {
        UiPrimitiveRegistry::setClassDiscovery($this->classDiscovery);
        UiPrimitiveRegistry::setFactory(new UiPrimitiveMetadataFactory());
        UiPrimitiveRegistry::initialize();

        UiComponentRegistry::setClassDiscovery($this->classDiscovery);
        UiComponentRegistry::setFactory(new UiComponentMetadataFactory());
        UiComponentRegistry::initialize();

        UiBehaviorRegistry::setClassDiscovery($this->classDiscovery);
        UiBehaviorRegistry::setFactory(new UiBehaviorMetadataFactory());
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
}
