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
use Semitexa\PlatformUi\Contract\UiPartDataProviderInterface;
use Semitexa\PlatformUi\Domain\Model\Component\UiExternalHandlerMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiPartContext;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Attribute\AsComponent;

final class UiEventManifestBuilderExternalBindingsTest extends TestCase
{
    private UiComponentMetadataFactory $factory;
    private UiEventManifestBuilder $builder;
    private ?string $previousSecret = null;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
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
    public function external_binding_produces_one_manifest_entry(): void
    {
        $metadata = $this->factory->fromClass(ExternalBindingFixtureComponent::class);

        $manifest = $this->builder->build(
            metadata: $metadata,
            instanceId: 'uci_ext_one_001',
            externalBindings: [$this->makeExternal('input', 'submit')],
        );

        self::assertCount(1, $manifest->entries);
        self::assertSame('input', $manifest->entries[0]->part);
        self::assertSame('submit', $manifest->entries[0]->event);
        self::assertNull($manifest->entries[0]->updatesPath);
    }

    #[Test]
    public function external_binding_entry_signed_claims_omit_updates_path(): void
    {
        $metadata = $this->factory->fromClass(ExternalBindingFixtureComponent::class);

        $manifest = $this->builder->build(
            metadata: $metadata,
            instanceId: 'uci_ext_nou_001',
            externalBindings: [$this->makeExternal('input', 'submit')],
        );

        $claims = SignedContext::verify($manifest->entries[0]->signedContext);
        self::assertNotNull($claims);
        self::assertSame('platform.test-manifest-external-bindings-fixture', $claims['c']);
        self::assertSame('uci_ext_nou_001', $claims['i']);
        self::assertSame('input', $claims['p']);
        self::assertSame('submit', $claims['e']);
        self::assertArrayNotHasKey('u', $claims);
    }

    #[Test]
    public function external_binding_inherits_sub_and_dp_claims(): void
    {
        $metadata = $this->factory->fromClass(ExternalBindingFixtureComponent::class);

        $manifest = $this->builder->build(
            metadata: $metadata,
            instanceId: 'uci_ext_combo_001',
            subscriberChannelId: 'sse_ext_combo',
            dataProviderClass: ExternalBindingFixtureDp::class,
            externalBindings: [$this->makeExternal('input', 'submit')],
        );

        $claims = SignedContext::verify($manifest->entries[0]->signedContext);
        self::assertNotNull($claims);
        self::assertSame('sse_ext_combo', $claims['sub']);
        self::assertSame(ExternalBindingFixtureDp::class, $claims['dp']);
    }

    #[Test]
    public function external_binding_inherits_cfg_when_keyed_match(): void
    {
        $metadata = $this->factory->fromClass(ExternalBindingFixtureComponent::class);

        $manifest = $this->builder->build(
            metadata: $metadata,
            instanceId: 'uci_ext_cfg_001',
            eventConfig: ['input.submit' => ['paginationWindow' => 5]],
            externalBindings: [$this->makeExternal('input', 'submit')],
        );

        $claims = SignedContext::verify($manifest->entries[0]->signedContext);
        self::assertNotNull($claims);
        self::assertSame(['paginationWindow' => 5], $claims['cfg']);
    }

    #[Test]
    public function method_and_external_bindings_both_appear_in_one_manifest(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $manifest = $this->builder->build(
            metadata: $metadata,
            instanceId: 'uci_mixed_001',
            externalBindings: [
                new UiExternalHandlerMetadata(
                    componentName: 'platform.field',
                    componentClass: FieldComponent::class,
                    partName: 'input',
                    eventName: 'submit',
                    handlerClass: FieldComponent::class,
                    payloadClass: null,
                ),
            ],
        );

        // FieldComponent declares one #[UiOn(input, change)]; the manifest
        // should also contain the external (input, submit) entry — total 2.
        self::assertCount(2, $manifest->entries);

        $byPair = [];
        foreach ($manifest->entries as $entry) {
            $byPair[$entry->part . '.' . $entry->event] = $entry;
        }
        self::assertArrayHasKey('input.change', $byPair);
        self::assertArrayHasKey('input.submit', $byPair);

        // Method entry carries the updates path; external does not.
        self::assertSame('value', $byPair['input.change']->updatesPath);
        self::assertNull($byPair['input.submit']->updatesPath);
    }

    #[Test]
    public function empty_external_bindings_list_leaves_manifest_unchanged(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $manifest = $this->builder->build(
            metadata: $metadata,
            instanceId: 'uci_no_ext_001',
            externalBindings: [],
        );

        // Pre-task-3.5 behaviour: one entry for FieldComponent's single #[UiOn].
        self::assertCount(1, $manifest->entries);
        self::assertSame('change', $manifest->entries[0]->event);
    }

    private function makeExternal(string $part, string $event): UiExternalHandlerMetadata
    {
        return new UiExternalHandlerMetadata(
            componentName: 'platform.test-manifest-external-bindings-fixture',
            componentClass: ExternalBindingFixtureComponent::class,
            partName: $part,
            eventName: $event,
            handlerClass: ExternalBindingFixtureComponent::class, // dummy — builder doesn't reflect the handler
            payloadClass: null,
        );
    }
}

#[AsComponent(name: 'platform.test-manifest-external-bindings-fixture', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class ExternalBindingFixtureComponent {}

final class ExternalBindingFixtureDp implements UiPartDataProviderInterface
{
    public function provide(UiPartContext $context): array
    {
        return [];
    }
}
