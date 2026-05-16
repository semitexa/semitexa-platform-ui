<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\SkinGen\Llm;

use Semitexa\Llm\Contract\LlmProviderInterface;
use Semitexa\Llm\Data\LlmRequest;
use Semitexa\Theme\Contract\SkinAlgorithm;

/**
 * LLM-assisted refinement of an existing skin's knobs.
 *
 * Separate from PromptResolver (which handles generation — pick a seed
 * + algorithm from scratch). Refinement is narrower: algorithm is
 * FIXED, only the knobs change. System prompt is built dynamically
 * from the algorithm's knob schema so new algorithms automatically
 * describe themselves to the LLM without any prompt-file maintenance.
 *
 * Output contract:
 *   {
 *     "knob_deltas": {"knob_name": "new_value", ...},
 *     "rationale": "<short explanation>"
 *   }
 *
 * Validator rules (fail → retry with correction hint, ≤ maxAttempts):
 *   - knob_deltas is object; keys exist in $algorithm->knobSchema()
 *   - values are within each knob's enum
 *   - rationale is a non-empty string
 */
final class RefinementResolver
{
    public function __construct(
        private readonly LlmProviderInterface $provider,
        private readonly RetryPolicy $retry = new RetryPolicy(),
    ) {
    }

    /**
     * @param array<string, string> $currentKnobs
     * @return array{deltas: array<string, string>, rationale: string, attempts: int, latency_ms: ?float}
     */
    public function resolve(
        SkinAlgorithm $algorithm,
        array $currentKnobs,
        string $userPrompt,
    ): array {
        $systemPrompt = $this->buildSystemPrompt($algorithm, $currentKnobs);
        $history = [];
        $latencyMs = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->retry->maxAttempts; $attempt++) {
            $started = microtime(true);
            $response = $this->provider->complete(new LlmRequest(
                systemPrompt: $systemPrompt,
                userMessage: $userPrompt,
                history: $history,
            ));
            $latencyMs = $response->latencyMs ?? ((microtime(true) - $started) * 1000.0);

            if (! $response->success) {
                throw new \RuntimeException('LLM provider error: ' . ($response->error ?? 'unknown'));
            }

            try {
                $parsed = $this->validate($response->content, $algorithm);
                return [
                    'deltas' => $parsed['deltas'],
                    'rationale' => $parsed['rationale'],
                    'attempts' => $attempt,
                    'latency_ms' => $latencyMs,
                ];
            } catch (\InvalidArgumentException $e) {
                $lastError = $e;
                $history[] = ['role' => 'assistant', 'content' => $response->content];
                $history[] = ['role' => 'user', 'content' => 'Your previous response was invalid: ' . $e->getMessage() . ' Return ONLY valid JSON per the schema.'];
            }
        }

        $msg = $lastError !== null
            ? "LLM refinement failed after {$this->retry->maxAttempts} attempts: {$lastError->getMessage()}"
            : "LLM refinement failed after {$this->retry->maxAttempts} attempts";
        throw new \RuntimeException($msg);
    }

    /** @param array<string, string> $currentKnobs */
    private function buildSystemPrompt(SkinAlgorithm $algorithm, array $currentKnobs): string
    {
        $lines = [];
        $lines[] = "You refine an existing UI skin by adjusting its tunable knobs.";
        $lines[] = "The skin's algorithm is FIXED: '{$algorithm->id()}' — {$algorithm->description()}";
        $lines[] = "You may NOT switch algorithm. Only tweak the knobs listed below.";
        $lines[] = "";
        $lines[] = "Available knobs:";
        foreach ($algorithm->knobSchema() as $name => $spec) {
            $current = $currentKnobs[$name] ?? $spec['default'];
            $values = implode(' | ', $spec['enum']);
            $lines[] = "  - {$name}: [{$values}] — currently '{$current}'. {$spec['description']}";
        }
        $lines[] = "";
        $lines[] = "The user will describe a desired change. Decide which knobs to move and how.";
        $lines[] = "Only include knobs you actually want to CHANGE from current value.";
        $lines[] = "If the user's request is not achievable within the available knobs, return empty knob_deltas and explain in rationale.";
        $lines[] = "";
        $lines[] = "Respond ONLY with a JSON object in this exact shape (no prose, no markdown):";
        $lines[] = '{"knob_deltas": {"knob_name": "new_value"}, "rationale": "short explanation"}';

        return implode("\n", $lines);
    }

    /**
     * @return array{deltas: array<string, string>, rationale: string}
     * @throws \InvalidArgumentException
     */
    private function validate(string $raw, SkinAlgorithm $algorithm): array
    {
        $json = $this->extractJson($raw);
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new \InvalidArgumentException('Response is not a JSON object.');
        }

        $deltas = $data['knob_deltas'] ?? null;
        if (! is_array($deltas)) {
            throw new \InvalidArgumentException('Missing or invalid "knob_deltas" field.');
        }

        $schema = $algorithm->knobSchema();
        $cleanDeltas = [];
        foreach ($deltas as $name => $value) {
            if (! is_string($name) || ! isset($schema[$name])) {
                throw new \InvalidArgumentException(
                    "Unknown knob '{$name}'. Valid: " . implode(', ', array_keys($schema))
                );
            }
            if (! is_string($value) || ! in_array($value, $schema[$name]['enum'], true)) {
                throw new \InvalidArgumentException(
                    "Knob '{$name}' value invalid. Allowed: " . implode(',', $schema[$name]['enum'])
                );
            }
            $cleanDeltas[$name] = $value;
        }

        $rationale = $data['rationale'] ?? '';
        if (! is_string($rationale) || trim($rationale) === '') {
            throw new \InvalidArgumentException('Missing or empty "rationale" field.');
        }

        return ['deltas' => $cleanDeltas, 'rationale' => $rationale];
    }

    private function extractJson(string $raw): string
    {
        // Models sometimes wrap output in ```json fences or add prose around.
        $raw = trim($raw);
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            return $m[0];
        }
        return $raw;
    }
}
