<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\SkinGen\Llm;

final readonly class ResolutionResult
{
    public function __construct(
        public ResolvedSkinParams $params,
        public int $attempts,
        public string $modelName,
        public ?float $latencyMs,
    ) {
    }

    public function toLlmMetadata(): array
    {
        return [
            'skill' => 'platform-ui.skin.resolve-prompt',
            'skill_version' => '1.0',
            'model' => $this->modelName,
            'generated_at' => gmdate('c'),
            'attempts' => $this->attempts,
            'latency_ms' => $this->latencyMs,
            'rationale' => $this->params->rationale,
        ];
    }
}
