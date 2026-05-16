<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Exception\UiInteractionBadRequestException;

/**
 * Rejects payloads that smuggle handler-routing fields.
 *
 * The signed-context blob is the only source of (component, instance,
 * part, event, updates) identity. Any same-shape field in the request
 * body would let a client try to redirect dispatch — so the guard walks
 * the payload tree and throws on any disallowed key, anywhere.
 *
 * Disallowed names are matched case-sensitively across the dotted path
 * but the *normalised* form (lowercased, hyphens + underscores stripped)
 * must not equal one of the canonical routing tokens. That covers
 * camelCase, snake_case, kebab-case, and SHOUTY_SNAKE variants without
 * an exhaustive enumeration.
 *
 * The guard also recursively descends both objects (assoc arrays) AND
 * lists. Numeric keys cannot themselves smuggle a routing field, but
 * a nested object inside a list can.
 */
final class UiPayloadFieldGuard
{
    /**
     * Canonical (post-normalisation) routing tokens that may never
     * appear as a key in the request body.
     *
     * @var list<string>
     */
    private const FORBIDDEN_NORMALISED = [
        'handler',
        'handlerid',
        'handlerclass',
        'handlermethod',
        'method',
        'methodname',
        'class',
        'classname',
        'component',
        'componentname',
        'componentinstance',
        'componentinstanceid',
        'instance',
        'instanceid',
        'part',
        'partname',
        'event',
        'eventname',
        'updates',
        'updatespath',
        'endpoint',
        'url',
        'route',
        'action',
        'submitaction',
        'controller',
        'callback',
        'dispatcher',
        // Submit action authorization / security policy hooks. These
        // live entirely on the server: identity comes from the
        // dispatcher's container-bound authorizer + the form submit
        // authorizer + security policy. A payload that tries to
        // suggest a CSRF token / policy decision is a smuggling
        // attempt and is rejected uniformly.
        //
        // Single-letter `a` is INTENTIONALLY not listed: the signed
        // submit ctx already carries the action under `cfg.a`, but a
        // legitimate form field could legitimately be named `a`. We
        // protect the longer aliases (`action`, `submitaction`)
        // instead — those are the names a hostile payload would
        // realistically try.
        'csrf',
        'csrftoken',
        'security',
        'policy',
        'authorization',
        'authz',
        'payloadclass',
        'authzscope',
        'backendhandler',
        // Patch-related fields. The server is the only source of patches —
        // clients cannot inject patch instructions through the request body.
        'patch',
        'patches',
        'target',
        'selector',
        'html',
        'script',
        // dispatchId / requestId / eventId belong ONLY at the top level of
        // the request body. Inside `payload` they would be confusing at
        // best and a routing-smuggling attempt at worst — reject them.
        // (The dispatcher reads dispatchId from the top-level body before
        // walking the payload, so the guard is only ever called against
        // the payload subtree.)
        'dispatchid',
        'requestid',
        'eventid',
        // Validation rule specs and signed per-event config travel
        // through the signed ctx (cfg claim) — NEVER through the
        // client payload. A payload that tries to inject `rules` / `r`
        // / `cfg` is treated as a smuggling attempt and rejected.
        'rules',
        'r',
        'cfg',
        'config',
    ];

    /**
     * @param array<array-key, mixed> $payload
     *
     * @throws UiInteractionBadRequestException
     */
    public function assertSafe(array $payload, string $rootPathLabel = 'payload'): void
    {
        $forbidden = array_flip(self::FORBIDDEN_NORMALISED);
        $this->walk($payload, $rootPathLabel, $forbidden);
    }

    /**
     * @param array<array-key, mixed>  $node
     * @param array<string, int>       $forbidden
     */
    private function walk(array $node, string $path, array $forbidden): void
    {
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $normalised = strtolower(str_replace(['_', '-'], '', $key));
                if (isset($forbidden[$normalised])) {
                    throw new UiInteractionBadRequestException(
                        'forbidden_payload_field',
                        sprintf(
                            'Field "%s" is not allowed in the request body. Routing identity comes only from the signed ctx.',
                            $path . '.' . $key,
                        ),
                    );
                }
            }
            if (is_array($value)) {
                $segment = is_string($key) ? $key : (string) $key;
                $this->walk($value, $path . '.' . $segment, $forbidden);
            }
        }
    }
}
