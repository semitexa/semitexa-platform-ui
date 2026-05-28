<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command\SkinGen;

use ReflectionClass;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Llm\Contract\LlmProviderInterface;
use Semitexa\PlatformUi\Application\Service\SkinGen\Eval\ResolverScorer;
use Semitexa\PlatformUi\Application\Service\SkinGen\Llm\PromptResolverFactory;
use Semitexa\PlatformUi\Application\Service\SkinGen\Llm\ValidationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'skins:eval:run',
    description: 'Run the prompt-resolution eval corpus against the live LLM and report hit rate.',
)]
final class RunCommand extends Command
{
    public function __construct(
        private readonly LlmProviderInterface $llmProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fixtures', null, InputOption::VALUE_REQUIRED, 'Fixtures JSON path', 'tests/Fixtures/skin-prompts.json')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output')
            ->addOption('fail-threshold', null, InputOption::VALUE_REQUIRED, 'Min hit rate 0..1 to exit 0', '0.8');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packageRoot = $this->packageRoot();
        $fixturesPath = $packageRoot . '/' . $input->getOption('fixtures');

        if (!is_file($fixturesPath)) {
            $output->writeln("<error>Fixtures not found: {$fixturesPath}</error>");
            return Command::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($fixturesPath), true);
        if (!is_array($manifest) || !isset($manifest['fixtures'])) {
            $output->writeln("<error>Invalid fixtures file</error>");
            return Command::FAILURE;
        }

        if (!$this->llmProvider->healthCheck()) {
            $output->writeln("<error>LLM provider '{$this->llmProvider->name()}' at {$this->llmProvider->baseUrl()} is unreachable.</error>");
            return Command::FAILURE;
        }

        $factory = new PromptResolverFactory($this->llmProvider, $packageRoot . '/resources');
        $resolver = $factory->create();
        $scorer = new ResolverScorer();

        $results = [];
        $hits = 0;
        $fails = 0;
        $errors = 0;

        foreach ($manifest['fixtures'] as $fixture) {
            $id = $fixture['id'];
            $prompt = $fixture['prompt'];
            $expect = $fixture['expect'];
            $kind = $fixture['kind'] ?? 'mono';

            try {
                $result = $resolver->resolve($prompt);
                $report = $scorer->score($result->params, $expect);
                $hit = $report->hit();
                if ($hit) {
                    $hits++;
                } else {
                    $fails++;
                }
                $results[] = [
                    'id' => $id,
                    'kind' => $kind,
                    'prompt' => $prompt,
                    'status' => $hit ? 'hit' : 'miss',
                    'passed' => $report->passed,
                    'total' => $report->total,
                    'checks' => $report->checks,
                    'resolved' => $result->params->toArray(),
                    'attempts' => $result->attempts,
                    'latency_ms' => $result->latencyMs,
                ];
                $output->writeln(sprintf(
                    '  %-4s  %-24s  %2d/%-2d  seed=%s  %s',
                    $hit ? 'HIT' : 'miss',
                    $id,
                    $report->passed,
                    $report->total,
                    $result->params->seed,
                    $this->summarizeFails($report->checks),
                ));
            } catch (ValidationException $e) {
                $errors++;
                $results[] = [
                    'id' => $id,
                    'kind' => $kind,
                    'prompt' => $prompt,
                    'status' => 'validation_error',
                    'error' => $e->getMessage(),
                ];
                $output->writeln(sprintf('  err   %-24s  validation: %s', $id, $e->getMessage()));
            } catch (\Throwable $e) {
                $errors++;
                $results[] = [
                    'id' => $id,
                    'kind' => $kind,
                    'prompt' => $prompt,
                    'status' => 'llm_error',
                    'error' => $e->getMessage(),
                ];
                $output->writeln(sprintf('  err   %-24s  llm: %s', $id, $e->getMessage()));
            }
        }

        $total = count($manifest['fixtures']);
        $rate = $total > 0 ? $hits / $total : 0.0;
        $threshold = (float) $input->getOption('fail-threshold');

        $summary = [
            'artifact' => 'semitexa.platform-ui.eval-report/v1',
            'model' => $this->llmProvider->model(),
            'fixtures_path' => $fixturesPath,
            'total' => $total,
            'hits' => $hits,
            'misses' => $fails,
            'errors' => $errors,
            'hit_rate' => $rate,
            'threshold' => $threshold,
            'pass' => $rate >= $threshold,
            'results' => $results,
        ];

        $reportPath = $packageRoot . '/var/last-report.json';
        @mkdir(dirname($reportPath), 0755, true);
        file_put_contents($reportPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln('');
            $output->writeln(sprintf(
                '<info>Hits: %d/%d (%.0f%%)  Misses: %d  Errors: %d  Model: %s</info>',
                $hits, $total, $rate * 100, $fails, $errors, $this->llmProvider->model(),
            ));
            $output->writeln("Report: {$reportPath}");
            if ($rate < $threshold) {
                $output->writeln(sprintf('<comment>Below threshold %.2f — investigate misses above.</comment>', $threshold));
            }
        }

        return $rate >= $threshold ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param list<array{label: string, pass: bool, detail: string}> $checks */
    private function summarizeFails(array $checks): string
    {
        $fails = array_filter($checks, static fn(array $c): bool => !$c['pass']);
        if ($fails === []) {
            return '';
        }
        return '(' . implode(', ', array_map(static fn(array $c): string => "{$c['label']}: {$c['detail']}", $fails)) . ')';
    }

    private function packageRoot(): string
    {
        // File lives at src/Application/Console/Command/SkinGen/RunCommand.php → 6 levels up reaches the package root.
        return dirname((new ReflectionClass($this))->getFileName(), 6);
    }
}
