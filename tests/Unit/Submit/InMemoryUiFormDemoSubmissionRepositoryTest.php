<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormDemoSubmissionRepository;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionRecord;

/**
 * Repository contract — exercised against the in-memory impl so the
 * test does not need the cache layer. The cache-backed impl shares
 * the same semantics (covered by the round-trip dispatch tests).
 *
 * Pins:
 *   - `save()` returns the same id the record carries (idempotent);
 *   - `find()` returns the verbatim record;
 *   - missing / expired id returns null;
 *   - repository never stores tokens / contexts / debug internals
 *     because it can only store the explicit record shape;
 *   - reset clears all records.
 */
final class InMemoryUiFormDemoSubmissionRepositoryTest extends TestCase
{
    private function record(string $id, array $values = ['contact_name' => 'Ada']): UiFormDemoSubmissionRecord
    {
        return new UiFormDemoSubmissionRecord(
            id: $id,
            formInstanceId: 'uci_demo_form_unit',
            actionName: 'platform.demo.storeContact',
            submittedAt: time(),
            values: $values,
        );
    }

    #[Test]
    public function save_returns_record_id_verbatim(): void
    {
        $repo = new InMemoryUiFormDemoSubmissionRepository();
        $id = $repo->save($this->record('uifs_unit_0001'));
        self::assertSame('uifs_unit_0001', $id);
    }

    #[Test]
    public function find_returns_the_same_record_shape(): void
    {
        $repo = new InMemoryUiFormDemoSubmissionRepository();
        $rec = $this->record('uifs_unit_0002', [
            'contact_name'    => 'Ada Lovelace',
            'contact_message' => 'Hi, this is a demo message.',
        ]);
        $repo->save($rec);
        $found = $repo->find('uifs_unit_0002');
        self::assertNotNull($found);
        self::assertSame($rec->id, $found->id);
        self::assertSame($rec->formInstanceId, $found->formInstanceId);
        self::assertSame($rec->actionName, $found->actionName);
        self::assertSame($rec->values, $found->values);
    }

    #[Test]
    public function find_returns_null_for_unknown_id(): void
    {
        $repo = new InMemoryUiFormDemoSubmissionRepository();
        self::assertNull($repo->find('uifs_unit_unknown'));
    }

    #[Test]
    public function expired_record_returns_null(): void
    {
        $repo = new InMemoryUiFormDemoSubmissionRepository();
        $repo->save($this->record('uifs_unit_expire'));
        // Reach in and rewind expiresAt to the past.
        $prop = new \ReflectionProperty($repo, 'records');
        $prop->setAccessible(true);
        $records = $prop->getValue($repo);
        $records['uifs_unit_expire']['expiresAt'] = time() - 1;
        $prop->setValue($repo, $records);
        self::assertNull($repo->find('uifs_unit_expire'));
    }

    #[Test]
    public function count_reflects_only_live_records(): void
    {
        $repo = new InMemoryUiFormDemoSubmissionRepository();
        $repo->save($this->record('uifs_unit_c1'));
        $repo->save($this->record('uifs_unit_c2'));
        self::assertSame(2, $repo->count());
        $repo->reset();
        self::assertSame(0, $repo->count());
    }

    #[Test]
    public function diagnostic_name_does_not_leak_class_fqcn(): void
    {
        $repo = new InMemoryUiFormDemoSubmissionRepository();
        $name = $repo->diagnosticName();
        self::assertSame('in-memory (worker-local)', $name);
        self::assertStringNotContainsString('Semitexa\\\\', $name);
    }

    #[Test]
    public function record_stored_carries_only_documented_fields(): void
    {
        // Defence in depth: the only thing the repository accepts is
        // the documented record shape. No token / ctx / debug fields
        // can ride along — the interface signature enforces it.
        $repo = new InMemoryUiFormDemoSubmissionRepository();
        $rec = $this->record('uifs_unit_shape', ['contact_name' => 'X']);
        $repo->save($rec);
        $found = $repo->find('uifs_unit_shape');
        self::assertNotNull($found);
        self::assertSame(
            ['id', 'formInstanceId', 'actionName', 'submittedAt', 'values'],
            array_keys(get_object_vars($found)),
        );
    }
}
