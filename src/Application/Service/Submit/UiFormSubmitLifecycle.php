<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitConfig;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/**
 * What a form does after its submit action answered, as effects:
 *
 *   - the action's field errors land on the signed fields, like a failed rule;
 *   - `reset` restores the controls and clears every field's verdict;
 *   - `redirectTo` leaves the page (nothing else is worth doing then);
 *   - `closeModal` closes the overlay the form sits in;
 *   - the form root announces `ui-form:accepted` / `ui-form:rejected`
 *     (`ui-form:invalid` when validation stopped it) as a DOM event;
 *   - once the one-time CSRF token is spent, the form is re-armed: a fresh
 *     token signed into a fresh manifest replaces the old one, so a second
 *     submit works without a reload.
 */
final class UiFormSubmitLifecycle
{
    public const EVENT_ACCEPTED = 'ui-form:accepted';
    public const EVENT_REJECTED = 'ui-form:rejected';
    public const EVENT_INVALID  = 'ui-form:invalid';

    /** Matches the form template's default CSRF TTL. */
    private const REARM_TOKEN_TTL = 600;

    /** @return list<UiResponsePatch> */
    public static function afterAction(UiInteractionEvent $event, UiFormSubmitConfig $config, UiFormSubmitActionResult $result): array
    {
        $instance = $event->instanceId;
        $patches = self::fieldErrorPatches($config, $result->fieldErrors);
        $detail = $config->actionName !== null ? ['action' => $config->actionName] : [];
        $patches[] = UiResponsePatch::dispatch($instance, $result->accepted ? self::EVENT_ACCEPTED : self::EVENT_REJECTED, $detail);

        if ($result->redirectTo !== null) {
            $patches[] = UiResponsePatch::redirect($instance, $result->redirectTo);
            return $patches;
        }
        if ($result->reset) {
            $patches[] = UiResponsePatch::reset($instance, 'form');
            foreach ($config->fields as $def) {
                if ($def->instanceId === null) {
                    continue;
                }
                $patches[] = UiResponsePatch::setAttribute($def->instanceId, 'aria-invalid', null, UiFieldValidationResult::INPUT_PART_NAME);
                $patches[] = UiResponsePatch::setAttribute($def->instanceId, 'ui-state', null, UiFieldValidationResult::INPUT_PART_NAME);
                $patches[] = UiResponsePatch::setText($def->instanceId, '', null, UiFieldValidationResult::VALIDATION_TARGET_NAME);
            }
        }
        if ($result->closeModal) {
            $patches[] = UiResponsePatch::close($instance);
        }
        $rearm = self::rearm($event);
        if ($rearm !== null) {
            $patches[] = $rearm;
        }

        return $patches;
    }

    public static function invalid(UiInteractionEvent $event, ?string $actionName): UiResponsePatch
    {
        return UiResponsePatch::dispatch($event->instanceId, self::EVENT_INVALID, $actionName !== null ? ['action' => $actionName] : []);
    }

    /**
     * A fresh one-time token, signed into a manifest built exactly like the
     * rendered one (same instance, props, KISS session, data provider).
     * Forms without a token (no `cfg.s`) have nothing to re-arm.
     */
    public static function rearm(UiInteractionEvent $event): ?UiResponsePatch
    {
        $metadata = UiComponentRegistry::get($event->componentName);
        if (!is_array($event->config['s'] ?? null) || $metadata === null) {
            return null;
        }
        $handle = UiFormSubmitCsrfTokenStore::getActive()->issue(self::REARM_TOKEN_TTL);
        $config = ['s' => ['k' => $handle->id, 't' => $handle->raw]] + $event->config;
        $claims = $event->claims;
        $manifest = (new UiEventManifestBuilder())->build(
            metadata: $metadata,
            instanceId: $event->instanceId,
            eventConfig: [$event->partName . '.' . $event->eventName => $config],
            subscriberChannelId: is_string($claims['sub'] ?? null) ? $claims['sub'] : null,
            dataProviderClass: is_string($claims['dp'] ?? null) ? $claims['dp'] : null,
            externalBindings: UiComponentRegistry::externalBindingsFor($metadata->name),
            props: $event->props(),
        );

        return UiResponsePatch::replace($event->instanceId, $manifest->toScriptHtml(), null, 'event-manifest');
    }

    /**
     * @param array<string, string> $fieldErrors
     * @return list<UiResponsePatch>
     */
    private static function fieldErrorPatches(UiFormSubmitConfig $config, array $fieldErrors): array
    {
        $byName = [];
        foreach ($config->fields as $def) {
            $byName[$def->name] = $def;
        }
        $patches = [];
        foreach ($fieldErrors as $name => $message) {
            $def = $byName[$name] ?? null;
            if ($def === null || !is_string($message) || $message === '') {
                // An error for a field the form never signed would vanish
                // silently; refuse it where the developer will see it.
                throw new UiInteractionUnprocessableException(
                    'invalid_action_field_error',
                    'A submit action reported an error for a field the form does not have.',
                );
            }
            if ($def->instanceId !== null) {
                array_push($patches, ...UiFieldValidationResult::invalid($message)->toPatches($def->instanceId));
            }
        }

        return $patches;
    }
}
