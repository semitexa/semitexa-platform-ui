<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit\Action;

use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;

/**
 * Built-in no-op action used by the playground submit demo and by
 * tests that need to exercise the action seam without writing a
 * custom registry.
 *
 * Returns an accepted result with a fixed user-facing message. Does
 * NOT:
 *
 *   - persist any data;
 *   - send email or any other external call;
 *   - mutate any process-local state;
 *   - echo submitted values back in the message or debug payload;
 *   - contribute extra patches.
 *
 * This action is intentionally inert. Persistence-capable actions
 * are an explicit future slice with their own authorization / CSRF /
 * storage validation requirements.
 */
final class PlatformDemoAcceptAction implements UiFormSubmitActionInterface
{
    public const NAME = 'platform.demo.accept';
    public const MESSAGE = 'Demo action accepted. No data was persisted.';

    public function name(): string
    {
        return self::NAME;
    }

    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult
    {
        // Debug carries ONLY counts / context the dispatcher would
        // already surface elsewhere. No raw submitted values — even
        // for non-sensitive fields — so the action keeps the same
        // log-safety guarantees as the rest of the submit pipeline.
        return UiFormSubmitActionResult::accepted(
            message: self::MESSAGE,
            debug: [
                'fieldCount' => count($context->fields),
                'snapshotFieldCount' => count($context->values),
            ],
        );
    }
}
