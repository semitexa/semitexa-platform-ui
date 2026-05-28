<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Contract\UiPartDataProviderInterface;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiExternalHandlerMetadata;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventManifest;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventManifestEntry;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Domain\Contract\DataProviderInterface;

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
     * Safe shape for the optional `sub` (subscriber channel id) claim:
     * starts with an alphanumeric, followed by 0–127 `[A-Za-z0-9_-]`
     * characters. Same family as the legacy dispatchId pattern; the
     * canonical SSE session id ({@see \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::handle()}
     * — `session_id` query param) is opaque to the framework, so the
     * builder enforces the conservative identifier shape rather than
     * trusting any blob the page handler hands in.
     */
    private const SUBSCRIBER_CHANNEL_ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/';

    /**
     * @param array<string, array<string, mixed>> $eventConfig Map of
     *        "<part>.<event>" → config payload. Each value is embedded
     *        verbatim as the `cfg` claim on its matching manifest
     *        entry. Empty / unset entries omit `cfg` from the signed
     *        ctx. Caller is responsible for the config being JSON-
     *        encodable and free of secrets / PHP class FQCNs.
     * @param string|null $subscriberChannelId Optional canonical SSE
     *        subscriber channel id (the `session_id` the page intends
     *        to open `/__semitexa_kiss` with). When set, every signed
     *        entry carries an additive `sub` claim that the dispatcher
     *        reads after verification to publish `ui.patch` messages
     *        on that channel. Old ctxs minted without this argument
     *        remain valid — the dispatcher falls back to inline
     *        response patches when `sub` is absent.
     * @param string|null $dataProviderClass Optional FQCN of a class
     *        implementing **either** {@see UiPartDataProviderInterface}
     *        (the platform-ui part-data contract) **or**
     *        {@see DataProviderInterface} (the semitexa-ssr smart-
     *        component contract). Both shapes are accepted because the
     *        canonical UI Interaction layer needs to integrate with
     *        smart-components already built on the SSR data-provider
     *        contract (e.g. LeadsGridComponent's LeadGridDataProvider)
     *        without forcing a contract migration. When set, every
     *        signed entry carries an additive `dp` claim — the
     *        downstream handler reads it to resolve and invoke the
     *        read-side data provider for filter / sort / pagination
     *        flows without trusting any client-supplied class name.
     *        Caller is responsible for passing the FQCN of a class
     *        the application actually authorises to expose as a UI
     *        data source.
     * @param list<UiExternalHandlerMetadata> $externalBindings Optional
     *        list of class-level #[HandlesUiEvent] bindings that target
     *        the component, typically obtained from
     *        {@see \Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry::externalBindingsFor()}.
     *        Each binding produces one additional manifest entry with a
     *        signed ctx carrying c/i/p/e (no `u` — external bindings do
     *        not own an updates path) plus the same cfg/sub/dp claims as
     *        method-level entries. The builder treats them as a parallel
     *        binding source: the registry already enforces no-collision
     *        across the two sources, so the rendered manifest never
     *        emits two entries for the same (part, event).
     */
    public function build(
        UiComponentMetadata $metadata,
        string $instanceId,
        ?int $ttlSeconds = null,
        array $eventConfig = [],
        ?string $subscriberChannelId = null,
        ?string $dataProviderClass = null,
        array $externalBindings = [],
    ): UiEventManifest {
        if ($instanceId === '') {
            throw new UiComponentRegistryException(
                'UiEventManifestBuilder requires a non-empty instance id.',
            );
        }

        $normalisedSub = null;
        if ($subscriberChannelId !== null && $subscriberChannelId !== '') {
            if (preg_match(self::SUBSCRIBER_CHANNEL_ID_PATTERN, $subscriberChannelId) !== 1) {
                throw new UiComponentRegistryException(
                    'UiEventManifestBuilder subscriberChannelId must match [A-Za-z0-9][A-Za-z0-9_-]{0,127}.',
                );
            }
            $normalisedSub = $subscriberChannelId;
        }

        $normalisedDp = null;
        if ($dataProviderClass !== null && $dataProviderClass !== '') {
            if (!class_exists($dataProviderClass) && !interface_exists($dataProviderClass)) {
                throw new UiComponentRegistryException(sprintf(
                    'UiEventManifestBuilder dataProviderClass "%s" is not a loadable class.',
                    $dataProviderClass,
                ));
            }
            $implementsUiPart = is_subclass_of($dataProviderClass, UiPartDataProviderInterface::class);
            $implementsSsr    = is_subclass_of($dataProviderClass, DataProviderInterface::class);
            if (!$implementsUiPart && !$implementsSsr) {
                throw new UiComponentRegistryException(sprintf(
                    'UiEventManifestBuilder dataProviderClass "%s" must implement %s or %s.',
                    $dataProviderClass,
                    UiPartDataProviderInterface::class,
                    DataProviderInterface::class,
                ));
            }
            $normalisedDp = $dataProviderClass;
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

            if ($normalisedSub !== null) {
                $claims['sub'] = $normalisedSub;
            }

            if ($normalisedDp !== null) {
                $claims['dp'] = $normalisedDp;
            }

            $blob = SignedContext::sign($claims, $ttlSeconds);

            $entries[] = new UiEventManifestEntry(
                part: $event->partName,
                event: $event->eventName,
                signedContext: $blob,
                updatesPath: $event->updatesPath !== null ? (string) $event->updatesPath : null,
            );
        }

        foreach ($externalBindings as $external) {
            $claims = [
                'c' => $metadata->name,
                'i' => $instanceId,
                'p' => $external->partName,
                'e' => $external->eventName,
            ];

            $configKey = $external->partName . '.' . $external->eventName;
            if (isset($eventConfig[$configKey]) && $eventConfig[$configKey] !== []) {
                $claims['cfg'] = $eventConfig[$configKey];
            }

            if ($normalisedSub !== null) {
                $claims['sub'] = $normalisedSub;
            }

            if ($normalisedDp !== null) {
                $claims['dp'] = $normalisedDp;
            }

            $blob = SignedContext::sign($claims, $ttlSeconds);

            $entries[] = new UiEventManifestEntry(
                part: $external->partName,
                event: $external->eventName,
                signedContext: $blob,
                updatesPath: null,
            );
        }

        return new UiEventManifest(
            componentName: $metadata->name,
            instanceId: $instanceId,
            entries: $entries,
        );
    }
}
