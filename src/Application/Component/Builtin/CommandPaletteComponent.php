<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Application\Service\Palette\UiCommandResultsHtml;
use Semitexa\PlatformUi\Application\Service\Palette\UiCommandSources;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * The Ctrl+K / ⌘K command palette: a native modal <dialog> with a combobox.
 *
 * Commands come from the page (marked `data-ui-command`, plus the page's
 * navigation links — filtered in the browser) and from the server: the query
 * is a part of this component, so typing reaches onQuery() through HUG with a
 * signed, session-bound context, and every #[AsCommandSource] answers for the
 * visitor; commands needing a permission they lack never leave the server.
 */
#[AsComponent(
    name: 'platform.command-palette',
    template: '@platform-ui/components/runtime/command-palette.html.twig',
    cacheable: false,
)]
#[UiPart(name: 'query', uses: InputPrimitive::class)]
#[AsUiContract(
    summary: 'A Ctrl+K command palette: page commands and navigation filtered in the browser, server commands from #[AsCommandSource] services filtered by permission.',
    props: [
        new UiProp('id', default: 'command-palette', description: 'Id of the <dialog>; a button opens it with commandfor="<id>" command="show-modal".'),
        new UiProp('placeholder', default: 'Search or jump to…'),
        new UiProp('trigger', UiPropType::Boolean, default: true, description: 'Render a "Search… Ctrl K" button that opens it.'),
        new UiProp('hotkey', UiPropType::Boolean, default: true, description: 'Open with Ctrl+K / ⌘K.'),
    ],
    previewSafe: false,
)]
final class CommandPaletteComponent
{
    #[UiOn(part: 'query', event: 'input', debounce: 150)]
    public function onQuery(UiInteractionEvent $event): UiInteractionResult
    {
        $query = mb_substr(trim(is_scalar($event->value()) ? (string) $event->value() : ''), 0, 100);

        return UiInteractionResult::patch([
            UiResponsePatch::replace(
                $event->instanceId,
                UiCommandResultsHtml::render($event->instanceId, $query, UiCommandSources::search($query)),
                null,
                'server-results',
            ),
        ]);
    }
}
