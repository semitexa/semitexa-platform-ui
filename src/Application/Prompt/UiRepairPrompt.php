<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * The turn that hands a model the server's repair errors for the tree it
 * answered with (ep-platform-ai-ui): what is wrong, where, and what was
 * expected — and asks for the whole corrected tree.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'platform-ui',
    template: 'resources/prompts/platform-ui.ui-tree.repair.twig',
    description: 'Hands a model the server\'s repair errors for its UI tree and asks for the corrected tree.',
)]
final class UiRepairPrompt implements BoundPromptInterface
{
    public const ID = 'platform-ui.ui-tree.repair';

    public function __construct(private readonly string $errors = '')
    {
    }

    public function withErrors(string $errors): self
    {
        return new self($errors);
    }

    public function promptId(): string
    {
        return self::ID;
    }

    public function errors(): string
    {
        return $this->errors;
    }
}
