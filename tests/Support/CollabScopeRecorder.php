<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Support;

use Semitexa\Ssr\Domain\Contract\ScopeInvalidatorInterface;

/** Records the scopes touched, in order: what the open editors' feeds would re-run on. */
final class CollabScopeRecorder implements ScopeInvalidatorInterface
{
    /** @var list<string> */
    public array $touched = [];

    public function touch(string $scope): void
    {
        $this->touched[] = $scope;
    }
}
