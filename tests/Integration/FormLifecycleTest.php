<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FormComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\FormRootPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\CacheBackedUiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;
use Semitexa\PlatformUi\Tests\Support\HugDispatch;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * tk-la-form-lifecycle: what a form does after its action answered — field
 * errors from the action, reset, redirect, the `ui-form:*` event, and the
 * re-arm that lets the same form submit again without a reload.
 */
final class FormLifecycleTest extends TestCase
{
    private const FORM = 'uci_form_lifecycle_0001';
    private const FIELD = 'uci_form_lifecycle_name';

    protected function setUp(): void
    {
        putenv('APP_SECRET=platform-ui-form-lifecycle-test');
        putenv('APP_ENV=dev');
        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        UiPrimitiveRegistry::register((new UiPrimitiveMetadataFactory())->fromClass(FormRootPrimitive::class));
        UiComponentRegistry::register((new UiComponentMetadataFactory())->fromClass(FormComponent::class));
        UiFormSubmitSecurityPolicy::setActive(new CacheBackedUiFormSubmitSecurityPolicy());
        UiFormSubmitCsrfTokenStore::setActive(new InMemoryUiFormSubmitCsrfTokenStore());
        UiFormSubmitActionRegistry::setDiscovered([LifecycleFixtureAction::NAME => new LifecycleFixtureAction()]);
    }

    protected function tearDown(): void
    {
        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        UiFormSubmitActionRegistry::reset();
        UiFormSubmitSecurityPolicy::reset();
        UiFormSubmitCsrfTokenStore::reset();
        putenv('APP_SECRET');
        putenv('APP_ENV');
    }

    #[Test]
    public function an_accepted_submit_resets_announces_and_re_arms_the_form(): void
    {
        $first = $this->submit($ctx = $this->ctx(), 'Ada');
        $first['ctx'] = $ctx;
        self::assertSame(200, $first['code']);
        $ops = $this->ops($first['body']);

        self::assertContains(['reset', self::FORM, 'form', null], $ops);
        self::assertContains(['setText', self::FIELD, null, 'validation-message'], $ops, 'the field verdict is cleared');
        self::assertSame('ui-form:accepted', $this->dispatched($first['body']));

        // The re-armed manifest: same instance, same signed fields and action,
        // a different one-time token.
        $claims = $this->rearmedClaims($first['body']);
        self::assertSame(self::FORM, $claims['i']);
        self::assertSame(LifecycleFixtureAction::NAME, $claims['cfg']['a']);
        self::assertSame(self::FIELD, $claims['cfg']['f'][0]['i']);

        // …and it works: the second submit goes through without a reload,
        // while the spent context does not.
        $again = $this->submit($this->rearmedCtx($first['body']), 'Grace');
        self::assertSame('ui-form:accepted', $this->dispatched($again['body']));
        $stale = $this->submit($first['ctx'], 'Grace');
        self::assertSame('ui-form:rejected', $this->dispatched($stale['body']));
        self::assertSame('submit_security_failed', $stale['body']['debug']['action']['reason']);
    }

    #[Test]
    public function an_actions_field_errors_land_on_the_field(): void
    {
        $response = $this->submit($this->ctx(), 'taken');

        $fieldPatches = array_values(array_filter($response['body']['patches'], static fn (array $p): bool => $p['target']['instance'] === self::FIELD));
        self::assertContains(['setAttribute', 'aria-invalid', 'true'], array_map(static fn (array $p): array => [$p['op'], $p['attribute'] ?? null, $p['value']], $fieldPatches));
        self::assertSame('That name is taken.', end($fieldPatches)['value']);
        self::assertSame('ui-form:rejected', $this->dispatched($response['body']));
        self::assertNotNull($this->rearmedCtx($response['body']), 'the token was spent, so a rejected form is re-armed too');
    }

    #[Test]
    public function a_redirect_leaves_without_re_arming(): void
    {
        $response = $this->submit($this->ctx(), 'leave');

        $ops = array_column($response['body']['patches'], 'op');
        self::assertContains('redirect', $ops);
        self::assertNotContains('replace', $ops);
        self::assertNotContains('reset', $ops);
    }

