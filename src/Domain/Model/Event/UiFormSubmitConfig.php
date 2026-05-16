<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Server-owned form submit definition. Signed into the platform.form
 * submit event's `cfg.f` claim at render time; read back by
 * FormComponent::onSubmit() out of the verified ctx.
 *
 * Wire shape (embedded under `cfg.f` of the signed submit ctx):
 *
 *   "cfg": {
 *     "f": [
 *       {"n": "access_code",         "r": [{"n":"required"},{"n":"minLength","p":[4]}],         "l": "Access code", "q": true},
 *       {"n": "confirm_access_code", "r": [{"n":"required"},{"n":"sameAsField","p":["access_code","Codes must match."]}], "q": true}
 *     ]
 *   }
 *
 * The list is **authoritative**. Submit validation iterates these
 * definitions and runs them against the client-submitted
 * `payload.form.values` — never the other way around. The client
 * cannot add, remove, or retarget a definition without breaking the
 * HMAC.
 */
final readonly class UiFormSubmitConfig
{
    /**
     * @param list<UiFormSubmitFieldDefinition> $fields
     * @param ?string                           $actionName
     *        Optional signed submit action name (lands under `cfg.a`
     *        of the signed submit ctx). Validated at render time by
     *        UiFormSubmitConfigParser against the active
     *        UiFormSubmitActionRegistryInterface.
     */
    public function __construct(
        public array $fields,
        public ?string $actionName = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * @return list<array{n: string, r: list<array<string, mixed>>, l?: string, q?: true}>
     */
    public function toWireShape(): array
    {
        $out = [];
        foreach ($this->fields as $field) {
            $out[] = $field->toWireShape();
        }
        return $out;
    }
}
