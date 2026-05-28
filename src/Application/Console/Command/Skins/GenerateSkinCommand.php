<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command\Skins;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Policy\AiArgumentPolicy;
use Semitexa\Llm\Policy\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Policy\AiRiskLevel;
use Semitexa\PlatformUi\Application\Service\SkinResolver\Llm\PromptResolverFactory;
use Semitexa\PlatformUi\Application\Service\SkinResolver\Llm\ResolutionResult;
use Semitexa\Theme\Application\Service\Skin\KnobResolver;
use Semitexa\Theme\Application\Service\Skin\SkinAlgorithmRegistry;
use Semitexa\Theme\Application\Service\Skin\SkinBuilder;
use Semitexa\Theme\Application\Service\Skin\SkinManifest;
use Semitexa\Theme\Application\Service\Skin\TokenEmitter;
use Semitexa\Theme\Discovery\SkinDiscovery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'skins:generate',
    description: 'Generate a semantic skin from a primary color hex (seed mode) or a natural-language prompt (LLM mode). Writes both light and dark mode tokens to src/skins/.',
)]
#[AsAiSkill(
    allowed: true,
    summary: 'Generate a UI skin (semantic color palette) from a primary hex color or a natural-language description of a mood. Always produces both light and dark mode tokens.',
    useWhen: 'user asks for a new theme, skin, palette, or color scheme — either in prose ("створи скін про море і захід сонця", "make a playful startup theme") or with an explicit hex color.',
    avoidWhen: 'user wants to tweak a single token value directly, switch an existing skin, or query which skins are installed (use skins:list or skins:inspect instead).',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::WhenMutating,
    supportsDryRun: true,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['algorithm', 'hex', 'prompt', 'name', 'write'],
    requiredArguments: [],
    executionKind: AiExecutionKind::DirectCommand,
    channels: ['console'],
)]
final class GenerateSkinCommand extends Command
{
    public function __construct(
        private readonly LlmProviderInterface $llmProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $registry = new SkinAlgorithmRegistry();
        $algoList = implode(', ', $registry->ids());

        $this
            ->addArgument('algorithm', InputArgument::OPTIONAL, "Algorithm id ({$algoList})", 'balanced')
            ->addArgument('hex', InputArgument::OPTIONAL, 'Primary color as #rrggbb (omit if --prompt)')
            ->addOption('prompt', null, InputOption::VALUE_REQUIRED, 'Natural-language description of the desired mood (activates LLM mode)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Skin slug (directory name under src/skins/)', 'custom')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Optional skin description for the tokens.css header')
            ->addOption('knob', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Algorithm knob as name:value, repeatable. See --describe.')
            ->addOption('describe', null, InputOption::VALUE_NONE, 'Show available algorithms with their knob schemas and exit')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Persist skin.json + tokens.css (default: dry-run preview)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = new SkinAlgorithmRegistry();

        if ((bool) $input->getOption('describe')) {
            $this->describeAlgorithms($output, $registry);
            return Command::SUCCESS;
        }

        $prompt = $input->getOption('prompt');
        $hex = $input->getArgument('hex');

        $resolution = null;

        if (is_string($prompt) && $prompt !== '') {
            if (is_string($hex) && $hex !== '') {
                return $this->fail($output, 'Cannot combine --prompt with <hex> argument. Choose one.');
            }
            if (!$this->llmProvider->healthCheck()) {
                return $this->fail($output, "LLM provider '{$this->llmProvider->name()}' at {$this->llmProvider->baseUrl()} is unreachable. Start Ollama or use seed mode: skins:generate balanced \"#rrggbb\".");
            }
            try {
                $resolution = $this->resolvePrompt($prompt);
            } catch (\Throwable $e) {
                return $this->fail($output, 'LLM resolution failed: ' . $e->getMessage());
            }
            $hex = $resolution->params->seed;
            $algorithmId = $resolution->params->algorithm;
        } else {
            $algorithmId = (string) $input->getArgument('algorithm');
            if (!is_string($hex) || $hex === '') {
                return $this->fail($output, 'Missing <hex> argument. Usage: skins:generate <algorithm> "#rrggbb"  OR  skins:generate --prompt "..."');
            }
        }

        if (!$registry->has($algorithmId)) {
            return $this->fail($output, "Unknown algorithm '{$algorithmId}'. Available: " . implode(', ', $registry->ids()));
        }
        $algorithm = $registry->get($algorithmId);

        // Knob sources — CLI --knob overrides win over LLM-proposed knobs.
        $cliKnobs = $this->parseKnobOptions((array) $input->getOption('knob'));
        $llmKnobs = $resolution !== null ? $resolution->params->knobs : [];
        $mergedKnobs = array_merge($llmKnobs, $cliKnobs);
        try {
            $resolvedKnobs = KnobResolver::resolve($mergedKnobs, $algorithm->knobSchema());
            $dual = (new SkinBuilder())->buildDualPalette($algorithm, (string) $hex, $resolvedKnobs);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }

        $now = gmdate('c');
        $historyEntry = [
            'at' => $now,
            'kind' => 'generate',
            'algorithm' => $algorithm->id(),
            'seed' => $hex,
            'knobs' => $resolvedKnobs,
            'source' => $resolution !== null ? 'prompt' : 'seed',
        ];
        if ($resolution !== null) {
            $historyEntry['prompt'] = $prompt;
            $historyEntry['llm_mood'] = $resolution->params->mood;
        }

        $name = (string) $input->getOption('name');
        $description = $input->getOption('description');
        $manifest = new SkinManifest(
            name: $name,
            source: $resolution !== null ? 'prompt' : 'seed',
            algorithm: $algorithm->id(),
            seedHex: (string) $hex,
            tokens: $dual,
            knobs: $resolvedKnobs,
            history: [$historyEntry],
            prompt: $resolution !== null ? $prompt : null,
            llm: $resolution?->toLlmMetadata(),
            generatedAt: $now,
            updatedAt: $now,
            description: is_string($description) && $description !== '' ? $description : null,
        );

        $css = (new TokenEmitter())->emit($manifest->tokens, $manifest->emitterContext());

        if ((bool) $input->getOption('write')) {
            $skinDir = $this->projectSkinsDir() . '/' . $manifest->name;
            if (!is_dir($skinDir) && !mkdir($skinDir, 0755, true) && !is_dir($skinDir)) {
                return $this->fail($output, "Failed to create skin directory: {$skinDir}");
            }
            file_put_contents($skinDir . '/tokens.css', $css);
            file_put_contents($skinDir . '/skin.json', $manifest->toJson());

            if ((bool) $input->getOption('json')) {
                $output->writeln($this->jsonEnvelope('written', $manifest, $skinDir, $resolution));
            } else {
                $output->writeln("<info>Wrote skin '{$manifest->name}' to {$skinDir}</info>");
                if ($resolution !== null) {
                    $output->writeln("  via prompt: \"{$prompt}\"");
                    $output->writeln("  resolved seed: {$resolution->params->seed}"
                        . ($resolution->params->accentHint !== null ? " + {$resolution->params->accentHint}" : ''));
                    $output->writeln("  mood: {$resolution->params->mood} | attempts: {$resolution->attempts} | model: {$resolution->modelName}");
                }
                $output->writeln('  tokens.css  ' . strlen($css) . ' bytes (light + dark)');
                $output->writeln('  skin.json   ' . strlen($manifest->toJson()) . ' bytes (schema 3.0)');
            }
            return Command::SUCCESS;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln($this->jsonEnvelope('dry-run', $manifest, null, $resolution));
        } else {
            $output->writeln("<comment>Dry run — pass --write to persist to {$this->projectSkinsDir()}/{$manifest->name}/.</comment>");
            if ($resolution !== null) {
                $output->writeln("  resolved: seed={$resolution->params->seed}"
                    . ($resolution->params->accentHint !== null ? " accent={$resolution->params->accentHint}" : '')
                    . " mood={$resolution->params->mood}");
                $output->writeln("  rationale: {$resolution->params->rationale}");
                $output->writeln('');
            }
            $output->writeln($css);
        }
        return Command::SUCCESS;
    }

    private function resolvePrompt(string $userPrompt): ResolutionResult
    {
        $factory = new PromptResolverFactory(provider: $this->llmProvider);
        return $factory->create()->resolve($userPrompt);
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln("<error>{$message}</error>");
        return Command::FAILURE;
    }

    private function jsonEnvelope(string $status, SkinManifest $manifest, ?string $path, ?ResolutionResult $resolution): string
    {
        $envelope = [
            'artifact' => 'semitexa.skins-base.skin/v1',
            'status' => $status,
            'path' => $path,
            'skin' => $manifest->toArray(),
        ];
        if ($resolution !== null) {
            $envelope['resolution'] = [
                'attempts' => $resolution->attempts,
                'model' => $resolution->modelName,
                'latency_ms' => $resolution->latencyMs,
                'mood' => $resolution->params->mood,
                'rationale' => $resolution->params->rationale,
            ];
        }
        return json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function projectSkinsDir(): string
    {
        return ProjectRoot::get() . SkinDiscovery::PROJECT_SKINS_DIR;
    }

    /**
     * @param list<string> $raw
     * @return array<string, string>
     */
    private function parseKnobOptions(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            if (!is_string($entry) || !str_contains($entry, ':')) {
                throw new \InvalidArgumentException("Malformed --knob '{$entry}'. Expected 'name:value'.");
            }
            [$name, $value] = explode(':', $entry, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name === '' || $value === '') {
                throw new \InvalidArgumentException("Malformed --knob '{$entry}'. Both name and value must be non-empty.");
            }
            $out[$name] = $value;
        }
        return $out;
    }

    private function describeAlgorithms(OutputInterface $output, SkinAlgorithmRegistry $registry): void
    {
        foreach ($registry->all() as $algo) {
            $output->writeln("<info>{$algo->id()}</info> — {$algo->description()}");
            $schema = $algo->knobSchema();
            if ($schema === []) {
                $output->writeln('  <comment>(no tunable knobs)</comment>');
            } else {
                foreach ($schema as $name => $spec) {
                    $output->writeln(sprintf(
                        '  <comment>%-22s</comment> %s  default=<info>%s</info>  %s',
                        $name,
                        '[' . implode('|', $spec['enum']) . ']',
                        $spec['default'],
                        $spec['description'],
                    ));
                }
            }
            $output->writeln('');
        }
    }
}
