<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Application\Prompt\UiComposePrompt;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Tree\UiAgentManifest;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeParser;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTree;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What an agent is told (ep-platform-ai-ui · tk-ai-catalog): the components a
 * visitor holding the --grant permissions may be shown, with their props and
 * slots; with --schema the JSON Schema of a whole tree; with --prompt the
 * rendered prompt that asks a model for one.
 */
#[AsCommand(
    name: 'ui:tree:catalog',
    description: 'The components an agent may compose a screen from, for a visitor: manifest, --schema (whole-tree JSON Schema), --prompt (the compose prompt).',
)]
final class TreeCatalogCommand extends Command
{
    #[InjectAsReadonly]
    protected UiAgentManifest $manifest;

    #[InjectAsReadonly]
    protected PromptRenderer $prompts;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('grant', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A permission the visitor holds (repeatable, or comma-separated)')
            ->addOption('schema', null, InputOption::VALUE_NONE, 'Add the JSON Schema of a whole tree')
            ->addOption('prompt', null, InputOption::VALUE_NONE, 'Print the rendered compose prompt instead (text)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Accepted for symmetry; the manifest is always JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $grants */
        $grants = UiPermissions::grantsOf((array) $input->getOption('grant'));
        UiPermissions::actAsHolding($grants);
        try {
            if ($input->getOption('prompt')) {
                $prompt = (new UiComposePrompt())->withCatalog($this->manifest->describe(), UiTree::VERSION, $this->manifest->describeActions(), UiTreeParser::MAX_NODES);
                $output->writeln($this->prompts->render($prompt)->system, OutputInterface::OUTPUT_RAW);

                return self::SUCCESS;
            }
            $envelope = ['artifact' => UiAgentManifest::ARTIFACT, 'visitor' => ['permissions' => $grants], 'components' => $this->manifest->components()];
            if ($input->getOption('schema')) {
                $envelope['treeSchema'] = $this->manifest->treeSchema();
            }
            $output->writeln((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);
        } finally {
            UiPermissions::reset();
        }

        return self::SUCCESS;
    }
}
