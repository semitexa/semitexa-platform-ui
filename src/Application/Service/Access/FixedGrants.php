<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Access;

use Semitexa\Authorization\Domain\Contract\AuthorizerInterface;
use Semitexa\Authorization\Domain\Enum\DenyReason;
use Semitexa\Authorization\Domain\Model\AccessDecision;
use Semitexa\Authorization\Domain\Model\AccessPolicy;
use Semitexa\Core\Authorization\SubjectInterface;

/** Exactly these permissions, nothing else ({@see UiPermissions::actAsHolding()}). */
final readonly class FixedGrants implements AuthorizerInterface
{
    /** @param list<string> $permissions */
    public function __construct(private array $permissions)
    {
    }

    public function authorize(SubjectInterface $subject, AccessPolicy $policy): AccessDecision
    {
        return array_diff($policy->requiredPermissions, $this->permissions) === []
            ? AccessDecision::allow()
            : AccessDecision::denyForbidden(DenyReason::PermissionRequired);
    }
}
