<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Environment;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\PlatformUi\Application\Service\Server\BootPlatformUiRegistryListener;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\WireCoreInstancesListener;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeRenderer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Draw a UI tree as the server would for a visitor holding the --grant
 * permissions: checked first, drawn only when sound. One JSON envelope with
 * the HTML or the errors; exit 1 when the tree was refused.
 */
#[AsCommand(
    name: 'ui:tree:render',
    description: 'Check a UI tree for a visitor and draw it: the HTML, or every fault with its path and repair hint.',
)]
final class TreeRenderCommand extends Command
{
    public const ARTIFACT = 'semitexa.platform-ui.ui-tree-render/v1';

    #[InjectAsReadonly]
    protected UiTreeRenderer $renderer;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tree', InputArgument::REQUIRED, 'A tree JSON file, or - for stdin')
            ->addOption('grant', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A permission the visitor holds (repeatable, or comma-separated)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Accepted for symmetry; output is always JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = (string) $input->getArgument('tree');
        $document = $source === '-' ? stream_get_contents(STDIN) : (is_file($source) ? file_get_contents($source) : false);
        /** @var list<string> $grants */
        $grants = UiPermissions::grantsOf((array) $input->getOption('grant'));
        $this->bootRendering();
        UiPermissions::actAsHolding($grants);
        try {
            $result = is_string($document)
                ? $this->renderer->render($document)
                : ['html' => null, 'errors' => []];
        } finally {
            UiPermissions::reset();
        }
        $output->writeln((string) json_encode([
            'artifact' => self::ARTIFACT,
            'rendered' => $result['html'] !== null,
            'html' => $result['html'],
            'errors' => array_map(static fn ($e): array => $e->toArray(), $result['errors']),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

        return $result['html'] !== null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A worker wires the renderers at boot; a one-shot command has no worker,
     * so the two listeners that wire what drawing needs (the SSR core: Twig,
     * the component catalog; Platform UI's registries) run here.
     */
    private function bootRendering(): void
    {
        $context = new ServerLifecycleContext(
            server: null,
            workerId: null,
            environment: $this->container->get(Environment::class),
            container: $this->container instanceof SemitexaContainer ? $this->container : null,
        );
        if (!$this->container instanceof SemitexaContainer) {
            return;
        }
        // Built the way the lifecycle invoker builds a listener: new, then injected.
        foreach ([WireCoreInstancesListener::class, BootPlatformUiRegistryListener::class] as $class) {
            $listener = new $class();
            $this->container->injectInto($listener);
            if ($listener instanceof ServerLifecycleListenerInterface) {
                $listener->handle($context);
            }
        }
    }
}
