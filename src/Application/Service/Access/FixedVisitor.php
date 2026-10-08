<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Access;

use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\AuthenticatableInterface;

/** A signed-in visitor with no request behind it ({@see UiPermissions::actAsHolding()}). */
final class FixedVisitor implements AuthContextInterface
{
    private ?AuthenticatableInterface $user;

    public function __construct()
    {
        $this->user = new class implements AuthenticatableInterface {
            public function getId(): string { return 'fixed-visitor'; }
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 'fixed-visitor'; }
        };
    }

    public function getUser(): ?AuthenticatableInterface { return $this->user; }

    public function isGuest(): bool { return $this->user === null; }

    public function setUser(?AuthenticatableInterface $user): void { $this->user = $user; }

    public static function get(): ?AuthContextInterface { return null; }

    public static function getOrFail(): AuthContextInterface { throw new \LogicException('A fixed visitor has no ambient context.'); }
}
