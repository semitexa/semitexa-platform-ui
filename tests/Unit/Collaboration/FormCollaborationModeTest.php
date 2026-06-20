<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;

/**
 * Collaborative Form Data · Phase 1 — the mode enum's classification helpers,
 * which the inbound handler and client branch on.
 */
final class FormCollaborationModeTest extends TestCase
{
    #[Test]
    public function the_default_is_optimistic(): void
    {
        self::assertSame(FormCollaborationMode::Optimistic, FormCollaborationMode::default());
    }

    #[Test]
    public function only_shared_and_field_lock_broadcast_field_edits(): void
    {
        self::assertTrue(FormCollaborationMode::Shared->broadcastsFieldEdits());
        self::assertTrue(FormCollaborationMode::FieldLock->broadcastsFieldEdits());
        self::assertFalse(FormCollaborationMode::Optimistic->broadcastsFieldEdits());
        self::assertFalse(FormCollaborationMode::FormLock->broadcastsFieldEdits());
    }

    #[Test]
    public function only_lock_modes_use_a_lock(): void
    {
        self::assertTrue(FormCollaborationMode::FormLock->usesLock());
        self::assertTrue(FormCollaborationMode::FieldLock->usesLock());
        self::assertFalse(FormCollaborationMode::Optimistic->usesLock());
        self::assertFalse(FormCollaborationMode::Shared->usesLock());
    }

    #[Test]
    public function only_optimistic_is_optimistic(): void
    {
        self::assertTrue(FormCollaborationMode::Optimistic->isOptimistic());
        self::assertFalse(FormCollaborationMode::Shared->isOptimistic());
    }
}
