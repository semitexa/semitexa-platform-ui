<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Core\RateLimit\InMemoryRateLimitStore;
use Semitexa\Core\RateLimit\RateLimiter;
use Semitexa\Core\RateLimit\RateLimitStorage;
use Semitexa\Core\Request;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\PlatformUi\Application\Service\Submit\Auth\ForgotPasswordAction;
use Semitexa\PlatformUi\Application\Service\Submit\Auth\ResetPasswordAction;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;
use Semitexa\Platform\User\Application\Service\PasswordHasher;
use Semitexa\Platform\User\Application\Service\PasswordResetService;
use Semitexa\Platform\User\Application\Service\PasswordResetTokenStore;
use Semitexa\Platform\User\Application\Service\UnconfiguredPasswordResetMailer;
use Semitexa\Platform\User\Domain\Contract\PasswordResetMailerInterface;
use Semitexa\Platform\User\Domain\Contract\PlatformUserRepositoryInterface;
use Semitexa\Platform\User\Domain\Model\PlatformUser;

/**
 * The ready reset action: the project wrote it from a recipe, every time.
 */
final class PasswordResetActionsTest extends TestCase
{
    private OrmManager $orm;
    private PasswordResetService $service;
    private string $token = '';

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute('CREATE TABLE platform_user_password_reset (token_hash CHAR(64) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, tenant_id VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, credential_version INT NOT NULL DEFAULT 0)');
        $tokens = new PasswordResetTokenStore();
        self::set($tokens, 'orm', $this->orm);
        $hasher = new PasswordHasher();
        self::set($hasher, 'memoryKb', 8);
        self::set($hasher, 'timeCost', 1);

        $this->service = new PasswordResetService();
        self::set($this->service, 'users', new ResetActionUsers(new PlatformUser(id: 'u-1', email: 'ada@example.test', passwordHash: password_hash('old-passphrase-1', PASSWORD_BCRYPT, ['cost' => 4]))));
        self::set($this->service, 'hasher', $hasher);
        self::set($this->service, 'tokens', $tokens);
        $token = &$this->token;
        self::set($this->service, 'mailer', new class ($token) implements PasswordResetMailerInterface {
            public function __construct(private string &$token) {}
            public function sendResetLink(PlatformUser $user, #[\SensitiveParameter] string $token, \DateTimeImmutable $expiresAt): void
            {
                $this->token = $token;
            }
        });
        $this->service->request('ada@example.test');
    }

    protected function tearDown(): void
    {
        $this->orm->shutdown();
    }

    #[Test]
    public function the_link_s_token_resets_the_password_once_and_goes_to_the_sign_in_path(): void
    {
        $first = $this->reset(['password' => 'a-new-long-passphrase', 'password_confirmation' => 'a-new-long-passphrase'], ['token' => $this->token, 'signInHref' => '/sign-in']);
        $again = $this->reset(['password' => 'a-new-long-passphrase', 'password_confirmation' => 'a-new-long-passphrase'], ['token' => $this->token]);

        self::assertTrue($first->accepted);
        self::assertSame('/sign-in', $first->redirectTo);
        self::assertFalse($again->accepted, 'single use');
    }

    #[Test]
    public function a_full_url_is_not_a_redirect_target(): void
    {
        $result = $this->reset(['password' => 'a-new-long-passphrase', 'password_confirmation' => 'a-new-long-passphrase'], ['token' => $this->token, 'signInHref' => 'https://evil.test/']);

        self::assertTrue($result->accepted);
        self::assertNull($result->redirectTo);
    }

    #[Test]
    public function a_mismatched_repeat_or_a_weak_password_is_refused_and_the_link_kept(): void
    {
        self::assertFalse($this->reset(['password' => 'a-new-long-passphrase', 'password_confirmation' => 'something-else-entirely'], ['token' => $this->token])->accepted);
        self::assertFalse($this->reset(['password' => 'short', 'password_confirmation' => 'short'], ['token' => $this->token])->accepted);
        self::assertTrue($this->reset(['password' => 'a-new-long-passphrase', 'password_confirmation' => 'a-new-long-passphrase'], ['token' => $this->token])->accepted, 'the link still works');
    }

