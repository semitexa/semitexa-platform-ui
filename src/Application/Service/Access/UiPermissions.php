<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Access;

use Psr\Container\ContainerInterface;
use Semitexa\Authorization\Domain\Contract\AuthorizerInterface;
use Semitexa\Authorization\Domain\Model\AccessPolicy;
use Semitexa\Authorization\Domain\Model\AuthenticatedSubject;
use Semitexa\Authorization\Domain\Model\GuestSubject;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Container\RequestScopedContainer;

/**
 * "Does the visitor hold this permission?" — answered by the same Authorizer a
 * #[RequiresPermission] route uses, for the subject of the CURRENT request, so
 * what the UI offers (palette commands, navigation, a screen's buttons and its
 * form actions) follows the same grants as the routes. Anything that cannot
 * be resolved denies: no check installed, no auth context, no authorizer.
 *
 * Installed once per worker at boot; the subject is looked up per call.
 */
final class UiPermissions
{
    /** @var (\Closure(): (AuthContextInterface|null))|null */
    private static ?\Closure $auth = null;

    /** @var (\Closure(): (AuthorizerInterface|null))|null */
    private static ?\Closure $authorizer = null;

    public static function resolveFrom(ContainerInterface $container): void
    {
        $get = static function (string $id) use ($container): ?object {
            try {
                return RequestScopedContainer::forCurrentExecution($container)->get($id);
            } catch (\Throwable) {
                return null;
            }
        };
        self::$auth = static fn (): ?AuthContextInterface => ($a = $get(AuthContextInterface::class)) instanceof AuthContextInterface ? $a : null;
        self::$authorizer = static fn (): ?AuthorizerInterface => ($a = $get(AuthorizerInterface::class)) instanceof AuthorizerInterface ? $a : null;
    }

    /** Test seam: a fixed visitor and authorizer (null clears). */
    public static function use(?AuthContextInterface $auth, ?AuthorizerInterface $authorizer): void
    {
        self::$auth = $auth === null ? null : static fn (): AuthContextInterface => $auth;
        self::$authorizer = $authorizer === null ? null : static fn (): AuthorizerInterface => $authorizer;
    }

    /**
     * The permissions a command line names — each option repeated, or
     * comma-separated in one: `--grant=a --grant=b` and `--grant=a,b` agree.
     *
     * @param array<array-key, mixed> $options
     * @return list<string>
     */
    public static function grantsOf(array $options): array
    {
        $grants = [];
        foreach ($options as $option) {
            foreach (is_string($option) ? explode(',', $option) : [] as $grant) {
                if (trim($grant) !== '') {
                    $grants[] = trim($grant);
                }
            }
        }

        return array_values(array_unique($grants));
    }

    /**
     * A signed-in visitor holding exactly these permissions — for a check run
     * where there is no request (the CLI asking "what would this visitor be
     * shown?"). reset() ends it.
     *
     * @param list<string> $permissions
     */
    public static function actAsHolding(array $permissions): void
    {
        self::use(new FixedVisitor(), new FixedGrants($permissions));
    }

    public static function reset(): void
    {
        self::$auth = null;
        self::$authorizer = null;
    }

    public static function signedIn(): bool
    {
        $auth = self::$auth !== null ? (self::$auth)() : null;

        return $auth !== null && !$auth->isGuest() && $auth->getUser() !== null;
    }

    public static function allows(string $permission): bool
    {
        $auth = self::$auth !== null ? (self::$auth)() : null;
        $authorizer = self::$authorizer !== null ? (self::$authorizer)() : null;
        if ($auth === null || $authorizer === null) {
            return false;
        }
        $user = $auth->getUser();
        $subject = $auth->isGuest() || $user === null ? new GuestSubject() : new AuthenticatedSubject((string) $user->getId());

        return $authorizer->authorize($subject, new AccessPolicy(PayloadAccessType::Protected, [], [$permission]))->allowed;
    }

    /** A permission, or null meaning "signed in is enough". */
    public static function permits(?string $permission): bool
    {
        return $permission === null ? self::signedIn() : self::allows($permission);
    }
}
