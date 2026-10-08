<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Tree;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Tree\UiMcpServer;

/**
 * tk-ai-mcp: the server speaks JSON-RPC 2.0 the way an MCP host expects — an
 * answer for every request, none for a notification, an error for anything
 * it does not know.
 */
final class UiMcpServerTest extends TestCase
{
    #[Test]
    public function it_answers_requests_and_ignores_notifications(): void
    {
        $server = new UiMcpServer();

        $init = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
        self::assertSame(UiMcpServer::PROTOCOL_VERSION, $init['result']['protocolVersion']);
        self::assertArrayHasKey('tools', $init['result']['capabilities']);

        self::assertNull($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        $tools = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        self::assertSame(['ui_catalog', 'ui_schema', 'ui_validate', 'ui_render'], array_column($tools['result']['tools'], 'name'));
        self::assertSame(['tree'], $tools['result']['tools'][3]['inputSchema']['required']);

        self::assertSame(-32601, $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'sampling/createMessage'])['error']['code']);
        self::assertSame(-32600, $server->handle(['id' => 4, 'method' => 'ping'])['error']['code'], 'not JSON-RPC 2.0');
        self::assertSame(-32602, $server->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'shell']])['error']['code']);
        self::assertSame(-32002, $server->handle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/read', 'params' => ['uri' => 'ui://semitexa/tree/none']])['error']['code']);
        self::assertSame(7, $server->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping'])['id']);
    }
}
