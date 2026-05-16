<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Stable, safe return shape from a #[UiOn] handler.
 *
 * Two kinds in this slice:
 *   - `ack`   — pure acknowledgement, optional `debug` data.
 *   - `patch` — acknowledgement + one or more `UiResponsePatch` instructions
 *               the frontend applies after dispatch. When the patch list is
 *               empty, the factory degrades to `ack` so consumers can keep a
 *               single happy path.
 *
 * The dispatcher's response normalisation also accepts:
 *   - `void` returns (mapped to `ack()` with empty debug data);
 *   - bare `array` returns (mapped to `ack($array)` for ergonomics).
 *
 * The class is intentionally narrow:
 *   - no `redirect`;
 *   - no `partial-html`;
 *   - no `state` mutation.
 *
 * Future slices will extend with discriminated `Redirect` / `Stream`
 * variants without breaking the ack/patch contract.
 */
final readonly class UiInteractionResult
{
    public const KIND_ACK = 'ack';
    public const KIND_PATCH = 'patch';

    /**
     * @param array<string, mixed>  $debug
     * @param list<UiResponsePatch> $patches
     */
    private function __construct(
        public string $kind,
        public array $debug,
        public array $patches,
    ) {}

    /**
     * @param array<string, mixed> $debug
     */
    public static function ack(array $debug = []): self
    {
        return new self(kind: self::KIND_ACK, debug: $debug, patches: []);
    }

    /**
     * @param list<UiResponsePatch> $patches
     * @param array<string, mixed>  $debug
     */
    public static function patch(array $patches, array $debug = []): self
    {
        $kind = $patches === [] ? self::KIND_ACK : self::KIND_PATCH;
        return new self(kind: $kind, debug: $debug, patches: $patches);
    }
}
