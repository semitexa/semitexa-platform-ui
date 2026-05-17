<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Exception\UiFormDemoSubmissionCursorException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionCursor;

/**
 * Opaque cursor contract:
 *   - round-trip encode/decode preserves the boundary values;
 *   - tight key whitelist + shape validation;
 *   - any deviation throws UiFormDemoSubmissionCursorException;
 *   - decoding never invokes unserialize / eval;
 *   - failure messages do not echo the bad cursor back.
 */
final class UiFormDemoSubmissionCursorTest extends TestCase
{
    #[Test]
    public function encode_decode_round_trips(): void
    {
        $original = new UiFormDemoSubmissionCursor(
            submittedAt: 1778900000,
            id:          'uifs_0123456789abcdef',
        );
        $encoded = $original->encode();
        // Pin the wire shape — base64url alphabet only, no padding.
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_\-]+\z/', $encoded);
        $decoded = UiFormDemoSubmissionCursor::decode($encoded);
        self::assertSame($original->submittedAt, $decoded->submittedAt);
        self::assertSame($original->id, $decoded->id);
    }

    #[Test]
    public function try_from_string_returns_null_for_missing_or_empty_input(): void
    {
        self::assertNull(UiFormDemoSubmissionCursor::tryFromString(null));
        self::assertNull(UiFormDemoSubmissionCursor::tryFromString(''));
        self::assertNull(UiFormDemoSubmissionCursor::tryFromString('   '));
    }

    #[Test]
    public function decode_rejects_outside_base64url_alphabet(): void
    {
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        UiFormDemoSubmissionCursor::decode('NOT$VALID#CURSOR');
    }

    #[Test]
    public function decode_rejects_invalid_base64(): void
    {
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        // Looks base64url but decodes to garbage JSON.
        UiFormDemoSubmissionCursor::decode('aaaa');
    }

    #[Test]
    public function decode_rejects_malformed_json(): void
    {
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        // base64url('not-json') — valid base64, garbage JSON.
        UiFormDemoSubmissionCursor::decode(rtrim(strtr(base64_encode('not-json'), '+/', '-_'), '='));
    }

    #[Test]
    public function decode_rejects_missing_submitted_at(): void
    {
        $payload = json_encode(['i' => 'uifs_0123456789abcdef'], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        UiFormDemoSubmissionCursor::decode($encoded);
    }

    #[Test]
    public function decode_rejects_missing_id(): void
    {
        $payload = json_encode(['s' => 1778900000], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        UiFormDemoSubmissionCursor::decode($encoded);
    }

    #[Test]
    public function decode_rejects_extra_keys(): void
    {
        $payload = json_encode(['s' => 1, 'i' => 'uifs_0123456789abcdef', 'evil' => 'extra'], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        UiFormDemoSubmissionCursor::decode($encoded);
    }

    #[Test]
    public function decode_rejects_wrong_types(): void
    {
        // submittedAt must be int (not numeric string).
        $payload = json_encode(['s' => '1778900000', 'i' => 'uifs_0123456789abcdef'], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        UiFormDemoSubmissionCursor::decode($encoded);
    }

    #[Test]
    public function decode_rejects_unsafe_id(): void
    {
        $payload = json_encode(['s' => 1, 'i' => 'evil"id'], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        UiFormDemoSubmissionCursor::decode($encoded);
    }

    #[Test]
    public function decode_rejects_negative_submitted_at(): void
    {
        $payload = json_encode(['s' => -1, 'i' => 'uifs_0123456789abcdef'], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        UiFormDemoSubmissionCursor::decode($encoded);
    }

    #[Test]
    public function constructor_rejects_unsafe_id(): void
    {
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        new UiFormDemoSubmissionCursor(submittedAt: 1, id: 'uifs_TOO_SHORT');
    }

    #[Test]
    public function exception_message_is_fixed_and_does_not_leak_input(): void
    {
        try {
            UiFormDemoSubmissionCursor::decode('aaaa');
            self::fail('Expected cursor exception.');
        } catch (UiFormDemoSubmissionCursorException $e) {
            self::assertSame('Pagination cursor is invalid.', $e->getMessage());
            self::assertSame('invalid_cursor', $e->reasonCode);
            self::assertStringNotContainsString('aaaa', $e->getMessage());
        }
    }

    #[Test]
    public function codec_does_not_use_serialize_or_unserialize(): void
    {
        // Defence in depth: the codec must reject PHP-serialized
        // input verbatim (it does not even look like JSON).
        $serialized = serialize(['s' => 1, 'i' => 'uifs_0123456789abcdef']);
        $encoded = rtrim(strtr(base64_encode($serialized), '+/', '-_'), '=');
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        UiFormDemoSubmissionCursor::decode($encoded);
    }

    #[Test]
    public function encode_decode_round_trips_with_filter_fingerprint(): void
    {
        $cursor = new UiFormDemoSubmissionCursor(
            submittedAt:       1778900000,
            id:                'uifs_0123456789abcdef',
            filterFingerprint: 'abcdef0123456789',
        );
        $encoded = UiFormDemoSubmissionCursor::decode($cursor->encode());
        self::assertSame(1778900000,           $encoded->submittedAt);
        self::assertSame('uifs_0123456789abcdef', $encoded->id);
        self::assertSame('abcdef0123456789',   $encoded->filterFingerprint);
    }

    #[Test]
    public function legacy_two_key_cursor_decodes_with_null_fingerprint(): void
    {
        // Pin backward compatibility — v1 cursors (no `f`) still decode.
        $payload = json_encode(['s' => 1, 'i' => 'uifs_0123456789abcdef'], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $decoded = UiFormDemoSubmissionCursor::decode($encoded);
        self::assertSame(1, $decoded->submittedAt);
        self::assertSame('uifs_0123456789abcdef', $decoded->id);
        self::assertNull($decoded->filterFingerprint);
    }

    #[Test]
    public function decode_rejects_unsafe_filter_fingerprint(): void
    {
        $payload = json_encode([
            's' => 1,
            'i' => 'uifs_0123456789abcdef',
            'f' => 'NOT-HEX-AT-ALL!!',
        ], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        UiFormDemoSubmissionCursor::decode($encoded);
    }

    #[Test]
    public function decode_rejects_wrong_length_filter_fingerprint(): void
    {
        $payload = json_encode([
            's' => 1,
            'i' => 'uifs_0123456789abcdef',
            'f' => 'a1b2c3d4', // 8 hex — fingerprint must be 16
        ], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        UiFormDemoSubmissionCursor::decode($encoded);
    }

    #[Test]
    public function decode_rejects_extra_keys_alongside_filter(): void
    {
        // Even with `f` present, any unknown key (e.g. `x`, `evil`) is
        // a smuggling attempt and must be rejected.
        $payload = json_encode([
            's' => 1,
            'i' => 'uifs_0123456789abcdef',
            'f' => 'abcdef0123456789',
            'x' => 'extra',
        ], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $this->expectException(UiFormDemoSubmissionCursorException::class);
        UiFormDemoSubmissionCursor::decode($encoded);
    }

    #[Test]
    public function encoded_cursor_does_not_contain_sensitive_substrings(): void
    {
        $cursor = new UiFormDemoSubmissionCursor(1778900000, 'uifs_deadbeefcafebabe');
        $encoded = $cursor->encode();
        // The encoded form is base64url(JSON), so the id WILL appear
        // base64-encoded within. We pin that the cursor never carries
        // table names, class names, tokens, dispatchIds, or any
        // operator-internal jargon — and that it stays a single
        // URL-safe token.
        self::assertStringNotContainsString('platform_ui_demo', $encoded);
        self::assertStringNotContainsString('Semitexa', $encoded);
        self::assertStringNotContainsString('dispatchId', $encoded);
        self::assertStringNotContainsString('csrf', $encoded);
        self::assertSame('', strpbrk($encoded, '+/=') ?: '', 'cursor must be URL-safe (base64url)');
    }
}
