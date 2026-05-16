<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * One entry in a per-component-render UI event manifest.
 *
 * The pair (part, event) identifies the declared #[UiOn] handler on the
 * server side. `signedContext` is the opaque SSR SignedContext blob
 * (sc1.<b64>.<sig>) that the future runtime will hand back to the server
 * verbatim — the server resolves the actual handler method by looking up
 * the component and event in UiComponentRegistry, never by trusting
 * client-supplied strings.
 *
 * No method name, no class FQCN, no transport URL — those are server
 * internals and must not leak to the client.
 */
final readonly class UiEventManifestEntry
{
    public function __construct(
        public string $part,
        public string $event,
        public string $signedContext,
        public ?string $updatesPath = null,
    ) {}

    /**
     * Plain-array projection used by the Twig helper to serialize the
     * manifest into a JSON `<script>` tag. Key names are intentionally
     * short to keep the rendered payload compact.
     *
     * @return array{p: string, e: string, ctx: string, u?: string}
     */
    public function toJsonShape(): array
    {
        $shape = [
            'p' => $this->part,
            'e' => $this->event,
            'ctx' => $this->signedContext,
        ];
        if ($this->updatesPath !== null) {
            $shape['u'] = $this->updatesPath;
        }
        return $shape;
    }
}
