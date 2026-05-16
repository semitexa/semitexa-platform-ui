<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Primitive;

enum UiEventResponseMode: string
{
    case None = 'none';
    case Patch = 'patch';
    case Rerender = 'rerender';
    case Command = 'command';
    case Async = 'async';
}
