<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\UiSseChannelToken;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

final class UiSseChannelTokenTest extends TestCase
{
    private ?string $previousSecret = null;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-sse-token-test');
        putenv('APP_ENV=dev');
    }

    protected function tearDown(): void
    {
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

    #[Test]
    public function sign_then_verify_returns_channel_id(): void
    {
        $channelId = UiSseChannelToken::generateChannelId();
        $token = UiSseChannelToken::sign($channelId);
        self::assertSame($channelId, UiSseChannelToken::verifyChannelId($token));
    }

    #[Test]
    public function generated_channel_id_matches_documented_format(): void
    {
        $id = UiSseChannelToken::generateChannelId();
        self::assertSame(1, preg_match('/\Auch_[0-9a-f]{32}\z/', $id));
        // Must also satisfy the public CHANNEL_ID_PATTERN regex.
        self::assertSame(1, preg_match(UiSseChannelToken::CHANNEL_ID_PATTERN, $id));
    }

    #[Test]
    public function verify_rejects_malformed_token(): void
    {
        self::assertNull(UiSseChannelToken::verifyChannelId('not-a-token'));
        self::assertNull(UiSseChannelToken::verifyChannelId(''));
        self::assertNull(UiSseChannelToken::verifyChannelId('sc1.aaaa.bbbb'));
    }

    #[Test]
    public function verify_rejects_token_signed_with_wrong_purpose(): void
    {
        // Hand-craft a token with the dispatch-ctx claims (purpose='platform.field')
        // — must fail because UiSseChannelToken requires purpose='ui-patch-stream'.
        $wrongPurposeToken = SignedContext::sign([
            'c'  => 'platform.field',
            'ch' => UiSseChannelToken::generateChannelId(),
        ]);
        self::assertNull(UiSseChannelToken::verifyChannelId($wrongPurposeToken));
    }

    #[Test]
    public function verify_rejects_expired_token(): void
    {
        $channelId = UiSseChannelToken::generateChannelId();
        $token = UiSseChannelToken::sign($channelId, 1);
        sleep(2);
        self::assertNull(UiSseChannelToken::verifyChannelId($token));
    }

    #[Test]
    public function verify_rejects_tampered_signature(): void
    {
        $channelId = UiSseChannelToken::generateChannelId();
        $token = UiSseChannelToken::sign($channelId);
        $tampered = substr($token, 0, -2) . 'AA';
        self::assertNull(UiSseChannelToken::verifyChannelId($tampered));
    }

    /** @return iterable<string, array{0: string}> */
    public static function malformedChannelIds(): iterable
    {
        yield 'empty'         => [''];
        yield 'too short'     => ['abc'];
        yield 'leading dash'  => ['-uch_abc'];
        yield 'with space'    => ['uch space'];
        yield 'unicode'       => ['uch_dżem'];
        yield 'too long'      => [str_repeat('a', 129)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedChannelIds')]
    #[Test]
    public function sign_rejects_malformed_channel_id(string $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UiSseChannelToken::sign($bad);
    }

    #[Test]
    public function verify_rejects_token_with_malformed_channel_id_claim(): void
    {
        // Adversary signs a valid sc1 token but with a channel id that
        // violates our format. The signature passes; the purpose passes;
        // the channel-id format check must still reject.
        $bad = SignedContext::sign([
            'c'  => UiSseChannelToken::PURPOSE_CLAIM,
            'ch' => 'a b c d',
        ]);
        self::assertNull(UiSseChannelToken::verifyChannelId($bad));
    }

    #[Test]
    public function tokens_for_different_channels_are_distinct(): void
    {
        $a = UiSseChannelToken::sign(UiSseChannelToken::generateChannelId());
        $b = UiSseChannelToken::sign(UiSseChannelToken::generateChannelId());
        self::assertNotSame($a, $b);
    }
}
