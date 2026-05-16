<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\PlatformUi\Application\Handler\PayloadHandler\UiSseStreamHandler;
use Semitexa\PlatformUi\Application\Payload\Request\UiSseStreamPayload;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiSseConnectionLimiter;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiSsePatchQueue;
use Semitexa\PlatformUi\Application\Service\Event\UiSseChannelToken;
use Semitexa\PlatformUi\Application\Service\Event\UiSseConnectionLease;
use Semitexa\PlatformUi\Application\Service\Event\UiSseConnectionLimiterInterface;
use Semitexa\PlatformUi\Application\Service\Event\UiSseSubscriptionAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\Event\UiSseSubscriptionContext;
use Semitexa\PlatformUi\Domain\Exception\UiSseConnectionLimitException;

/**
 * Pre-Swoole behaviour of the SSE stream handler.
 *
 * The handler delegates the actual streaming loop to SwooleBootstrap +
 * Swoole\Coroutine, which require a Swoole runtime — those paths are
 * exercised live by the curl verification in the slice report. The
 * unit-testable surface is the token / authz / limiter ordering that
 * runs BEFORE the handler ever touches the Swoole response, plus the
 * safe-JSON error mapping when those checks fail.
 */
final class UiSseStreamHandlerTest extends TestCase
{
    private ?string $previousSecret = null;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-sse-stream-handler-test');
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

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $server
     */
    private function newHandler(array $query, array $server = []): UiSseStreamHandler
    {
        $request = new Request(
            method:  'GET',
            uri:     '/__ui/stream',
            headers: [],
            query:   $query,
            post:    [],
            server:  $server,
            cookies: [],
            content: null,
            files:   [],
        );
        return (new UiSseStreamHandler())
            ->withRequest($request)
            ->withQueue(new InMemoryUiSsePatchQueue());
    }

