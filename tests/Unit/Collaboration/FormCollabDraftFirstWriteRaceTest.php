<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\PlatformUi\Application\Db\MySQL\Repository\FormCollabDraftDbRepository;
use Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException;

/**
 * Two first edits of one form race to create its draft row.
 *
 * Seen live 2026-10-15: "Collaborative form event failed … 1062 Duplicate entry
 * 'default-formdoc:collab-demo:…' for key form_collab_draft.uniq…" — the loser's
 * edit was dropped.
 *
 * The interleaving is reproduced exactly, every run: the store reads through a
 * view that hides the other writer's row from the FIRST select only (a PHP
 * function with state), so the store sees no draft, its insert goes through an
 * INSTEAD OF trigger into the real table and meets the real unique key, and
 * the row is visible to every read after that — as it is after a lost race.
 *
 * Only paths that do not UPDATE afterwards are proven here: SQLite reports
 * zero changed rows for a write through a view, which the #[Version] guard
 * reads as a concurrent edit. The merge that follows a lost insert is the same
 * re-read-and-merge proven against a real concurrent writer in
 * FormCollabDraftConcurrentMergeTest.
 */
final class FormCollabDraftFirstWriteRaceTest extends TestCase
{
    private OrmManager $orm;

    private FormCollabDraftDbRepository $store;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $db = $this->orm->getAdapter();
        $db->execute(
            'CREATE TABLE draft_base (
                id TEXT PRIMARY KEY, tenant_id TEXT, scope_key TEXT NOT NULL,
                values_json TEXT NOT NULL, version INTEGER NOT NULL,
                updated_by TEXT, updated_at TEXT NOT NULL
            )',
        );
        $db->execute('CREATE UNIQUE INDEX uniq_draft_tenant_scope ON draft_base (tenant_id, scope_key)');

        $reads = 0;
        $pdo = $db->getPdo();
        self::createFunction($pdo, 'rival_visible', static function () use (&$reads): int {
            return $reads++ > 0 ? 1 : 0;
        });
        $db->execute(
            "CREATE VIEW form_collab_draft AS
             SELECT * FROM draft_base WHERE id NOT LIKE 'rival%' OR rival_visible() = 1",
        );
        $db->execute(
            'CREATE TRIGGER draft_insert INSTEAD OF INSERT ON form_collab_draft
             BEGIN
                INSERT INTO draft_base (id, tenant_id, scope_key, values_json, version, updated_by, updated_at)
                VALUES (NEW.id, NEW.tenant_id, NEW.scope_key, NEW.values_json, NEW.version, NEW.updated_by, NEW.updated_at);
             END',
        );
        $db->execute(
            'CREATE TRIGGER draft_update INSTEAD OF UPDATE ON form_collab_draft
             BEGIN
                UPDATE draft_base SET tenant_id = NEW.tenant_id, scope_key = NEW.scope_key,
                    values_json = NEW.values_json, version = NEW.version,
                    updated_by = NEW.updated_by, updated_at = NEW.updated_at
                WHERE id = OLD.id;
             END',
        );
        // The other writer's draft, already committed when ours is attempted.
        $db->execute(
            "INSERT INTO draft_base VALUES ('rival1', 'default', 'formdoc:demo:1', '{\"title\":\"theirs\"}', 1, 'bob', '2026-10-15 19:59:21')",
        );

        $this->store = (new FormCollabDraftDbRepository())->withOrmManager($this->orm);
    }

    // verify:accept-test-change the merge-after-lost-race case moved to FormCollabDraftConcurrentMergeTest, which proves it against a real concurrent writer; a write through this view harness reports zero changed rows under the new #[Version] guard
    #[Test]
    public function an_open_that_loses_the_race_returns_the_winners_draft(): void
    {
        $state = $this->store->open('formdoc:demo:1', ['title' => 'seed'], 'alice');

        self::assertSame(['title' => 'theirs'], $state->values);
        self::assertSame('bob', $state->updatedBy);
        self::assertSame(1, $this->rows());
    }

    #[Test]
    public function a_versioned_first_save_that_loses_the_race_is_a_version_conflict(): void
    {
        try {
            $this->store->apply('formdoc:demo:1', ['title' => 'mine'], 0, 'alice');
            self::fail('expected a version conflict');
        } catch (FormDraftVersionConflictException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(['title' => 'theirs'], $this->store->load('formdoc:demo:1')?->values);
    }

    #[Test]
    public function without_a_rival_the_first_edit_creates_the_draft(): void
    {
        $state = $this->store->mergeFields('formdoc:demo:2', ['body' => 'mine'], 'alice');

        self::assertSame(['body' => 'mine'], $state->values);
        self::assertSame(1, $state->version);
    }

    private function rows(): int
    {
        $rows = $this->orm->getAdapter()->execute('SELECT COUNT(*) AS n FROM draft_base')->fetchAll();

        return (int) ($rows[0]['n'] ?? -1);
    }
    /** Register a SQL function on whichever PDO the adapter opened. */
    private static function createFunction(\PDO $pdo, string $name, callable $fn): void
    {
        if ($pdo instanceof \Pdo\Sqlite) {
            $pdo->createFunction($name, $fn, 0, \Pdo\Sqlite::DETERMINISTIC);

            return;
        }
        $pdo->sqliteCreateFunction($name, $fn, 0, \PDO::SQLITE_DETERMINISTIC);
    }
}