    #[Test]
    public function closing_the_modal_aims_at_the_form_itself_and_keeps_it_armed(): void
    {
        $response = $this->submit($this->ctx(), 'modal');

        self::assertContains(['close', self::FORM, null, null], $this->ops($response['body']), 'close on the instance = the overlay it sits in');
        self::assertNotNull($this->rearmedCtx($response['body']), 'the form stays usable for the next time the modal opens');
    }

    #[Test]
    public function an_error_for_a_field_the_form_does_not_have_is_refused(): void
    {
        $response = $this->submit($this->ctx(), 'ghost');

        self::assertSame(422, $response['code']);
        self::assertSame('invalid_action_field_error', $response['body']['reason']);
    }

    #[Test]
    public function a_failed_validation_announces_invalid_and_keeps_the_token(): void
    {
        $ctx = $this->ctx();
        $invalid = $this->submit($ctx, '');
        self::assertSame('ui-form:invalid', $this->dispatched($invalid['body']));
        self::assertNull($this->rearmedCtx($invalid['body']));

        // The token was never spent: the same context still submits.
        self::assertSame('ui-form:accepted', $this->dispatched($this->submit($ctx, 'Ada')['body']));
    }

    private function ctx(): string
    {
        $handle = UiFormSubmitCsrfTokenStore::getActive()->issue(600);

        return SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => [
                'f' => [['n' => 'name', 'i' => self::FIELD, 'r' => [['n' => 'required']], 'q' => true]],
                'a' => LifecycleFixtureAction::NAME,
                's' => ['k' => $handle->id, 't' => $handle->raw],
            ],
        ]);
    }

    /** @return array{code: int, body: array<string, mixed>} */
    private function submit(string $ctx, string $name): array
    {
        $response = (new HugDispatch())->sendLegacy(json_encode([
            'ctx'        => $ctx,
            'dispatchId' => 'ui_evt_' . bin2hex(random_bytes(16)),
            'payload'    => ['form' => ['values' => ['name' => $name]]],
        ], JSON_THROW_ON_ERROR));

        return ['code' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)];
    }

    /** @return list<array{string, string, ?string, ?string}> */
    private function ops(array $body): array
    {
        return array_map(
            static fn (array $p): array => [$p['op'], $p['target']['instance'], $p['target']['part'] ?? null, $p['target']['name'] ?? null],
            $body['patches'] ?? [],
        );
    }

    private function dispatched(array $body): ?string
    {
        foreach ($body['patches'] ?? [] as $p) {
            if ($p['op'] === 'dispatch') {
                return $p['value'];
            }
        }

        return null;
    }

    private function rearmedCtx(array $body): ?string
    {
        foreach ($body['patches'] ?? [] as $p) {
            if ($p['op'] === 'replace' && ($p['target']['name'] ?? null) === 'event-manifest') {
                self::assertSame(1, preg_match('#data-ui-patch-target="event-manifest">(.*)</script>#s', $p['value'], $m));
                $manifest = json_decode(str_replace('<\/', '</', $m[1]), true, 512, JSON_THROW_ON_ERROR);

                return $manifest['events'][0]['ctx'];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function rearmedClaims(array $body): array
    {
        $ctx = $this->rearmedCtx($body);
        self::assertNotNull($ctx, 'no re-armed manifest in the reply');

        return SignedContext::verify($ctx) ?? [];
    }
}

final class LifecycleFixtureAction implements UiFormSubmitActionInterface
{
    public const NAME = 'test.lifecycle';

    public function name(): string
    {
        return self::NAME;
    }

    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult
    {
        return match ($context->values['name'] ?? null) {
            'taken' => UiFormSubmitActionResult::rejected('Fix the highlighted field.')->withFieldErrors(['name' => 'That name is taken.']),
            'ghost' => UiFormSubmitActionResult::rejected('x')->withFieldErrors(['nickname' => 'Nope.']),
            'leave' => UiFormSubmitActionResult::accepted('Saved.')->redirectingTo('/articles'),
            'modal' => UiFormSubmitActionResult::accepted('Sent.')->closingModal(),
            default => UiFormSubmitActionResult::accepted('Saved.')->resettingForm(),
        };
    }
}
