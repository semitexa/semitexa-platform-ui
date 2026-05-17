<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Request;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\PlatformUi\Application\Component\Builtin\FormComponent;
use Semitexa\PlatformUi\Application\Handler\PayloadHandler\UiDispatchHandler;
use Semitexa\PlatformUi\Application\Payload\Request\UiDispatchPayload;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\FormRootPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\Action\PlatformDemoAcceptAction;
use Semitexa\PlatformUi\Application\Service\Submit\Action\PlatformDemoStoreContactAction;
use Semitexa\PlatformUi\Application\Service\Submit\Action\PlatformDemoStoreContactDbAction;
use Semitexa\PlatformUi\Application\Service\Submit\CacheBackedUiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormDatabaseDemoSubmissionRepository;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormDemoSubmissionRepository;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDatabaseDemoSubmissionRepository;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDemoSubmissionRepository;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitSecurityPolicyInterface;
use Semitexa\PlatformUi\Application\Service\Submit\SignedContextOnlyUiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitCsrfTokenHandle;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionAuthorizationException;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitSecurityPolicyException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionAuthorizationContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitSecurityContext;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * End-to-end dispatch tests for the FormComponent::onSubmit pipeline.
 *
 * Builds signed `platform.form` submit contexts with `cfg.f` (signed
 * field definitions) and runs them through the actual
 * UiDispatchHandler / UiInteractionDispatcher / FormComponent stack.
 * Asserts the authoritative validation outcome, the patch shape, the
 * debug projection (no raw values), and the regression matrix for
 * smuggling / replay / tamper.
 */
final class FormSubmitDispatchTest extends TestCase
{
    private const FORM_INSTANCE = 'uci_form_submit_test_01';

    private ?string $previousSecret = null;
    private ?string $previousEnv = null;
    private int $dispatchSeq = 0;

