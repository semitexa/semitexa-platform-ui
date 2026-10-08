<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\Ssr\Domain\Model\ComponentInstanceId;

/**
 * Generates per-render Platform UI component instance ids.
 *
 * The id is meant to be:
 *   - DOM-attribute safe (no quoting needed);
 *   - cryptographically random enough that two parallel renders never
 *     collide within one page;
 *   - prefixed (`uci_`) so the future frontend runtime can pattern-match
 *     for diagnostics.
 *
 * Stateless. 8 random bytes → 16 hex chars → 64 bits of entropy, plenty
 * for the "no two ids collide on one page" requirement.
 */
final class UiInstanceIdGenerator
{
    public const PREFIX = 'uci_';

    /**
     * Canonical safe shape for a Platform UI component instance id.
     *
     * Accepts both the default generator output (`uci_<16hex>`) and
     * developer-provided stable ids like `uci_submit_access_code` —
     * underscores, hyphens, alphanumerics are all legal in the tail
     * segment. Bounded to 64 chars after the prefix so the signed
     * ctx stays small and operator logs stay grep-friendly.
     *
     * Tight enough to refuse anything that could escape an HTML
     * attribute or smuggle a selector: no spaces, no quotes, no
     * angle brackets, no slashes, no dots, no `#`. Subset of what
     * UiPatchValidator::IDENTIFIER_PATTERN accepts so the patch
     * validator's instance check keeps accepting these too.
     */
    public const SAFE_ID_PATTERN = '/\Auci_[A-Za-z0-9_-]{1,64}\z/';

    /** The one component identity is ssr's; this only names it for Platform UI. */
    public function next(): string
    {
        return ComponentInstanceId::mint();
    }

    /**
     * Returns true when $value is a safe Platform UI component
     * instance id (matches {@see self::SAFE_ID_PATTERN}).
     */
    public static function isSafe(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SAFE_ID_PATTERN, $value) === 1;
    }

    /**
     * The instance a template is rendering: the id the component renderer
     * minted (or kept, on a re-render) — never a second id beside it. A fresh
     * one only when rendered outside a component.
     *
     * @param array<array-key, mixed> $context
     */
    public static function forContext(array $context): string
    {
        $id = $context['_component_id'] ?? null;

        return ComponentInstanceId::isSafe($id) ? (string) $id : ComponentInstanceId::mint();
    }
}
