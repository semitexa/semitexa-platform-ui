<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Application\Service\Grid\UiGridActions;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridAction;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridActionContext;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.grid — the metadata-driven data grid.
 *
 * The component renders only a pointer shell; grid-runtime-v2.js reads the
 * feed route's OPTIONS contract and derives columns, sort, filters, search and
 * pager from it, then rides the page's KISS stream (subscribed through HUG by
 * the contract's route name) or pulls the feed when the page has none.
 *
 *     {{ component('platform.grid', { endpoint: '/admin/leads/feed', gridId: 'leads' }) }}
 *
 * Server actions — row, bulk (on a selection) and header — are `serverActions`
 * run by the #[AsGridAction] named in `actionHandler`; both are signed into the
 * grid, and onAction() lets through only an action the grid was rendered with,
 * in a scope it offers, on ids shaped like ids.
 */
#[AsComponent(
    name: 'platform.grid',
    template: '@platform-ui/components/runtime/grid-v2.html.twig',
    cacheable: false,
)]
#[UiPart(name: 'action', uses: ButtonPrimitive::class)]
#[AsUiContract(
    summary: 'A live data grid derived from a collection feed route\'s own contract.',
    props: [
        new UiProp('endpoint', required: true, description: 'The collection feed route (same-origin path).'),
        new UiProp('gridId', default: 'grid', description: 'Page-local instance name.'),
        new UiProp('emptyMessage', default: 'No rows.'),
        new UiProp('actions', type: UiPropType::Array, default: [], description: 'Page-local actions [{label, route, method?}] until the contract serves ui.actions.'),
        new UiProp('rowActions', type: UiPropType::Array, default: [], description: 'A column of per-row links {label, href} or route buttons {label, route, method?, confirm?}; {field} takes the row\'s value.'),
        new UiProp('urlState', default: null, description: 'A namespace that keeps the view (q, sort, filter, page size, page) in the address bar.'),
        new UiProp('serverActions', type: UiPropType::Array, default: [], description: 'Actions run on the server through HUG: [{id, label, scopes: [row|bulk|header], confirm?, tone?}] (UiGridAction::toProps()). A bulk action adds row selection.'),
        new UiProp('actionHandler', default: null, description: 'The #[AsGridAction] name that runs serverActions.'),
    ],
    examples: [
        new UiExample('default', 'Feed-backed grid', ['endpoint' => '/ui-playground/components/grid/feed', 'gridId' => 'example']),
    ],
    // A grid needs a live feed of the host application.
    previewSafe: false,
)]
final class GridComponent
{
    /** Ids per bulk action: enough for a page of rows, small enough for one request. */
    public const MAX_IDS = 200;

    #[UiOn(part: 'action', event: 'invoke')]
    public function onAction(UiInteractionEvent $event): UiInteractionResult
    {
        $props = $event->props();
        $handlerName = $props['actionHandler'] ?? null;
        $handler = is_string($handlerName) ? UiGridActions::get($handlerName) : null;
        if ($handler === null) {
            throw new UiInteractionUnprocessableException('unknown_grid_action_handler', 'This grid has no action handler.');
        }

        $value = $event->value();
        $op = is_array($value) ? ($value['op'] ?? null) : null;
        $ids = is_array($value) ? ($value['ids'] ?? []) : null;
        $action = null;
        foreach ((array) ($props['serverActions'] ?? []) as $offered) {
            if (is_array($offered) && ($offered['id'] ?? null) === $op) {
                $action = UiGridAction::fromProps($offered);
            }
        }
        if ($action === null || !is_array($ids) || !array_is_list($ids)) {
            throw new UiInteractionUnprocessableException('invalid_grid_action', 'The grid was not rendered with this action.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $id) !== 1) {
                throw new UiInteractionUnprocessableException('invalid_grid_action_ids', 'Row ids must be short plain strings.');
            }
        }
        $ids = array_values(array_unique($ids));
        $scope = is_string($value['scope'] ?? null) ? $value['scope'] : '';
        $fits = match ($scope) {
            'row' => count($ids) === 1,
            'bulk' => $ids !== [] && count($ids) <= self::MAX_IDS,
            'header' => $ids === [],
            default => false,
        };
        if (!$fits || !$action->allows($scope)) {
            throw new UiInteractionUnprocessableException('invalid_grid_action_scope', sprintf('Action "%s" does not run as "%s" on %d row(s).', $action->id, $scope, count($ids)));
        }

        $result = $handler->handle(new UiGridActionContext($event->instanceId, $action, $scope, $ids, $props));

        return UiInteractionResult::patch([
            UiResponsePatch::toast($event->instanceId, $result->message, $result->ok ? 'success' : 'error'),
            UiResponsePatch::dispatch($event->instanceId, 'ui-grid:action', ['op' => $action->id, 'ok' => $result->ok, 'affected' => $result->affected]),
        ], ['grid_action' => ['op' => $action->id, 'scope' => $scope, 'count' => count($ids), 'ok' => $result->ok]]);
    }
}
