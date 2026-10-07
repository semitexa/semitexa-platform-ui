<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

/** An uploaded image (PNG, JPEG, WebP or GIF); otherwise a file. */
final class ImageFieldType extends FileFieldType
{
    public function name(): string
    {
        return 'image';
    }

    public function description(): string
    {
        return 'An uploaded image (PNG, JPEG, WebP or GIF). Settings: maxBytes.';
    }

    protected function defaultAccept(): array
    {
        return ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    }
}
