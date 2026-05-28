<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\SkinResolver\Llm;

final readonly class ResolvedSkinParams
{
    /**
     * @param array<string, string> $knobs Algorithm-specific knob values the
     *                                     LLM proposed. Subset of the chosen
     *                                     algorithm's knobSchema; missing
     *                                     knobs take algorithm defaults.
     */
    public function __construct(
        public string $seed,
        public string $algorithm,
        public ?string $accentHint,
        public string $mood,
        public string $rationale,
        public array $knobs = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'algorithm' => $this->algorithm,
            'accent_hint' => $this->accentHint,
            'mood' => $this->mood,
            'rationale' => $this->rationale,
            'knobs' => $this->knobs,
        ];
    }
}
