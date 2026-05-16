<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Primitive;

enum UiEventTransport: string
{
    case Http = 'http';
    case Sse = 'sse';
    case Websocket = 'websocket';
}
