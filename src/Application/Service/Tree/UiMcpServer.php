<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Tree;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTreeError;

/**
 * The UI tree over MCP (ep-platform-ai-ui · tk-ai-mcp): one JSON-RPC 2.0
 * message in, one out (or none, for a notification) — what `ui:mcp` speaks on
 * stdio, so a coding agent or an MCP host can ask what it may compose, check a
 * tree, and get it drawn. The visitor is the one the server was started for.
 *
 * Tools: ui_catalog, ui_schema, ui_validate, ui_render. A drawn tree comes
 * back as an embedded `ui://` resource (text/html) and stays readable through
 * resources/read for the life of the server — the shape MCP Apps hosts show.
 */
#[AsService]
final class UiMcpServer
{
    public const PROTOCOL_VERSION = '2025-06-18';

    #[InjectAsReadonly]
    protected UiAgentManifest $manifest;

    #[InjectAsReadonly]
    protected UiTreeValidator $validator;

    #[InjectAsReadonly]
    protected UiTreeRenderer $renderer;

    /** @var array<string, string> uri => html, the trees drawn this session */
    private array $rendered = [];

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>|null the response; null for a notification
     */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = is_string($message['method'] ?? null) ? $message['method'] : '';
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        if (($message['jsonrpc'] ?? null) !== '2.0' || $method === '') {
            return self::error($id, -32600, 'Invalid request: a JSON-RPC 2.0 message with a method.');
        }
        if (!array_key_exists('id', $message)) {
            return null; // a notification (notifications/initialized, …): no answer
        }

        return match ($method) {
            'initialize' => self::result($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => ['listChanged' => false], 'resources' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'semitexa-ui', 'version' => '1'],
                'instructions' => 'Compose screens as Semitexa UI trees: ui_catalog lists what you may use, ui_validate checks a tree and says how to fix it, ui_render draws it.',
            ]),
            'ping' => self::result($id, []),
            'tools/list' => self::result($id, ['tools' => $this->tools()]),
            'tools/call' => $this->call($id, $params),
            'resources/list' => self::result($id, ['resources' => array_map(
                static fn (string $uri): array => ['uri' => $uri, 'name' => basename($uri), 'mimeType' => 'text/html'],
                array_keys($this->rendered),
            )]),
            'resources/read' => $this->read($id, $params),
            default => self::error($id, -32601, sprintf('No method "%s".', $method)),
        };
    }

    /** @return list<array<string, mixed>> */
    private function tools(): array
    {
        $tree = ['type' => 'object', 'properties' => ['tree' => ['type' => 'object', 'description' => 'A Semitexa UI tree (see ui_schema)']], 'required' => ['tree']];

        return [
            ['name' => 'ui_catalog', 'description' => 'The components and action kinds you may compose a screen from, for this visitor: props, slots, an example each.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'ui_schema', 'description' => 'The JSON Schema of a whole UI tree for this visitor.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'ui_validate', 'description' => 'Check a UI tree as the server will before drawing it: every fault with its path, what was expected and how to fix it.', 'inputSchema' => $tree],
            ['name' => 'ui_render', 'description' => 'Check and draw a UI tree; answers with a ui:// HTML resource, or the faults.', 'inputSchema' => $tree],
        ];
    }

    /** @param array<array-key, mixed> $params */
    private function call(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        return match ($name) {
            'ui_catalog' => self::result($id, self::text(['components' => $this->manifest->components(), 'actions' => $this->manifest->describeActions()])),
            'ui_schema' => self::result($id, self::text($this->manifest->treeSchema())),
            'ui_validate' => $this->validate($id, $arguments),
            'ui_render' => $this->render($id, $arguments),
            default => self::error($id, -32602, sprintf('No tool "%s".', is_string($name) ? $name : '')),
        };
    }

    /** @param array<array-key, mixed> $arguments */
    private function validate(mixed $id, array $arguments): array
    {
        $checked = $this->validator->check($arguments['tree'] ?? null);
        $errors = array_map(static fn (UiTreeError $e): array => $e->toArray(), $checked['errors']);

        return self::result($id, self::text(['valid' => $checked['tree'] !== null, 'errors' => $errors]) + ['isError' => $checked['tree'] === null]);
    }

    /** @param array<array-key, mixed> $arguments */
    private function render(mixed $id, array $arguments): array
    {
        $result = $this->renderer->render($arguments['tree'] ?? null);
        if ($result['html'] === null) {
            return self::result($id, self::text(['rendered' => false, 'errors' => array_map(static fn (UiTreeError $e): array => $e->toArray(), $result['errors'])]) + ['isError' => true]);
        }
        $uri = 'ui://semitexa/tree/' . substr(hash('sha256', $result['html']), 0, 16);
        $this->rendered[$uri] = $result['html'];

        return self::result($id, ['content' => [
            ['type' => 'text', 'text' => 'Drawn: ' . $uri],
            ['type' => 'resource', 'resource' => ['uri' => $uri, 'mimeType' => 'text/html', 'text' => $result['html']]],
        ]]);
    }

    /** @param array<array-key, mixed> $params */
    private function read(mixed $id, array $params): array
    {
        $uri = is_string($params['uri'] ?? null) ? $params['uri'] : '';
        if (!isset($this->rendered[$uri])) {
            return self::error($id, -32002, sprintf('No resource "%s".', $uri));
        }

        return self::result($id, ['contents' => [['uri' => $uri, 'mimeType' => 'text/html', 'text' => $this->rendered[$uri]]]]);
    }

    /** @return array{content: list<array{type: string, text: string}>} */
    private static function text(mixed $value): array
    {
        return ['content' => [['type' => 'text', 'text' => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]];
    }

    /** @return array<string, mixed> */
    private static function result(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result];
    }

    /** @return array<string, mixed> */
    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
