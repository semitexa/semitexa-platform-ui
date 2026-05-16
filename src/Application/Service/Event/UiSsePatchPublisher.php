<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/**
 * Server-side publisher for Platform UI SSE patches.
 *
 * Encapsulates the publish path: validate → encode → queue. The output
 * shape is the same `UiResponsePatch` JSON the POST /__ui/dispatch
 * response carries, so the frontend's safe patch applier can consume
 * either source with no second code path.
 *
 * Validation is non-negotiable: every patch must pass UiPatchValidator
 * (instance binding, op allow-list, attribute allow-list, target shape)
 * before it reaches the queue. A failed validation is the publisher's
 * problem — handlers / playground triggers receive a typed exception
 * and can map it to a safe HTTP response.
 *
 * The publisher does NOT verify channel tokens. The caller is expected
 * to have verified the subscription's right to receive these patches
 * already (typically by checking the channel token from the request
 * and pinning patches to the instance the page already knows about).
 *
 * Wire shape pushed onto the queue:
 *   {
 *     "v": 1,
 *     "patches": [
 *       {"op": "...", "target": {...}, ...}
 *     ],
 *     "messageId": "uchm_<32hex>",
 *     "publishedAt": <unix-ts>
 *   }
 *
 * The frontend listener iterates `patches` and feeds each into the
 * existing applyResponsePatches path. Top-level fields are
 * intentionally minimal — no handler names, no class FQCNs, no signed
 * ctx leaks.
 */
#[AsService]
final class UiSsePatchPublisher
{
    /** Wire-shape schema version. Frontend refuses unknown versions. */
    public const MESSAGE_VERSION = 1;

    /** Hard cap on patches per message — keeps a single SSE frame bounded. */
    public const MAX_PATCHES_PER_MESSAGE = 32;

    #[InjectAsReadonly]
    protected UiSsePatchQueue $queue;

    /**
     * Stateless helper. The container instantiates this service via
     * newInstanceWithoutConstructor() so we cannot rely on __construct
     * to assign it — instead we lazy-init in validator(). Keeps the
     * publisher's surface free of an extra DI dependency for a
     * stateless helper.
     */
    private ?UiPatchValidator $validator = null;

    /**
     * @param list<UiResponsePatch> $patches Patches to publish. Every
     *        patch's targetInstance MUST equal $instanceId; the
     *        validator enforces this. Empty list is a no-op.
     *
     * @throws UiInteractionUnprocessableException When validation fails.
     * @throws \InvalidArgumentException When the channelId/instanceId
     *         arguments are malformed.
     */
    public function publish(string $channelId, string $instanceId, array $patches): void
    {
        $channelId = trim($channelId);
        $instanceId = trim($instanceId);
        if ($channelId === '' || preg_match(UiSseChannelToken::CHANNEL_ID_PATTERN, $channelId) !== 1) {
            throw new \InvalidArgumentException('channelId is empty or malformed.');
        }
        if ($instanceId === '') {
            throw new \InvalidArgumentException('instanceId is empty.');
        }
        if ($patches === []) {
            return;
        }
        if (count($patches) > self::MAX_PATCHES_PER_MESSAGE) {
            throw new UiInteractionUnprocessableException(
                'too_many_patches',
                sprintf(
                    'SSE message exceeds the %d-patch limit.',
                    self::MAX_PATCHES_PER_MESSAGE,
                ),
            );
        }

        // Validation: reuse the dispatcher's patch validator so the
        // same rules govern both response.patches and SSE patches.
        // patch_instance_mismatch is raised here when a caller tries to
        // publish a patch that targets a different instance — the
        // exception bubbles up unchanged for the caller's error
        // response.
        $validated = $this->validator()->validateAll($patches, $instanceId);

        $shapes = [];
        foreach ($validated as $patch) {
            $shapes[] = $patch->toJsonShape();
        }

        $message = [
            'v'           => self::MESSAGE_VERSION,
            'patches'     => $shapes,
            'messageId'   => 'uchm_' . bin2hex(random_bytes(16)),
            'publishedAt' => time(),
        ];

        $encoded = json_encode(
            $message,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $this->queue->publish($channelId, $encoded);
    }

    /**
     * Test seam — wire deps without going through the container.
     */
    public function withDependencies(UiSsePatchQueue $queue, ?UiPatchValidator $validator = null): self
    {
        $this->queue = $queue;
        if ($validator !== null) {
            $this->validator = $validator;
        }
        return $this;
    }

    private function validator(): UiPatchValidator
    {
        if ($this->validator === null) {
            $this->validator = new UiPatchValidator();
        }
        return $this->validator;
    }
}
