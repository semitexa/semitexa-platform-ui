<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormSubmitCsrfTokenStore;

/**
 * Token store contract — exercised against the in-memory impl so the
 * tests do not need the cache layer. The cache-backed impl shares the
 * same semantics (verified by a parallel test) so this is the
 * canonical contract suite.
 */
final class InMemoryUiFormSubmitCsrfTokenStoreTest extends TestCase
{
    #[Test]
    public function issue_returns_safe_shape_id_and_high_entropy_raw_token(): void
    {
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        $handle = $store->issue(60);
        self::assertMatchesRegularExpression('/\Auicsrf_[a-f0-9]{16}\z/', $handle->id);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $handle->raw);
    }

    #[Test]
    public function two_consecutive_issues_produce_distinct_ids_and_tokens(): void
    {
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        $a = $store->issue(60);
        $b = $store->issue(60);
        self::assertNotSame($a->id, $b->id);
        self::assertNotSame($a->raw, $b->raw);
    }

    #[Test]
    public function valid_consume_returns_true_and_removes_entry(): void
    {
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        $h = $store->issue(60);
        self::assertTrue($store->consume($h->id, $h->raw));
        // One-time semantics: second consume of the same token fails.
        self::assertFalse($store->consume($h->id, $h->raw));
    }

    #[Test]
    public function consume_with_unknown_id_returns_false(): void
    {
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        self::assertFalse($store->consume('uicsrf_0123456789abcdef', 'deadbeef00000000000000000000abcd'));
    }

    #[Test]
    public function consume_with_wrong_token_returns_false_and_leaves_entry_intact(): void
    {
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        $h = $store->issue(60);
        self::assertFalse($store->consume($h->id, str_repeat('0', 32)));
        // Correct token still works after a wrong attempt.
        self::assertTrue($store->consume($h->id, $h->raw));
    }

    #[Test]
    public function expired_token_returns_false(): void
    {
        // Fast path: reach into the store and rewind the entry's
        // expiresAt to the past, then call consume(). Avoids a
        // multi-second sleep in the test suite.
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        $h = $store->issue(60);
        $prop = new \ReflectionProperty($store, 'tokens');
        $prop->setAccessible(true);
        $tokens = $prop->getValue($store);
        $tokens[$h->id]['expiresAt'] = time() - 1;
        $prop->setValue($store, $tokens);
        self::assertFalse($store->consume($h->id, $h->raw));
    }

    #[Test]
    public function reset_clears_all_pending_tokens(): void
    {
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        $h = $store->issue(60);
        $store->reset();
        self::assertFalse($store->consume($h->id, $h->raw));
    }

    #[Test]
    public function in_memory_store_reports_not_shared_and_safe_diagnostic_name(): void
    {
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        self::assertFalse($store->isShared());
        $name = $store->diagnosticName();
        self::assertSame('in-memory (worker-local)', $name);
        // Diagnostic name must NOT leak class FQCNs.
        self::assertStringNotContainsString('Semitexa\\\\', $name);
    }

    #[Test]
    public function stored_value_is_hashed_not_raw_token(): void
    {
        // Defence in depth: reflect into the store to confirm the
        // internal map keeps only hashed values.
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        $h = $store->issue(60);
        $reflection = new \ReflectionProperty($store, 'tokens');
        $reflection->setAccessible(true);
        $tokens = $reflection->getValue($store);
        self::assertArrayHasKey($h->id, $tokens);
        self::assertStringNotContainsString($h->raw, $tokens[$h->id]['hash']);
        self::assertSame(64, strlen($tokens[$h->id]['hash']));
    }
}
