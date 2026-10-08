<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check a UI tree (ep-platform-ai-ui) the way the server checks one before it
 * draws it: its shape, the catalog's contracts, and — for a visitor holding the
 * --grant permissions — what that visitor may be shown. Always one JSON
 * envelope; exit 0 when the tree is sound, 1 when it is not.
 *
 *     bin/semitexa ui:tree:validate screen.json --grant=reports.view
 *     echo '{…}' | bin/semitexa ui:tree:validate -
 */
#[AsCommand(
    name: 'ui:tree:validate',
    description: 'Check a UI tree against the catalog and a visitor\'s permissions; every fault with its path, expected value and repair hint.',
)]
final class TreeValidateCommand extends Command
{
    public const ARTIFACT = 'semitexa.platform-ui.ui-tree-check/v1';

    #[InjectAsReadonly]
    protected UiTreeValidator $validator;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tree', InputArgument::REQUIRED, 'A tree JSON file, or - for stdin')
            ->addOption('grant', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A permission the visitor holds (repeatable, or comma-separated); none: a signed-in visitor without permissions')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Accepted for symmetry; output is always JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = (string) $input->getArgument('tree');
        $document = $source === '-' ? stream_get_contents(STDIN) : (is_file($source) ? file_get_contents($source) : false);
        if (!is_string($document)) {
            $output->writeln((string) json_encode(['artifact' => self::ARTIFACT, 'valid' => false, 'errors' => [['code' => 'tree.unreadable', 'path' => '', 'message' => "No tree could be read from {$source}."]]], JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }

        /** @var list<string> $grants */
        $grants = UiPermissions::grantsOf((array) $input->getOption('grant'));
        UiPermissions::actAsHolding($grants);
        try {
            $result = $this->validator->check($document);
        } finally {
            UiPermissions::reset();
        }

        $output->writeln((string) json_encode([
            'artifact' => self::ARTIFACT,
            'valid' => $result['tree'] !== null,
            'visitor' => ['permissions' => $grants],
            'errors' => array_map(static fn ($e): array => $e->toArray(), $result['errors']),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

        return $result['tree'] !== null ? self::SUCCESS : self::FAILURE;
    }
}
