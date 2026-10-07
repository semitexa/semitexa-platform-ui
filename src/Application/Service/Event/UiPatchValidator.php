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
        if ($patch->op === UiResponsePatch::OP_RERENDER) {
            throw new UiInteractionUnprocessableException(
                'unresolved_rerender',
                sprintf('Patch %d is a rerender the dispatcher could not turn into a morph.', $index),
            );
        }
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
            case UiResponsePatch::OP_MORPH:
            case UiResponsePatch::OP_REPLACE:
            case UiResponsePatch::OP_APPEND:
            case UiResponsePatch::OP_PREPEND:
                if (!is_string($patch->value) || strlen($patch->value) > self::MAX_HTML_BYTES) {
                    $this->fail('invalid_patch_html', sprintf('Patch %d %s requires server-rendered HTML (a string up to %d bytes).', $index, $patch->op, self::MAX_HTML_BYTES));
                }
                if ($patch->op === UiResponsePatch::OP_MORPH && ($patch->targetPart !== null || $patch->targetName !== null)) {
                    $this->fail('invalid_patch_target', sprintf('Patch %d morph targets a whole component instance, not a part.', $index));
                }
                break;
            case UiResponsePatch::OP_REMOVE:
            case UiResponsePatch::OP_FOCUS:
            case UiResponsePatch::OP_RESET:
            case UiResponsePatch::OP_OPEN:
            case UiResponsePatch::OP_CLOSE:
                break;
            case UiResponsePatch::OP_REDIRECT:
                // Same-origin paths only: an absolute or protocol-relative URL
                // would let a handler bounce the user off-site.
                if (!is_string($patch->value) || preg_match('#\A/(?!/)[^\s\\\\]*\z#', $patch->value) !== 1) {
                    $this->fail('invalid_redirect', sprintf('Patch %d redirect must be a same-origin path starting with a single "/".', $index));
                }
                break;
            case UiResponsePatch::OP_TOAST:
                if (!is_string($patch->value) || trim($patch->value) === '') {
                    $this->fail('invalid_toast', sprintf('Patch %d toast needs a message.', $index));
                }
                if (!in_array($patch->args['level'] ?? 'info', UiResponsePatch::TOAST_LEVELS, true)) {
                    $this->fail('invalid_toast', sprintf('Patch %d toast level must be one of: %s.', $index, implode(', ', UiResponsePatch::TOAST_LEVELS)));
                }
                break;
            case UiResponsePatch::OP_DISPATCH:
                if (!is_string($patch->value) || preg_match('/\A[a-z][a-z0-9]*(?:[:.-][a-z0-9]+)*\z/', $patch->value) !== 1) {
                    $this->fail('invalid_dispatch', sprintf('Patch %d dispatch needs an event name like "cart:updated".', $index));
                }
                foreach ((array) ($patch->args['detail'] ?? []) as $key => $value) {
                    if (!is_string($key) || ($value !== null && !is_scalar($value))) {
                        $this->fail('invalid_dispatch', sprintf('Patch %d dispatch detail must be a flat map of scalars.', $index));
                    }
                }
                break;
            case UiResponsePatch::OP_URL:
                // Query parameters of the page's own address — never a path or
                // another origin; values are strings (set) or null (drop).
                $params = $patch->args['params'] ?? null;
                if (!is_array($params) || $params === [] || count($params) > 20
                    || !in_array($patch->args['history'] ?? null, ['push', 'replace'], true)) {
                    $this->fail('invalid_url', sprintf('Patch %d url needs 1..20 params and history push|replace.', $index));
                }
                foreach ($params as $key => $value) {
                    if (!is_string($key) || preg_match('/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/', $key) !== 1
                        || ($value !== null && (!is_string($value) || strlen($value) > 512))) {
                        $this->fail('invalid_url', sprintf('Patch %d url params are identifier keys with strings up to 512 bytes or null.', $index));
                    }
                }
                break;
        }
    }

    private const MAX_HTML_BYTES = 262144;

    private function fail(string $code, string $message): never
    {
        throw new UiInteractionUnprocessableException($code, $message);
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
