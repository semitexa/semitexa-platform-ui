<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\PlatformUi\Application\Service\Collaboration\InMemoryFormCollabDraftStore;
use Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException;

/**
 * Collaborative Form Data · Phase 2 — the draft-store contract, driven through
 * the in-memory implementation (the DB implementation mirrors the same
 * semantics and is integration-covered). Pins idempotent open, the optimistic
 * version guard, and last-write-wins merge.
 */
final class FormCollabDraftStoreTest extends TestCase
{
    private const SCOPE = 'formdoc:article:42';

    #[Test]
    public function open_seeds_a_new_draft_at_version_one(): void
    {
        $store = new InMemoryFormCollabDraftStore();

        $state = $store->open(self::SCOPE, ['title' => 'Hello'], 'alice');

        self::assertSame(1, $state->version);
        self::assertSame(['title' => 'Hello'], $state->values);
        self::assertSame('alice', $state->updatedBy);
    }

    #[Test]
    public function open_is_idempotent_and_does_not_reset_an_existing_draft(): void
    {
        $store = new InMemoryFormCollabDraftStore();
        $store->open(self::SCOPE, ['title' => 'Hello'], 'alice');
        $store->apply(self::SCOPE, ['title' => 'Edited'], 1, 'alice');

        $reopened = $store->open(self::SCOPE, ['title' => 'IGNORED'], 'bob');

        self::assertSame(2, $reopened->version);
        self::assertSame(['title' => 'Edited'], $reopened->values);
    }

    #[Test]
    public function apply_with_the_current_version_replaces_values_and_bumps_version(): void
    {
        $store = new InMemoryFormCollabDraftStore();
        $store->open(self::SCOPE, ['title' => 'Hello', 'body' => 'x'], 'alice');

        $state = $store->apply(self::SCOPE, ['title' => 'New', 'body' => 'y'], 1, 'bob');

        self::assertSame(2, $state->version);
        self::assertSame(['title' => 'New', 'body' => 'y'], $state->values);
        self::assertSame('bob', $state->updatedBy);
    }

    #[Test]
    public function apply_with_a_stale_version_throws_a_conflict_carrying_both_versions(): void
    {
        $store = new InMemoryFormCollabDraftStore();
        $store->open(self::SCOPE, ['title' => 'Hello'], 'alice');
        $store->apply(self::SCOPE, ['title' => 'Edited'], 1, 'alice'); // now v2

        try {
            $store->apply(self::SCOPE, ['title' => 'Stale'], 1, 'bob'); // bob read v1
            self::fail('Expected FormDraftVersionConflictException.');
        } catch (FormDraftVersionConflictException $e) {
            self::assertSame('form_draft_version_conflict', $e->getErrorCode());
            self::assertSame(1, $e->getErrorContext()['expectedVersion']);
            self::assertSame(2, $e->getErrorContext()['currentVersion']);
            self::assertSame(self::SCOPE, $e->getErrorContext()['scopeKey']);
        }
    }

    #[Test]
    public function apply_on_an_absent_draft_seeds_at_version_one_only_when_expecting_zero(): void
    {
        $store = new InMemoryFormCollabDraftStore();

        $state = $store->apply(self::SCOPE, ['title' => 'First'], 0, 'alice');
        self::assertSame(1, $state->version);

        $this->expectException(FormDraftVersionConflictException::class);
        (new InMemoryFormCollabDraftStore())->apply(self::SCOPE, ['x' => '1'], 5, 'alice');
    }

    #[Test]
    public function merge_fields_is_last_write_wins_per_field_and_bumps_version(): void
    {
        $store = new InMemoryFormCollabDraftStore();
        $store->open(self::SCOPE, ['title' => 'Hello', 'body' => 'orig'], 'alice');

        $state = $store->mergeFields(self::SCOPE, ['body' => 'changed'], 'bob');

        self::assertSame(2, $state->version);
        self::assertSame(['title' => 'Hello', 'body' => 'changed'], $state->values);
    }

    #[Test]
    public function merge_fields_on_an_absent_draft_seeds_it(): void
    {
        $store = new InMemoryFormCollabDraftStore();

        $state = $store->mergeFields(self::SCOPE, ['title' => 'Fresh'], 'alice');

        self::assertSame(1, $state->version);
        self::assertSame(['title' => 'Fresh'], $state->values);
    }

    #[Test]
    public function load_returns_null_for_an_unopened_scope(): void
    {
        self::assertNull((new InMemoryFormCollabDraftStore())->load('formdoc:article:nope'));
    }

    #[Test]
    public function drafts_are_isolated_per_tenant_even_on_an_identical_scope_key(): void
    {
        // The security property: two tenants editing the SAME formKey:recordId
        // must never read or overwrite each other's draft.
        $store = new InMemoryFormCollabDraftStore();

        $store->withTenantContext($this->tenant('acme'));
        $store->open(self::SCOPE, ['title' => 'Acme secret'], 'alice');

        // A different tenant on the identical scope key sees no draft …
        $store->withTenantContext($this->tenant('globex'));
        self::assertNull($store->load(self::SCOPE), 'Globex must not see Acme\'s draft');

        // … and writing under that tenant creates an INDEPENDENT row.
        $store->open(self::SCOPE, ['title' => 'Globex draft'], 'bob');
        $store->apply(self::SCOPE, ['title' => 'Globex edited'], 1, 'bob');
        self::assertSame(['title' => 'Globex edited'], $store->load(self::SCOPE)->values);

        // Acme's draft is untouched at its own version/values.
        $store->withTenantContext($this->tenant('acme'));
        $acme = $store->load(self::SCOPE);
        self::assertSame(['title' => 'Acme secret'], $acme->values);
        self::assertSame(1, $acme->version);
    }

    #[Test]
    public function the_default_context_shares_one_partition(): void
    {
        // No tenant context (playground / single-tenant) keeps the prior
        // behaviour: a single shared draft per scope key.
        $store = new InMemoryFormCollabDraftStore();
        $store->open(self::SCOPE, ['title' => 'Shared'], 'alice');

        self::assertSame(['title' => 'Shared'], $store->load(self::SCOPE)->values);
    }

    private function tenant(string $id): TenantContextInterface
    {
        return new class ($id) implements TenantContextInterface {
            public function __construct(private readonly string $id) {}

            public function getTenantId(): string
            {
                return $this->id;
            }

            public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
            {
                return null;
            }

            public function hasLayer(TenantLayerInterface $layer): bool
            {
                return false;
            }
        };
    }
}
