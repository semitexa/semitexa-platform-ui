<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Contract;

/**
 * Optional: the name a collaborative form shows for a signed-in user, in the
 * presence chips and in "Locked by …".
 *
 * The package has no user model, so without this it shows what the user object
 * itself offers (`getDisplayName()`, then `getName()`) and, failing that, the
 * id. An app that keeps names elsewhere binds one implementation:
 *
 *     #[SatisfiesServiceContract(of: FormCollaboratorLabelResolverInterface::class)]
 *     final class PartnerLabels implements FormCollaboratorLabelResolverInterface
 *
 * It runs on every presence heartbeat and lock request, so answer from what the
 * request already holds or from a cache, not with a query each time. The label
 * is plain text: it is shown as text, never as markup, and is cut to
 * {@see \Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationEventHandler::LABEL_MAX_LENGTH}
 * characters. Every other editor of the document sees it, so return nothing
 * they may not see (an e-mail address, usually).
 */
interface FormCollaboratorLabelResolverInterface
{
    /** The label for this user id, or null (or '') to fall back to the user object's own name. */
    public function labelFor(string $userId): ?string;
}
