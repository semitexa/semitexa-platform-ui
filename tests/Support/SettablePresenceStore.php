<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Support;

use Semitexa\PlatformUi\Domain\Contract\FormPresenceStoreInterface;

/**
 * A presence store whose "last heartbeat" a test sets by hand, so a draft can
 * be aged without sleeping. ping() stamps the test's clock.
 */
final class SettablePresenceStore implements FormPresenceStoreInterface
{
    /** @var array<string, int> scope → unix time of the last heartbeat */
    public array $lastSeen = [];

    /** @var list<string> the calls made, in order */
    public array $calls = [];

    /** @param \Closure(): int $clock */
    public function __construct(private readonly \Closure $clock)
    {
    }

    public function ping(string $scopeKey, string $participantId, string $label, string $role): array
    {
        $this->calls[] = 'ping';
        $this->lastSeen[$scopeKey] = ($this->clock)();

        return [];
    }

    public function leave(string $scopeKey, string $participantId): array
    {
        return [];
    }

    public function roster(string $scopeKey): array
    {
        return [];
    }

    public function lastSeenAt(string $scopeKey): ?int
    {
        $this->calls[] = 'lastSeenAt';

        return $this->lastSeen[$scopeKey] ?? null;
    }
}