    /** @return array<string, mixed> */
    private function decodeJson(ResourceResponse $response): array
    {
        $decoded = json_decode($response->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function freshToken(?string &$channelIdOut = null): string
    {
        $channelIdOut = UiSseChannelToken::generateChannelId();
        return UiSseChannelToken::sign($channelIdOut);
    }

    #[Test]
    public function rejects_request_without_token_query_param(): void
    {
        $response = $this->newHandler([])->handle(new UiSseStreamPayload(), new ResourceResponse());
        self::assertSame(401, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertFalse($data['ok']);
        self::assertSame('missing_channel_token', $data['reason']);
    }

    #[Test]
    public function rejects_request_with_malformed_token(): void
    {
        $response = $this->newHandler(['token' => 'not-a-valid-token'])
            ->handle(new UiSseStreamPayload(), new ResourceResponse());
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_channel_token', $this->decodeJson($response)['reason']);
    }

    #[Test]
    public function rejects_request_with_empty_token(): void
    {
        $response = $this->newHandler(['token' => ''])
            ->handle(new UiSseStreamPayload(), new ResourceResponse());
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('missing_channel_token', $this->decodeJson($response)['reason']);
    }

    #[Test]
    public function returns_503_when_swoole_runtime_is_unavailable(): void
    {
        // PHPUnit runs outside Swoole, so SwooleBootstrap returns null.
        // With allow-all authorizer + in-memory limiter, the token
        // verifies, the context builds, authz allows, the limiter
        // claims a lease — but step 5 (Swoole acquisition) bails with
        // 503. The lease MUST be released in finally.
        $token = $this->freshToken();
        $limiter = new InMemoryUiSseConnectionLimiter();
        $handler = $this->newHandler(['token' => $token])->withLimiter($limiter);
        $response = $handler->handle(new UiSseStreamPayload(), new ResourceResponse());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('sse_unavailable', $this->decodeJson($response)['reason']);
        // Reflection: the finally block should have released the
        // lease. A second claim from the same context succeeds.
        $context = new UiSseSubscriptionContext(
            channelId: 'uch_test_release',
            purpose:   'ui-patch-stream',
            issuedAt:  time(),
            expiresAt: time() + 60,
            requestIp: '',
        );
        // Force the per-IP cap to 1; if the previous lease was leaked,
        // a second claim from the same IP would fail.
        $limiter->maxPerIp = 1;
        $second = $limiter->claim($context);
        self::assertSame('', $second->ip);
    }

    #[Test]
    public function denied_subscription_returns_403_and_never_opens_stream(): void
    {
        $deny = new class implements UiSseSubscriptionAuthorizerInterface {
            public ?UiSseSubscriptionContext $seen = null;
            public function authorize(UiSseSubscriptionContext $context): bool
            {
                $this->seen = $context;
                return false;
            }
        };
        $limiter = new class implements UiSseConnectionLimiterInterface {
            public int $claimCalls = 0;
            public int $releaseCalls = 0;
            public function claim(UiSseSubscriptionContext $context): UiSseConnectionLease
            {
                $this->claimCalls++;
                return new UiSseConnectionLease('uscl_test', $context->requestIp, $context->channelId, time(), 600);
            }
            public function release(UiSseConnectionLease $lease): void
            {
                $this->releaseCalls++;
            }
        };

        $channelId = null;
        $token = $this->freshToken($channelId);
        $response = $this->newHandler(['token' => $token])
            ->withAuthorizer($deny)
            ->withLimiter($limiter)
            ->handle(new UiSseStreamPayload(), new ResourceResponse());

        self::assertSame(403, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('subscription_forbidden', $data['reason']);
        self::assertSame('SSE subscription is not allowed.', $data['message']);
        // Limiter must NEVER have been consulted — denied attempts do
        // not consume a slot.
        self::assertSame(0, $limiter->claimCalls);
        self::assertSame(0, $limiter->releaseCalls);
        // Authorizer received the right channel id + purpose claim.
        self::assertNotNull($deny->seen);
        self::assertSame($channelId, $deny->seen->channelId);
        self::assertSame('ui-patch-stream', $deny->seen->purpose);
    }

    #[Test]
    public function subscription_context_carries_token_claims_and_request_ip(): void
    {
        $capture = new class implements UiSseSubscriptionAuthorizerInterface {
            public ?UiSseSubscriptionContext $seen = null;
            public function authorize(UiSseSubscriptionContext $context): bool
            {
                $this->seen = $context;
                return false; // short-circuit before stream opens
            }
        };
        $channelId = UiSseChannelToken::generateChannelId();
        $token = UiSseChannelToken::sign($channelId, 300);
        $handler = $this->newHandler(['token' => $token], ['remote_addr' => '203.0.113.42'])
            ->withAuthorizer($capture);
        $handler->handle(new UiSseStreamPayload(), new ResourceResponse());

        self::assertNotNull($capture->seen);
        self::assertSame($channelId, $capture->seen->channelId);
        self::assertSame('ui-patch-stream', $capture->seen->purpose);
        self::assertSame('203.0.113.42', $capture->seen->requestIp);
        self::assertGreaterThan(0, $capture->seen->issuedAt);
        self::assertGreaterThan($capture->seen->issuedAt, $capture->seen->expiresAt);
    }

    #[Test]
    public function authorizer_runs_after_token_verification(): void
    {
        // An invalid token must NEVER reach the authorizer — denied
        // attempts on an invalid token must still surface
        // invalid_channel_token, not subscription_forbidden, so client
        // logs reflect the actual rejection reason.
        $capture = new class implements UiSseSubscriptionAuthorizerInterface {
            public int $calls = 0;
            public function authorize(UiSseSubscriptionContext $context): bool
            {
                $this->calls++;
                return true;
            }
        };
        $response = $this->newHandler(['token' => 'sc1.bad.token'])
            ->withAuthorizer($capture)
            ->handle(new UiSseStreamPayload(), new ResourceResponse());
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_channel_token', $this->decodeJson($response)['reason']);
        self::assertSame(0, $capture->calls);
    }

    #[Test]
    public function connection_limit_exceeded_returns_429_and_never_opens_stream(): void
    {
        $limiter = new class implements UiSseConnectionLimiterInterface {
            public function claim(UiSseSubscriptionContext $context): UiSseConnectionLease
            {
                throw new UiSseConnectionLimitException(
                    'sse_connection_limit_exceeded',
                    'SSE connection limit reached.',
                );
            }
            public function release(UiSseConnectionLease $lease): void
            {
                // never called
            }
        };
        $token = $this->freshToken();
        $response = $this->newHandler(['token' => $token])
            ->withLimiter($limiter)
            ->handle(new UiSseStreamPayload(), new ResourceResponse());
        self::assertSame(429, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('sse_connection_limit_exceeded', $data['reason']);
    }

    #[Test]
    public function limiter_is_not_consulted_when_token_is_invalid(): void
    {
        // Missing token must short-circuit before claim() is called.
        $limiter = new class implements UiSseConnectionLimiterInterface {
            public int $claimCalls = 0;
            public function claim(UiSseSubscriptionContext $context): UiSseConnectionLease
            {
                $this->claimCalls++;
                return new UiSseConnectionLease('uscl_test', $context->requestIp, $context->channelId, time(), 600);
            }
            public function release(UiSseConnectionLease $lease): void
            {
            }
        };
        $response = $this->newHandler([])
            ->withLimiter($limiter)
            ->handle(new UiSseStreamPayload(), new ResourceResponse());
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('missing_channel_token', $this->decodeJson($response)['reason']);
        self::assertSame(0, $limiter->claimCalls);
    }

    #[Test]
    public function denied_subscription_response_does_not_leak_class_or_handler_names(): void
    {
        $deny = new class implements UiSseSubscriptionAuthorizerInterface {
            public function authorize(UiSseSubscriptionContext $context): bool { return false; }
        };
        $token = $this->freshToken();
        $response = $this->newHandler(['token' => $token])
            ->withAuthorizer($deny)
            ->handle(new UiSseStreamPayload(), new ResourceResponse());
        $raw = $response->getContent();
        self::assertStringNotContainsString('UiSseStreamHandler', $raw);
        self::assertStringNotContainsString('Semitexa\\\\', $raw);
        self::assertStringNotContainsString('AllowAll', $raw);
    }
}
