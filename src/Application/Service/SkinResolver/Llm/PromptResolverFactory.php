<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\SkinResolver\Llm;

use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Theme\Contract\SkinAlgorithm;
use Semitexa\Theme\Application\Service\Skin\SkinAlgorithmRegistry;

/**
 * Builds a PromptResolver whose system prompt is assembled at construction
 * time from the algorithm registry. The `{{ALGORITHM_SECTIONS}}` placeholder
 * in skin-resolve-prompt.md is replaced with a dynamically-generated block
 * listing every registered algorithm's id, description, and knob schema.
 *
 * Effect: adding a new SkinAlgorithm (via the registry) requires ZERO edits
 * to prompt.md, schema.json, or OutputValidator — the LLM + validator both
 * pick it up automatically. Fewshot examples stay static because concrete
 * examples meaningfully shape LLM performance; documenting a new algorithm
 * with 1–2 fewshot rows is an expected step when contributing it.
 */
final class PromptResolverFactory
{
    private readonly string $resourcesPath;

    public function __construct(
        private readonly LlmProviderInterface $provider,
        ?string $resourcesPath = null,
        private readonly SkinAlgorithmRegistry $algorithms = new SkinAlgorithmRegistry(),
    ) {
        // Default to the resources/ directory at the package root.
        // __DIR__ is src/Application/Service/SkinResolver/Llm → go up 5 to reach the package root,
        // then into resources/. Callers outside platform-ui can still pass
        // a custom path.
        $this->resourcesPath = $resourcesPath ?? dirname(__DIR__, 5) . '/resources';
    }

    public function create(RetryPolicy $retry = new RetryPolicy()): PromptResolver
    {
        $template = $this->readFile('/llm/skin-resolve-prompt.md');
        $systemPrompt = $this->injectAlgorithmSections($template);

        $fewShotJson = $this->readFile('/llm/skin-resolve-fewshot.json');
        $fewShot = json_decode($fewShotJson, true);
        if (!is_array($fewShot)) {
            throw new \RuntimeException("Invalid few-shot JSON at {$this->resourcesPath}/llm/skin-resolve-fewshot.json");
        }

        $history = [];
        foreach ($fewShot as $example) {
            if (!is_array($example) || !isset($example['prompt'], $example['output'])) {
                continue;
            }
            $history[] = ['role' => 'user', 'content' => (string) $example['prompt']];
            $history[] = ['role' => 'assistant', 'content' => json_encode($example['output'], JSON_UNESCAPED_SLASHES)];
        }

        return new PromptResolver(
            provider: $this->provider,
            validator: new OutputValidator($this->algorithms),
            retry: $retry,
            systemPrompt: $systemPrompt,
            fewShotHistory: $history,
        );
    }

    private function injectAlgorithmSections(string $template): string
    {
        $sections = array_map($this->renderAlgorithmSection(...), $this->algorithms->all());
        $block = implode("\n\n", $sections);
        return str_replace('{{ALGORITHM_SECTIONS}}', $block, $template);
    }

    private function renderAlgorithmSection(SkinAlgorithm $algorithm): string
    {
        $lines = [];
        $lines[] = "## `{$algorithm->id()}`";
        $lines[] = '';
        $lines[] = $algorithm->description();

        $schema = $algorithm->knobSchema();
        if ($schema === []) {
            $lines[] = '';
            $lines[] = '*(no tunable knobs)*';
            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = 'Knobs:';
        foreach ($schema as $name => $spec) {
            $values = implode(' | ', $spec['enum']);
            $lines[] = "- `{$name}`: `{$values}` (default `{$spec['default']}`) — {$spec['description']}";
        }
        return implode("\n", $lines);
    }

    private function readFile(string $relative): string
    {
        $contents = @file_get_contents($this->resourcesPath . $relative);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read {$this->resourcesPath}{$relative}");
        }
        return $contents;
    }
}
