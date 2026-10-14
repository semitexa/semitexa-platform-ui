<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\PlatformUi\Application\Service\Catalog\UiCatalogProjector;
use Semitexa\PlatformUi\Application\Service\Catalog\UiUsageValidator;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Twig\Source;

/**
 * Refuses a UI contract the runtime only tolerates, and template calls the
 * contract would refuse.
 *
 * At runtime a bad #[AsUiContract] example is dropped and named on the
 * contract, so one package cannot take the catalog or the worker down. That
 * makes it quiet, so this is where it is loud: every example the catalog left
 * out, and any contract that could not be built at all, fails the lint.
 *
 * The same contracts then check what templates pass to component() and
 * primitive() ({@see UiUsageValidator}): an unknown prop or slot, a value
 * outside its type or enum, a required prop left out. A typo there used to
 * render silently wrong (an ignored prop) or fail only on the page that used it.
 */
#[AsCommand(
    name: 'lint:ui-contracts',
    description: 'Check every #[AsUiContract] builds with all its examples, and that templates call components and primitives as their contracts declare.',
)]
final class LintUiContractsCommand extends Command
{
    /** Why this check exists, and what taught us; ai:verify prints it when the lint fails. */
    public const RATIONALE = 'Why: a UI contract example is a worked instance of the props schema that the Workbench renders, agents copy and the catalog exports, so an invalid one teaches the wrong thing; the runtime drops it rather than fail, which makes it silent unless something refuses it. Learned 2026-10-14: an invalid example used to throw while the registry booted, so one bad example in any installed package could 500 the Workbench, the CLI catalog or the worker itself.';

    #[InjectAsReadonly]
    protected UiCatalogProjector $projector;

    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    protected function configure(): void
    {
        $this->setName('lint:ui-contracts')
            ->setDescription('Check every #[AsUiContract] builds with all its examples, and that templates call components and primitives as their contracts declare.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output findings as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issues = [];
        $count = 0;
        try {
            $entries = $this->projector->envelope(null, null)['entries'];
            $count = count($entries);
            foreach ($entries as $entry) {
                $source = is_array($entry['source'] ?? null) ? $entry['source'] : [];
                foreach (is_array($entry['broken_examples'] ?? null) ? $entry['broken_examples'] : [] as $broken) {
                    $issues[] = [
                        'entry' => (string) ($entry['name'] ?? ''),
                        'example' => (string) ($broken['name'] ?? ''),
                        'reason' => (string) ($broken['reason'] ?? ''),
                        'path' => (string) ($source['file'] ?? ''),
                        'line' => (int) ($source['line'] ?? 0),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // A contract that cannot be built at all (a prop declared twice, a
            // default outside its values) still stops the catalog: say which.
            $issues[] = ['entry' => '', 'example' => '', 'reason' => $e->getMessage(), 'path' => $e->getFile(), 'line' => $e->getLine()];
        }

        $usage = ['templates' => 0, 'checked' => 0, 'unresolved' => 0, 'unparsed' => 0];
        if ($issues === []) {
            foreach ($this->usageIssues($usage) as $issue) {
                $issues[] = $issue;
            }
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['clean' => $issues === [], 'entries' => $count, 'usage' => $usage, 'errors' => $issues], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return $issues === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        if ($issues === []) {
            $io->success(sprintf(
                'All %d UI catalog entries build with every declared example kept; %d typed calls in %d templates match their contracts (%d values built at render time, %d templates unparsed).',
                $count,
                $usage['checked'],
                $usage['templates'],
                $usage['unresolved'],
                $usage['unparsed'],
            ));

            return Command::SUCCESS;
        }
        foreach ($issues as $issue) {
            if (isset($issue['code'])) {
                $io->writeln(sprintf('  %s:%d  %s %s [%s at %s, expected %s]', $issue['path'], $issue['line'], $issue['entry'], $issue['reason'], $issue['code'], $issue['at'], $issue['expected']));
                continue;
            }
            $io->writeln($issue['entry'] === ''
                ? sprintf('  %s:%d  the catalog could not be built: %s', $issue['path'], $issue['line'], $issue['reason'])
                : sprintf('  %s:%d  %s example \'%s\' left out: %s', $issue['path'], $issue['line'], $issue['entry'], $issue['example'], $issue['reason']));
        }
        $io->error(sprintf('%d UI contract problem(s).', count($issues)));

        return Command::FAILURE;
    }

    /**
     * @param array{templates: int, checked: int, unresolved: int, unparsed: int} $usage
     * @return list<array{entry: string, example: string, reason: string, path: string, line: int, code: string, at: string, expected: string}>
     */
    private function usageIssues(array &$usage): array
    {
        ModuleTemplateRegistry::setModuleRegistry($this->moduleRegistry);
        // The template catalog builds Twig to answer, and Twig needs its extensions found.
        TwigExtensionRegistry::setClassDiscovery($this->classDiscovery);
        $twig = ModuleTemplateRegistry::getTwig();
        $validator = new UiUsageValidator($this->projector);
        $root = rtrim((string) getcwd(), '/') . '/';

        $issues = [];
        foreach ($this->templates() as $path) {
            $code = @file_get_contents($path);
            if (!is_string($code)) {
                $usage['unparsed']++;
                continue;
            }
            $usage['templates']++;
            $relative = str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
            $result = $validator->validate($twig, new Source($code, $relative, $relative));
            $usage['checked'] += $result['checked'];
            $usage['unresolved'] += $result['unresolved'];
            $usage['unparsed'] += $result['unparsed'] ? 1 : 0;
            foreach ($result['issues'] as $issue) {
                $issues[] = [
                    'entry' => $issue['entry'],
                    'example' => '',
                    'reason' => $issue['message'],
                    'path' => $issue['file'],
                    'line' => $issue['line'],
                    'code' => $issue['code'],
                    'at' => $issue['path'],
                    'expected' => $issue['expected'],
                ];
            }
        }

        return $issues;
    }

    /** @return list<string> every *.twig under the module template roots */
    private function templates(): array
    {
        $paths = [];
        foreach (array_unique(array_map(static fn (array $module): string => $module['path'], ModuleTemplateRegistry::getModulePaths())) as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.twig')) {
                    $paths[] = $file->getPathname();
                }
            }
        }
        sort($paths);

        return array_values(array_unique($paths));
    }
}
