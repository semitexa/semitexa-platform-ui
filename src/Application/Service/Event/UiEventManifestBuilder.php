<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventManifest;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventManifestEntry;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * Builds a render-time signed UI event manifest from a component's
 * #[UiOn] metadata.
 *
 * Each declared (part, event) pair is signed with SSR's SignedContext
 * substrate (sc1.<base64url-claims>.<base64url-hmac>) so the future
 * runtime can hand the opaque blob back to the server and the server
 * can verify it without trusting client-supplied strings.
 *
 * Signed claim payload (server-only — never exposed verbatim to JS):
 *   {
 *     c:   string,   // component canonical name, e.g. "platform.field"
 *     i:   string,   // per-render instance id, e.g. "uci_<hex>"
 *     p:   string,   // part name, e.g. "input"
 *     e:   string,   // event name, e.g. "change"
 *     u:   ?string,  // updates path (string form) — omitted when null
 *     cfg: ?array,   // optional per-event server-trusted config (see below)
 *     iat: int,      // added by SignedContext
 *     exp: int,      // added by SignedContext
 *   }
 *
 * `cfg` is the per-event configuration map the component contributed
 * at render time — currently used to carry validation rule specs for
 * FieldComponent, but the shape is generic and any component can
 * embed its own safe, JSON-encodable configuration. Because the map
 * is signed, the dispatcher trusts it as server-authored — a tampered
 * config breaks the HMAC, and the client can never inject `cfg` via
 * `payload.cfg` (the payload field guard forbids it).
 *
 * The method name and class FQCN are intentionally NOT in the claim
 * payload — the future dispatcher resolves them on the server via
 * UiComponentRegistry::get($c)->event($p, $e).
 *
 * Stateless and pure. No DOM emission happens here — the caller
 * renders the manifest into the page.
 */
final class UiEventManifestBuilder
{
    /**
     * @param array<string, array<string, mixed>> $eventConfig Map of
     *        "<part>.<event>" → config payload. Each value is embedded
     *        verbatim as the `cfg` claim on its matching manifest
     *        entry. Empty / unset entries omit `cfg` from the signed
     *        ctx. Caller is responsible for the config being JSON-
     *        encodable and free of secrets / PHP class FQCNs.
     */
    public function build(
        UiComponentMetadata $metadata,
        string $instanceId,
        ?int $ttlSeconds = null,
        array $eventConfig = [],
    ): UiEventManifest {
        if ($instanceId === '') {
            throw new UiComponentRegistryException(
                'UiEventManifestBuilder requires a non-empty instance id.',
            );
        }

        $entries = [];
        foreach ($metadata->events as $event) {
            $claims = [
                'c' => $metadata->name,
                'i' => $instanceId,
                'p' => $event->partName,
                'e' => $event->eventName,
            ];
            if ($event->updatesPath !== null) {
                $claims['u'] = (string) $event->updatesPath;
            }

            $configKey = $event->partName . '.' . $event->eventName;
            if (isset($eventConfig[$configKey]) && $eventConfig[$configKey] !== []) {
                $claims['cfg'] = $eventConfig[$configKey];
            }

            $blob = SignedContext::sign($claims, $ttlSeconds);

            $entries[] = new UiEventManifestEntry(
                part: $event->partName,
                event: $event->eventName,
                signedContext: $blob,
                updatesPath: $event->updatesPath !== null ? (string) $event->updatesPath : null,
            );
        }

        return new UiEventManifest(
            componentName: $metadata->name,
            instanceId: $instanceId,
            entries: $entries,
        );
    }
}
