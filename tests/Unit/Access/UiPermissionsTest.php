<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Authorization\Domain\Contract\AuthorizerInterface;
use Semitexa\Authorization\Domain\Enum\DenyReason;
use Semitexa\Authorization\Domain\Model\AccessDecision;
use Semitexa\Authorization\Domain\Model\AccessPolicy;
use Semitexa\Authorization\Domain\Model\GuestSubject;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Core\Authorization\SubjectInterface;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;

/**
 * What the UI offers follows the same grants as the routes, and anything
 * that cannot be answered denies.
 */
final class UiPermissionsTest extends TestCase
{
    protected function tearDown(): void
    {
        UiPermissions::reset();
    }

    #[Test]
    public function nothing_installed_grants_nothing(): void
    {
        self::assertFalse(UiPermissions::allows('content.read'));
        self::assertFalse(UiPermissions::signedIn());
        self::assertFalse(UiPermissions::permits(null));
    }

    #[Test]
    public function the_authorizer_decides_for_the_visitor(): void
    {
        $authorizer = new class implements AuthorizerInterface {
            /** @var list<SubjectInterface> */
            public array $asked = [];

            public function authorize(SubjectInterface $subject, AccessPolicy $policy): AccessDecision
            {
                $this->asked[] = $subject;

                return $policy->requiredPermissions === ['content.read'] && !$subject instanceof GuestSubject
                    ? AccessDecision::allow()
                    : AccessDecision::denyForbidden(DenyReason::PermissionRequired);
            }
        };
        UiPermissions::use(self::visitor('u-7'), $authorizer);

        self::assertTrue(UiPermissions::allows('content.read'));
        self::assertFalse(UiPermissions::allows('content.delete'));
        self::assertTrue(UiPermissions::permits(null), 'null: signed in is enough');
        self::assertSame('u-7', $authorizer->asked[0]->getIdentifier());

        UiPermissions::use(self::visitor(null), $authorizer);
        self::assertFalse(UiPermissions::allows('content.read'), 'a guest is asked as a guest');
        self::assertFalse(UiPermissions::permits(null));
    }

    private static function visitor(?string $id): AuthContextInterface
    {
        $user = $id === null ? null : new class($id) implements AuthenticatableInterface {
            public function __construct(private string $id) {}
            public function getId(): string { return $this->id; }
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return $this->id; }
        };

        return new class($user) implements AuthContextInterface {
            public function __construct(private ?AuthenticatableInterface $user) {}
            public function getUser(): ?AuthenticatableInterface { return $this->user; }
            public function isGuest(): bool { return $this->user === null; }
            public function setUser(?AuthenticatableInterface $user): void { $this->user = $user; }
            public static function get(): ?AuthContextInterface { return null; }
            public static function getOrFail(): AuthContextInterface { throw new \LogicException('not used'); }
        };
    }
}
