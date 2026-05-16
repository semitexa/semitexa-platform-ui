<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Console\Css;

use ReflectionClass;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\PlatformUi\Asset\SliceRegistry;
use Semitexa\PlatformUi\Css\Compiler\BundleCompiler;
use Semitexa\PlatformUi\Css\Slice\SliceCatalog;
use Semitexa\PlatformUi\Primitive\PrimitiveRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'platform-ui:css:build',
    description: 'Precompile platform-ui CSS bundles for static asset serving via semitexa/ssr.',
)]
final class BuildCommand extends Command
{
    private const BUNDLES = [
        'full' => 'All grammar slices + all primitives + baseline. Skin-neutral; active skin loads separately via theme resolver.',
        'baseline' => 'Reset + typography only. No grammar, no primitives — for users writing their own CSS.',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('bundle', InputArgument::OPTIONAL, 'Bundle id (full|baseline), empty = all', '')
            ->addOption('skin', null, InputOption::VALUE_REQUIRED, '(DEPRECATED — no effect; full.css is skin-neutral)', 'default')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packageRoot = $this->packageRoot();
        $resourcesPath = $packageRoot . '/resources';
        $staticCssDir = $packageRoot . '/src/Application/Static/css';

        if (!is_dir($staticCssDir) && !mkdir($staticCssDir, 0755, true) && !is_dir($staticCssDir)) {
            $output->writeln("<error>Failed to create {$staticCssDir}</error>");
            return Command::FAILURE;
        }

        $catalog = SliceCatalog::withDefaults();
        $primitives = new PrimitiveRegistry();
        $compiler = new BundleCompiler($catalog, $primitives, $resourcesPath);
        $skin = (string) $input->getOption('skin');
        $skinFlagUsed = $skin !== 'default';
        if ($skinFlagUsed) {
            $output->writeln("<comment>Warning: --skin flag is deprecated and has no effect. full.css is skin-neutral; the active skin loads at runtime via the theme resolver.</comment>");
        }

        $bundles = [];
        foreach (array_keys(self::BUNDLES) as $bundleId) {
            $registry = $this->populateRegistry($bundleId, $catalog, $primitives);
            $bundle = $compiler->compile(
                $registry,
                $skin,
                includeBaseline: true,
            );

            $outPath = $staticCssDir . '/' . $bundleId . '.css';
            file_put_contents($outPath, $bundle->css);

            $bundles[$bundleId] = [
                'path' => $outPath,
                'bytes' => $bundle->byteSize(),
                'gzip' => $bundle->gzipSize(),
                'hash' => $bundle->hash,
                'slices' => count($bundle->sliceIds),
                'primitives' => count($bundle->primitiveIds),
            ];
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa.platform-ui.css-build/v1',
                'skin_neutral' => true,
                'bundles' => $bundles,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln('<info>Compiled bundles (skin-neutral — active skin resolved at runtime)</info>');
        foreach ($bundles as $id => $meta) {
            $output->writeln(sprintf(
                '  %-10s %7d bytes (gz %5d)  %s  [%d slices, %d primitives]',
                $id,
                $meta['bytes'],
                $meta['gzip'],
                $meta['hash'],
                $meta['slices'],
                $meta['primitives'],
            ));
        }
        $output->writeln('');
        $output->writeln('Register in your layout:');
        $output->writeln('  <link rel="stylesheet" href="/assets/platform-ui/css/full.css">          <!-- grammar + primitives -->');
        $output->writeln('  <link rel="stylesheet" href="{{ theme_skin_css() }}">                   <!-- active skin tokens -->');
        $output->writeln('Or via ssr asset pipeline: $collector->requireModule("platform-ui");');

        return Command::SUCCESS;
    }

    private function populateRegistry(
        string $bundleId,
        SliceCatalog $catalog,
        PrimitiveRegistry $primitives,
    ): SliceRegistry {
        $registry = new SliceRegistry();

        if ($bundleId === 'baseline') {
            return $registry;
        }

        if ($bundleId === 'full') {
            foreach ($catalog->attributes() as $attr) {
                $emitter = $catalog->emitter($attr);
                if ($emitter === null) {
                    continue;
                }
                foreach ($emitter->allowedValues() as $value) {
                    $registry->registerGrammar($attr, $value);
                }
            }
            foreach ($primitives->ids() as $id) {
                $registry->registerPrimitive($id);
            }
        }

        return $registry;
    }

    private function packageRoot(): string
    {
        return dirname((new ReflectionClass($this))->getFileName(), 4);
    }
}
