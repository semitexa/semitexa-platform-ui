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
 * Two people edit different fields of one collaborative form at once.
 *
 * Before the #[Version] guard both read version N, both wrote N+1, and the
 * later write silently erased the other's field.
 *
 * The race is real, not mocked, and happens on every run. Alice and Bob are two
 * connections to one SQLite file in WAL mode, as two workers are two
 * connections to MySQL. The table carries a virtual column computed by a PHP
 * function, so Alice's own SELECT calls it; at that moment, while Alice holds
 * her read snapshot, Bob commits his edit through his connection (WAL lets a
 * writer commit under an open reader). Alice then writes on what she read.
 */
final class FormCollabDraftConcurrentMergeTest extends TestCase
{
    private const SCOPE = 'formdoc:demo:1';

    private string $file;

    private FormCollabDraftDbRepository $alice;

    private FormCollabDraftDbRepository $bob;

    /** @var (\Closure(): void)|null Bob's edit, run once inside Alice's next read. */
    private ?\Closure $bobDuringAlicesRead = null;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/collab-merge-' . bin2hex(random_bytes(6)) . '.sqlite';
        $aliceOrm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqlitePath: $this->file));
        $bobOrm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqlitePath: $this->file));

        self::createFunction($aliceOrm->getAdapter()->getPdo(), 'bob_moves', function (): int {
            $edit = $this->bobDuringAlicesRead;
            $this->bobDuringAlicesRead = null;
            if ($edit !== null) {
                $edit();
            }

            return 0;
        });
        self::createFunction($bobOrm->getAdapter()->getPdo(), 'bob_moves', static fn (): int => 0);

        $aliceOrm->getAdapter()->execute(
            'CREATE TABLE form_collab_draft (
                id TEXT PRIMARY KEY, tenant_id TEXT, scope_key TEXT NOT NULL,
                values_json TEXT NOT NULL, version INTEGER NOT NULL,
                updated_by TEXT, updated_at TEXT NOT NULL,
                race_hook INTEGER GENERATED ALWAYS AS (bob_moves()) VIRTUAL
            )',
        );
        $aliceOrm->getAdapter()->execute(
            'CREATE UNIQUE INDEX uniq_form_collab_draft_tenant_scope ON form_collab_draft (tenant_id, scope_key)',
        );

        $this->alice = (new FormCollabDraftDbRepository())->withOrmManager($aliceOrm);
        $this->bob = (new FormCollabDraftDbRepository())->withOrmManager($bobOrm);
        $this->bob->open(self::SCOPE, ['seed' => 'x'], 'bob');
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->file . $suffix);
        }
    }

    #[Test]
    public function concurrent_edits_of_different_fields_both_survive(): void
    {
        $this->bobDuringAlicesRead = fn () => $this->bob->mergeFields(self::SCOPE, ['title' => 'theirs'], 'bob');

        $state = $this->alice->mergeFields(self::SCOPE, ['body' => 'mine'], 'alice');

        $stored = $this->bob->load(self::SCOPE);
        self::assertNotNull($stored);
        self::assertSame(['seed' => 'x', 'title' => 'theirs', 'body' => 'mine'], $stored->values, 'what is stored, not only what was returned');
        self::assertSame(3, $stored->version);
        self::assertSame($stored->values, $state->values);
        self::assertSame(3, $state->version);
    }

    #[Test]
    public function a_versioned_save_over_a_concurrent_edit_is_a_conflict_not_an_overwrite(): void
    {
        $this->bobDuringAlicesRead = fn () => $this->bob->mergeFields(self::SCOPE, ['title' => 'theirs'], 'bob');

        try {
            $this->alice->apply(self::SCOPE, ['seed' => 'x', 'body' => 'mine'], 1, 'alice');
            self::fail('expected a version conflict');
        } catch (FormDraftVersionConflictException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(['seed' => 'x', 'title' => 'theirs'], $this->bob->load(self::SCOPE)?->values, "Bob's edit stands");
    }

    #[Test]
    public function sequential_edits_still_count_versions_one_by_one(): void
    {
        $this->alice->mergeFields(self::SCOPE, ['a' => '1'], 'alice');
        $state = $this->alice->mergeFields(self::SCOPE, ['b' => '2'], 'alice');

        self::assertSame(['seed' => 'x', 'a' => '1', 'b' => '2'], $state->values);
        self::assertSame(3, $state->version);
        self::assertSame(3, $this->bob->load(self::SCOPE)?->version);
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
