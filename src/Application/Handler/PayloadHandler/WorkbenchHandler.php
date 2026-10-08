<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\PlatformUi\Application\Payload\Request\WorkbenchPayload;
use Semitexa\PlatformUi\Application\Resource\Response\WorkbenchPageResponse;
use Semitexa\PlatformUi\Application\Service\Workbench\WorkbenchGate;
use Semitexa\PlatformUi\Application\Service\Workbench\WorkbenchViewBuilder;

#[AsPayloadHandler(payload: WorkbenchPayload::class, resource: WorkbenchPageResponse::class)]
final class WorkbenchHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected WorkbenchGate $gate;

    #[InjectAsReadonly]
    protected WorkbenchViewBuilder $view;

    public function handle(WorkbenchPayload $payload, WorkbenchPageResponse $resource): WorkbenchPageResponse
    {
        if (!$this->gate->allows()) {
            $resource->disableAutoRender();
            $resource->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
            return $resource;
        }

        $entry = $payload->entry === '' ? null : $this->view->entry($payload->entry);
        if ($payload->entry !== '' && $entry === null) {
            $resource->setStatusCode(HttpStatus::NotFound->value);
        }

        $resource->pageTitle($entry === null ? 'UI Workbench' : $entry['short'] . ' · UI Workbench', ' · Semitexa');
        return $resource->withWorkbench($this->view->index(), $entry, $payload->entry, $this->view->skins($payload->skin), $payload->mode, $this->gate->showsSource());
    }
}
