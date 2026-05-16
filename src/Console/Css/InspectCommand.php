<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Console\Css;

use ReflectionClass;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\PlatformUi\Css\Compiler\BundleCompiler;
use Semitexa\PlatformUi\Css\Extractor\TwigExtractor;
use Semitexa\PlatformUi\Css\Slice\SliceCatalog;
use Semitexa\PlatformUi\Asset\SliceRegistry;
use Semitexa\PlatformUi\Primitive\PrimitiveRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'platform-ui:css:inspect',
    description: 'Scan a template file for sx-*/ui-* usage and report what CSS slices/primitives it would need.',
)]
final class InspectCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Path to a Twig or HTML file (relative or absolute)')
            ->addOption('skin', null, InputOption::VALUE_REQUIRED, 'Skin to embed in size estimate', 'default')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('path');
        $packageRoot = $this->packageRoot();
        if (!str_starts_with($path, '/')) {
            $tryPath = getcwd() . '/' . $path;
            if (is_file($tryPath)) {
                $path = $tryPath;
            }
        }

        if (!is_file($path)) {
            $output->writeln("<error>Not a file: {$path}</error>");
            return Command::FAILURE;
        }

        $catalog = SliceCatalog::withDefaults();
        $primitives = new PrimitiveRegistry();
        $extractor = new TwigExtractor($catalog, $primitives);
        $compiler = new BundleCompiler($catalog, $primitives, $packageRoot . '/resources');

        $manifest = $extractor->extractFile($path);
        $registry = new SliceRegistry();
        $manifest->applyTo($registry);
        $bundle = $compiler->compile($registry, (string) $input->getOption('skin'));

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa.platform-ui.css-inspect/v1',
                'source' => $path,
                'grammar_slices' => $bundle->sliceIds,
                'primitives' => $bundle->primitiveIds,
                'unresolved' => $manifest->unresolved,
                'bundle' => [
                    'hash' => $bundle->hash,
                    'bytes' => $bundle->byteSize(),
                    'gzip_bytes' => $bundle->gzipSize(),
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln("<info>Inspecting</info> {$path}");
        $output->writeln('');
        $output->writeln('Grammar slices (' . count($bundle->sliceIds) . ')');
        foreach ($bundle->sliceIds as $id) {
            $output->writeln("  {$id}");
        }
        $output->writeln('');
        $output->writeln('Primitives (' . count($bundle->primitiveIds) . ')');
        foreach ($bundle->primitiveIds as $id) {
            $output->writeln("  ui=\"{$id}\"");
        }
        if ($manifest->unresolved !== []) {
            $output->writeln('');
            $output->writeln('<comment>Unresolved (add to safelist if dynamic):</comment>');
            foreach ($manifest->unresolved as $entry) {
                $output->writeln("  {$entry}");
            }
        }
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Compiled bundle:</info> %d bytes (gz %d), hash %s',
            $bundle->byteSize(),
            $bundle->gzipSize(),
            $bundle->hash,
        ));

        return Command::SUCCESS;
    }

    private function packageRoot(): string
    {
        return dirname((new ReflectionClass($this))->getFileName(), 4);
    }
}
