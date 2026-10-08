<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * The prompt that asks a model for a screen as a UI tree (ep-platform-ai-ui):
 * the format, the rules, and the components THIS visitor may be shown — no
 * other. A real prompt, so an operator reads it with
 * `prompt:show --id=platform-ui.ui-tree.compose` and overrides it per tenant.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'platform-ui',
    template: 'resources/prompts/platform-ui.ui-tree.compose.twig',
    description: 'Composes a screen as a Semitexa UI tree from the components the visitor may use.',
)]
final class UiComposePrompt implements BoundPromptInterface
{
    public const ID = 'platform-ui.ui-tree.compose';

    public function __construct(
        private readonly string $components = '',
        private readonly string $version = '',
        private readonly string $actions = '',
        private readonly int $maxNodes = 0,
    ) {}

    public function withCatalog(string $components, string $version, string $actions, int $maxNodes): self
    {
        return new self($components, $version, $actions, $maxNodes);
    }

    public function promptId(): string
    {
        return self::ID;
    }

    public function components(): string
    {
        return $this->components;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** The action kinds, one line each. */
    public function actions(): string
    {
        return $this->actions;
    }

    public function maxNodes(): int
    {
        return $this->maxNodes;
    }
}
