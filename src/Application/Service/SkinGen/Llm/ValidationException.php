<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\SkinGen\Llm;

final class ValidationException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $correctionHint)
    {
        parent::__construct($message);
    }

    public static function invalidJson(string $rawExcerpt): self
    {
        return new self(
            "LLM output was not valid JSON matching the schema",
            "RESPOND WITH JSON ONLY matching the exact schema. No prose, no markdown fences. Example: {\"seed\":\"#1e4d6b\",\"algorithm\":\"balanced\",\"accent_hint\":null,\"mood\":\"calm\",\"rationale\":\"...\"}",
        );
    }

    public static function missingField(string $field): self
    {
        return new self(
            "Missing required field: {$field}",
            "Your previous output is missing the '{$field}' field. Return the full JSON object including all required fields: seed, algorithm, mood, rationale, and accent_hint (may be null).",
        );
    }

    public static function invalidHex(string $field, string $value): self
    {
        return new self(
            "Field '{$field}' is not a valid hex color: '{$value}'",
            "The '{$field}' field must be a 6-digit hex color like #1e4d6b. Got: '{$value}'. Please retry with a valid hex.",
        );
    }

    public static function oklchOutOfRange(string $dimension, float $value, string $expected): self
    {
        return new self(
            "OKLCH {$dimension} out of range: {$value} (expected {$expected})",
            "Your seed's OKLCH {$dimension} is {$value}. Required: {$expected}. Pick a color with safer perceptual parameters.",
        );
    }

    public static function lowContrast(float $got, float $required): self
    {
        $gotFmt = number_format($got, 2);
        $reqFmt = number_format($required, 2);
        return new self(
            "Seed contrast against white is {$gotFmt}:1 (need {$reqFmt}:1)",
            "Your seed's contrast against white is {$gotFmt}:1, but WCAG AA requires {$reqFmt}:1. Darken the seed significantly and retry.",
        );
    }

    public static function accentTooClose(float $distance): self
    {
        $distFmt = number_format($distance, 1);
        return new self(
            "accent_hint hue is only {$distFmt}° from seed (need ≥ 30°)",
            "Your accent_hint is too close to the seed hue ({$distFmt}°). Choose an accent at least 30° away, or set accent_hint to null.",
        );
    }

    public static function invalidMood(string $value): self
    {
        return new self(
            "Invalid mood: '{$value}'",
            "The 'mood' field must be one of: calm, energetic, corporate, playful, accessible, minimal. Got: '{$value}'.",
        );
    }

    public static function invalidAlgorithm(string $value): self
    {
        return new self(
            "Invalid algorithm: '{$value}'",
            "The 'algorithm' field must be one of: balanced, glass, brutalist. Got: '{$value}'. Pick the one matching the prompt.",
        );
    }

    public static function invalidKnobs(string $detail): self
    {
        return new self(
            "Invalid knobs: {$detail}",
            "Include only knob keys valid for the CHOSEN algorithm (listed in the prompt's 'Algorithm selection' section). Omit a knob to accept its default.",
        );
    }
}
