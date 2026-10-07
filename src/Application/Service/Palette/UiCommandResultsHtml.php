<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Palette;

use Semitexa\PlatformUi\Application\Service\Icon\IconRegistry;
use Semitexa\PlatformUi\Domain\Model\Palette\UiPaletteItem;

/**
 * The palette's server section: grouped `role="option"` links, escaped, under
 * the patch target the `replace` effect swaps. `data-query` lets the client
 * drop an answer to a query the visitor has already typed past.
 */
final class UiCommandResultsHtml
{
    /** @param list<UiPaletteItem> $commands */
    public static function render(string $instanceId, string $query, array $commands): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = sprintf('<div data-ui-patch-target="server-results" data-query="%s">', $e($query));
        $byGroup = [];
        foreach ($commands as $command) {
            $byGroup[$command->group !== '' ? $command->group : 'Results'][] = $command;
        }
        $n = 0;
        foreach ($byGroup as $group => $items) {
            $html .= sprintf('<div role="group" aria-label="%1$s"><div ui-command-group-label role="presentation">%1$s</div>', $e($group));
            foreach ($items as $command) {
                $icon = $command->icon !== null ? IconRegistry::render($command->icon, ['size' => 16]) : '';
                $html .= sprintf(
                    '<a role="option" id="%s-s%d" href="%s" ui-command-option data-command-group="%s">%s<span ui-command-title>%s</span>%s</a>',
                    $e($instanceId),
                    $n++,
                    $e($command->href),
                    $e($group),
                    $icon,
                    $e($command->title),
                    $command->subtitle !== '' ? '<span ui-command-subtitle>' . $e($command->subtitle) . '</span>' : '',
                );
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }
}
