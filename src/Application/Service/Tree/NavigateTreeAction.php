<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Tree;

use Semitexa\Core\Attribute\AsService;
use Semitexa\PlatformUi\Attribute\AsUiTreeAction;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTreeError;

/**
 * `{"kind": "navigate", "to": "/orders"}` — a link within the site. Only a
 * same-site path: never a scheme, never another host. The page it leads to
 * decides who may see it, as it does for any link.
 */
#[AsService]
#[AsUiTreeAction(kind: 'navigate')]
final class NavigateTreeAction implements UiTreeActionKindInterface
{
    public function check(array $action, string $path): array
    {
        $to = $action['to'] ?? null;
        if (!is_string($to) || preg_match(UiProp::SITE_PATH, $to) !== 1) {
            return [new UiTreeError('tree.action_target', $path . '/to', 'A navigate action leads to a path on this site.', 'a path like "/orders?create"', UiTreeError::describe($to), 'Use a path that starts with a single "/".')];
        }
        $extra = array_diff(array_keys($action), ['kind', 'to']);

        return $extra === [] ? [] : [new UiTreeError('tree.action_field', $path, 'A navigate action has only "to".', 'kind, to', implode(', ', $extra))];
    }

    public function propValue(array $action): string
    {
        return (string) ($action['to'] ?? '');
    }

    public function describe(): string
    {
        return '{"kind": "navigate", "to": "/path"} — a link to a page of this site (a button\'s href).';
    }
}
