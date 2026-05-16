<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Normalized validation-rule specification.
 *
 * Wire shape (signed into the event manifest's ctx claim):
 *   { "n": "required" }
 *   { "n": "minLength", "p": [3] }
 *   { "n": "maxLength", "p": [20] }
 *
 * Compact keys keep the signed ctx small. `n` is the rule id; `p` is
 * the positional parameter list (optional, only scalars allowed). The
 * normalized shape is the exact thing the parser writes, the manifest
 * signs, and the dispatcher reads back — so a tampered rule spec
 * invalidates the HMAC.
 *
 * Constructing instances directly bypasses the parser's safety checks.
 * Always go through UiFieldRuleParser unless you're inside a unit
 * test that already vets the parameters.
 */
final readonly class UiFieldRuleSpec
{
    /**
     * @param list<scalar> $params Positional parameters (string/int/float/bool only).
     */
    public function __construct(
        public string $name,
        public array  $params = [],
    ) {}

    /**
     * @return array{n: string, p?: list<scalar>}
     */
    public function toWireShape(): array
    {
        $out = ['n' => $this->name];
        if ($this->params !== []) {
            $out['p'] = $this->params;
        }
        return $out;
    }
}
