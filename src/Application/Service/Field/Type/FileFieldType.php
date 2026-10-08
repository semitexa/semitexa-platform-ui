<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * An upload. The form value is the signed one-time ticket (UiUploadTickets),
 * never the file; the save action redeems it. Not a grid filter.
 */
class FileFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'file';
    }

    public function description(): string
    {
        return 'A file uploaded through HUG; the value is a one-time ticket the save action redeems. Settings: accept (MIME patterns), maxBytes.';
    }

    protected function controlProps(UiField $field): array
    {
        $props = ['control' => 'file', 'accept' => $field->setting('accept', $this->defaultAccept())];
        if (is_int($field->setting('maxBytes'))) {
            $props['maxBytes'] = $field->setting('maxBytes');
        }

        return $props;
    }

    /** @return list<string> */
    protected function defaultAccept(): array
    {
        return ['application/pdf', 'image/*', 'text/plain'];
    }

    public function filter(UiField $field): ?array
    {
        return null;
    }

    public function cast(UiField $field, mixed $raw): mixed
    {
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
