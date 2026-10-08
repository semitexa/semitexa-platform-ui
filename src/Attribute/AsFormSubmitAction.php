<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/**
 * Registers a form submit action by name — the whole registration step.
 *
 *     #[AsService]
 *     #[AsFormSubmitAction('blog.article.create')]
 *     final class CreateArticleAction implements UiFormSubmitActionInterface { … }
 *
 * `{{ component('platform.form', { submitAction: 'blog.article.create' }) }}`
 * then signs the name into the form and its submit reaches this action. The
 * class is taken from the container, so it injects what it needs
 * (#[InjectAsReadonly]) — no registry to replace, no static holder to fill.
 */
#[Capability(
    id: 'ui.form-submit-action',
    summary: 'Registers a named server action a platform.form submit runs after its signed rules pass; it answers with a message, field errors, reset or redirect.',
    useWhen: 'A form must do something on submit - save, send, sign up - beyond validating its fields.',
    avoidWhen: 'The form is a plain page POST with its own route and payload - keep that route.',
    replaces: [
        'replacing UiFormSubmitActionRegistry by hand to add one action',
        'a fetch() to an ad-hoc endpoint from page JavaScript on submit',
    ],
    seeAlso: 'ui.event-intent',
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsFormSubmitAction
{
    public function __construct(public string $name) {}
}
