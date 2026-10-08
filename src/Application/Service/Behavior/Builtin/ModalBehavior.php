<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * A dialog overlay built on the native <dialog> element — showModal() gives a
 * real focus trap, Escape dismissal, ::backdrop and inert-the-page for free
 * (the 2026 platform choice, same spirit as CSS Anchor Positioning for the
 * dropdown). The behavior adds open triggers, a body scroll-lock, animated
 * transitions and events on top.
 *
 * With `urlParam`, the dialog is part of the address (`?create`), so it can
 * be linked to and reloaded. Opening rewrites the history entry rather than
 * pushing one, so Back leaves the page instead of only closing the dialog;
 * back/forward onto an entry with or without the parameter opens or closes it:
 *
 *   <a href="?create" ui-behavior-open="#create">New</a>
 *   <dialog id="create" ui-behavior="modal" ui-modal="urlParam: create"> … </dialog>
 *
 *   <button ui-behavior-open="#confirm">Delete…</button>
 *   <dialog id="confirm" ui-behavior="modal" ui-modal="bgClose: true">
 *     <div ui-behavior-content> … <button ui-behavior-dismiss>Cancel</button> </div>
 *   </dialog>
 */
#[AsUiBehavior(
    name: 'platform.modal',
    ui: 'modal',
    script: 'platform-ui:js:behaviors',
    options: [
        new UiBehaviorOption('bgClose', UiOptionType::Bool, default: true, description: 'Close when the backdrop is clicked.'),
        new UiBehaviorOption('urlParam', UiOptionType::String, default: '', description: 'A query parameter the dialog follows: open on load when the address has it, added on open, dropped on close, followed on back/forward.'),
    ],
    a11y: ['focus-trap', 'esc-dismiss', 'aria-modal', 'scroll-lock'],
)]
#[AsUiContract(
    summary: 'A focused dialog over the page, built on the native <dialog>.',
    examples: [
        new UiExample('form', 'Form dialog', [], template: '@platform-ui/examples/modal.html.twig'),
    ],
    previewSafe: true,
)]
final class ModalBehavior {}