    protected function setUp(): void
    {
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-form-submit-test');
        putenv('APP_ENV=dev');

        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        UiPrimitiveRegistry::register(
            (new UiPrimitiveMetadataFactory())->fromClass(FormRootPrimitive::class),
        );
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(FormComponent::class),
        );
        // The vast majority of the existing dispatch tests exercise
        // submit BEHAVIOUR that is independent of CSRF. The new
        // CacheBackedUiFormSubmitSecurityPolicy default rejects every
        // submit-with-action that does not also sign cfg.s, which
        // would force a churny update to every legacy test. Default
        // back to the demo-only SignedContextOnly policy here; the
        // dedicated csrf_* tests below explicitly install the
        // cache-backed policy + a fresh in-memory token store.
        UiFormSubmitSecurityPolicy::setActive(new SignedContextOnlyUiFormSubmitSecurityPolicy());
        $this->dispatchSeq = 0;
    }

    protected function tearDown(): void
    {
        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        UiFormSubmitActionRegistry::reset();
        UiFormSubmitActionAuthorizer::reset();
        UiFormSubmitSecurityPolicy::reset();
        UiFormSubmitCsrfTokenStore::reset();
        UiFormDemoSubmissionRepository::reset();
        UiFormDatabaseDemoSubmissionRepository::reset();
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

    private function freshDispatchId(): string
    {
        $this->dispatchSeq++;
        return sprintf('ui_evt_%032s', dechex(($this->dispatchSeq << 16) | random_int(0, 0xFFFF)));
    }

    /**
     * Sign a platform.form submit ctx with the same signed-cfg shape
     * the production template produces.
     *
     * @param list<array<string, mixed>> $signedFields
     */
    private function submitCtx(?array $signedFields = null): string
    {
        $fields = $signedFields ?? [
            [
                'n' => 'access_code',
                'r' => [
                    ['n' => 'required'],
                    ['n' => 'minLength', 'p' => [4]],
                ],
                'l' => 'Access code',
                'q' => true,
            ],
            [
                'n' => 'confirm_access_code',
                'r' => [
                    ['n' => 'required'],
                    ['n' => 'sameAsField', 'p' => ['access_code', 'Codes must match.']],
                ],
                'l' => 'Confirm access code',
                'q' => true,
            ],
        ];
        return SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => ['f' => $fields],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $ctx, array $payload = []): ResourceResponse
    {
        $body = json_encode([
            'ctx'        => $ctx,
            'dispatchId' => $this->freshDispatchId(),
            'payload'    => $payload,
        ], JSON_THROW_ON_ERROR);

        $request = new Request(
            method: 'POST',
            uri: '/__ui/dispatch',
            headers: [],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: $body,
            files: [],
        );
        $handler  = (new UiDispatchHandler())->withRequest($request);
        $resource = new ResourceResponse();
        return $handler->handle(new UiDispatchPayload(), $resource);
    }

    /** @return array<string, mixed> */
    private function decode(ResourceResponse $response): array
    {
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function form_component_has_submit_uion_metadata(): void
    {
        $metadata = UiComponentRegistry::get('platform.form');
        self::assertNotNull($metadata);
        self::assertNotNull($metadata->part('form'));
        $event = $metadata->event('form', 'submit');
        self::assertNotNull($event);
        self::assertSame('onSubmit', $event->methodName);
    }

    #[Test]
    public function empty_submit_returns_invalid_summary(): void
    {
        $resp = $this->post($this->submitCtx(), [
            'form' => ['values' => [
                'access_code'         => '',
                'confirm_access_code' => '',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('platform.form', $data['component']);
        self::assertSame('form', $data['part']);
        self::assertSame('submit', $data['event']);
        self::assertFalse($data['debug']['submit']['valid']);
        self::assertSame(2, $data['debug']['submit']['totalCount']);
        self::assertSame(0, $data['debug']['submit']['validCount']);
        self::assertSame(2, $data['debug']['submit']['invalidCount']);
        self::assertSame('2 fields need attention.', $data['debug']['submit']['message']);
        // Patches: setText form-status + setAttribute ui-state.
        self::assertCount(2, $data['patches']);
        self::assertSame('setText', $data['patches'][0]['op']);
        self::assertSame('form-status', $data['patches'][0]['target']['name']);
        self::assertSame('2 fields need attention.', $data['patches'][0]['value']);
        self::assertSame('setAttribute', $data['patches'][1]['op']);
        self::assertSame('ui-state', $data['patches'][1]['attribute']);
        self::assertSame('invalid', $data['patches'][1]['value']);
    }

    #[Test]
    public function mismatched_same_as_field_returns_invalid_summary_with_custom_message_per_field(): void
    {
        $resp = $this->post($this->submitCtx(), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'zzzz',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertFalse($data['debug']['submit']['valid']);
        self::assertSame(1, $data['debug']['submit']['invalidCount']);
        self::assertSame('1 field needs attention.', $data['patches'][0]['value']);
        self::assertSame('invalid', $data['patches'][1]['value']);
        $fields = $data['debug']['submit']['fields'];
        self::assertSame('valid', $fields[0]['state']);
        self::assertSame('access_code', $fields[0]['name']);
        self::assertSame('invalid', $fields[1]['state']);
        self::assertSame('confirm_access_code', $fields[1]['name']);
        self::assertSame('Codes must match.', $fields[1]['message']);
    }

    #[Test]
    public function matching_values_return_accepted_summary(): void
    {
        $resp = $this->post($this->submitCtx(), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertTrue($data['debug']['submit']['valid']);
        self::assertSame(2, $data['debug']['submit']['validCount']);
        self::assertSame(0, $data['debug']['submit']['invalidCount']);
        self::assertSame('Form is valid. Submit accepted.', $data['patches'][0]['value']);
        self::assertSame('valid', $data['patches'][1]['value']);
    }

    #[Test]
    public function submit_response_does_not_echo_raw_values(): void
    {
        $resp = $this->post($this->submitCtx(), [
            'form' => ['values' => [
                'access_code'         => 'super-secret-canary',
                'confirm_access_code' => 'super-secret-canary',
            ]],
        ]);
        $raw = $resp->getContent();
        self::assertStringNotContainsString('super-secret-canary', $raw);
        // Snapshot field-key set IS exposed (operator-friendly).
        $data = $this->decode($resp);
        self::assertSame(['access_code', 'confirm_access_code'], $data['debug']['form']['snapshotFields']);
    }

    #[Test]
    public function submit_response_does_not_leak_class_or_method_names(): void
    {
        $resp = $this->post($this->submitCtx(), [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        $raw = $resp->getContent();
        self::assertStringNotContainsString('FormComponent', $raw);
        self::assertStringNotContainsString('onSubmit', $raw);
        self::assertStringNotContainsString('Semitexa\\\\', $raw);
    }

    #[Test]
    public function payload_rules_is_rejected_as_smuggling(): void
    {
        $resp = $this->post($this->submitCtx(), [
            'rules' => [['minLength', 1]],
            'form' => ['values' => ['access_code' => 'a', 'confirm_access_code' => 'a']],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('forbidden_payload_field', $this->decode($resp)['reason']);
    }

    #[Test]
    public function payload_form_rules_is_rejected_as_smuggling(): void
    {
        $resp = $this->post($this->submitCtx(), [
            'form' => [
                'values' => ['access_code' => 'a'],
                'rules'  => [['minLength', 1]],
            ],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('forbidden_payload_field', $this->decode($resp)['reason']);
    }

    #[Test]
    public function payload_form_cfg_is_rejected_as_smuggling(): void
    {
        $resp = $this->post($this->submitCtx(), [
            'form' => [
                'values' => ['access_code' => 'a'],
                'cfg'    => ['f' => []],
            ],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('forbidden_payload_field', $this->decode($resp)['reason']);
    }

    #[Test]
    public function tampered_submit_ctx_returns_403(): void
    {
        $resp = $this->post($this->submitCtx() . 'xx', [
            'form' => ['values' => ['access_code' => 'abcd']],
        ]);
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('invalid_signed_ctx', $this->decode($resp)['reason']);
    }

    #[Test]
    public function replay_same_dispatch_id_returns_409(): void
    {
        $ctx = $this->submitCtx();
        $body = json_encode([
            'ctx'        => $ctx,
            'dispatchId' => 'ui_evt_replay_test_01_padding_padding00',
            'payload'    => ['form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']]],
        ], JSON_THROW_ON_ERROR);
        $req = new Request('POST', '/__ui/dispatch', [], [], [], [], [], $body, []);
        $handler = (new UiDispatchHandler())->withRequest($req);
        $first  = $handler->handle(new UiDispatchPayload(), new ResourceResponse());
        $second = $handler->handle(new UiDispatchPayload(), new ResourceResponse());
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(409, $second->getStatusCode());
        self::assertSame('duplicate_dispatch', json_decode($second->getContent(), true)['reason']);
    }

    #[Test]
    public function signed_fields_are_authoritative_client_cannot_add_fields(): void
    {
        // Submit only the access_code rule in the SIGNED config. The
        // client sends additional unrelated keys; the server ignores
        // them (the signed cfg lists only access_code).
        $ctx = $this->submitCtx([
            ['n' => 'access_code', 'r' => [['n' => 'required']]],
        ]);
        $resp = $this->post($ctx, [
            'form' => ['values' => [
                'access_code' => 'abcd',
                'evil_extra'  => 'should_be_ignored',
            ]],
        ]);
        $data = $this->decode($resp);
        self::assertTrue($data['debug']['submit']['valid']);
        self::assertSame(1, $data['debug']['submit']['totalCount']);
        self::assertSame(['access_code'], array_column($data['debug']['submit']['fields'], 'name'));
    }

    #[Test]
    public function empty_signed_field_list_produces_no_signed_fields_debug(): void
    {
        $ctx = $this->submitCtx([]);
        $resp = $this->post($ctx, ['form' => ['values' => []]]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('no_signed_fields', $data['debug']['reason']);
        self::assertSame('Form has no fields.', $data['patches'][0]['value']);
        self::assertSame('invalid', $data['patches'][1]['value']);
    }

    #[Test]
    public function patches_target_form_instance_not_any_field_instance(): void
    {
        // Back-compat path: submit ctx WITHOUT signed cfg.f.i. The
        // handler skips per-field projection; only form-level
        // summary patches are emitted, all targeting the form
        // instance.
        $resp = $this->post($this->submitCtx(), [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        $data = $this->decode($resp);
        foreach ($data['patches'] as $patch) {
            self::assertSame(self::FORM_INSTANCE, $patch['target']['instance']);
        }
    }

    // ---------------------------------------------------------------
    // Per-field submit projection (cfg.f.i)
    // ---------------------------------------------------------------

    private const FIELD_INSTANCE_ACCESS = 'uci_submit_access_code';
    private const FIELD_INSTANCE_CONFIRM = 'uci_submit_confirm_access_code';

    /**
     * Like submitCtx() but each signed field carries an instance id.
     */
    private function submitCtxWithFieldIds(): string
    {
        return SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => ['f' => [
                [
                    'n' => 'access_code',
                    'i' => self::FIELD_INSTANCE_ACCESS,
                    'r' => [
                        ['n' => 'required'],
                        ['n' => 'minLength', 'p' => [4]],
                    ],
                    'l' => 'Access code',
                    'q' => true,
                ],
                [
                    'n' => 'confirm_access_code',
                    'i' => self::FIELD_INSTANCE_CONFIRM,
                    'r' => [
                        ['n' => 'required'],
                        ['n' => 'sameAsField', 'p' => ['access_code', 'Codes must match.']],
                    ],
                    'l' => 'Confirm access code',
                    'q' => true,
                ],
            ]],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function patchesForInstance(array $data, string $instance): array
    {
        $out = [];
        foreach ($data['patches'] as $patch) {
            if ($patch['target']['instance'] === $instance) {
                $out[] = $patch;
            }
        }
        return $out;
    }

    #[Test]
    public function empty_submit_projects_invalid_patches_per_field(): void
    {
        $resp = $this->post($this->submitCtxWithFieldIds(), [
            'form' => ['values' => [
                'access_code'         => '',
                'confirm_access_code' => '',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);

        $accessPatches  = $this->patchesForInstance($data, self::FIELD_INSTANCE_ACCESS);
        $confirmPatches = $this->patchesForInstance($data, self::FIELD_INSTANCE_CONFIRM);
        $formPatches    = $this->patchesForInstance($data, self::FORM_INSTANCE);

        // Each field gets the 3-patch field shape:
        // aria-invalid + ui-state + validation-message setText.
        self::assertCount(3, $accessPatches);
        self::assertCount(3, $confirmPatches);
        self::assertSame('true', $accessPatches[0]['value']);
        self::assertSame('aria-invalid', $accessPatches[0]['attribute']);
        self::assertSame('invalid', $accessPatches[1]['value']);
        self::assertSame('ui-state', $accessPatches[1]['attribute']);
        self::assertSame('This field is required.', $accessPatches[2]['value']);
        self::assertSame('validation-message', $accessPatches[2]['target']['name']);
        self::assertSame('This field is required.', $confirmPatches[2]['value']);

        // Form-level summary still last.
        self::assertCount(2, $formPatches);
        self::assertSame('2 fields need attention.', $formPatches[0]['value']);
        self::assertSame('invalid', $formPatches[1]['value']);
    }

    #[Test]
    public function mismatch_projects_access_valid_and_confirm_invalid_with_custom_message(): void
    {
        $resp = $this->post($this->submitCtxWithFieldIds(), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'zzzz',
            ]],
        ]);
        $data = $this->decode($resp);

        $access  = $this->patchesForInstance($data, self::FIELD_INSTANCE_ACCESS);
        $confirm = $this->patchesForInstance($data, self::FIELD_INSTANCE_CONFIRM);

        // access_code passes → aria-invalid removed (null) + ui-state valid + "Looks good." setText.
        self::assertNull($access[0]['value']);
        self::assertSame('valid', $access[1]['value']);
        self::assertSame('Looks good.', $access[2]['value']);

        // confirm_access_code fails sameAsField → custom message.
        self::assertSame('true', $confirm[0]['value']);
        self::assertSame('invalid', $confirm[1]['value']);
        self::assertSame('Codes must match.', $confirm[2]['value']);
    }

    #[Test]
    public function matching_values_project_both_fields_valid(): void
    {
        $resp = $this->post($this->submitCtxWithFieldIds(), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        $data = $this->decode($resp);
        $access  = $this->patchesForInstance($data, self::FIELD_INSTANCE_ACCESS);
        $confirm = $this->patchesForInstance($data, self::FIELD_INSTANCE_CONFIRM);
        self::assertSame('valid', $access[1]['value']);
        self::assertSame('valid', $confirm[1]['value']);
        // Form-level summary accepted.
        $form = $this->patchesForInstance($data, self::FORM_INSTANCE);
        self::assertSame('Form is valid. Submit accepted.', $form[0]['value']);
        self::assertSame('valid', $form[1]['value']);
    }

    #[Test]
    public function per_field_patches_precede_form_summary_patches(): void
    {
        $resp = $this->post($this->submitCtxWithFieldIds(), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        $data = $this->decode($resp);
        // Pin the order: the last two patches must be the form-level
        // summary (setText form-status + setAttribute ui-state),
        // every preceding patch targets a FIELD instance id.
        $patches = $data['patches'];
        $totalCount = count($patches);
        self::assertGreaterThanOrEqual(8, $totalCount);
        $lastTwo = array_slice($patches, -2);
        self::assertSame(self::FORM_INSTANCE, $lastTwo[0]['target']['instance']);
        self::assertSame('form-status', $lastTwo[0]['target']['name']);
        self::assertSame(self::FORM_INSTANCE, $lastTwo[1]['target']['instance']);
        self::assertSame('ui-state', $lastTwo[1]['attribute']);
        // Preceding patches target one of the two field instances.
        for ($i = 0; $i < $totalCount - 2; $i++) {
            self::assertContains(
                $patches[$i]['target']['instance'],
                [self::FIELD_INSTANCE_ACCESS, self::FIELD_INSTANCE_CONFIRM],
                "patch $i must target a signed field instance",
            );
        }
    }

    #[Test]
    public function projection_does_not_leak_raw_values(): void
    {
        $resp = $this->post($this->submitCtxWithFieldIds(), [
            'form' => ['values' => [
                'access_code'         => 'leak-canary-XYZ',
                'confirm_access_code' => 'leak-canary-XYZ',
            ]],
        ]);
        $raw = $resp->getContent();
        self::assertStringNotContainsString('leak-canary-XYZ', $raw);
    }

    #[Test]
    public function debug_carries_projected_field_instance_list(): void
    {
        $resp = $this->post($this->submitCtxWithFieldIds(), [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        $data = $this->decode($resp);
        self::assertSame(
            [self::FIELD_INSTANCE_ACCESS, self::FIELD_INSTANCE_CONFIRM],
            $data['debug']['submit']['projectedFieldInstances'],
        );
    }

    #[Test]
    public function tampered_field_instance_id_in_ctx_returns_403(): void
    {
        $resp = $this->post($this->submitCtxWithFieldIds() . 'xx', [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('invalid_signed_ctx', $this->decode($resp)['reason']);
    }

    #[Test]
    public function client_cannot_supply_field_instance_id_through_payload(): void
    {
        // The payload has no instance-id surface at all. Try to
        // smuggle one inside payload.form.values keyed as `i` (which
        // would itself be a normal form value key the snapshot
        // accepts). The handler ignores it — submit projection is
        // gated entirely by the signed cfg.f.i.
        $resp = $this->post($this->submitCtxWithFieldIds(), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
                'i'                   => 'uci_evil_target',
            ]],
        ]);
        $data = $this->decode($resp);
        // Projected instances are exactly the two signed ones — the
        // attempted smuggled `i` value never reaches projection.
        self::assertSame(
            [self::FIELD_INSTANCE_ACCESS, self::FIELD_INSTANCE_CONFIRM],
            $data['debug']['submit']['projectedFieldInstances'],
        );
        // No patch targets uci_evil_target.
        foreach ($data['patches'] as $patch) {
            self::assertNotSame('uci_evil_target', $patch['target']['instance']);
        }
    }

    /**
     * Build the cfg.f wire from a list of (name, instanceId, rules, label, required)
     * tuples and sign it as a submit ctx — same shape the
     * autoFields render path produces.
     *
     * @param list<array{n: string, i: string, r: list<array<string, mixed>>, l?: string, q?: bool}> $fields
     */
    private function autoDerivedSubmitCtx(array $fields): string
    {
        return SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => ['f' => $fields],
        ]);
    }

    #[Test]
    public function autofields_derived_ctx_returns_per_field_invalid_patches_on_empty_submit(): void
    {
        // The auto-derived cfg.f wire produced by the
        // UiFormSubmitDefinitionCollector → parseSignedWire → toWireShape
        // path is identical to the manually signed shape — confirm
        // dispatch projects per-field patches just the same.
        $ctx = $this->autoDerivedSubmitCtx([
            [
                'n' => 'access_code',
                'i' => self::FIELD_INSTANCE_ACCESS,
                'r' => [['n' => 'required'], ['n' => 'minLength', 'p' => [4]]],
                'l' => 'Access code',
                'q' => true,
            ],
            [
                'n' => 'confirm_access_code',
                'i' => self::FIELD_INSTANCE_CONFIRM,
                'r' => [
                    ['n' => 'required'],
                    ['n' => 'sameAsField', 'p' => ['access_code', 'Codes must match.']],
                ],
                'l' => 'Confirm access code',
                'q' => true,
            ],
        ]);
        $resp = $this->post($ctx, ['form' => ['values' => [
            'access_code' => '',
            'confirm_access_code' => '',
        ]]]);
        $data = $this->decode($resp);
        self::assertFalse($data['debug']['submit']['valid']);
        self::assertSame(
            [self::FIELD_INSTANCE_ACCESS, self::FIELD_INSTANCE_CONFIRM],
            $data['debug']['submit']['projectedFieldInstances'],
        );
    }

    #[Test]
    public function autofields_derived_ctx_matching_values_returns_per_field_valid_patches(): void
    {
        $ctx = $this->autoDerivedSubmitCtx([
            [
                'n' => 'access_code',
                'i' => self::FIELD_INSTANCE_ACCESS,
                'r' => [['n' => 'required'], ['n' => 'minLength', 'p' => [4]]],
                'l' => 'Access code',
                'q' => true,
            ],
            [
                'n' => 'confirm_access_code',
                'i' => self::FIELD_INSTANCE_CONFIRM,
                'r' => [
                    ['n' => 'required'],
                    ['n' => 'sameAsField', 'p' => ['access_code', 'Codes must match.']],
                ],
                'l' => 'Confirm access code',
                'q' => true,
            ],
        ]);
        $resp = $this->post($ctx, ['form' => ['values' => [
            'access_code' => 'abcd',
            'confirm_access_code' => 'abcd',
        ]]]);
        $data = $this->decode($resp);
        self::assertTrue($data['debug']['submit']['valid']);
        $access = $this->patchesForInstance($data, self::FIELD_INSTANCE_ACCESS);
        $confirm = $this->patchesForInstance($data, self::FIELD_INSTANCE_CONFIRM);
        self::assertSame('valid', $access[1]['value']);
        self::assertSame('valid', $confirm[1]['value']);
    }

    #[Test]
    public function autofields_derived_ctx_tampered_signature_returns_403(): void
    {
        $ctx = $this->autoDerivedSubmitCtx([
            [
                'n' => 'access_code',
                'i' => self::FIELD_INSTANCE_ACCESS,
                'r' => [['n' => 'required']],
            ],
        ]);
        $resp = $this->post($ctx . 'xx', ['form' => ['values' => ['access_code' => 'abcd']]]);
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('invalid_signed_ctx', $this->decode($resp)['reason']);
    }

    #[Test]
    public function mixed_ctx_some_fields_with_id_some_without(): void
    {
        // Backwards-compat scenario: a signed ctx with one field
        // having `i` and one without. Per-field projection runs for
        // the one with id, skipped for the other. Form-level summary
        // still aggregates both.
        $ctx = SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => ['f' => [
                ['n' => 'with_id',    'i' => 'uci_field_a', 'r' => [['n' => 'required']]],
                ['n' => 'without_id',                       'r' => [['n' => 'required']]],
            ]],
        ]);
        $resp = $this->post($ctx, [
            'form' => ['values' => ['with_id' => '', 'without_id' => '']],
        ]);
        $data = $this->decode($resp);
        self::assertSame(
            ['uci_field_a'],
            $data['debug']['submit']['projectedFieldInstances'],
        );
        // Form-level summary acknowledges 2 invalid fields total.
        self::assertSame(2, $data['debug']['submit']['invalidCount']);
    }

    // ---------------------------------------------------------------
    // Submit action seam (cfg.a → UiFormSubmitActionRegistry → handle)
    // ---------------------------------------------------------------

    /**
     * Sign a submit ctx with an action name baked into cfg.a.
     *
     * @param list<array<string, mixed>> $fields
     */
    private function submitCtxWithAction(string $actionName, ?array $fields = null): string
    {
        $fields ??= [
            [
                'n' => 'access_code',
                'r' => [['n' => 'required'], ['n' => 'minLength', 'p' => [4]]],
                'l' => 'Access code',
                'q' => true,
            ],
            [
                'n' => 'confirm_access_code',
                'r' => [
                    ['n' => 'required'],
                    ['n' => 'sameAsField', 'p' => ['access_code', 'Codes must match.']],
                ],
                'l' => 'Confirm access code',
                'q' => true,
            ],
        ];
        return SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => ['f' => $fields, 'a' => $actionName],
        ]);
    }

    #[Test]
    public function valid_submit_with_demo_action_returns_accepted_action_message(): void
    {
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);

        // Form-level patches now carry the action's message + state.
        $formStatusPatch = null;
        $uiStatePatch = null;
        foreach ($data['patches'] as $patch) {
            if (($patch['target']['name'] ?? null) === 'form-status') {
                $formStatusPatch = $patch;
            } elseif (($patch['attribute'] ?? null) === 'ui-state') {
                $uiStatePatch = $patch;
            }
        }
        self::assertNotNull($formStatusPatch);
        self::assertSame(PlatformDemoAcceptAction::MESSAGE, $formStatusPatch['value']);
        self::assertSame('Demo action accepted. No data was persisted.', $formStatusPatch['value']);
        self::assertNotNull($uiStatePatch);
        self::assertSame('valid', $uiStatePatch['value']);

        // Action debug surfaces but does not leak raw values.
        self::assertSame(PlatformDemoAcceptAction::NAME, $data['debug']['action']['name']);
        self::assertTrue($data['debug']['action']['accepted']);
    }

    #[Test]
    public function invalid_submit_with_signed_action_does_NOT_invoke_action(): void
    {
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => [
                'access_code'         => '',
                'confirm_access_code' => '',
            ]],
        ]);
        $data = $this->decode($resp);
        self::assertFalse($data['debug']['submit']['valid']);

        // Action debug shows the action was wired but explicitly NOT invoked.
        self::assertSame(PlatformDemoAcceptAction::NAME, $data['debug']['action']['name']);
        self::assertFalse($data['debug']['action']['invoked']);
        self::assertSame('validation_invalid', $data['debug']['action']['reason']);
        self::assertArrayNotHasKey('accepted', $data['debug']['action']);

        // Form-status carries the validation summary, NOT an action message.
        $formStatusPatch = null;
        foreach ($data['patches'] as $patch) {
            if (($patch['target']['name'] ?? null) === 'form-status') {
                $formStatusPatch = $patch;
            }
        }
        self::assertNotNull($formStatusPatch);
        self::assertSame('2 fields need attention.', $formStatusPatch['value']);
        self::assertStringNotContainsString('Demo action', $resp->getContent());
    }

    #[Test]
    public function mismatch_submit_with_signed_action_does_NOT_invoke_action(): void
    {
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'zzzz',
            ]],
        ]);
        $data = $this->decode($resp);
        self::assertFalse($data['debug']['submit']['valid']);
        self::assertFalse($data['debug']['action']['invoked']);
        // Existing cross-field message preserved for the invalid field.
        self::assertSame('Codes must match.', $data['debug']['submit']['fields'][1]['message']);
    }

    #[Test]
    public function valid_submit_without_action_preserves_original_accepted_summary(): void
    {
        $resp = $this->post($this->submitCtx(), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        $data = $this->decode($resp);
        $formStatusPatch = null;
        foreach ($data['patches'] as $patch) {
            if (($patch['target']['name'] ?? null) === 'form-status') {
                $formStatusPatch = $patch;
            }
        }
        self::assertNotNull($formStatusPatch);
        self::assertSame('Form is valid. Submit accepted.', $formStatusPatch['value']);
        self::assertArrayNotHasKey('action', $data['debug']);
    }

    #[Test]
    public function tampered_cfg_a_returns_403(): void
    {
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME) . 'xx', [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('invalid_signed_ctx', $this->decode($resp)['reason']);
    }

    #[Test]
    public function unknown_signed_action_returns_safe_unprocessable_response(): void
    {
        // Sign a real-looking action name that is NOT registered.
        // The dispatcher validates the ctx, the handler then asks the
        // registry which throws — wrapped as a 422 safe response.
        $resp = $this->post($this->submitCtxWithAction('app.never.registered'), [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertSame(422, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('unknown_signed_form_action', $data['reason']);
        // Safe response — no class names, no service ids.
        self::assertStringNotContainsString('PlatformDemoAcceptAction', $resp->getContent());
        self::assertStringNotContainsString('Semitexa\\\\', $resp->getContent());
    }

    #[Test]
    public function tampered_cfg_a_shape_returns_safe_unprocessable_response(): void
    {
        // Sign a shape-violating action value (whitespace) and confirm
        // the handler refuses to invoke without leaking the bad value.
        $ctx = SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => [
                'f' => [
                    ['n' => 'access_code', 'r' => [['n' => 'required']]],
                ],
                'a' => 'evil action with spaces',
            ],
        ]);
        $resp = $this->post($ctx, ['form' => ['values' => ['access_code' => 'abcd']]]);
        self::assertSame(422, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertSame('invalid_signed_form_action', $data['reason']);
    }

    #[Test]
    public function payload_submit_action_smuggling_is_rejected(): void
    {
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'submitAction' => 'app.never.registered',
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('forbidden_payload_field', $this->decode($resp)['reason']);
    }

    #[Test]
    public function payload_form_submit_action_smuggling_is_rejected(): void
    {
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => [
                'values' => ['access_code' => 'abcd'],
                'submitAction' => 'app.never.registered',
            ],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('forbidden_payload_field', $this->decode($resp)['reason']);
    }

    #[Test]
    public function action_response_does_not_echo_raw_values(): void
    {
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => [
                'access_code'         => 'leak-canary-XYZ',
                'confirm_access_code' => 'leak-canary-XYZ',
            ]],
        ]);
        $raw = $resp->getContent();
        self::assertStringNotContainsString('leak-canary-XYZ', $raw);
    }

    #[Test]
    public function replay_of_action_dispatch_returns_409(): void
    {
        $ctx = $this->submitCtxWithAction(PlatformDemoAcceptAction::NAME);
        $body = json_encode([
            'ctx'        => $ctx,
            'dispatchId' => 'ui_evt_action_replay_test_padding_pad',
            'payload'    => ['form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']]],
        ], JSON_THROW_ON_ERROR);
        $req = new Request('POST', '/__ui/dispatch', [], [], [], [], [], $body, []);
        $handler = (new UiDispatchHandler())->withRequest($req);
        $first  = $handler->handle(new UiDispatchPayload(), new ResourceResponse());
        $second = $handler->handle(new UiDispatchPayload(), new ResourceResponse());
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(409, $second->getStatusCode());
    }

    #[Test]
    public function per_field_patches_still_precede_action_form_status_patches(): void
    {
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME, [
            [
                'n' => 'access_code',
                'i' => 'uci_action_field_pin',
                'r' => [['n' => 'required']],
            ],
        ]), [
            'form' => ['values' => ['access_code' => 'abcd']],
        ]);
        $data = $this->decode($resp);
        $totalCount = count($data['patches']);
        // First patches target the field instance, last patches target
        // the form instance and carry the action message + ui-state.
        self::assertSame('uci_action_field_pin', $data['patches'][0]['target']['instance']);
        $lastTwo = array_slice($data['patches'], -2);
        self::assertSame(self::FORM_INSTANCE, $lastTwo[0]['target']['instance']);
        self::assertSame('form-status', $lastTwo[0]['target']['name']);
        self::assertSame(PlatformDemoAcceptAction::MESSAGE, $lastTwo[0]['value']);
        self::assertSame(self::FORM_INSTANCE, $lastTwo[1]['target']['instance']);
        self::assertSame('ui-state', $lastTwo[1]['attribute']);
        self::assertSame('valid', $lastTwo[1]['value']);
    }

    // ---------------------------------------------------------------
    // Submit action authorizer + security policy ordering
    // ---------------------------------------------------------------

    /**
     * Per-call audit log used by the ordering tests: each gate writes
     * its name into a shared list so we can assert the dispatcher
     * walked them in the documented order. PUBLIC because the
     * anonymous-class recorders defined inside the install*() helpers
     * have their own scope and cannot reach a private static.
     */
    public static array $orderingTrace = [];

    private function installRecordingAuthorizer(bool $deny = false, string $reason = 'role_required'): void
    {
        UiFormSubmitActionAuthorizer::setActive(new class($deny, $reason) implements UiFormSubmitActionAuthorizerInterface {
            public function __construct(private readonly bool $deny, private readonly string $reason) {}
            public function authorize(UiFormSubmitActionAuthorizationContext $context): void
            {
                FormSubmitDispatchTest::$orderingTrace[] = 'authorizer';
                if ($this->deny) {
                    throw new UiFormSubmitActionAuthorizationException(
                        message: 'You do not have permission to run this action.',
                        reasonCode: $this->reason,
                    );
                }
            }
        });
    }

    private function installRecordingSecurityPolicy(bool $fail = false, string $reason = 'csrf_verification_failed'): void
    {
        UiFormSubmitSecurityPolicy::setActive(new class($fail, $reason) implements UiFormSubmitSecurityPolicyInterface {
            public function __construct(private readonly bool $fail, private readonly string $reason) {}
            public function verify(UiFormSubmitSecurityContext $context): void
            {
                FormSubmitDispatchTest::$orderingTrace[] = 'security_policy';
                if ($this->fail) {
                    throw new UiFormSubmitSecurityPolicyException(
                        message: 'Form security token has expired. Reload and try again.',
                        reasonCode: $this->reason,
                    );
                }
            }
        });
    }

    private function installRecordingDemoAction(): void
    {
        // Compose a tracing wrapper around the default registry — the
        // default is final, so we cannot extend it. Implement the
        // interface directly and delegate.
        $default = new \Semitexa\PlatformUi\Application\Service\Submit\DefaultUiFormSubmitActionRegistry();
        $registry = new class($default) implements \Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionRegistryInterface {
            public function __construct(private readonly \Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionRegistryInterface $inner) {}

            public function resolve(string $actionName): \Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface
            {
                $action = $this->inner->resolve($actionName);
                return new class($action) implements \Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface {
                    public function __construct(private readonly \Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface $action) {}
                    public function name(): string { return $this->action->name(); }
                    public function handle(\Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext $context): \Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult
                    {
                        FormSubmitDispatchTest::$orderingTrace[] = 'action';
                        return $this->action->handle($context);
                    }
                };
            }

            public function knownActionNames(): array
            {
                return $this->inner->knownActionNames();
            }
        };
        UiFormSubmitActionRegistry::setActive($registry);
    }

    #[Test]
    public function valid_submit_runs_authorizer_before_security_policy_before_action(): void
    {
        self::$orderingTrace = [];
        $this->installRecordingAuthorizer();
        $this->installRecordingSecurityPolicy();
        $this->installRecordingDemoAction();

        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame(['authorizer', 'security_policy', 'action'], self::$orderingTrace);
    }

    #[Test]
    public function invalid_submit_does_NOT_invoke_authorizer_security_policy_or_action(): void
    {
        self::$orderingTrace = [];
        $this->installRecordingAuthorizer();
        $this->installRecordingSecurityPolicy();
        $this->installRecordingDemoAction();

        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => [
                'access_code'         => '',
                'confirm_access_code' => '',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertFalse($data['debug']['submit']['valid']);
        self::assertSame([], self::$orderingTrace, 'No authz/policy/action should run on invalid submit.');
    }

    #[Test]
    public function denied_authorizer_prevents_security_policy_and_action(): void
    {
        self::$orderingTrace = [];
        $this->installRecordingAuthorizer(deny: true);
        $this->installRecordingSecurityPolicy();
        $this->installRecordingDemoAction();

        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        // Authorizer denied → policy + action skipped.
        self::assertSame(['authorizer'], self::$orderingTrace);
        $data = $this->decode($resp);
        self::assertSame('platform.demo.accept', $data['debug']['action']['name']);
        self::assertFalse($data['debug']['action']['invoked']);
        self::assertSame('action_forbidden', $data['debug']['action']['reason']);
        self::assertSame('role_required', $data['debug']['action']['detail']);
    }

    #[Test]
    public function denied_authorizer_emits_safe_form_status_and_invalid_ui_state(): void
    {
        $this->installRecordingAuthorizer(deny: true, reason: 'role_required');
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        $data = $this->decode($resp);
        $form = $this->patchesForInstance($data, self::FORM_INSTANCE);
        self::assertSame('form-status', $form[0]['target']['name']);
        self::assertSame('You do not have permission to run this action.', $form[0]['value']);
        self::assertSame('ui-state', $form[1]['attribute']);
        self::assertSame('invalid', $form[1]['value']);
    }

    #[Test]
    public function failing_security_policy_prevents_action_but_authorizer_still_ran(): void
    {
        self::$orderingTrace = [];
        $this->installRecordingAuthorizer();
        $this->installRecordingSecurityPolicy(fail: true);
        $this->installRecordingDemoAction();

        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        $data = $this->decode($resp);
        self::assertSame(['authorizer', 'security_policy'], self::$orderingTrace);
        self::assertFalse($data['debug']['action']['invoked']);
        self::assertSame('submit_security_failed', $data['debug']['action']['reason']);
        self::assertSame('csrf_verification_failed', $data['debug']['action']['detail']);
    }

    #[Test]
    public function failing_security_policy_emits_safe_form_status(): void
    {
        $this->installRecordingSecurityPolicy(fail: true);
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        $data = $this->decode($resp);
        $form = $this->patchesForInstance($data, self::FORM_INSTANCE);
        self::assertSame('form-status', $form[0]['target']['name']);
        self::assertSame('Form security token has expired. Reload and try again.', $form[0]['value']);
        self::assertSame('invalid', $form[1]['value']);
    }

    #[Test]
    public function denied_path_does_not_echo_raw_submitted_values(): void
    {
        $this->installRecordingAuthorizer(deny: true);
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => [
                'access_code'         => 'denied-canary-XYZ',
                'confirm_access_code' => 'denied-canary-XYZ',
            ]],
        ]);
        $raw = $resp->getContent();
        self::assertStringNotContainsString('denied-canary-XYZ', $raw);
    }

    #[Test]
    public function denied_path_does_not_leak_class_names(): void
    {
        $this->installRecordingAuthorizer(deny: true);
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        $raw = $resp->getContent();
        self::assertStringNotContainsString('AllowAllUiFormSubmitActionAuthorizer', $raw);
        self::assertStringNotContainsString('Semitexa\\\\', $raw);
    }

    #[Test]
    public function payload_csrf_smuggling_returns_400(): void
    {
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'csrf' => 'token',
            'form' => ['values' => ['access_code' => 'abcd']],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('forbidden_payload_field', $this->decode($resp)['reason']);
    }

    #[Test]
    public function payload_form_csrf_smuggling_returns_400(): void
    {
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'form' => ['values' => ['access_code' => 'abcd'], 'csrf' => 'token'],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('forbidden_payload_field', $this->decode($resp)['reason']);
    }

    #[Test]
    public function payload_authorization_smuggling_returns_400(): void
    {
        $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
            'authorization' => 'Bearer evil',
            'form' => ['values' => ['access_code' => 'abcd']],
        ]);
        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('forbidden_payload_field', $this->decode($resp)['reason']);
    }

    #[Test]
    public function payload_security_and_policy_smuggling_returns_400(): void
    {
        foreach (['security', 'policy', 'authz'] as $key) {
            $resp = $this->post($this->submitCtxWithAction(PlatformDemoAcceptAction::NAME), [
                $key => 'evil',
                'form' => ['values' => ['access_code' => 'abcd']],
            ]);
            self::assertSame(400, $resp->getStatusCode(), "payload.$key must be rejected");
            self::assertSame('forbidden_payload_field', $this->decode($resp)['reason']);
        }
    }

    // ---------------------------------------------------------------
    // CSRF / submit security policy (cache-backed, one-time consume)
    // ---------------------------------------------------------------

    /**
     * Install the cache-backed CSRF policy + a fresh in-memory token
     * store, mint a token, and return the handle. Callers sign the
     * handle into cfg.s of the submit ctx via `submitCtxWithCsrf()`.
     */
    private function installCsrfPolicyAndIssueToken(): UiFormSubmitCsrfTokenHandle
    {
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        UiFormSubmitCsrfTokenStore::setActive($store);
        UiFormSubmitSecurityPolicy::setActive(new CacheBackedUiFormSubmitSecurityPolicy());
        return $store->issue(60);
    }

    /**
     * Sign a submit ctx with cfg.f + cfg.a + cfg.s = {k, t}.
     */
    private function submitCtxWithCsrf(?UiFormSubmitCsrfTokenHandle $handle): string
    {
        $cfg = [
            'f' => [
                [
                    'n' => 'access_code',
                    'r' => [['n' => 'required'], ['n' => 'minLength', 'p' => [4]]],
                    'l' => 'Access code',
                    'q' => true,
                ],
                [
                    'n' => 'confirm_access_code',
                    'r' => [
                        ['n' => 'required'],
                        ['n' => 'sameAsField', 'p' => ['access_code', 'Codes must match.']],
                    ],
                    'l' => 'Confirm access code',
                    'q' => true,
                ],
            ],
            'a' => PlatformDemoAcceptAction::NAME,
        ];
        if ($handle !== null) {
            $cfg['s'] = ['k' => $handle->id, 't' => $handle->raw];
        }
        return SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => $cfg,
        ]);
    }

    #[Test]
    public function valid_submit_with_valid_csrf_token_invokes_action(): void
    {
        $h = $this->installCsrfPolicyAndIssueToken();
        $resp = $this->post($this->submitCtxWithCsrf($h), [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertTrue($data['debug']['action']['accepted']);
        self::assertSame(PlatformDemoAcceptAction::MESSAGE, $data['debug']['action']['message']);
    }

    #[Test]
    public function second_valid_submit_with_same_token_fails_csrf(): void
    {
        $h = $this->installCsrfPolicyAndIssueToken();
        $ctx = $this->submitCtxWithCsrf($h);
        $first  = $this->post($ctx, [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertSame(200, $first->getStatusCode());
        self::assertTrue($this->decode($first)['debug']['action']['accepted']);

        // Same ctx, fresh dispatchId, same token → CSRF must fail
        // because the token was consumed by the first call.
        $second = $this->post($ctx, [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertSame(200, $second->getStatusCode());
        $data = $this->decode($second);
        self::assertSame('submit_security_failed', $data['debug']['action']['reason']);
        self::assertSame('csrf_verification_failed', $data['debug']['action']['detail']);
        self::assertFalse($data['debug']['action']['invoked']);
    }

    #[Test]
    public function invalid_submit_does_NOT_consume_token(): void
    {
        $h = $this->installCsrfPolicyAndIssueToken();
        $ctx = $this->submitCtxWithCsrf($h);
        // First call has empty values → validation fails → CSRF
        // policy never runs → token survives.
        $first = $this->post($ctx, [
            'form' => ['values' => ['access_code' => '', 'confirm_access_code' => '']],
        ]);
        $data = $this->decode($first);
        self::assertFalse($data['debug']['submit']['valid']);
        self::assertSame('validation_invalid', $data['debug']['action']['reason']);

        // Second call with same token + valid values must succeed.
        $second = $this->post($ctx, [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertTrue($this->decode($second)['debug']['action']['accepted']);
    }

    #[Test]
    public function authorizer_deny_does_NOT_consume_token(): void
    {
        $h = $this->installCsrfPolicyAndIssueToken();
        // Install a denying authorizer.
        UiFormSubmitActionAuthorizer::setActive(new class implements UiFormSubmitActionAuthorizerInterface {
            public function authorize(
                \Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionAuthorizationContext $context,
            ): void {
                throw new \Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionAuthorizationException(
                    'forbidden', 'role_required',
                );
            }
        });
        $ctx = $this->submitCtxWithCsrf($h);
        $first = $this->post($ctx, [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertSame('action_forbidden', $this->decode($first)['debug']['action']['reason']);

        // Remove the deny → token must still work.
        UiFormSubmitActionAuthorizer::reset();
        $second = $this->post($ctx, [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertTrue($this->decode($second)['debug']['action']['accepted']);
    }

    #[Test]
    public function valid_submit_with_missing_cfg_s_fails_csrf(): void
    {
        $this->installCsrfPolicyAndIssueToken();
        $resp = $this->post($this->submitCtxWithCsrf(null), [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        $data = $this->decode($resp);
        self::assertSame('submit_security_failed', $data['debug']['action']['reason']);
        self::assertSame('csrf_verification_failed', $data['debug']['action']['detail']);
    }

    #[Test]
    public function valid_submit_with_unknown_token_fails_csrf(): void
    {
        $this->installCsrfPolicyAndIssueToken();
        $ctx = $this->submitCtxWithCsrf(new UiFormSubmitCsrfTokenHandle(
            id: 'uicsrf_0123456789abcdef',
            raw: str_repeat('a', 32),
        ));
        $resp = $this->post($ctx, [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertSame('csrf_verification_failed', $this->decode($resp)['debug']['action']['detail']);
    }

    #[Test]
    public function tampered_signed_ctx_still_returns_403_before_csrf_check(): void
    {
        $h = $this->installCsrfPolicyAndIssueToken();
        $resp = $this->post($this->submitCtxWithCsrf($h) . 'xx', [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('invalid_signed_ctx', $this->decode($resp)['reason']);
    }

    #[Test]
    public function replay_same_dispatch_id_returns_409_before_csrf_consumes_token(): void
    {
        $h = $this->installCsrfPolicyAndIssueToken();
        $ctx = $this->submitCtxWithCsrf($h);
        $body = json_encode([
            'ctx'        => $ctx,
            'dispatchId' => 'ui_evt_csrf_replay_test_padding_pad',
            'payload'    => ['form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']]],
        ], JSON_THROW_ON_ERROR);
        $req = new Request('POST', '/__ui/dispatch', [], [], [], [], [], $body, []);
        $handler = (new UiDispatchHandler())->withRequest($req);
        $first  = $handler->handle(new UiDispatchPayload(), new ResourceResponse());
        $second = $handler->handle(new UiDispatchPayload(), new ResourceResponse());
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(409, $second->getStatusCode());
    }

    #[Test]
    public function payload_csrf_smuggling_still_returns_400(): void
    {
        $h = $this->installCsrfPolicyAndIssueToken();
        foreach (['csrf', 'csrfToken', 'csrf_token'] as $key) {
            $resp = $this->post($this->submitCtxWithCsrf($h), [
                $key => 'evil',
                'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
            ]);
            self::assertSame(400, $resp->getStatusCode(), "top-level payload.$key must be rejected");
        }
        foreach (['csrf', 'csrfToken'] as $key) {
            $resp = $this->post($this->submitCtxWithCsrf($h), [
                'form' => ['values' => ['access_code' => 'abcd'], $key => 'evil'],
            ]);
            self::assertSame(400, $resp->getStatusCode(), "form-nested payload.$key must be rejected");
        }
    }

    #[Test]
    public function csrf_response_does_not_leak_raw_token_or_id(): void
    {
        $h = $this->installCsrfPolicyAndIssueToken();
        // Force a CSRF failure (use a stale ctx whose token has been
        // consumed) and confirm neither the id nor the raw token
        // appears in the response body.
        $ctx = $this->submitCtxWithCsrf($h);
        $this->post($ctx, [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]); // consumes token
        $resp = $this->post($ctx, [
            'form' => ['values' => ['access_code' => 'abcd', 'confirm_access_code' => 'abcd']],
        ]);
        $raw = $resp->getContent();
        self::assertStringNotContainsString($h->id, $raw);
        self::assertStringNotContainsString($h->raw, $raw);
    }

    // ---------------------------------------------------------------
    // Persistent demo action — platform.demo.storeContact
    // ---------------------------------------------------------------

    /**
     * Install the persistent demo pipeline: in-memory repository +
     * cache-backed CSRF policy + a freshly minted token. Returns the
     * (repository, token) pair so tests can assert what landed in
     * storage and sign cfg.s into the dispatch ctx.
     *
     * @return array{0: InMemoryUiFormDemoSubmissionRepository, 1: UiFormSubmitCsrfTokenHandle}
     */
    private function installPersistentPipeline(): array
    {
        $repo = new InMemoryUiFormDemoSubmissionRepository();
        UiFormDemoSubmissionRepository::setActive($repo);
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        UiFormSubmitCsrfTokenStore::setActive($store);
        UiFormSubmitSecurityPolicy::setActive(new CacheBackedUiFormSubmitSecurityPolicy());
        return [$repo, $store->issue(60)];
    }

    /**
     * Sign a submit ctx for the persistent demo: contact_name +
     * contact_message field defs, cfg.a = storeContact, cfg.s = the
     * passed token (or omitted when null, to exercise missing-cfg.s
     * failure).
     */
    private function persistentSubmitCtx(?UiFormSubmitCsrfTokenHandle $handle): string
    {
        $cfg = [
            'f' => [
                [
                    'n' => 'contact_name',
                    'r' => [['n' => 'required'], ['n' => 'minLength', 'p' => [2]], ['n' => 'maxLength', 'p' => [50]]],
                    'l' => 'Contact name',
                    'q' => true,
                ],
                [
                    'n' => 'contact_message',
                    'r' => [['n' => 'required'], ['n' => 'minLength', 'p' => [5]], ['n' => 'maxLength', 'p' => [500]]],
                    'l' => 'Message',
                    'q' => true,
                ],
            ],
            'a' => PlatformDemoStoreContactAction::NAME,
        ];
        if ($handle !== null) {
            $cfg['s'] = ['k' => $handle->id, 't' => $handle->raw];
        }
        return SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => $cfg,
        ]);
    }

    #[Test]
    public function valid_persistent_submit_stores_exactly_one_record(): void
    {
        [$repo, $h] = $this->installPersistentPipeline();
        $resp = $this->post($this->persistentSubmitCtx($h), [
            'form' => ['values' => [
                'contact_name'    => 'Ada Lovelace',
                'contact_message' => 'This is a demo submission.',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertTrue($data['debug']['action']['accepted']);
        self::assertSame(
            'Demo submission saved. No external side effects were performed.',
            $data['debug']['action']['message'],
        );
        // Action-side debug lands under `detail` because
        // UiFormSubmitActionResult::toDebug() nests $this->debug there.
        $detail = $data['debug']['action']['detail'];
        self::assertTrue($detail['stored']);
        self::assertMatchesRegularExpression('/\Auifs_[a-f0-9]{16}\z/', $detail['submissionId']);
        self::assertSame(2, $detail['storedFieldCount']);

        // Exactly one record landed; values are the sanitised inputs.
        self::assertSame(1, $repo->count());
        $stored = $repo->find($detail['submissionId']);
        self::assertNotNull($stored);
        self::assertSame('Ada Lovelace', $stored->values['contact_name']);
        self::assertSame('This is a demo submission.', $stored->values['contact_message']);
        self::assertSame(PlatformDemoStoreContactAction::NAME, $stored->actionName);
    }

    #[Test]
    public function valid_persistent_response_does_not_echo_submitted_values(): void
    {
        [, $h] = $this->installPersistentPipeline();
        $resp = $this->post($this->persistentSubmitCtx($h), [
            'form' => ['values' => [
                'contact_name'    => 'persist-canary-name',
                'contact_message' => 'persist-canary-message',
            ]],
        ]);
        $raw = $resp->getContent();
        self::assertStringNotContainsString('persist-canary-name', $raw);
        self::assertStringNotContainsString('persist-canary-message', $raw);
    }

    #[Test]
    public function invalid_persistent_submit_stores_nothing(): void
    {
        [$repo, $h] = $this->installPersistentPipeline();
        $resp = $this->post($this->persistentSubmitCtx($h), [
            'form' => ['values' => ['contact_name' => '', 'contact_message' => '']],
        ]);
        $data = $this->decode($resp);
        self::assertFalse($data['debug']['submit']['valid']);
        self::assertSame('validation_invalid', $data['debug']['action']['reason']);
        self::assertSame(0, $repo->count(), 'Invalid submit MUST NOT persist.');
    }

    #[Test]
    public function csrf_failure_on_persistent_action_stores_nothing(): void
    {
        [$repo, $h] = $this->installPersistentPipeline();
        $ctx = $this->persistentSubmitCtx($h);
        // First call consumes the token + stores one record.
        $this->post($ctx, [
            'form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello.']],
        ]);
        self::assertSame(1, $repo->count());

        // Second call with same ctx and fresh dispatchId — CSRF fails
        // because the token was consumed; no new record stored.
        $resp = $this->post($ctx, [
            'form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello.']],
        ]);
        $data = $this->decode($resp);
        self::assertSame('csrf_verification_failed', $data['debug']['action']['detail']);
        self::assertSame(1, $repo->count(), 'CSRF failure MUST NOT persist a second record.');
    }

    #[Test]
    public function authorizer_denial_on_persistent_action_stores_nothing(): void
    {
        [$repo, $h] = $this->installPersistentPipeline();
        UiFormSubmitActionAuthorizer::setActive(new class implements UiFormSubmitActionAuthorizerInterface {
            public function authorize(
                \Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionAuthorizationContext $context,
            ): void {
                throw new \Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionAuthorizationException(
                    'forbidden', 'role_required',
                );
            }
        });
        $resp = $this->post($this->persistentSubmitCtx($h), [
            'form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello.']],
        ]);
        $data = $this->decode($resp);
        self::assertSame('action_forbidden', $data['debug']['action']['reason']);
        self::assertSame(0, $repo->count(), 'Authorizer denial MUST NOT persist.');
    }

    #[Test]
    public function tampered_ctx_on_persistent_action_stores_nothing(): void
    {
        [$repo, $h] = $this->installPersistentPipeline();
        $resp = $this->post($this->persistentSubmitCtx($h) . 'xx', [
            'form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello.']],
        ]);
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame(0, $repo->count(), 'Tampered ctx MUST NOT persist.');
    }

    #[Test]
    public function payload_smuggling_on_persistent_action_stores_nothing(): void
    {
        [$repo, $h] = $this->installPersistentPipeline();
        foreach (['submitAction', 'action', 'csrf'] as $key) {
            $resp = $this->post($this->persistentSubmitCtx($h), [
                $key => 'evil',
                'form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello.']],
            ]);
            self::assertSame(400, $resp->getStatusCode());
        }
        self::assertSame(0, $repo->count(), 'Payload-smuggled requests MUST NOT persist.');
    }

    #[Test]
    public function replay_same_dispatch_id_on_persistent_action_stores_only_once(): void
    {
        [$repo, $h] = $this->installPersistentPipeline();
        $ctx = $this->persistentSubmitCtx($h);
        $body = json_encode([
            'ctx'        => $ctx,
            'dispatchId' => 'ui_evt_persist_replay_padding_padding00',
            'payload'    => ['form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello from replay test.']]],
        ], JSON_THROW_ON_ERROR);
        $req = new Request('POST', '/__ui/dispatch', [], [], [], [], [], $body, []);
        $handler = (new UiDispatchHandler())->withRequest($req);
        $first  = $handler->handle(new UiDispatchPayload(), new ResourceResponse());
        $second = $handler->handle(new UiDispatchPayload(), new ResourceResponse());
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(409, $second->getStatusCode());
        self::assertSame(1, $repo->count(), 'Replay MUST NOT persist a second record.');
    }

    #[Test]
    public function persistent_action_drops_unknown_snapshot_fields(): void
    {
        // Defence in depth — even if the snapshot smuggles past the
        // signed cfg.f set (it cannot today, but the action's own
        // allow-list is the canonical perimeter), the stored record
        // contains ONLY the allow-listed contact_* fields.
        [$repo, $h] = $this->installPersistentPipeline();
        $resp = $this->post($this->persistentSubmitCtx($h), [
            'form' => ['values' => [
                'contact_name'    => 'Ada',
                'contact_message' => 'Hello.',
                'role'            => 'admin',
            ]],
        ]);
        $data = $this->decode($resp);
        $stored = $repo->find($data['debug']['action']['detail']['submissionId']);
        self::assertNotNull($stored);
        self::assertSame(['contact_name', 'contact_message'], array_keys($stored->values));
        self::assertArrayNotHasKey('role', $stored->values);
    }

    // ---------------------------------------------------------------
    // Database-backed demo action — platform.demo.storeContactDb
    // ---------------------------------------------------------------

    /**
     * Install the persistent-DB demo pipeline.
     *
     * @return array{0: InMemoryUiFormDatabaseDemoSubmissionRepository, 1: UiFormSubmitCsrfTokenHandle}
     */
    private function installDbPersistentPipeline(): array
    {
        $dbRepo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        UiFormDatabaseDemoSubmissionRepository::setActive($dbRepo);
        $store = new InMemoryUiFormSubmitCsrfTokenStore();
        UiFormSubmitCsrfTokenStore::setActive($store);
        UiFormSubmitSecurityPolicy::setActive(new CacheBackedUiFormSubmitSecurityPolicy());
        return [$dbRepo, $store->issue(60)];
    }

    private function dbSubmitCtx(?UiFormSubmitCsrfTokenHandle $handle): string
    {
        $cfg = [
            'f' => [
                [
                    'n' => 'contact_name',
                    'r' => [['n' => 'required'], ['n' => 'minLength', 'p' => [2]], ['n' => 'maxLength', 'p' => [50]]],
                    'l' => 'Contact name',
                    'q' => true,
                ],
                [
                    'n' => 'contact_message',
                    'r' => [['n' => 'required'], ['n' => 'minLength', 'p' => [5]], ['n' => 'maxLength', 'p' => [500]]],
                    'l' => 'Message',
                    'q' => true,
                ],
            ],
            'a' => PlatformDemoStoreContactDbAction::NAME,
        ];
        if ($handle !== null) {
            $cfg['s'] = ['k' => $handle->id, 't' => $handle->raw];
        }
        return SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => $cfg,
        ]);
    }

    #[Test]
    public function valid_db_persistent_submit_stores_exactly_one_db_record(): void
    {
        [$repo, $h] = $this->installDbPersistentPipeline();
        $resp = $this->post($this->dbSubmitCtx($h), [
            'form' => ['values' => [
                'contact_name'    => 'Ada Lovelace',
                'contact_message' => 'Hello from the DB demo.',
            ]],
        ]);
        self::assertSame(200, $resp->getStatusCode());
        $data = $this->decode($resp);
        self::assertTrue($data['debug']['action']['accepted']);
        self::assertSame(
            'Demo submission saved to the database. No external side effects were performed.',
            $data['debug']['action']['message'],
        );
        $detail = $data['debug']['action']['detail'];
        self::assertSame('database', $detail['storage']);
        self::assertMatchesRegularExpression('/\Auifs_[a-f0-9]{16}\z/', $detail['submissionId']);

        self::assertSame(1, $repo->count());
        $stored = $repo->find($detail['submissionId']);
        self::assertNotNull($stored);
        self::assertSame('Ada Lovelace', $stored->values['contact_name']);
        self::assertSame('Hello from the DB demo.', $stored->values['contact_message']);
        self::assertSame(PlatformDemoStoreContactDbAction::NAME, $stored->actionName);
    }

    #[Test]
    public function db_response_does_not_echo_submitted_values(): void
    {
        [, $h] = $this->installDbPersistentPipeline();
        $resp = $this->post($this->dbSubmitCtx($h), [
            'form' => ['values' => [
                'contact_name'    => 'db-canary-name',
                'contact_message' => 'db-canary-message-text',
            ]],
        ]);
        $raw = $resp->getContent();
        self::assertStringNotContainsString('db-canary-name', $raw);
        self::assertStringNotContainsString('db-canary-message-text', $raw);
    }

    #[Test]
    public function invalid_db_submit_stores_nothing(): void
    {
        [$repo, $h] = $this->installDbPersistentPipeline();
        $resp = $this->post($this->dbSubmitCtx($h), [
            'form' => ['values' => ['contact_name' => '', 'contact_message' => '']],
        ]);
        $data = $this->decode($resp);
        self::assertFalse($data['debug']['submit']['valid']);
        self::assertSame(0, $repo->count(), 'Invalid DB submit MUST NOT persist.');
    }

    #[Test]
    public function csrf_failure_on_db_action_stores_nothing(): void
    {
        [$repo, $h] = $this->installDbPersistentPipeline();
        $ctx = $this->dbSubmitCtx($h);
        $this->post($ctx, [
            'form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello.']],
        ]);
        self::assertSame(1, $repo->count());
        $resp = $this->post($ctx, [
            'form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello.']],
        ]);
        $data = $this->decode($resp);
        self::assertSame('csrf_verification_failed', $data['debug']['action']['detail']);
        self::assertSame(1, $repo->count(), 'CSRF failure MUST NOT persist a second DB record.');
    }

    #[Test]
    public function authorizer_denial_on_db_action_stores_nothing(): void
    {
        [$repo, $h] = $this->installDbPersistentPipeline();
        UiFormSubmitActionAuthorizer::setActive(new class implements UiFormSubmitActionAuthorizerInterface {
            public function authorize(
                \Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionAuthorizationContext $context,
            ): void {
                throw new \Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionAuthorizationException(
                    'forbidden', 'role_required',
                );
            }
        });
        $resp = $this->post($this->dbSubmitCtx($h), [
            'form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello.']],
        ]);
        $data = $this->decode($resp);
        self::assertSame('action_forbidden', $data['debug']['action']['reason']);
        self::assertSame(0, $repo->count(), 'Authorizer denial MUST NOT persist.');
    }

    #[Test]
    public function tampered_ctx_on_db_action_stores_nothing(): void
    {
        [$repo, $h] = $this->installDbPersistentPipeline();
        $resp = $this->post($this->dbSubmitCtx($h) . 'xx', [
            'form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello.']],
        ]);
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame(0, $repo->count(), 'Tampered ctx MUST NOT persist.');
    }

    #[Test]
    public function payload_smuggling_on_db_action_stores_nothing(): void
    {
        [$repo, $h] = $this->installDbPersistentPipeline();
        foreach (['submitAction', 'action', 'csrf', 'security', 'policy', 'authorization', 'authz'] as $key) {
            $resp = $this->post($this->dbSubmitCtx($h), [
                $key => 'evil',
                'form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello.']],
            ]);
            self::assertSame(400, $resp->getStatusCode(), "payload.$key must be rejected");
        }
        self::assertSame(0, $repo->count(), 'Payload-smuggled requests MUST NOT persist.');
    }

    #[Test]
    public function replay_same_dispatch_id_on_db_action_stores_only_once(): void
    {
        [$repo, $h] = $this->installDbPersistentPipeline();
        $ctx = $this->dbSubmitCtx($h);
        $body = json_encode([
            'ctx'        => $ctx,
            'dispatchId' => 'ui_evt_db_replay_padding_padding00',
            'payload'    => ['form' => ['values' => ['contact_name' => 'Ada', 'contact_message' => 'Hello from replay test.']]],
        ], JSON_THROW_ON_ERROR);
        $req = new Request('POST', '/__ui/dispatch', [], [], [], [], [], $body, []);
        $handler = (new UiDispatchHandler())->withRequest($req);
        $first  = $handler->handle(new UiDispatchPayload(), new ResourceResponse());
        $second = $handler->handle(new UiDispatchPayload(), new ResourceResponse());
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(409, $second->getStatusCode());
        self::assertSame(1, $repo->count(), 'Replay MUST NOT persist a second DB record.');
    }

    #[Test]
    public function db_stored_record_carries_no_tokens_or_internals(): void
    {
        // Pin the perimeter: even if the snapshot smuggles past the
        // signed cfg.f set (it cannot, but defence-in-depth), the
        // stored record shape stays minimal and never carries
        // tokens / ctx / dispatchId / debug.
        [$repo, $h] = $this->installDbPersistentPipeline();
        $resp = $this->post($this->dbSubmitCtx($h), [
            'form' => ['values' => [
                'contact_name'    => 'Ada',
                'contact_message' => 'Hello.',
            ]],
        ]);
        $data = $this->decode($resp);
        $stored = $repo->find($data['debug']['action']['detail']['submissionId']);
        self::assertNotNull($stored);
        $props = array_keys(get_object_vars($stored));
        self::assertSame(
            ['id', 'formInstanceId', 'actionName', 'submittedAt', 'values'],
            $props,
        );
    }

    #[Test]
    public function cache_and_db_demo_actions_coexist_without_cross_storing(): void
    {
        // Install BOTH demo pipelines; submit through each in turn
        // and verify each record landed in its own repository, with
        // no cross-pollination.
        $cacheRepo = new InMemoryUiFormDemoSubmissionRepository();
        UiFormDemoSubmissionRepository::setActive($cacheRepo);
        [$dbRepo, $h1] = $this->installDbPersistentPipeline();

        // Mint a second token so the cache-action call has its own
        // valid CSRF token (the DB action consumed the first one
        // during pipeline install).
        $cacheToken = new \Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitCsrfTokenHandle(
            id: 'uicsrf_0123456789abcdef',
            raw: str_repeat('a', 32),
        );
        // Inject the cache token into the active CSRF store.
        $reflectionStore = new \ReflectionProperty(
            \Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitCsrfTokenStore::class,
            'active',
        );
        $reflectionStore->setAccessible(true);
        /** @var InMemoryUiFormSubmitCsrfTokenStore $activeStore */
        $activeStore = $reflectionStore->getValue();
        $tokensProp = new \ReflectionProperty(InMemoryUiFormSubmitCsrfTokenStore::class, 'tokens');
        $tokensProp->setAccessible(true);
        $tokens = $tokensProp->getValue($activeStore);
        $tokens[$cacheToken->id] = [
            'hash' => hash_hmac('sha256', $cacheToken->raw, $cacheToken->id),
            'expiresAt' => time() + 60,
        ];
        $tokensProp->setValue($activeStore, $tokens);

        // DB action submission.
        $dbResp = $this->post($this->dbSubmitCtx($h1), [
            'form' => ['values' => ['contact_name' => 'DB Ada', 'contact_message' => 'DB hello.']],
        ]);
        $dbData = $this->decode($dbResp);
        self::assertTrue($dbData['debug']['action']['accepted']);
        self::assertSame('database', $dbData['debug']['action']['detail']['storage']);
        $dbSubmissionId = $dbData['debug']['action']['detail']['submissionId'];
        $dbStored = $dbRepo->find($dbSubmissionId);
        self::assertNotNull($dbStored);
        self::assertSame(PlatformDemoStoreContactDbAction::NAME, $dbStored->actionName);
        self::assertSame('DB Ada', $dbStored->values['contact_name'] ?? null);
        self::assertNull($cacheRepo->find($dbSubmissionId));

        // Cache action submission — re-use the new helper.
        $cacheResp = $this->post($this->submitCtxForActionWithCsrf($cacheToken, PlatformDemoStoreContactAction::NAME), [
            'form' => ['values' => ['contact_name' => 'Cache Ada', 'contact_message' => 'Cache hello.']],
        ]);
        $cacheData = $this->decode($cacheResp);
        self::assertTrue($cacheData['debug']['action']['accepted']);
        // Cache action's detail does NOT carry a `storage` key — only the DB action does.
        self::assertArrayNotHasKey('storage', $cacheData['debug']['action']['detail']);
        $cacheSubmissionId = $cacheData['debug']['action']['detail']['submissionId'];
        $cacheStored = $cacheRepo->find($cacheSubmissionId);
        self::assertNotNull($cacheStored);
        self::assertSame(PlatformDemoStoreContactAction::NAME, $cacheStored->actionName);
        self::assertSame('Cache Ada', $cacheStored->values['contact_name'] ?? null);
        self::assertNull($dbRepo->find($cacheSubmissionId));

        // Each landed in its own bucket — no cross-storage.
        self::assertSame(1, $dbRepo->count());
        self::assertSame(1, $cacheRepo->count());
    }

    /**
     * Helper for the coexistence test — sign a submit ctx that
     * targets a named action with the supplied CSRF token.
     * Distinct from `submitCtxWithCsrf()` (which targets the
     * fixed-action storeContact-equivalent suite).
     */
    private function submitCtxForActionWithCsrf(
        \Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitCsrfTokenHandle $handle,
        string $actionName,
    ): string {
        return SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => [
                'f' => [
                    [
                        'n' => 'contact_name',
                        'r' => [['n' => 'required'], ['n' => 'minLength', 'p' => [2]]],
                        'q' => true,
                    ],
                    [
                        'n' => 'contact_message',
                        'r' => [['n' => 'required'], ['n' => 'minLength', 'p' => [5]]],
                        'q' => true,
                    ],
                ],
                'a' => $actionName,
                's' => ['k' => $handle->id, 't' => $handle->raw],
            ],
        ]);
    }
}
