<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;

/**
 * Collaborative Form Data · Phase 1 — declares a form as a live collaborative
 * document and pins the concurrency mode it runs under.
 *
 * Placed on a form-carrying class (a `platform.form` component or its request
 * payload), it is the declarative seam that mirrors a grid's
 * `#[WatchScopes]`/`#[ResourceKey]` pairing: `$formKey` is the STABLE form
 * identity (the `formKey` half of the `formdoc:{formKey}:{recordId}` scope key —
 * see {@see \Semitexa\Ssr\Domain\Model\FormDocumentScope}), and `$mode` is the
 * {@see FormCollaborationMode} the inbound handler enforces and the client
 * mirrors. The per-record half of the scope is resolved at request time (the
 * record id is runtime), so this attribute carries only the stable identity —
 * the document feed payload joins it with the record id to form the watched
 * scope.
 *
 * `$formKey` is constrained to a safe slug (`/^[a-z][a-z0-9_-]*$/`) because it
 * becomes a segment of the public invalidation channel name; an invalid key
 * fails fast at construction (attribute instantiation), never at publish time.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class CollaborativeForm
{
    private const FORM_KEY_PATTERN = '/^[a-z][a-z0-9_-]*$/';

    public function __construct(
        public readonly string $formKey,
        public readonly FormCollaborationMode $mode = FormCollaborationMode::Optimistic,
    ) {
        if (preg_match(self::FORM_KEY_PATTERN, $this->formKey) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid #[CollaborativeForm] formKey "%s": expected a safe slug /%s/ '
                . '(it becomes a segment of the ui.invalidate.{tenant}.formdoc:{formKey}:{id} channel).',
                $this->formKey,
                trim(self::FORM_KEY_PATTERN, '/'),
            ));
        }
    }
}
