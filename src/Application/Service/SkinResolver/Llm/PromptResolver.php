<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\SkinResolver\Llm;

use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Model\LlmRequest;

final class PromptResolver
{
    public function __construct(
        private readonly LlmProviderInterface $provider,
        private readonly OutputValidator $validator,
        private readonly RetryPolicy $retry,
        private readonly string $systemPrompt,
        /** @var list<array{role: string, content: string}> */
        private readonly array $fewShotHistory,
    ) {
    }

    public function resolve(string $userPrompt, float $contrastFloor = 4.5): ResolutionResult
    {
        $history = $this->fewShotHistory;
        $latencyMs = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->retry->maxAttempts; $attempt++) {
            $started = microtime(true);
            $response = $this->provider->complete(new LlmRequest(
                systemPrompt: $this->systemPrompt,
                userMessage: $userPrompt,
                history: $history,
            ));
            $latencyMs = ($response->latencyMs ?? ((microtime(true) - $started) * 1000.0));

            if (!$response->success) {
                throw new \RuntimeException("LLM provider error: " . ($response->error ?? 'unknown'));
            }

            try {
                $params = $this->validator->validate($response->content, $contrastFloor);
                return new ResolutionResult(
                    params: $params,
                    attempts: $attempt,
                    modelName: $this->provider->model(),
                    latencyMs: $latencyMs,
                );
            } catch (ValidationException $e) {
                $lastError = $e;
                $history[] = ['role' => 'assistant', 'content' => $response->content];
                $history[] = ['role' => 'user', 'content' => $e->correctionHint];
            }
        }

        $msg = $lastError !== null
            ? "LLM resolution failed after {$this->retry->maxAttempts} attempts: {$lastError->getMessage()}"
            : "LLM resolution failed after {$this->retry->maxAttempts} attempts";
        throw new \RuntimeException($msg);
    }
}
