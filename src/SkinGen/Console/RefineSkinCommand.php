<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\SkinGen\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Llm\Contract\LlmProviderInterface;
use Semitexa\PlatformUi\SkinGen\Llm\RefinementResolver;
use Semitexa\Theme\Skin\KnobResolver;
use Semitexa\Theme\Skin\SkinAlgorithmRegistry;
use Semitexa\Theme\Skin\SkinBuilder;
use Semitexa\Theme\Skin\SkinManifest;
use Semitexa\Theme\Skin\TokenEmitter;
use Semitexa\Theme\Discovery\SkinDiscovery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Refine an existing skin by adjusting its algorithm knobs.
 *
 * Two delta sources:
 *   - `--prompt="more impression"`           → LLM picks which knobs to move
 *   - `--set=shadow_intensity:pronounced`    → explicit structured edit (repeatable)
 *
 * Algorithm + seed stay fixed; only knobs change. The skin is regenerated for
 * BOTH light and dark modes via SkinBuilder; the canonical `tokens.css` is
 * re-emitted by TokenEmitter; `skin.json` history[] grows by one entry.
 *
 * Schema 3.0 only — manifests carrying schema_version != 3.0 are rejected
 * with the migration message from SkinManifest::fromJson(). There is no
 * runtime fallback; obsolete manifests must be regenerated explicitly.
 *
 * Default `--write` target is the same slug; `--as=<new-slug>` forks. Refines
 * always land in the project src/skins/ dir even when the source skin is a
 * framework default.
 */
