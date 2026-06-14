<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command\Skins;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\PlatformUi\Application\Service\SkinResolver\Llm\PromptResolverFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'skins:explain-prompt',
    description: 'Show how the LLM skill resolves a natural-language prompt into skin seed parameters, without generating CSS.',
)]
#[AsAiSkill(
    allowed: true,
    summary: 'Explain how a natural-language prompt would be resolved into skin seed parameters, without writing any files.',
    useWhen: 'user wants to preview what colors a prompt would produce before committing to generate a skin.',
    avoidWhen: 'user has already decided to generate — use skins:generate --prompt directly.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    supportsDryRun: false,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['prompt'],
    requiredArguments: ['prompt'],
    executionKind: AiExecutionKind::DirectCommand,
    channels: ['console'],
)]
final class ExplainPromptCommand extends Command
{
    public function __construct(
        private readonly LlmProviderInterface $llmProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('prompt', InputArgument::REQUIRED, 'Natural-language description')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON envelope output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prompt = (string) $input->getArgument('prompt');

        if (!$this->llmProvider->healthCheck()) {
            $output->writeln("<error>LLM provider '{$this->llmProvider->name()}' at {$this->llmProvider->baseUrl()} is unreachable.</error>");
            return Command::FAILURE;
        }

        $factory = new PromptResolverFactory(provider: $this->llmProvider);

        try {
            $result = $factory->create()->resolve($prompt);
        } catch (\Throwable $e) {
            $output->writeln("<error>Resolution failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa.skins-base.skin-resolve/v1',
                'prompt' => $prompt,
                'resolved' => $result->params->toArray(),
                'llm' => $result->toLlmMetadata(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln("<info>Prompt:</info> \"{$prompt}\"");
        $output->writeln('');
        $output->writeln("  seed         {$result->params->seed}");
        $output->writeln('  accent_hint  ' . ($result->params->accentHint ?? '(none)'));
        $output->writeln("  algorithm    {$result->params->algorithm}");
        $output->writeln("  mood         {$result->params->mood}");
        $output->writeln("  rationale    {$result->params->rationale}");
        $output->writeln('');
        $output->writeln("  model        {$result->modelName}");
        $output->writeln("  attempts     {$result->attempts}");
        if ($result->latencyMs !== null) {
            $output->writeln('  latency      ' . number_format($result->latencyMs, 0) . ' ms');
        }

        return Command::SUCCESS;
    }
}
