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
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Server\BootPlatformUiRegistryListener;
use Semitexa\PlatformUi\Application\Service\Tree\UiMcpServer;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\WireCoreInstancesListener;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * An MCP server on stdio for composing screens (ep-platform-ai-ui): one
 * JSON-RPC message per line in, one per line out, until stdin closes. The
 * tools act for a visitor holding the --grant permissions. No HTTP door: the
 * browser's transport stays KISS and HUG.
 *
 *     {"mcpServers": {"semitexa-ui": {"command": "bin/semitexa", "args": ["ui:mcp", "--grant=catalog.read"]}}}
 */
#[AsCommand(
    name: 'ui:mcp',
    description: 'MCP server on stdio: ui_catalog, ui_schema, ui_validate, ui_render (ui:// HTML) for a visitor holding the --grant permissions.',
)]
final class McpCommand extends Command
{
    #[InjectAsReadonly]
    protected UiMcpServer $server;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('grant', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A permission the visitor holds (repeatable, or comma-separated)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootRendering();
        /** @var list<string> $grants */
        $grants = UiPermissions::grantsOf((array) $input->getOption('grant'));
        UiPermissions::actAsHolding($grants);
        try {
            while (($line = fgets(STDIN)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $message = json_decode($line, true);
                $response = is_array($message)
                    ? $this->server->handle($message)
                    : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error: one JSON object per line.']];
                if ($response !== null) {
                    // stdout carries only protocol messages: one per line.
                    fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                    fflush(STDOUT);
                }
            }
        } finally {
            UiPermissions::reset();
        }

        return self::SUCCESS;
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
