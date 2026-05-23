<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventManifest;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Attribute\AsComponent;

final class UiEventManifestBuilderTest extends TestCase
{
    private UiComponentMetadataFactory $factory;
    private UiEventManifestBuilder $builder;

    private ?string $previousSecret = null;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        // Pin a deterministic dev secret so SignedContext::sign + verify
        // round-trip works inside the test container.
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;

        putenv('APP_SECRET=platform-ui-test-secret');
        putenv('APP_ENV=dev');

        $this->factory = new UiComponentMetadataFactory();
        $this->builder = new UiEventManifestBuilder();
    }

    protected function tearDown(): void
    {
        if ($this->previousSecret === null) {
            putenv('APP_SECRET');
        } else {
            putenv('APP_SECRET=' . $this->previousSecret);
        }
        if ($this->previousEnv === null) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->previousEnv);
        }
    }

    #[Test]
    public function builds_one_entry_per_uion_for_field_component(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);
        $manifest = $this->builder->build($metadata, 'uci_test0000000001');

        self::assertInstanceOf(UiEventManifest::class, $manifest);
        self::assertSame('platform.field', $manifest->componentName);
        self::assertSame('uci_test0000000001', $manifest->instanceId);
        self::assertSame(UiEventManifest::SCHEMA_VERSION, $manifest->schemaVersion);
        self::assertCount(1, $manifest->entries);

        $entry = $manifest->entries[0];
        self::assertSame('input', $entry->part);
        self::assertSame('change', $entry->event);
        self::assertSame('value', $entry->updatesPath);
        self::assertNotSame('', $entry->signedContext);
        self::assertStringStartsWith('sc1.', $entry->signedContext);
    }

    #[Test]
    public function signed_context_round_trips_through_verify_with_expected_claims(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);
        $manifest = $this->builder->build($metadata, 'uci_round_trip_001');

        $entry = $manifest->entries[0];
        $claims = SignedContext::verify($entry->signedContext);

        self::assertNotNull($claims);
        self::assertSame('platform.field', $claims['c']);
        self::assertSame('uci_round_trip_001', $claims['i']);
        self::assertSame('input', $claims['p']);
        self::assertSame('change', $claims['e']);
        self::assertSame('value', $claims['u']);
        self::assertIsInt($claims['iat']);
        self::assertIsInt($claims['exp']);
        self::assertGreaterThan($claims['iat'], $claims['exp']);
    }

    #[Test]
    public function tampered_context_blob_fails_verification(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);
        $manifest = $this->builder->build($metadata, 'uci_tamper_001');

        $blob = $manifest->entries[0]->signedContext;
        // Flip one character in the middle (b64 payload section).
        $tampered = substr_replace($blob, 'A', 12, 1);
        if ($tampered === $blob) {
            $tampered = substr_replace($blob, 'B', 12, 1);
        }

        self::assertNull(SignedContext::verify($tampered));
    }

    #[Test]
    public function omits_updates_when_part_has_no_bind(): void
    {
        $metadata = $this->factory->fromClass(NoBindEventComponent::class);
        $manifest = $this->builder->build($metadata, 'uci_no_bind_0001');

        $entry = $manifest->entries[0];
        self::assertNull($entry->updatesPath);

        $claims = SignedContext::verify($entry->signedContext);
        self::assertNotNull($claims);
        self::assertArrayNotHasKey('u', $claims);
    }

    #[Test]
    public function empty_instance_id_is_rejected(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/non-empty instance id/');

        $this->builder->build($metadata, '');
    }

    #[Test]
    public function subscriber_channel_id_when_set_lands_in_signed_sub_claim(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);
        $manifest = $this->builder->build(
            metadata: $metadata,
            instanceId: 'uci_sub_001',
            subscriberChannelId: 'sse_abcd1234',
        );

        $entry = $manifest->entries[0];
        $claims = SignedContext::verify($entry->signedContext);

        self::assertNotNull($claims);
        self::assertSame('sse_abcd1234', $claims['sub']);
    }

    #[Test]
    public function subscriber_channel_id_omitted_when_null_or_empty(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $manifestNull = $this->builder->build(
            metadata: $metadata,
            instanceId: 'uci_no_sub_001',
            subscriberChannelId: null,
        );
        $manifestEmpty = $this->builder->build(
            metadata: $metadata,
            instanceId: 'uci_no_sub_002',
            subscriberChannelId: '',
        );

        $claimsNull = SignedContext::verify($manifestNull->entries[0]->signedContext);
        $claimsEmpty = SignedContext::verify($manifestEmpty->entries[0]->signedContext);

        self::assertNotNull($claimsNull);
        self::assertNotNull($claimsEmpty);
        self::assertArrayNotHasKey('sub', $claimsNull);
        self::assertArrayNotHasKey('sub', $claimsEmpty);
    }

    #[Test]
    public function subscriber_channel_id_unsafe_shape_is_rejected(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/subscriberChannelId must match/');

        $this->builder->build(
            metadata: $metadata,
            instanceId: 'uci_bad_sub_001',
            subscriberChannelId: 'has space',
        );
    }

    #[Test]
    public function manifest_for_component_without_uion_has_zero_entries(): void
    {
        $metadata = $this->factory->fromClass(NoEventsComponent::class);
        $manifest = $this->builder->build($metadata, 'uci_no_events_001');

        self::assertSame([], $manifest->entries);
        self::assertSame('platform.test-no-events', $manifest->componentName);
        self::assertSame('uci_no_events_001', $manifest->instanceId);
    }

    #[Test]
    public function each_entry_carries_a_distinct_blob_within_one_manifest(): void
    {
        // FieldComponent has only one UiOn today; use the file-local two-event
        // fixture defined at the bottom of this test file so the test is
        // runnable in isolation (no cross-file fixture lookup).
        $metadata = $this->factory->fromClass(TwoEventsManifestFixture::class);
        $manifest = $this->builder->build($metadata, 'uci_two_events_01');

        self::assertCount(2, $manifest->entries);
        self::assertNotSame(
            $manifest->entries[0]->signedContext,
            $manifest->entries[1]->signedContext,
            'Two distinct (part, event) pairs must produce distinct signed blobs.',
        );
    }

    #[Test]
    public function manifest_json_shape_uses_compact_keys_and_includes_updates(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);
        $manifest = $this->builder->build($metadata, 'uci_jshape_0001');

        $shape = $manifest->toJsonShape();
        self::assertSame(UiEventManifest::SCHEMA_VERSION, $shape['v']);
        self::assertSame('platform.field', $shape['c']);
        self::assertSame('uci_jshape_0001', $shape['i']);
        self::assertCount(1, $shape['events']);

        $event = $shape['events'][0];
        self::assertSame('input', $event['p']);
        self::assertSame('change', $event['e']);
        self::assertSame('value', $event['u']);
        self::assertStringStartsWith('sc1.', $event['ctx']);
    }
}

#[AsComponent(name: 'platform.test-no-events', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class NoEventsComponent {}

#[AsComponent(name: 'platform.test-no-bind-with-event', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class NoBindEventComponent
{
    #[\Semitexa\PlatformUi\Attribute\UiOn(part: 'input', event: 'change')]
    public function onChange(array $event): void {}
}

#[AsComponent(name: 'platform.test-manifest-two-events', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class TwoEventsManifestFixture
{
    #[\Semitexa\PlatformUi\Attribute\UiOn(part: 'input', event: 'change')]
    public function onChange(array $event): void {}

    #[\Semitexa\PlatformUi\Attribute\UiOn(part: 'input', event: 'blur')]
    public function onBlur(array $event): void {}
}
