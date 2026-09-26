<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service;

use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\PlatformUi\Domain\Model\CalendarEvent;

/**
 * Whose calendar a request acts on: always the authenticated user, never an id
 * the client sends. The endpoints are #[AsProtectedPayload], so a guest never
 * gets this far; the check here is the second line, not the first.
 */
final class CalendarOwner
{
    public static function of(AuthContextInterface $auth): string
    {
        $user = $auth->getUser();
        if ($user === null || $user->getId() === '') {
            throw new AccessDeniedException('The calendar requires an authenticated user.');
        }

        return $user->getId();
    }

    /** An existing event may only be changed or deleted by the user who owns it. */
    public static function assertOwns(string $ownerId, CalendarEvent $event): void
    {
        if ($event->getUserId() !== $ownerId) {
            throw new AccessDeniedException('This calendar event belongs to another user.');
        }
    }
}
