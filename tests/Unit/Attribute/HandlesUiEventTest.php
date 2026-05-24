<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\PlatformUi\Attribute\HandlesUiEvent;

final class HandlesUiEventTest extends TestCase
{
    #[Test]
    public function carries_component_part_event_payload(): void
    {
        $attr = new HandlesUiEvent(
            component: SomeComponent::class,
            part: 'filters',
            event: 'submit',
            payload: SomePayload::class,
        );

        self::assertSame(SomeComponent::class, $attr->component);
        self::assertSame('filters', $attr->part);
        self::assertSame('submit', $attr->event);
        self::assertSame(SomePayload::class, $attr->payload);
    }

    #[Test]
    public function payload_defaults_to_null(): void
    {
        $attr = new HandlesUiEvent(
            component: SomeComponent::class,
            part: 'rows',
            event: 'sort',
        );

        self::assertNull($attr->payload);
    }

    #[Test]
    public function is_repeatable_on_a_class(): void
    {
        $attrs = (new ReflectionClass(MultiBoundHandler::class))->getAttributes(HandlesUiEvent::class);

        self::assertCount(3, $attrs);

        $instances = array_map(static fn ($a): HandlesUiEvent => $a->newInstance(), $attrs);
        self::assertSame(['submit', 'sort', 'paginate'], array_map(static fn (HandlesUiEvent $a): string => $a->event, $instances));
    }
}

final class SomeComponent {}
final class SomePayload {}

#[HandlesUiEvent(component: SomeComponent::class, part: 'filters', event: 'submit')]
#[HandlesUiEvent(component: SomeComponent::class, part: 'rows', event: 'sort')]
#[HandlesUiEvent(component: SomeComponent::class, part: 'rows', event: 'paginate')]
final class MultiBoundHandler {}
