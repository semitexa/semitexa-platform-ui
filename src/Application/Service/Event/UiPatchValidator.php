<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/**
 * Server-side patch validator.
 *
 * Every patch returned by a #[UiOn] handler is checked against:
 *   1. an allow-listed op verb;
 *   2. a target whose instance matches the signed-claims instance —
 *      handlers cannot patch other component instances;
 *   3. a value type compatible with the op (`setText` / `setValue` require
 *      a scalar; `setAttribute` requires an allow-listed attribute name);
 *   4. identifier-like part / name shapes — no `/` `\` `<` `>` `"` `'`
 *      characters that would let a string smuggle a selector.
 *
 * Failures raise `UiInteractionUnprocessableException` (HTTP 422). The
 * dispatcher catches the exception and returns a safe JSON error — no
 * PHP class / method / file leaks.
 */
final class UiPatchValidator
{
    private const IDENTIFIER_PATTERN = '/\A[a-z_][a-z0-9_-]*\z/i';

    /**
     * @param list<UiResponsePatch> $patches
     * @param list<string>          $additionalAllowedInstances Optional
     *        list of secondary instance ids the dispatch identifies as
     *        ALSO server-signed (e.g. FormComponent submit's
     *        `cfg.f[*].i` field-instance ids). The primary
     *        $expectedInstance plus every entry in this list form the
     *        union of legal `targetInstance` values for this dispatch.
     *        The dispatcher MUST populate this only from
     *        HMAC-verified claims — anything else would let a handler
     *        retarget arbitrary components.
     * @return list<UiResponsePatch>
     *
     * @throws UiInteractionUnprocessableException
     */
    public function validateAll(
        array $patches,
        string $expectedInstance,
        array $additionalAllowedInstances = [],
    ): array {
        $allowed = [$expectedInstance => true];
        foreach ($additionalAllowedInstances as $id) {
            if (is_string($id) && $id !== '') {
                $allowed[$id] = true;
            }
        }
        $validated = [];
        foreach ($patches as $index => $patch) {
            if (!$patch instanceof UiResponsePatch) {
                throw new UiInteractionUnprocessableException(
                    'invalid_patch',
                    sprintf('Handler returned a non-UiResponsePatch value at index %d.', $index),
                );
            }
            $this->validateOne($patch, $allowed, $index);
            $validated[] = $patch;
        }
        return $validated;
    }

    /**
     * @param array<string, true> $allowedInstances Lookup table built
     *        in validateAll() — keys are the allow-listed
     *        targetInstance values for this dispatch.
     */
    private function validateOne(UiResponsePatch $patch, array $allowedInstances, int $index): void
    {
        if (!in_array($patch->op, UiResponsePatch::ALLOWED_OPS, true)) {
            throw new UiInteractionUnprocessableException(
                'invalid_patch_op',
                sprintf(
                    'Patch %d has unsupported op "%s". Allowed: %s.',
                    $index,
                    $patch->op,
                    implode(', ', UiResponsePatch::ALLOWED_OPS),
                ),
            );
        }

        if (!isset($allowedInstances[$patch->targetInstance])) {
            throw new UiInteractionUnprocessableException(
                'patch_instance_mismatch',
                sprintf(
                    'Patch %d targets a different component instance than the signed event. Handlers may only patch their own signed instances.',
                    $index,
                ),
            );
        }

        if ($patch->targetPart !== null && preg_match(self::IDENTIFIER_PATTERN, $patch->targetPart) !== 1) {
            throw new UiInteractionUnprocessableException(
                'invalid_patch_target_part',
                sprintf('Patch %d declares an invalid part name "%s".', $index, $patch->targetPart),
            );
        }

        if ($patch->targetName !== null && preg_match(self::IDENTIFIER_PATTERN, $patch->targetName) !== 1) {
            throw new UiInteractionUnprocessableException(
                'invalid_patch_target_name',
                sprintf('Patch %d declares an invalid patch-target name "%s".', $index, $patch->targetName),
            );
        }

        switch ($patch->op) {
            case UiResponsePatch::OP_SET_TEXT:
            case UiResponsePatch::OP_SET_VALUE:
                $this->assertScalarOrNull($patch->value, $patch->op, $index);
                break;
            case UiResponsePatch::OP_SET_ATTRIBUTE:
                if ($patch->attribute === null
                    || !in_array($patch->attribute, UiResponsePatch::ALLOWED_ATTRIBUTES, true)
                ) {
                    throw new UiInteractionUnprocessableException(
                        'invalid_patch_attribute',
                        sprintf(
                            'Patch %d setAttribute requires an allow-listed attribute name. Allowed: %s.',
                            $index,
                            implode(', ', UiResponsePatch::ALLOWED_ATTRIBUTES),
                        ),
                    );
                }
                $this->assertScalarOrNull($patch->value, $patch->op, $index);
                break;
        }
    }

    private function assertScalarOrNull(mixed $value, string $op, int $index): void
    {
        if ($value !== null && !is_scalar($value)) {
            throw new UiInteractionUnprocessableException(
                'invalid_patch_value',
                sprintf(
                    'Patch %d %s requires a scalar (string/int/float/bool) or null value; got %s.',
                    $index,
                    $op,
                    get_debug_type($value),
                ),
            );
        }
    }
}
