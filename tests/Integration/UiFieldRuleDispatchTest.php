<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Event\AllowAllUiInteractionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiReplayStore;
use Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatcher;
use Semitexa\PlatformUi\Application\Service\Event\UiPatchValidator;
use Semitexa\PlatformUi\Application\Service\Event\UiPayloadFieldGuard;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleParser;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * End-to-end coverage of the validation-rules-via-signed-ctx flow:
 *
 *   1. Build a manifest with rules embedded as cfg.r.
 *   2. Hand the resulting signed ctx to the dispatcher.
 *   3. Confirm the handler emits the expected validation patches.
 *
 * Also covers the security boundary: tampering with cfg.r breaks the
 * HMAC, and payload.rules is rejected.
 */
final class UiFieldRuleDispatchTest extends TestCase
{
    private ?string $previousSecret = null;
    private ?string $previousEnv = null;
    private int $dispatchSeq = 0;

    protected function setUp(): void
    {
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-field-rules-test');
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
     * @param array<int, mixed> $rawRules
     */
    private function ctxForFieldWithRules(array $rawRules, string $instance = 'uci_rules_test_01'): string
    {
        // Pull the manifest the way the field template does, with
        // `cfg.r` populated by the rule parser. Returns the signed
        // ctx for the input.change event.
        $metadata = UiComponentRegistry::get('platform.field');
        $wire = (new UiFieldRuleParser())->parseAllToWire($rawRules);
        $manifest = (new UiEventManifestBuilder())->build(
            metadata:    $metadata,
            instanceId:  $instance,
            ttlSeconds:  300,
            eventConfig: ['input.change' => ['r' => $wire]],
        );
        return $manifest->entries[0]->signedContext;
    }

    private function newDispatcher(): UiInteractionDispatcher
    {
        return new UiInteractionDispatcher(
            payloadGuard: new UiPayloadFieldGuard(),
            patchValidator: new UiPatchValidator(),
            replayStore: new InMemoryUiReplayStore(),
            authorizer: new AllowAllUiInteractionAuthorizer(),
            productionLike: false,
        );
    }

    private function valueOfMessagePatch(UiInteractionResult $result): string
    {
        // Validation message lives in the third patch (index 2):
        // setAttribute aria-invalid, setAttribute ui-state, setText
        // validation-message, setText server-ack.
        $patch = $result->patches[2];
        self::assertSame(UiResponsePatch::OP_SET_TEXT, $patch->op);
        self::assertSame('validation-message', $patch->targetName);
        self::assertIsString($patch->value);
        return $patch->value;
    }

    #[Test]
    public function empty_value_with_signed_required_rule_returns_required_failure(): void
    {
        $ctx = $this->ctxForFieldWithRules(['required', ['minLength', 3]]);
        $result = $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), ['value' => '']);
        self::assertSame(UiInteractionResult::KIND_PATCH, $result->kind);
        self::assertSame('This field is required.', $this->valueOfMessagePatch($result));
        // aria-invalid=true on the input part.
        self::assertSame('aria-invalid', $result->patches[0]->attribute);
        self::assertSame('true', $result->patches[0]->value);
    }

    #[Test]
    public function short_value_with_signed_min_length_returns_too_short_failure(): void
    {
        $ctx = $this->ctxForFieldWithRules(['required', ['minLength', 3]]);
        $result = $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), ['value' => 'ab']);
        self::assertSame('Please enter at least 3 characters.', $this->valueOfMessagePatch($result));
    }

    #[Test]
    public function over_max_length_with_signed_max_length_returns_too_long_failure(): void
    {
        $ctx = $this->ctxForFieldWithRules(['required', ['minLength', 3], ['maxLength', 5]]);
        $result = $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), ['value' => 'hello!']);
        self::assertSame('Please enter no more than 5 characters.', $this->valueOfMessagePatch($result));
    }

    #[Test]
    public function valid_value_with_signed_rules_returns_valid_result(): void
    {
        $ctx = $this->ctxForFieldWithRules(['required', ['minLength', 3], ['maxLength', 20]]);
        $result = $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), ['value' => 'taras']);
        self::assertSame('Looks good.', $this->valueOfMessagePatch($result));
        // aria-invalid is REMOVED (null) on valid.
        self::assertNull($result->patches[0]->value);
        // ui-state flips to valid.
        self::assertSame('valid', $result->patches[1]->value);
    }

    #[Test]
    public function manifest_without_cfg_falls_back_to_default_rules(): void
    {
        // Build a manifest WITHOUT eventConfig — same as a render that
        // omits the `rules` prop. FieldComponent should fall back to
        // DEFAULT_RULES (required + minLength 3) and emit the same
        // demo behaviour the previous slice shipped.
        $metadata = UiComponentRegistry::get('platform.field');
        $manifest = (new UiEventManifestBuilder())->build(
            metadata:   $metadata,
            instanceId: 'uci_no_cfg_01',
            ttlSeconds: 300,
        );
        $ctx = $manifest->entries[0]->signedContext;

        $result = $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), ['value' => 'ab']);
        self::assertSame('Please enter at least 3 characters.', $this->valueOfMessagePatch($result));
    }

    #[Test]
    public function tampering_with_cfg_rules_breaks_the_hmac(): void
    {
        // Forge a token with a DIFFERENT cfg.r but reuse the original
        // signature. SignedContext::verify must reject it.
        $original = $this->ctxForFieldWithRules(['required', ['minLength', 3]]);
        [$prefix, $payloadB64, $sigB64] = explode('.', $original, 3);

        $tamperedClaims = json_decode(self::b64UrlDecode($payloadB64), true);
        // Swap minLength for maxLength=999 — an attacker trying to
        // bypass the minLength gate.
        $tamperedClaims['cfg']['r'] = [['n' => 'maxLength', 'p' => [999]]];
        $tamperedPayloadB64 = self::b64UrlEncode(json_encode($tamperedClaims));
        $tamperedToken = $prefix . '.' . $tamperedPayloadB64 . '.' . $sigB64;

        // SignedContext::verify returns null on bad signature.
        self::assertNull(SignedContext::verify($tamperedToken));
    }

    #[Test]
    public function client_payload_rules_is_rejected_as_forbidden_field(): void
    {
        $ctx = $this->ctxForFieldWithRules(['required']);
        $this->expectException(\Semitexa\PlatformUi\Domain\Exception\UiInteractionBadRequestException::class);
        try {
            $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), [
                'value' => 'ab',
                'rules' => [['maxLength', 1]], // attacker injection
            ]);
        } catch (\Semitexa\PlatformUi\Domain\Exception\UiInteractionBadRequestException $e) {
            self::assertSame('forbidden_payload_field', $e->reason);
            self::assertStringContainsString('payload.rules', $e->getMessage());
            throw $e;
        }
    }

    #[Test]
    public function rules_are_taken_from_signed_ctx_not_payload(): void
    {
        // Build a ctx with required+minLength(10). The value 'ab' (2
        // chars) must fail minLength because the signed ctx says so —
        // even if a client tried to soften it (which the guard
        // forbids, tested above). This test confirms the positive
        // case: the dispatcher reads cfg.r and uses it.
        $ctx = $this->ctxForFieldWithRules(['required', ['minLength', 10]]);
        $result = $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), ['value' => 'ab']);
        self::assertSame('Please enter at least 10 characters.', $this->valueOfMessagePatch($result));
    }

    private static function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $encoded): string
    {
        $pad = strlen($encoded) % 4;
        if ($pad > 0) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($encoded, '-_', '+/'), true) ?: '';
    }
}
