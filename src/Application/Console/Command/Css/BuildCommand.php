<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command\Css;

use ReflectionClass;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\PlatformUi\Application\Service\Asset\SliceRegistry;
use Semitexa\PlatformUi\Application\Service\Css\Compiler\BundleCompiler;
use Semitexa\PlatformUi\Application\Service\Css\Slice\SliceCatalog;
use Semitexa\PlatformUi\Application\Service\Css\PrimitiveRegistry;
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
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Compile and compare with the committed bundles; write nothing, fail when one differs');
    }

    /**
     * Test seam: read and write the bundles in $dir instead of the package's own
     * static css (the sources stay the package's).
     */
    public function withStaticCssDir(string $dir): self
    {
        $command = clone $this;
        $command->staticCssDir = $dir;

        return $command;
    }

    private ?string $staticCssDir = null;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packageRoot = $this->packageRoot();
        $resourcesPath = $packageRoot . '/resources';
        $staticCssDir = $this->staticCssDir ?? $packageRoot . '/src/Application/Static/css';
        $check = (bool) $input->getOption('check');

        if (!$check && !is_dir($staticCssDir) && !mkdir($staticCssDir, 0755, true) && !is_dir($staticCssDir)) {
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
        $stale = [];
        foreach (array_keys(self::BUNDLES) as $bundleId) {
            $registry = $this->populateRegistry($bundleId, $catalog, $primitives);
            $bundle = $compiler->compile(
                $registry,
                $skin,
                includeBaseline: true,
            );

            $outPath = $staticCssDir . '/' . $bundleId . '.css';
            if ($check) {
                // The served CSS is the committed file, not the sources: a source fix
                // nobody rebuilt never reaches a page (tk-full-css-drifts-from-its-sources).
                if (!is_file($outPath) || file_get_contents($outPath) !== $bundle->css) {
                    $stale[] = $bundleId . '.css';
                }
            } else {
                file_put_contents($outPath, $bundle->css);
            }

            $bundles[$bundleId] = [
                'path' => $outPath,
                'bytes' => $bundle->byteSize(),
                'gzip' => $bundle->gzipSize(),
                'hash' => $bundle->hash,
                'slices' => count($bundle->sliceIds),
                'primitives' => count($bundle->primitiveIds),
            ];
        }

        if ($check) {
            return $this->reportCheck($output, $stale, (bool) $input->getOption('json'));
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa.platform-ui.css-build/v1',
                'skin_neutral' => true,
                'bundles' => $bundles,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
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
        $output->writeln("  <link rel=\"stylesheet\" href=\"{{ asset('css/full.css', 'platform-ui') }}\">   <!-- grammar + primitives -->");
        $output->writeln('  <link rel="stylesheet" href="{{ theme_skin_css() }}">                   <!-- active skin tokens -->');
        $output->writeln('Or via ssr asset pipeline: $collector->requireModule("platform-ui");');

        return Command::SUCCESS;
    }

    /** @param list<string> $stale the committed bundles that differ from a fresh compile */
    private function reportCheck(OutputInterface $output, array $stale, bool $json): int
    {
        $rebuild = 'bin/semitexa platform-ui:css:build';
        if ($json) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.platform-ui.css-build/v1',
                'check' => true,
                'up_to_date' => $stale === [],
                'stale' => $stale,
                'rebuild' => $rebuild,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return $stale === [] ? Command::SUCCESS : Command::FAILURE;
        }
        if ($stale === []) {
            $output->writeln('<info>' . implode(' and ', array_map(static fn (string $id): string => $id . '.css', array_keys(self::BUNDLES))) . ' match their sources.</info>');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf(
            '<error>Stale: %s. The sources changed since it was built, and pages are served the built file, not the sources. Rebuild: %s, and commit the result.</error>',
            implode(', ', $stale),
            $rebuild,
        ));

        return Command::FAILURE;
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
        // Walk up from this file until the package composer.json — robust to the
        // command's nesting depth (it lives under src/Application/Console/Command/Css/).
        $dir = dirname((string) (new ReflectionClass($this))->getFileName());
        while ($dir !== dirname($dir)) {
            if (is_file($dir . '/composer.json')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        // Fallback: src/Application/Console/Command/Css → package root is 6 up.
        return dirname((string) (new ReflectionClass($this))->getFileName(), 6);
    }
}
