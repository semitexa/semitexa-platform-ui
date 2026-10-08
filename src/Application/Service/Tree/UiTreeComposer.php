<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Tree;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\PlatformUi\Application\Prompt\UiComposePrompt;
use Semitexa\PlatformUi\Application\Prompt\UiRepairPrompt;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTree;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTreeError;
use Semitexa\Prompt\Application\Service\PromptRenderer;

/**
 * A screen from a description (tk-ai-compose): the model is asked for a UI
 * tree with the visitor's catalog, the server checks the answer, and the
 * repair errors go back as a correction turn — up to MAX_ROUNDS — until the
 * tree is sound, and only then is it drawn. A tree still wrong after the last
 * round is never drawn: the result says why.
 */
#[AsService]
final class UiTreeComposer
{
    public const MAX_ROUNDS = 3;

    #[InjectAsReadonly]
    protected LlmProviderResolver $providers;

    #[InjectAsReadonly]
    protected PromptRenderer $prompts;

    #[InjectAsReadonly]
    protected UiAgentManifest $manifest;

    #[InjectAsReadonly]
    protected UiTreeValidator $validator;

    #[InjectAsReadonly]
    protected UiTreeRenderer $renderer;

    /**
     * @return array{
     *     tree: ?UiTree,
     *     html: ?string,
     *     rounds: list<array{reply: string, errors: list<array<string, string>>}>,
     *     failure: ?string
     * }
     */
    public function compose(string $description, ?LlmProviderInterface $provider = null, int $maxRounds = self::MAX_ROUNDS): array
    {
        $provider ??= $this->providers->provider();
        $system = $this->prompts->render(
            (new UiComposePrompt())->withCatalog($this->manifest->describe(), UiTree::VERSION, $this->manifest->describeActions(), UiTreeParser::MAX_NODES),
        )->system;

        $history = [];
        $message = trim($description);
        $rounds = [];
        for ($round = 1; $round <= max(1, $maxRounds); $round++) {
            $response = $provider->complete(new LlmRequest($system, $message, $history));
            if (!$response->success) {
                return ['tree' => null, 'html' => null, 'rounds' => $rounds, 'failure' => 'The model did not answer: ' . ($response->error ?? 'no reason given') . '.'];
            }
            $reply = $response->content;
            $checked = $this->validator->check(self::jsonOf($reply));
            $rounds[] = ['reply' => $reply, 'errors' => array_map(static fn (UiTreeError $e): array => $e->toArray(), $checked['errors'])];
            if ($checked['tree'] !== null) {
                return ['tree' => $checked['tree'], 'html' => $this->renderer->draw($checked['tree']), 'rounds' => $rounds, 'failure' => null];
            }

            $history[] = ['role' => 'user', 'content' => $message];
            $history[] = ['role' => 'assistant', 'content' => $reply];
            $message = $this->prompts->render(
                (new UiRepairPrompt())->withErrors((string) json_encode($rounds[count($rounds) - 1]['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            )->system;
        }

        return ['tree' => null, 'html' => null, 'rounds' => $rounds, 'failure' => sprintf('The tree was still refused after %d rounds.', count($rounds))];
    }

    /** The JSON object a reply carries — inside code fences or prose, if the model added them. */
    public static function jsonOf(string $reply): string
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');

        return $start === false || $end === false || $end < $start ? $reply : substr($reply, $start, $end - $start + 1);
    }
}