#[AsCommand(
    name: 'skins:refine',
    description: 'Refine an existing skin by adjusting its knobs (LLM or structured). Regenerates both light and dark mode tokens.',
)]
final class RefineSkinCommand extends Command
{
    public function __construct(
        private readonly LlmProviderInterface $llmProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Skin slug to refine (as discovered by SkinDiscovery)')
            ->addOption('prompt', null, InputOption::VALUE_REQUIRED, 'Natural-language delta (e.g. "more impression, less transparency"). Activates LLM mode.')
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Structured delta as name:value. Repeatable. Mutually exclusive with --prompt.')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'Fork target slug — write to a NEW skin instead of overwriting')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Persist. Default: dry-run preview.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = (string) $input->getArgument('slug');
        $prompt = $input->getOption('prompt');
        $sets = (array) $input->getOption('set');
        $forkTo = $input->getOption('as');
        $write = (bool) $input->getOption('write');
        $asJson = (bool) $input->getOption('json');

        if (is_string($prompt) && $prompt !== '' && $sets !== []) {
            return $this->fail($output, $asJson, 'Cannot combine --prompt with --set. Choose one.');
        }
        if ((!is_string($prompt) || $prompt === '') && $sets === []) {
            return $this->fail($output, $asJson, 'Either --prompt="..." or --set=name:value is required.');
        }

        $discovery = new SkinDiscovery(ProjectRoot::get());
        $entry = $discovery->find($slug);
        if ($entry === null) {
            return $this->fail($output, $asJson, "Skin '{$slug}' not found. Available: " . implode(', ', $discovery->availableSlugs()));
        }
        $skinDir = dirname($entry->tokensFilePath);
        $manifestPath = $skinDir . '/skin.json';
        if (!is_file($manifestPath)) {
            return $this->fail($output, $asJson, "Skin '{$slug}' has no skin.json at {$manifestPath}");
        }

        try {
            $existing = SkinManifest::fromJson((string) file_get_contents($manifestPath));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($output, $asJson, "Cannot refine '{$slug}': " . $e->getMessage());
        }

        $registry = new SkinAlgorithmRegistry();
        if (!$registry->has($existing->algorithm)) {
            return $this->fail($output, $asJson, "Skin references unknown algorithm '{$existing->algorithm}'. Manual skins cannot be refined — re-curate skin.json directly and run skins:rebuild.");
        }
        if ($existing->seedHex === null) {
            return $this->fail($output, $asJson, "Skin '{$slug}' has no seed (algorithm='{$existing->algorithm}'). Manual skins cannot be refined.");
        }
        $algorithm = $registry->get($existing->algorithm);

        try {
            if (is_string($prompt) && $prompt !== '') {
                if (!$this->llmProvider->healthCheck()) {
                    return $this->fail($output, $asJson, "LLM provider '{$this->llmProvider->name()}' at {$this->llmProvider->baseUrl()} is unreachable.");
                }
                $resolver = new RefinementResolver($this->llmProvider);
                $llmOutcome = $resolver->resolve($algorithm, $existing->knobs, $prompt);
                $deltas = $llmOutcome['deltas'];
                $rationale = $llmOutcome['rationale'];
                $source = 'prompt';
            } else {
                $deltas = $this->parseSets($sets);
                $rationale = '(structured --set edit)';
                $source = 'set';
            }
        } catch (\Throwable $e) {
            return $this->fail($output, $asJson, $e->getMessage());
        }

        if ($deltas === []) {
            $output->writeln("<comment>No knob changes proposed.</comment>");
            if (isset($rationale)) {
                $output->writeln("  Rationale: {$rationale}");
            }
            return Command::SUCCESS;
        }

        try {
            $newKnobs = KnobResolver::resolve(
                array_merge($existing->knobs, $deltas),
                $algorithm->knobSchema(),
            );
            $dual = (new SkinBuilder())->buildDualPalette($algorithm, $existing->seedHex, $newKnobs);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($output, $asJson, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail($output, $asJson, 'Regeneration failed: ' . $e->getMessage());
        }

        $now = gmdate('c');
        $historyEntry = [
            'at' => $now,
            'kind' => 'refine',
            'source' => $source,
            'deltas' => $deltas,
            'rationale' => $rationale,
        ];
        if ($source === 'prompt') {
            $historyEntry['prompt'] = $prompt;
            $historyEntry['llm'] = [
                'model' => $this->llmProvider->model(),
                'attempts' => $llmOutcome['attempts'] ?? null,
                'latency_ms' => $llmOutcome['latency_ms'] ?? null,
            ];
        }

        $targetSlug = is_string($forkTo) && $forkTo !== '' ? $forkTo : $slug;
        $history = array_merge($existing->history, [$historyEntry]);

        $manifest = new SkinManifest(
            name: $targetSlug,
            source: $existing->source,
            algorithm: $algorithm->id(),
            seedHex: $existing->seedHex,
            tokens: $dual,
            knobs: $newKnobs,
            history: $history,
            prompt: $existing->prompt,
            llm: $existing->llm,
            generatedAt: $existing->generatedAt,
            updatedAt: $now,
            description: $existing->description,
        );

        $css = (new TokenEmitter())->emit($manifest->tokens, $manifest->emitterContext());

        $targetDir = ProjectRoot::get() . SkinDiscovery::PROJECT_SKINS_DIR . '/' . $targetSlug;

        if ($asJson) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.skins-base.skin-refine/v1',
                'status' => $write ? 'written' : 'dry-run',
                'slug' => $targetSlug,
                'fork' => $targetSlug !== $slug,
                'source_was_framework' => $entry->source === 'framework',
                'deltas' => $deltas,
                'rationale' => $rationale,
                'knobs_after' => $newKnobs,
                'target' => $targetDir,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $verb = $write ? '<info>Refined</info>' : '<comment>Dry-run</comment>';
            $output->writeln("{$verb} '{$slug}'" . ($targetSlug !== $slug ? " → <info>{$targetSlug}</info>" : ''));
            if ($entry->source === 'framework' && $targetSlug === $slug) {
                $output->writeln("  <comment>Source is framework default — writing project-local override to src/skins/{$slug}/.</comment>");
            }
            $output->writeln("  algorithm: {$algorithm->id()}  seed: {$existing->seedHex}");
            $output->writeln('  deltas:    ' . json_encode($deltas, JSON_UNESCAPED_SLASHES));
            $output->writeln("  rationale: {$rationale}");
            $output->writeln('  knobs after: ' . json_encode($newKnobs, JSON_UNESCAPED_SLASHES));
            if (!$write) {
                $output->writeln('');
                $output->writeln("  <comment>Pass --write to persist to {$targetDir}/.</comment>");
                $output->writeln("  <comment>Note: browser caches /assets/skins/{$targetSlug}/tokens.css. Hard-refresh (Ctrl+Shift+R) after --write to see visual changes.</comment>");
            }
        }

        if (!$write) {
            return Command::SUCCESS;
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return $this->fail($output, $asJson, "Failed to create target directory: {$targetDir}");
        }
        file_put_contents($targetDir . '/tokens.css', $css);
        file_put_contents($targetDir . '/skin.json', $manifest->toJson());

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $raw
     * @return array<string, string>
     */
    private function parseSets(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            if (!is_string($entry) || !str_contains($entry, ':')) {
                throw new \InvalidArgumentException("Malformed --set '{$entry}'. Expected 'name:value'.");
            }
            [$name, $value] = explode(':', $entry, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name === '' || $value === '') {
                throw new \InvalidArgumentException("Malformed --set '{$entry}'. Both name and value must be non-empty.");
            }
            $out[$name] = $value;
        }
        return $out;
    }

    private function fail(OutputInterface $output, bool $asJson, string $message): int
    {
        if ($asJson) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.skins-base.skin-refine/v1',
                'status' => 'error',
                'error' => $message,
            ], JSON_PRETTY_PRINT));
        } else {
            $output->writeln("<error>{$message}</error>");
        }
        return Command::FAILURE;
    }
}