    /** The same answer, known address or not; the link goes only to a real one. */
    #[Test]
    public function the_forgot_form_answers_the_same_for_every_address(): void
    {
        $this->token = '';
        $known = $this->forgot('ada@example.test');
        $mailed = $this->token;
        $this->token = '';
        $unknown = $this->forgot('nobody@example.test');

        self::assertSame([$known->accepted, $known->message], [$unknown->accepted, $unknown->message]);
        self::assertNotSame('', $mailed);
        self::assertSame('', $this->token);
    }

    /** A real account costs a token and a mail: the answer time must not tell. */
    #[Test]
    public function every_forgot_answer_takes_at_least_the_floor(): void
    {
        $started = microtime(true);
        $this->forgot('nobody@example.test', floorMs: 300);

        self::assertGreaterThanOrEqual(0.29, microtime(true) - $started);
    }

    #[Test]
    public function a_client_asking_too_often_is_refused(): void
    {
        $store = new InMemoryRateLimitStore();
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($this->forgot('nobody@example.test', store: $store)->accepted);
        }

        self::assertFalse($this->forgot('nobody@example.test', store: $store)->accepted);
    }

    #[Test]
    public function without_a_mailer_the_form_says_reset_is_not_available(): void
    {
        self::set($this->service, 'mailer', new UnconfiguredPasswordResetMailer());

        $result = $this->forgot('ada@example.test');

        self::assertFalse($result->accepted);
        self::assertStringContainsString('not available', $result->message);
    }

    #[Test]
    public function a_redirect_the_response_would_refuse_is_not_given(): void
    {
        foreach (['/\\evil.test', '//evil.test', '/sign in'] as $href) {
            $this->setUp();
            $result = $this->reset(['password' => 'a-new-long-passphrase', 'password_confirmation' => 'a-new-long-passphrase'], ['token' => $this->token, 'signInHref' => $href]);
            self::assertTrue($result->accepted, $href);
            self::assertNull($result->redirectTo, $href);
        }
    }

    private function forgot(string $email, int $floorMs = 0, ?InMemoryRateLimitStore $store = null): UiFormSubmitActionResult
    {
        $storage = new RateLimitStorage();
        $storage->use($store ?? new InMemoryRateLimitStore());
        $limiter = new RateLimiter();
        self::set($limiter, 'storage', $storage);
        self::set($limiter, 'request', new Request('POST', '/', [], [], [], ['remote_addr' => '203.0.113.7'], []));
        self::set($limiter, 'authContext', new class implements AuthContextInterface {
            public function getUser(): ?AuthenticatableInterface { return null; }
            public function isGuest(): bool { return true; }
            public function setUser(?AuthenticatableInterface $user): void {}
            public static function get(): ?self { return null; }
            public static function getOrFail(): self { throw new \LogicException('not in tests'); }
        });
        $action = new ForgotPasswordAction();
        self::set($action, 'resets', $this->service);
        self::set($action, 'limiter', $limiter);
        self::set($action, 'minAnswerMs', $floorMs);

        return $action->handle(new UiFormSubmitActionContext('uci_forgot_0001', ForgotPasswordAction::NAME, 'ui_evt_x', ['email' => $email], [], UiFormSubmitResult::fromFieldResults([])));
    }

    /**
     * @param array<string, scalar|null> $values
     * @param array<string, mixed> $props
     */
    private function reset(array $values, array $props): UiFormSubmitActionResult
    {
        $action = new ResetPasswordAction();
        self::set($action, 'resets', $this->service);

        return $action->handle(new UiFormSubmitActionContext('uci_reset_0001', ResetPasswordAction::NAME, 'ui_evt_x', $values, [], UiFormSubmitResult::fromFieldResults([]), null, $props));
    }

    private static function set(object $target, string $property, mixed $value): void
    {
        (new \ReflectionProperty($target, $property))->setValue($target, $value);
    }
}

final class ResetActionUsers implements PlatformUserRepositoryInterface
{
    public function __construct(private PlatformUser $user) {}
    public function findById(string $id): ?PlatformUser { return $this->user->getId() === $id ? $this->user : null; }
    public function findByEmail(string $email, ?string $tenantId = null): ?PlatformUser { return $this->user->getEmail() === $email ? $this->user : null; }
    public function findAll(?string $tenantId = null, bool $includeDisabled = false): array { return [$this->user]; }
    public function save(PlatformUser $user): void { $this->user = $user; }
    public function update(PlatformUser $user): void { $this->user = $user; }
    public function delete(string $id): void {}
}
