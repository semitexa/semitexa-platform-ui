<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Resource\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

#[AsResource(handle: 'platform_ui_workbench', template: '@platform-ui/workbench/page.html.twig')]
final class WorkbenchPageResponse extends HtmlResponse implements ResourceInterface
{
    /**
     * @param array<string, mixed> $index
     * @param array<string, mixed>|null $entry
     * @param array{slugs: list<string>, current: string, url: ?string} $skins
     */
    public function withWorkbench(array $index, ?array $entry, string $requested, array $skins, string $mode): self
    {
        return $this->with('workbench', ['index' => $index, 'entry' => $entry, 'requested' => $requested, 'skins' => $skins, 'mode' => $mode]);
    }
}
