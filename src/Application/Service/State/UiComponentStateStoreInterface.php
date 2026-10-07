<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\State;

/**
 * Where component state lives between a render and the events that follow:
 * the props an instance was drawn with, under an unguessable key. Must be
 * shared across workers — an event may reach any of them.
 */
interface UiComponentStateStoreInterface
{
    /** @return array<string, mixed>|null the props saved under $key, or null when they are gone */
    public function get(string $key): ?array;

    /** @param array<string, mixed> $props */
    public function put(string $key, array $props, int $ttlSeconds): void;

    /** Whether every worker sees the same store (a per-worker one cannot hold state an event reads). */
    public function isShared(): bool;
}
