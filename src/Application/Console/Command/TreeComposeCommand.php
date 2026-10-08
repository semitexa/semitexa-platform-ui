<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Environment;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Llm\Application\Service\ScriptedProvider;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Server\BootPlatformUiRegistryListener;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeComposer;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\WireCoreInstancesListener;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compose a screen from a description, for a visitor holding the --grant
 * permissions: the configured model (LLM_BACKEND) is asked, every reply is
 * checked, repair errors go back, and the sound tree is drawn. --scripted
 * answers with the given files instead, in order, with no network.
 */
#[AsCommand(
    name: 'ui:tree:compose',
    description: 'Compose a screen from a description: model -> UI tree -> server check -> repair turns -> HTML (--scripted=reply1.json,reply2.json for no network).',
)]
final class TreeComposeCommand extends Command
{
    public const ARTIFACT = 'semitexa.platform-ui.ui-tree-compose/v1';

    #[InjectAsReadonly]
    protected UiTreeComposer $composer;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('description', InputArgument::REQUIRED, 'What the screen is for')
            ->addOption('grant', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A permission the visitor holds (repeatable, or comma-separated)')
            ->addOption('scripted', null, InputOption::VALUE_REQUIRED, 'Comma-separated files: the model\'s replies, in order')
            ->addOption('rounds', null, InputOption::VALUE_REQUIRED, 'At most this many rounds', (string) UiTreeComposer::MAX_ROUNDS)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Accepted for symmetry; output is always JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = null;
        $scripted = $input->getOption('scripted');
        if (is_string($scripted) && $scripted !== '') {
            $replies = [];
            foreach (explode(',', $scripted) as $file) {
                $file = trim($file);
                $reply = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
                // A reply file that cannot be read stops here, named: as an
                // empty reply it would read as the model's invalid JSON.
                if ($reply === false) {
                    $output->writeln((string) json_encode([
                        'artifact' => self::ARTIFACT,
                        'composed' => false,
                        'rounds' => 0,
                        'errorsPerRound' => [],
                        'failure' => sprintf('No model reply could be read from %s.', $file),
                        'html' => null,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

                    return self::FAILURE;
                }
                $replies[] = $reply;
            }
            $provider = new ScriptedProvider($replies);
        }
        $this->bootRendering();
        /** @var list<string> $grants */
        $grants = UiPermissions::grantsOf((array) $input->getOption('grant'));
        UiPermissions::actAsHolding($grants);
        try {
            $result = $this->composer->compose((string) $input->getArgument('description'), $provider, max(1, (int) $input->getOption('rounds')));
        } finally {
            UiPermissions::reset();
        }
        $output->writeln((string) json_encode([
            'artifact' => self::ARTIFACT,
            'composed' => $result['html'] !== null,
            'rounds' => count($result['rounds']),
            'errorsPerRound' => array_map(static fn (array $r): array => array_column($r['errors'], 'code'), $result['rounds']),
            'failure' => $result['failure'],
            'html' => $result['html'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

        return $result['html'] !== null ? self::SUCCESS : self::FAILURE;
    }

    /** As ui:tree:render: the two listeners that wire what drawing needs. */
    private function bootRendering(): void
    {
        if (!$this->container instanceof SemitexaContainer) {
            return;
        }
        $context = new ServerLifecycleContext(server: null, workerId: null, environment: $this->container->get(Environment::class), container: $this->container);
        foreach ([WireCoreInstancesListener::class, BootPlatformUiRegistryListener::class] as $class) {
            $listener = new $class();
            $this->container->injectInto($listener);
            if ($listener instanceof ServerLifecycleListenerInterface) {
                $listener->handle($context);
            }
        }
    }
}
