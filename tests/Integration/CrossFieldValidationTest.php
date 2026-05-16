<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Request;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Handler\PayloadHandler\UiDispatchHandler;
use Semitexa\PlatformUi\Application\Payload\Request\UiDispatchPayload;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * Full-stack dispatch tests for the cross-field validation slice.
 *
 * Builds signed contexts containing `cfg.r` (the signed rule list,
 * including a sameAsField spec) AND `cfg.fn` (the signed field
 * name). Posts dispatch requests with `payload.form.values`
 * snapshots and asserts the response shape — validation patches,
 * debug.validation, debug.form snapshot key set (no values), plus
 * the regression matrix for sibling-missing, tampered ctx, and
 * snapshot-smuggling attempts.
 */
final class CrossFieldValidationTest extends TestCase
{
    private ?string $previousSecret = null;
    private ?string $previousEnv = null;
    private int $dispatchSeq = 0;

    private const INSTANCE_ID = 'uci_cross_field_test_01';

    protected function setUp(): void
    {
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-cross-field-test');
        putenv('APP_ENV=dev');

        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(FieldComponent::class),
        );
        $this->dispatchSeq = 0;
    }

    protected function tearDown(): void
    {
        UiComponentRegistry::reset();
        if ($this->previousSecret === null) {
            putenv('APP_SECRET');
        } else {
            putenv('APP_SECRET=' . $this->previousSecret);
        }
        if ($this->previousEnv === null) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->previousEnv);
        }
    }

    private function freshDispatchId(): string
    {
        $this->dispatchSeq++;
        return sprintf('ui_evt_%032s', dechex(($this->dispatchSeq << 16) | random_int(0, 0xFFFF)));
    }

    /**
     * Mint a signed ctx for the confirm_access_code field carrying the
     * signed rule list AND the signed field name.
     *
     * @param list<array<string, mixed>> $rules
     */
    private function confirmCtx(
        array $rules = [
            ['n' => 'required'],
            ['n' => 'sameAsField', 'p' => ['access_code', 'Codes must match.']],
        ],
        string $fieldName = 'confirm_access_code',
    ): string {
        return SignedContext::sign([
            'c' => 'platform.field',
            'i' => self::INSTANCE_ID,
            'p' => 'input',
            'e' => 'change',
            'u' => 'value',
            'cfg' => array_filter([
                'r' => $rules !== [] ? $rules : null,
                'fn' => $fieldName !== '' ? $fieldName : null,
            ], static fn ($v) => $v !== null),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $ctx, array $payload = []): ResourceResponse
    {
        $body = json_encode([
            'ctx'        => $ctx,
            'dispatchId' => $this->freshDispatchId(),
            'payload'    => $payload,
        ], JSON_THROW_ON_ERROR);

        $request = new Request(
            method: 'POST',
            uri: '/__ui/dispatch',
            headers: [],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: $body,
            files: [],
        );
        $handler  = (new UiDispatchHandler())->withRequest($request);
        $resource = new ResourceResponse();
        return $handler->handle(new UiDispatchPayload(), $resource);
    }

    /** @return array<string, mixed> */
    private function decode(ResourceResponse $response): array
    {
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function validationMessage(array $data): string
    {
        // Patch index 2 is the validation-message setText (after the
        // aria-invalid + ui-state setAttribute pair).
        return $data['patches'][2]['value'];
    }

    #[Test]
    public function matching_values_pass_through_required_then_same_as_field(): void
    {
        $resp = $this->post($this->confirmCtx(), [
            'value' => 'abcd',
            'form'  => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('valid', $data['debug']['validation']['state']);
        self::assertSame('Looks good.', $data['debug']['validation']['message']);
        self::assertSame('valid', $data['patches'][1]['value']);
        self::assertSame('Looks good.', $this->validationMessage($data));
    }

    #[Test]
    public function mismatching_values_fail_with_custom_message(): void
    {
        $resp = $this->post($this->confirmCtx(), [
            'value' => 'zzzz',
            'form'  => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'zzzz',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('invalid', $data['debug']['validation']['state']);
        self::assertSame('Codes must match.', $data['debug']['validation']['message']);
        self::assertSame('Codes must match.', $this->validationMessage($data));
        // aria-invalid flips true, ui-state flips invalid.
        self::assertSame('true', $data['patches'][0]['value']);
        self::assertSame('invalid', $data['patches'][1]['value']);
    }

    #[Test]
    public function missing_sibling_returns_sentinel_message(): void
    {
        $resp = $this->post($this->confirmCtx(), [
            'value' => 'zzzz',
            // sibling access_code intentionally absent; the snapshot
            // still carries the confirm field (the dispatched value
            // is also self-merged by cfg.fn, but that is the current
            // field, not the sibling).
            'form'  => ['values' => ['confirm_access_code' => 'zzzz']],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('Please complete the related field first.', $this->validationMessage($data));
    }

    #[Test]
    public function self_merge_overrides_client_snapshot_for_own_field(): void
    {
        // Client sneaks a "different" current value into the snapshot
        // for its own field name. The handler self-merges the
        // dispatched value (the only canonical "current") and rule
        // comparisons see the dispatched value.
        $resp = $this->post($this->confirmCtx(), [
            'value' => 'abcd',
            'form'  => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'tampered',
            ]],
        ]);
        $data = $this->decode($resp);
        // sameAsField compares dispatched value (abcd) to sibling
        // (abcd) → match.
        self::assertSame('valid', $data['debug']['validation']['state']);
    }

    #[Test]
    public function debug_form_surfaces_snapshot_field_keys_not_values(): void
    {
        $resp = $this->post($this->confirmCtx(), [
            'value' => 'abcd',
            'form'  => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
                'unrelated_field'     => 'leak-canary',
            ]],
        ]);
        $data = $this->decode($resp);
        self::assertArrayHasKey('form', $data['debug']);
        self::assertSame(
            ['access_code', 'confirm_access_code', 'unrelated_field'],
            $data['debug']['form']['snapshotFields'],
        );
        self::assertSame(3, $data['debug']['form']['snapshotSize']);

        // Values must NEVER leak into debug — even non-sensitive
        // strings. Pin the negative case explicitly.
        $raw = $resp->getContent();
        self::assertStringNotContainsString('leak-canary', $raw);
    }

    #[Test]
    public function debug_form_absent_when_no_snapshot_submitted(): void
    {
        // Field outside any form / frontend didn't send a snapshot.
        // The legacy debug shape stays exactly as before.
        // Use a single required rule (no sameAsField) so the validation
        // outcome doesn't depend on a sibling.
        $ctx = $this->confirmCtx(
            rules: [['n' => 'required']],
            fieldName: 'access_code',
        );
        $resp = $this->post($ctx, ['value' => 'standalone']);
        $data = $this->decode($resp);
        self::assertArrayNotHasKey('form', $data['debug']);
    }

    #[Test]
    public function payload_form_rules_is_rejected_as_smuggling(): void
    {
        $resp = $this->post($this->confirmCtx(), [
            'value' => 'abcd',
            'form'  => [
                'values' => ['access_code' => 'abcd'],
                'rules'  => [['minLength', 1]],   // smuggling attempt
            ],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('forbidden_payload_field', $data['reason']);
    }

    #[Test]
    public function payload_form_cfg_is_rejected_as_smuggling(): void
    {
        $resp = $this->post($this->confirmCtx(), [
            'value' => 'abcd',
            'form'  => [
                'values' => ['access_code' => 'abcd'],
                'cfg'    => ['x' => 'y'],
            ],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('forbidden_payload_field', $data['reason']);
    }

    #[Test]
    public function payload_form_must_be_object(): void
    {
        $resp = $this->post($this->confirmCtx(), [
            'value' => 'abcd',
            'form'  => 'oops',
        ]);
        self::assertSame(400, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('invalid_form_snapshot', $data['reason']);
    }

    #[Test]
    public function payload_form_values_with_array_value_rejected(): void
    {
        $resp = $this->post($this->confirmCtx(), [
            'value' => 'abcd',
            'form'  => ['values' => ['multi' => [1, 2]]],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('invalid_form_snapshot_value', $data['reason']);
    }

    #[Test]
    public function payload_form_values_with_unsafe_key_rejected(): void
    {
        $resp = $this->post($this->confirmCtx(), [
            'value' => 'abcd',
            'form'  => ['values' => ['evil key' => 'x']],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('invalid_form_snapshot_key', $data['reason']);
    }

    #[Test]
    public function tampered_ctx_returns_403_invalid_signed_ctx(): void
    {
        $tampered = $this->confirmCtx() . 'xx';
        $resp = $this->post($tampered, ['value' => 'abcd']);
        self::assertSame(403, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('invalid_signed_ctx', $data['reason']);
    }

    #[Test]
    public function client_cannot_change_same_as_field_target_through_payload(): void
    {
        // Signed rule pins access_code as the sibling. A payload
        // attempting to redirect to evil_field through any means
        // can't change cfg.r (it's signed) and can't smuggle a
        // forbidden routing field (payload guard rejects).
        $resp = $this->post($this->confirmCtx(), [
            'value' => 'abcd',
            'form'  => ['values' => [
                'access_code' => 'WRONG',
                'evil_field'  => 'abcd',  // not what the signed rule targets
            ]],
        ]);
        $data = $this->decode($resp);
        // Rule compares dispatched "abcd" to access_code "WRONG" → mismatch.
        self::assertSame('invalid', $data['debug']['validation']['state']);
        self::assertSame('Codes must match.', $data['debug']['validation']['message']);
    }

    #[Test]
    public function existing_required_min_length_max_length_unchanged(): void
    {
        // Same rule list the previous slice signs for a standalone
        // username field, no form snapshot. The legacy validation
        // path stays bit-for-bit identical.
        $ctx = SignedContext::sign([
            'c' => 'platform.field',
            'i' => self::INSTANCE_ID,
            'p' => 'input',
            'e' => 'change',
            'u' => 'value',
            'cfg' => [
                'r' => [
                    ['n' => 'required'],
                    ['n' => 'minLength', 'p' => [3]],
                    ['n' => 'maxLength', 'p' => [20]],
                ],
                // No fn → no self-merge needed for this path.
            ],
        ]);

        $resp = $this->post($ctx, ['value' => '']);
        $data = $this->decode($resp);
        self::assertSame('This field is required.', $this->validationMessage($data));

        $resp = $this->post($ctx, ['value' => 'ab']);
        $data = $this->decode($resp);
        self::assertSame('Please enter at least 3 characters.', $this->validationMessage($data));

        $resp = $this->post($ctx, ['value' => 'taras']);
        $data = $this->decode($resp);
        self::assertSame('Looks good.', $this->validationMessage($data));
    }
}
