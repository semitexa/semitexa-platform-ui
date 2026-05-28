<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\SkinResolver\Eval;

use Semitexa\PlatformUi\Application\Service\SkinResolver\Llm\ResolvedSkinParams;
use Semitexa\Theme\Application\Service\Skin\Oklch\ContrastScore;
use Semitexa\Theme\Application\Service\Skin\Oklch\Converter;

final class ResolverScorer
{
    /**
     * @param array<string, mixed> $expect Fixture's "expect" block
     */
    public function score(ResolvedSkinParams $actual, array $expect): ScoreReport
    {
        $checks = [];
        $passed = 0;
        $total = 0;

        if (isset($expect['seed_hue_range_deg'])) {
            $total++;
            [$lo, $hi] = $expect['seed_hue_range_deg'];
            $hue = Converter::hexToOklch($actual->seed)->h;
            $hit = $this->hueInRange($hue, $lo, $hi);
            $checks[] = $this->row('seed_hue', $hit, sprintf('got %.1f°, expect [%d..%d]°', $hue, $lo, $hi));
            if ($hit) $passed++;
        }

        if (!empty($expect['accent_required'])) {
            $total++;
            $present = $actual->accentHint !== null;
            $checks[] = $this->row('accent_present', $present, $present ? 'yes' : 'missing');
            if ($present) $passed++;

            if ($present && isset($expect['accent_hue_range_deg'])) {
                $total++;
                [$alo, $ahi] = $expect['accent_hue_range_deg'];
                $aHue = Converter::hexToOklch((string) $actual->accentHint)->h;
                $hit = $this->hueInRange($aHue, $alo, $ahi);
                $checks[] = $this->row('accent_hue', $hit, sprintf('got %.1f°, expect [%d..%d]°', $aHue, $alo, $ahi));
                if ($hit) $passed++;
            }
        }

        if (isset($expect['max_chroma'])) {
            $total++;
            $c = Converter::hexToOklch($actual->seed)->c;
            $hit = $c <= $expect['max_chroma'];
            $checks[] = $this->row('max_chroma', $hit, sprintf('got %.3f, max %.3f', $c, $expect['max_chroma']));
            if ($hit) $passed++;
        }

        if (isset($expect['min_chroma'])) {
            $total++;
            $c = Converter::hexToOklch($actual->seed)->c;
            $hit = $c >= $expect['min_chroma'];
            $checks[] = $this->row('min_chroma', $hit, sprintf('got %.3f, min %.3f', $c, $expect['min_chroma']));
            if ($hit) $passed++;
        }

        if (isset($expect['expected_mood'])) {
            $total++;
            $hit = $actual->mood === $expect['expected_mood'];
            $checks[] = $this->row('mood', $hit, "got {$actual->mood}, expect {$expect['expected_mood']}");
            if ($hit) $passed++;
        }

        if (isset($expect['min_contrast_vs_white'])) {
            $total++;
            $c = ContrastScore::contrast($actual->seed, '#ffffff');
            $hit = $c >= $expect['min_contrast_vs_white'];
            $checks[] = $this->row('contrast', $hit, sprintf('got %.2f:1, min %.2f:1', $c, $expect['min_contrast_vs_white']));
            if ($hit) $passed++;
        }

        return new ScoreReport($passed, $total, $checks);
    }

    private function hueInRange(float $hue, float $lo, float $hi): bool
    {
        if ($lo <= $hi) {
            return $hue >= $lo && $hue <= $hi;
        }
        // range wraps around 360° (e.g., 340..20 for red)
        return $hue >= $lo || $hue <= $hi;
    }

    /** @return array{label: string, pass: bool, detail: string} */
    private function row(string $label, bool $pass, string $detail): array
    {
        return ['label' => $label, 'pass' => $pass, 'detail' => $detail];
    }
}
