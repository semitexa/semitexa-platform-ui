<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * A dialog overlay built on the native <dialog> element — showModal() gives a
 * real focus trap, Escape dismissal, ::backdrop and inert-the-page for free
 * (the 2026 platform choice, same spirit as CSS Anchor Positioning for the
 * dropdown). The behavior adds open triggers, a body scroll-lock, animated
 * transitions and events on top.
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
    ],
    a11y: ['focus-trap', 'esc-dismiss', 'aria-modal', 'scroll-lock'],
)]
final class ModalBehavior {}
