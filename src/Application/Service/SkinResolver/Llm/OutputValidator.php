<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\SkinResolver\Llm;

use Semitexa\Theme\Application\Service\Skin\Oklch\ContrastScore;
use Semitexa\Theme\Application\Service\Skin\Oklch\Converter;
use Semitexa\Theme\Application\Service\Skin\SkinAlgorithmRegistry;

final class OutputValidator
{
    private const ALLOWED_MOODS = ['calm', 'energetic', 'corporate', 'playful', 'accessible', 'minimal'];

    public function __construct(
        private readonly SkinAlgorithmRegistry $algorithms = new SkinAlgorithmRegistry(),
    ) {
    }

    public function validate(string $llmOutput, float $contrastFloor = 4.5): ResolvedSkinParams
    {
        $json = $this->parseJson($llmOutput);

        foreach (['seed', 'algorithm', 'mood', 'rationale'] as $field) {
            if (!array_key_exists($field, $json)) {
                throw ValidationException::missingField($field);
            }
        }

        $seed = strtolower((string) $json['seed']);
        if (!preg_match('/^#[0-9a-f]{6}$/', $seed)) {
            throw ValidationException::invalidHex('seed', (string) $json['seed']);
        }

        $accentHint = $json['accent_hint'] ?? null;
        if ($accentHint !== null && $accentHint !== '') {
            $accentHint = strtolower((string) $accentHint);
            if (!preg_match('/^#[0-9a-f]{6}$/', $accentHint)) {
                throw ValidationException::invalidHex('accent_hint', (string) $json['accent_hint']);
            }
        } else {
            $accentHint = null;
        }

        $algorithm = (string) $json['algorithm'];
        if (!$this->algorithms->has($algorithm)) {
            throw ValidationException::invalidAlgorithm($algorithm);
        }

        // Knobs: key must exist in chosen algorithm's schema, value in its enum.
        // Missing knobs are OK — caller fills defaults via KnobResolver.
        $rawKnobs = $json['knobs'] ?? [];
        if (!is_array($rawKnobs)) {
            throw ValidationException::invalidKnobs('knobs field must be an object');
        }
        $knobSchema = $this->algorithms->get($algorithm)->knobSchema();
        $knobs = [];
        foreach ($rawKnobs as $name => $value) {
            if (!is_string($name) || !isset($knobSchema[$name])) {
                throw ValidationException::invalidKnobs(
                    "Unknown knob '{$name}' for algorithm '{$algorithm}'. Allowed: "
                    . ($knobSchema === [] ? '(none — algorithm has no knobs)' : implode(', ', array_keys($knobSchema)))
                );
            }
            if (!is_string($value) || !in_array($value, $knobSchema[$name]['enum'], true)) {
                throw ValidationException::invalidKnobs(
                    "Knob '{$name}' value must be one of: " . implode(', ', $knobSchema[$name]['enum'])
                );
            }
            $knobs[$name] = $value;
        }

        $mood = (string) $json['mood'];
        if (!in_array($mood, self::ALLOWED_MOODS, true)) {
            throw ValidationException::invalidMood($mood);
        }

        $seedOklch = Converter::hexToOklch($seed);
        if ($seedOklch->l < 0.2 || $seedOklch->l > 0.7) {
            throw ValidationException::oklchOutOfRange('lightness', $seedOklch->l, 'between 0.2 and 0.7');
        }
        if ($seedOklch->c > 0.3) {
            throw ValidationException::oklchOutOfRange('chroma', $seedOklch->c, '<= 0.3');
        }

        $contrast = ContrastScore::contrast($seed, '#ffffff');
        if ($contrast < $contrastFloor) {
            throw ValidationException::lowContrast($contrast, $contrastFloor);
        }

        if ($accentHint !== null) {
            $accentOklch = Converter::hexToOklch($accentHint);
            $hueDiff = abs($seedOklch->h - $accentOklch->h);
            $distance = min($hueDiff, 360.0 - $hueDiff);
            if ($distance < 30.0) {
                throw ValidationException::accentTooClose($distance);
            }
        }

        $rationale = substr((string) ($json['rationale'] ?? ''), 0, 200);

        return new ResolvedSkinParams(
            seed: $seed,
            algorithm: $algorithm,
            accentHint: $accentHint,
            mood: $mood,
            rationale: $rationale,
            knobs: $knobs,
        );
    }

    /** @return array<string, mixed> */
    private function parseJson(string $output): array
    {
        $trimmed = trim($output);

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $trimmed, $m)) {
            $trimmed = $m[1];
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start === false || $end === false || $end < $start) {
            throw ValidationException::invalidJson($output);
        }
        $candidate = substr($trimmed, $start, $end - $start + 1);

        $parsed = json_decode($candidate, true);
        if (!is_array($parsed) || json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::invalidJson($output);
        }

        return $parsed;
    }
}
