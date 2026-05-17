<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\CacheBackedUiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitSecurityPolicyException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitSecurityContext;

/**
 * Cache-backed CSRF policy contract. The policy delegates to the
 * active UiFormSubmitCsrfTokenStore; we drive both via the in-memory
 * impl so unit tests do not need the cache layer.
 *
 * Pins:
 *   - missing / malformed cfg.s → safe csrf_verification_failed;
 *   - unknown / wrong / consumed token → same failure;
 *   - successful verify removes the token (one-time);
 *   - failure exception carries the documented reason code + safe message;
 *   - failure surface never echoes the bad id/token.
 */
final class CacheBackedUiFormSubmitSecurityPolicyTest extends TestCase
{
    private InMemoryUiFormSubmitCsrfTokenStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryUiFormSubmitCsrfTokenStore();
        UiFormSubmitCsrfTokenStore::setActive($this->store);
    }

    protected function tearDown(): void
    {
        UiFormSubmitCsrfTokenStore::reset();
    }

    private function makeContext(array $cfgS): UiFormSubmitSecurityContext
    {
        return new UiFormSubmitSecurityContext(
            formInstanceId: 'uci_form_csrf_unit',
            actionName:     'platform.demo.accept',
            dispatchId:     'ui_evt_csrf_unit',
            fields:         [],
            submitResult:   UiFormSubmitResult::fromFieldResults([
                ['name' => 'a', 'state' => 'valid', 'message' => null],
            ]),
            securityConfig: $cfgS,
        );
    }

    #[Test]
    public function valid_token_passes_and_is_consumed(): void
    {
        $h = $this->store->issue(60);
        $policy = new CacheBackedUiFormSubmitSecurityPolicy();
        $policy->verify($this->makeContext(['k' => $h->id, 't' => $h->raw]));
        // Token is consumed — second verify with same handle fails.
        $this->expectException(UiFormSubmitSecurityPolicyException::class);
        $policy->verify($this->makeContext(['k' => $h->id, 't' => $h->raw]));
    }

    #[Test]
    public function missing_security_config_fails_with_csrf_reason(): void
    {
        $policy = new CacheBackedUiFormSubmitSecurityPolicy();
        try {
            $policy->verify($this->makeContext([]));
            self::fail('Expected csrf_verification_failed.');
        } catch (UiFormSubmitSecurityPolicyException $e) {
            self::assertSame('csrf_verification_failed', $e->reasonCode);
            self::assertSame(
                'Submit security check failed. Please reload the form and try again.',
                $e->getMessage(),
            );
        }
    }

    #[Test]
    public function unsafe_token_id_shape_fails(): void
    {
        $policy = new CacheBackedUiFormSubmitSecurityPolicy();
        $this->expectException(UiFormSubmitSecurityPolicyException::class);
        $policy->verify($this->makeContext([
            'k' => 'evil"id', // not uicsrf_<hex>
            't' => str_repeat('a', 32),
        ]));
    }

    #[Test]
    public function unsafe_token_raw_shape_fails(): void
    {
        $policy = new CacheBackedUiFormSubmitSecurityPolicy();
        $this->expectException(UiFormSubmitSecurityPolicyException::class);
        $policy->verify($this->makeContext([
            'k' => 'uicsrf_0123456789abcdef',
            't' => 'tooshort',
        ]));
    }

    #[Test]
    public function uci_instance_id_smuggled_as_csrf_id_fails(): void
    {
        // Defensive: a caller cannot mis-use a component instance id
        // (uci_…) as a CSRF id (uicsrf_…). Different prefix, different
        // namespace — the policy must reject explicitly.
        $policy = new CacheBackedUiFormSubmitSecurityPolicy();
        $this->expectException(UiFormSubmitSecurityPolicyException::class);
        $policy->verify($this->makeContext([
            'k' => 'uci_0123456789abcdef',
            't' => str_repeat('a', 32),
        ]));
    }

    #[Test]
    public function unknown_token_id_fails(): void
    {
        $policy = new CacheBackedUiFormSubmitSecurityPolicy();
        $this->expectException(UiFormSubmitSecurityPolicyException::class);
        $policy->verify($this->makeContext([
            'k' => 'uicsrf_0123456789abcdef',
            't' => str_repeat('a', 32),
        ]));
    }

    #[Test]
    public function wrong_token_for_known_id_fails(): void
    {
        $h = $this->store->issue(60);
        $policy = new CacheBackedUiFormSubmitSecurityPolicy();
        $this->expectException(UiFormSubmitSecurityPolicyException::class);
        $policy->verify($this->makeContext([
            'k' => $h->id,
            't' => str_repeat('0', 32),
        ]));
    }

    #[Test]
    public function failure_message_does_not_leak_token_id_or_value(): void
    {
        $policy = new CacheBackedUiFormSubmitSecurityPolicy();
        try {
            $policy->verify($this->makeContext([
                'k' => 'uicsrf_deadbeefcafebabe',
                't' => str_repeat('1', 32),
            ]));
            self::fail('Expected csrf_verification_failed.');
        } catch (UiFormSubmitSecurityPolicyException $e) {
            self::assertStringNotContainsString('uicsrf_deadbeefcafebabe', $e->getMessage());
            self::assertStringNotContainsString('11111111', $e->getMessage());
            self::assertStringNotContainsString('CacheBacked', $e->getMessage());
            self::assertStringNotContainsString('Semitexa\\\\', $e->getMessage());
        }
    }
}
