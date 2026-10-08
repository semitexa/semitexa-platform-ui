<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * Transient corner notifications. Two ways to use:
 *   - programmatic: `window.SemitexaUi.toast('Saved', { status: 'success' })`
 *   - declarative trigger: a control that fires a toast on click:
 *     <button ui-behavior="toast" ui-toast="message: Saved!; status: success">Save</button>
 *
 * Toasts stack in an aria-live region (SR-announced) at the chosen corner and
 * auto-dismiss after `timeout` (0 = sticky). Interaction lives in
 * js/behavior-builtin.js.
 */
#[AsUiBehavior(
    name: 'platform.toast',
    ui: 'toast',
    script: 'platform-ui:js:behaviors',
    options: [
        new UiBehaviorOption('message', UiOptionType::String, description: 'Toast text (defaults to the trigger text).'),
        new UiBehaviorOption('title', UiOptionType::String, description: 'Optional bold first line above the message.'),
        new UiBehaviorOption('status', UiOptionType::Enum, default: 'info', values: ['info', 'success', 'warning', 'danger'], description: 'Semantic tone.'),
        new UiBehaviorOption('pos', UiOptionType::Enum, default: 'top-end', values: ['top-end', 'top-start', 'bottom-end', 'bottom-start'], description: 'Corner.'),
        new UiBehaviorOption('timeout', UiOptionType::Number, default: 4000, description: 'Auto-dismiss ms (0 = sticky).'),
    ],
    a11y: ['aria-live'],
)]
#[AsUiContract(
    summary: 'A transient notification in a corner of the screen.',
    examples: [
        new UiExample('success', 'Success', ['title' => 'Customer saved', 'message' => 'Jane Doe was added to Customers.', 'status' => 'success'], template: '@platform-ui/examples/toast.html.twig'),
        new UiExample('danger', 'Error', ['title' => 'Payment failed', 'message' => 'Update the card on file.', 'status' => 'danger'], template: '@platform-ui/examples/toast.html.twig'),
    ],
    previewSafe: true,
)]
final class ToastBehavior {}
