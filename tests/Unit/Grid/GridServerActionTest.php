<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Grid;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\GridComponent;
use Semitexa\PlatformUi\Application\Service\Grid\UiGridActionInterface;
use Semitexa\PlatformUi\Application\Service\Grid\UiGridActions;
use Semitexa\PlatformUi\Attribute\AsGridAction;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridAction;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridActionContext;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridActionResult;

/**
 * tk-rs-actions: a grid runs only the actions it was rendered with (signed
 * props), in a scope it offers, on ids shaped like ids; the answer is a toast
 * and an event the grid clears its selection on.
 */
final class GridServerActionTest extends TestCase
{
    private RecordingGridActionFixture $handler;

    /** @var array<string, mixed> The registry as this test found it. */
    private array $handlersBefore;

    protected function setUp(): void
    {
        // The registry is process-global: put back what was there, or a
        // later test that dispatches a discovered action fails in company.
        $this->handlersBefore = (new \ReflectionProperty(UiGridActions::class, 'handlers'))->getValue();
        $this->handler = new RecordingGridActionFixture();
        UiGridActions::add($this->handler);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(UiGridActions::class, 'handlers'))->setValue(null, $this->handlersBefore);
    }

    #[Test]
    public function an_offered_action_reaches_its_handler_and_answers_with_a_toast_and_an_event(): void
    {
        $result = (new GridComponent())->onAction($this->event(['op' => 'archive', 'scope' => 'bulk', 'ids' => ['a', 'b', 'a']]));

        $context = $this->handler->last;
        self::assertNotNull($context);
        self::assertSame(['archive', 'bulk', ['a', 'b']], [$context->action->id, $context->scope, $context->ids], 'duplicates collapse');
        self::assertSame('screen.one', $context->props['crud']);
        self::assertSame([UiResponsePatch::OP_TOAST, UiResponsePatch::OP_DISPATCH], array_map(static fn (UiResponsePatch $p) => $p->op, $result->patches));
        self::assertSame('Archived 2.', $result->patches[0]->value);
        self::assertSame('ui-grid:action', $result->patches[1]->value);
        self::assertSame(['op' => 'archive', 'ok' => true, 'affected' => 2], $result->patches[1]->args['detail']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'an action the grid was not rendered with' => [['op' => 'drop', 'scope' => 'row', 'ids' => ['a']], 'invalid_grid_action'];
        yield 'a row action on two rows' => [['op' => 'archive', 'scope' => 'row', 'ids' => ['a', 'b']], 'invalid_grid_action_scope'];
        yield 'a header action with rows' => [['op' => 'archive', 'scope' => 'header', 'ids' => ['a']], 'invalid_grid_action_scope'];
        yield 'a scope the action does not offer' => [['op' => 'export', 'scope' => 'bulk', 'ids' => ['a']], 'invalid_grid_action_scope'];
        yield 'an id that is not an id' => [['op' => 'archive', 'scope' => 'row', 'ids' => ['../x']], 'invalid_grid_action_ids'];
        yield 'too many ids' => [['op' => 'archive', 'scope' => 'bulk', 'ids' => array_map('strval', range(1, GridComponent::MAX_IDS + 1))], 'invalid_grid_action_scope'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function anything_else_is_refused_before_the_handler(array $value, string $reason): void
    {
        try {
            (new GridComponent())->onAction($this->event($value));
            self::fail('must be refused');
        } catch (UiInteractionUnprocessableException $e) {
            self::assertSame($reason, $e->reason);
        }
        self::assertNull($this->handler->last);
    }

    #[Test]
    public function a_grid_without_a_registered_handler_runs_nothing(): void
    {
        $this->expectException(UiInteractionUnprocessableException::class);
        (new GridComponent())->onAction($this->event(['op' => 'archive', 'scope' => 'row', 'ids' => ['a']], 'nobody.home'));
    }

    #[Test]
    public function an_action_names_its_scopes_and_round_trips_through_props(): void
    {
        $action = new UiGridAction('delete', 'Delete', ['row', 'bulk'], 'Delete this?', 'danger', 'Delete {count}?');
        self::assertEquals($action, UiGridAction::fromProps($action->toProps()));
        self::assertNull(UiGridAction::fromProps(['id' => 'x', 'label' => 'X', 'scopes' => ['everywhere']]));

        $this->expectException(\InvalidArgumentException::class);
        new UiGridAction('delete', 'Delete', []);
    }

    #[Test]
    public function a_duplicate_handler_name_fails_boot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declared twice');
        UiGridActions::discover([RecordingGridActionFixture::class, TwinGridActionFixture::class], static fn (string $c): object => new $c());
    }

    /** @param array<string, mixed> $value */
    private function event(array $value, string $handler = 'test.grid'): UiInteractionEvent
    {
        $props = [
            'actionHandler' => $handler,
            'crud' => 'screen.one',
            'serverActions' => [
                (new UiGridAction('archive', 'Archive', ['row', 'bulk', 'header']))->toProps(),
                (new UiGridAction('export', 'Export', ['header']))->toProps(),
            ],
        ];

        return new UiInteractionEvent('platform.grid', 'uci_grid_000000001', 'action', 'invoke', null, ['value' => $value], time(), time() + 60, ['pr' => $props]);
    }
}

#[AsGridAction('test.grid')]
final class RecordingGridActionFixture implements UiGridActionInterface
{
    public ?UiGridActionContext $last = null;

    public function name(): string { return 'test.grid'; }

    public function handle(UiGridActionContext $context): UiGridActionResult
    {
        $this->last = $context;

        return UiGridActionResult::done('Archived ' . count($context->ids) . '.', count($context->ids));
    }
}

#[AsGridAction('test.grid')]
final class TwinGridActionFixture implements UiGridActionInterface
{
    public function name(): string { return 'test.grid'; }
    public function handle(UiGridActionContext $context): UiGridActionResult { return UiGridActionResult::refused('no'); }
}
