<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\Action\PlatformDemoStoreContactDbAction;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormDatabaseDemoSubmissionRepository;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;

/**
 * Unit-level contract for the database-backed demo action.
 *
 * Mirrors {@see PlatformDemoStoreContactActionTest} pin-for-pin so
 * the cache-backed + DB-backed actions stay behaviourally
 * interchangeable from the form's perspective. Pins:
 *   - name matches the registry constant;
 *   - exactly one record persists per valid handle();
 *   - allow-list silently drops unknown snapshot keys;
 *   - whitespace trim;
 *   - empty-after-trim values dropped;
 *   - debug carries `submissionId` + `storage: database` but never
 *     raw values;
 *   - debug carries no class FQCN.
 */
final class PlatformDemoStoreContactDbActionTest extends TestCase
{
    private InMemoryUiFormDatabaseDemoSubmissionRepository $repo;
    private PlatformDemoStoreContactDbAction $action;

    protected function setUp(): void
    {
        $this->repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $this->action = new PlatformDemoStoreContactDbAction($this->repo);
    }

    private function context(array $values): UiFormSubmitActionContext
    {
        return new UiFormSubmitActionContext(
            formInstanceId: 'uci_demo_form_db_act',
            actionName:     PlatformDemoStoreContactDbAction::NAME,
            dispatchId:     'ui_evt_act_db_unit',
            values:         $values,
            fields:         [],
            submitResult:   UiFormSubmitResult::fromFieldResults([
                ['name' => 'contact_name', 'state' => 'valid', 'message' => null],
            ]),
        );
    }

    #[Test]
    public function name_matches_registry_constant(): void
    {
        self::assertSame('platform.demo.storeContactDb', $this->action->name());
    }

    #[Test]
    public function valid_submission_stores_one_record_and_returns_accepted(): void
    {
        $result = $this->action->handle($this->context([
            'contact_name'    => 'Ada Lovelace',
            'contact_message' => 'Hello world.',
        ]));
        self::assertTrue($result->accepted);
        self::assertSame(PlatformDemoStoreContactDbAction::MESSAGE, $result->message);
        self::assertTrue($result->debug['stored']);
        self::assertSame('database', $result->debug['storage']);
        self::assertMatchesRegularExpression('/\Auifs_[a-f0-9]{16}\z/', $result->debug['submissionId']);
        self::assertSame(2, $result->debug['storedFieldCount']);
        self::assertSame(1, $this->repo->count());

        $found = $this->repo->find($result->debug['submissionId']);
        self::assertNotNull($found);
        self::assertSame('Ada Lovelace', $found->values['contact_name']);
        self::assertSame('Hello world.', $found->values['contact_message']);
        self::assertSame(PlatformDemoStoreContactDbAction::NAME, $found->actionName);
        self::assertSame('uci_demo_form_db_act', $found->formInstanceId);
    }

    #[Test]
    public function allowed_field_list_drops_unknown_snapshot_keys(): void
    {
        $result = $this->action->handle($this->context([
            'contact_name'    => 'Ada',
            'contact_message' => 'msg',
            'contact_topic'   => 'topic',
            'evil_extra'      => 'should_be_dropped',
            'role'            => 'admin',
        ]));
        $found = $this->repo->find($result->debug['submissionId']);
        self::assertNotNull($found);
        self::assertSame(['contact_name', 'contact_message', 'contact_topic'], array_keys($found->values));
        self::assertArrayNotHasKey('evil_extra', $found->values);
        self::assertArrayNotHasKey('role', $found->values);
    }

    #[Test]
    public function whitespace_around_values_is_trimmed(): void
    {
        $result = $this->action->handle($this->context([
            'contact_name'    => "   Ada   \n",
            'contact_message' => "\tHello\t",
        ]));
        $found = $this->repo->find($result->debug['submissionId']);
        self::assertSame('Ada', $found->values['contact_name']);
        self::assertSame('Hello', $found->values['contact_message']);
    }

    #[Test]
    public function empty_after_trim_values_are_dropped(): void
    {
        $result = $this->action->handle($this->context([
            'contact_name'    => 'Ada',
            'contact_message' => '   ',
            'contact_topic'   => null,
        ]));
        $found = $this->repo->find($result->debug['submissionId']);
        self::assertSame(['contact_name'], array_keys($found->values));
        self::assertSame(1, $result->debug['storedFieldCount']);
    }

    #[Test]
    public function debug_payload_does_not_echo_raw_values(): void
    {
        $result = $this->action->handle($this->context([
            'contact_name'    => 'leak-canary-name',
            'contact_message' => 'leak-canary-message',
        ]));
        $serialised = json_encode($result->toDebug(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('leak-canary-name', $serialised);
        self::assertStringNotContainsString('leak-canary-message', $serialised);
    }

    #[Test]
    public function debug_payload_does_not_leak_class_or_repository_fqcn(): void
    {
        $result = $this->action->handle($this->context(['contact_name' => 'Ada']));
        $serialised = json_encode($result->toDebug(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PlatformDemoStoreContactDbAction', $serialised);
        self::assertStringNotContainsString('Repository', $serialised);
        self::assertStringNotContainsString('Semitexa\\\\', $serialised);
    }

    #[Test]
    public function stored_record_carries_no_unsafe_metadata(): void
    {
        // Defence in depth: even though the repository interface
        // signature already constrains the shape, pin that no
        // surprise property (tokens / ctx / dispatchId) ever appears
        // in the stored record.
        $result = $this->action->handle($this->context([
            'contact_name'    => 'Ada',
            'contact_message' => 'Hello.',
        ]));
        $found = $this->repo->find($result->debug['submissionId']);
        $props = array_keys(get_object_vars($found));
        self::assertSame(
            ['id', 'formInstanceId', 'actionName', 'submittedAt', 'values'],
            $props,
        );
    }
}
