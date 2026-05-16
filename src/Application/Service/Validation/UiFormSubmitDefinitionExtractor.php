<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation;

use Semitexa\PlatformUi\Application\Service\Event\UiInstanceIdGenerator;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitConfig;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitFieldDefinition;

/**
 * Extracts auto-derived submit field definitions from
 * FormComponent slot HTML.
 *
 * Why marker-based, not render-scope collector: SSR's slot semantics
 * are eager — `component('platform.form', props, {content: $captured})`
 * materialises the slot HTML BEFORE the form template runs. A render-
 * scope collector frame opened by the form template would already be
 * past the field renders by the time it pushes onto the stack.
 *
 * Each FieldComponent renders an inert metadata marker:
 *
 *     <script type="application/json" data-ui-field-submit-definition>{"n":"…","i":"…","r":[…],"l":"…","q":true}</script>
 *
 * The marker has no executable JavaScript — application/json is inert
 * in the browser — and carries only data already present on the
 * field's root attributes / signed manifest. Markers are emitted only
 * for fields whose name passes the safe-identifier check, mirroring
 * the existing `data-ui-field-name` gate.
 *
 * The extractor:
 *
 *   1. Scans the captured slot HTML for the tight marker tag.
 *   2. Decodes each marker's JSON payload.
 *   3. Builds a UiFormSubmitFieldDefinition list and round-trips it
 *      through UiFormSubmitConfigParser::parseSignedWire() — which
 *      enforces the same duplicate-name / duplicate-instance-id /
 *      safe-id checks the existing signed-cfg path runs.
 *   4. Returns the validated config plus a "cleaned" HTML copy with
 *      the marker tags removed (so nested forms do not accidentally
 *      consume an inner form's markers, and the final DOM stays
 *      clean).
 *
 * Trust perimeter:
 *
 *   - Markers are server-rendered. The client cannot inject them
 *     into a signed ctx.
 *   - No raw field values, no class names, no service / method names
 *     ever land in the marker payload (the field template only puts
 *     n/i/r/l/q there).
 *   - Marker parsing is tight: the JSON must round-trip cleanly, the
 *     name and instance id must match safe-identifier shapes, the
 *     rule list must be wire-shape arrays, and duplicates fail loud.
 */
final class UiFormSubmitDefinitionExtractor
{
    /**
     * Regex matches the canonical marker shape FieldComponent emits.
     * Anchored to `<script type="application/json" data-ui-field-submit-definition>`
     * with a non-greedy capture for the JSON body, terminated by the
     * literal `</script>` closer. Tight enough that arbitrary `<script>`
     * tags in slot content are NOT consumed.
     */
    private const MARKER_REGEX = '/<script type="application\/json" data-ui-field-submit-definition>(.*?)<\/script>/s';

    private const SAFE_NAME = '/\A[A-Za-z_][A-Za-z0-9_-]*\z/';

    private readonly UiFormSubmitConfigParser $configParser;

    public function __construct(?UiFormSubmitConfigParser $configParser = null)
    {
        $this->configParser = $configParser ?? new UiFormSubmitConfigParser();
    }

    /**
     * @return array{config: UiFormSubmitConfig, html: string}
     *
     * @throws UiFieldValidationRuleException
     */
    public function extract(string $html): array
    {
        if (preg_match_all(self::MARKER_REGEX, $html, $matches) === 0) {
            return ['config' => new UiFormSubmitConfig([]), 'html' => $html];
        }

        $wireFields = [];
        foreach ($matches[1] as $index => $jsonBody) {
            // Reverse the `<\/` escape the renderer applies inside
            // the JSON payload to keep the closing script tag from
            // prematurely ending the inert script block.
            $jsonBody = str_replace('<\\/', '</', $jsonBody);
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($jsonBody, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new UiFieldValidationRuleException(sprintf(
                    'FormComponent autoFields: marker %d carries malformed JSON.',
                    $index,
                ));
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new UiFieldValidationRuleException(sprintf(
                    'FormComponent autoFields: marker %d does not carry an object.',
                    $index,
                ));
            }
            $name = $decoded['n'] ?? null;
            if (!is_string($name) || preg_match(self::SAFE_NAME, $name) !== 1) {
                throw new UiFieldValidationRuleException(sprintf(
                    'FormComponent autoFields: marker %d carries an unsafe field name.',
                    $index,
                ));
            }
            $instanceId = $decoded['i'] ?? null;
            if (!UiInstanceIdGenerator::isSafe($instanceId)) {
                throw new UiFieldValidationRuleException(sprintf(
                    'FormComponent autoFields: marker %d carries an unsafe instance id.',
                    $index,
                ));
            }
            $rules = $decoded['r'] ?? [];
            if (!is_array($rules) || ($rules !== [] && !array_is_list($rules))) {
                throw new UiFieldValidationRuleException(sprintf(
                    'FormComponent autoFields: marker %d carries malformed rules.',
                    $index,
                ));
            }
            $wire = [
                'n' => $name,
                'i' => $instanceId,
                'r' => $rules,
            ];
            if (isset($decoded['l']) && is_string($decoded['l']) && $decoded['l'] !== '') {
                $wire['l'] = $decoded['l'];
            }
            if (!empty($decoded['q'])) {
                $wire['q'] = true;
            }
            $wireFields[] = $wire;
        }

        // Round-trip through the signed-wire parser so duplicate
        // names / duplicate instance ids / oversized labels / count
        // limits all fail the SAME way the existing signed path does.
        $config = $this->configParser->parseSignedWire($wireFields);

        return [
            'config' => $config,
            'html' => $this->stripMarkers($html),
        ];
    }

    /**
     * Remove every submit-definition marker from $html. Idempotent;
     * cheap to call on HTML that has none.
     */
    public function stripMarkers(string $html): string
    {
        $stripped = preg_replace(self::MARKER_REGEX, '', $html);
        return $stripped ?? $html;
    }
}
