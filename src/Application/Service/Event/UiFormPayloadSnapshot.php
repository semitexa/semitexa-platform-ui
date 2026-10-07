<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Exception\UiInteractionBadRequestException;

/**
 * Extracts and sanitises the optional client-submitted form value
 * snapshot carried in `payload.form.values`.
 *
 * Trust boundary (must be re-read with every change):
 *
 *   - The snapshot is **UX-feedback input**, not authoritative state.
 *     Cross-field validation rules consult it through
 *     UiFieldValidationContext so a user sees a coherent message as
 *     they type. The final-submit pipeline (future slice) MUST
 *     revalidate against trusted state before persisting anything.
 *   - The snapshot CANNOT carry rule specs, signed claims, routing
 *     identity, patches, or any other server-authored data. Those
 *     fields are scrubbed by UiPayloadFieldGuard before this
 *     extractor runs; the extractor enforces shape on what survives
 *     (keys must be safe identifiers; values must be scalar or null;
 *     totals are bounded).
 *
 * Wire shape accepted:
 *
 *   payload: {
 *       value: "...",                                        // top-level — unchanged
 *       form: {                                              // NEW, optional
 *           values: {                                        // NEW
 *               <safe-identifier>: <scalar | null>,
 *               ...
 *           }
 *       }
 *   }
 *
 * Hard limits (security ceiling, not configurable in this slice):
 *
 *   - At most {@see self::MAX_FIELDS} keys in `values`.
 *   - Each scalar value capped at {@see self::MAX_VALUE_LENGTH} bytes
 *     (mb_strlen for unicode safety).
 *   - Keys must match `[A-Za-z_][A-Za-z0-9_-]*` — same shape the
 *     FieldComponent template uses for `data-ui-field-name` and the
 *     patch validator uses for target names.
 *
 * Anything outside the contract is a 400 BadRequest. The extractor is
 * intentionally strict: the snapshot is a narrow UX seam and any
 * deviation is treated as a smuggling attempt.
 */
final class UiFormPayloadSnapshot
{
    public const MAX_FIELDS       = 50;
    /** Long enough for an article body in a textarea. */
    public const MAX_VALUE_LENGTH = 65536;
    /** A multi-select or checkbox group: at most this many picked values. */
    public const MAX_LIST_ITEMS   = 100;

    private const SAFE_IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_-]*\z/';

    /**
     * Read the snapshot out of a payload object that has already been
     * vetted by UiPayloadFieldGuard. Missing `form` / missing
     * `form.values` is the common case and produces an empty map.
     *
     * @param array<string, mixed> $payload
     * @return array<string, scalar|null>
     *
     * @throws UiInteractionBadRequestException
     */
    public function extract(array $payload): array
    {
        if (!array_key_exists('form', $payload)) {
            return [];
        }
        $form = $payload['form'];
        if ($form === null) {
            return [];
        }
        if (!is_array($form) || (!empty($form) && array_is_list($form))) {
            // PHP's array_is_list([]) returns true — treat an empty
            // array as "object with zero keys", which is equivalent
            // to "no values present".
            throw new UiInteractionBadRequestException(
                'invalid_form_snapshot',
                'The "payload.form" field must be a JSON object when provided.',
            );
        }

        if (!array_key_exists('values', $form)) {
            return [];
        }
        $values = $form['values'];
        if ($values === null) {
            return [];
        }
        if (!is_array($values) || (!empty($values) && array_is_list($values))) {
            throw new UiInteractionBadRequestException(
                'invalid_form_snapshot',
                'The "payload.form.values" field must be a JSON object mapping field names to scalar values.',
            );
        }

        if (count($values) > self::MAX_FIELDS) {
            throw new UiInteractionBadRequestException(
                'form_snapshot_too_large',
                sprintf(
                    'Form snapshot may not carry more than %d fields.',
                    self::MAX_FIELDS,
                ),
            );
        }

        $out = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || preg_match(self::SAFE_IDENTIFIER, $key) !== 1) {
                throw new UiInteractionBadRequestException(
                    'invalid_form_snapshot_key',
                    'Form snapshot keys must match the safe identifier shape [A-Za-z_][A-Za-z0-9_-]*.',
                );
            }
            if (is_array($value)) {
                // A multi-select or a checkbox group: a flat list of strings.
                if (!array_is_list($value) || count($value) > self::MAX_LIST_ITEMS) {
                    throw new UiInteractionBadRequestException(
                        'invalid_form_snapshot_value',
                        sprintf('Form snapshot value for "%s" must be a scalar, null, or a list of at most %d strings.', $key, self::MAX_LIST_ITEMS),
                    );
                }
                foreach ($value as $item) {
                    if (!is_string($item) || mb_strlen($item) > self::MAX_VALUE_LENGTH) {
                        throw new UiInteractionBadRequestException(
                            'invalid_form_snapshot_value',
                            sprintf('Form snapshot list for "%s" may only hold strings.', $key),
                        );
                    }
                }
                $out[$key] = $value;
                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                throw new UiInteractionBadRequestException(
                    'invalid_form_snapshot_value',
                    sprintf(
                        'Form snapshot value for "%s" must be a scalar, null, or a list of strings; objects are rejected.',
                        $key,
                    ),
                );
            }
            if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                throw new UiInteractionBadRequestException(
                    'form_snapshot_value_too_long',
                    sprintf(
                        'Form snapshot value for "%s" exceeds the %d-character limit.',
                        $key,
                        self::MAX_VALUE_LENGTH,
                    ),
                );
            }
            /** @var scalar|null $value */
            $out[$key] = $value;
        }
        return $out;
    }
}
