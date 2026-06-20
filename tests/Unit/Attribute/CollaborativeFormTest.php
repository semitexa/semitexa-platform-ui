<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Attribute\CollaborativeForm;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;

/**
 * Collaborative Form Data · Phase 1 — the `#[CollaborativeForm]` declaration:
 * it carries the stable form key + concurrency mode and fails fast on an
 * unsafe form key (it becomes a public channel segment).
 */
final class CollaborativeFormTest extends TestCase
{
    #[Test]
    public function it_defaults_to_optimistic_mode(): void
    {
        $attr = new CollaborativeForm(formKey: 'article');

        self::assertSame('article', $attr->formKey);
        self::assertSame(FormCollaborationMode::Optimistic, $attr->mode);
    }

    #[Test]
    public function it_carries_the_declared_mode(): void
    {
        $attr = new CollaborativeForm(formKey: 'lead_form', mode: FormCollaborationMode::Shared);

        self::assertSame(FormCollaborationMode::Shared, $attr->mode);
    }

    #[Test]
    public function it_is_readable_via_reflection_off_a_decorated_class(): void
    {
        $attrs = (new \ReflectionClass(CollaborativeFormFixture::class))->getAttributes(CollaborativeForm::class);
        self::assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        self::assertSame('demo_form', $instance->formKey);
        self::assertSame(FormCollaborationMode::FieldLock, $instance->mode);
    }

    #[Test]
    public function an_unsafe_form_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollaborativeForm(formKey: 'Bad.Key');
    }
}

#[CollaborativeForm(formKey: 'demo_form', mode: FormCollaborationMode::FieldLock)]
final class CollaborativeFormFixture
{
}
