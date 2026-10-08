<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * One UI effect — the single response vocabulary of a component handler.
 *
 * The SAME list rides the HUG reply (inline, when the page has no KISS stream)
 * and the KISS push (`ui.patch`), so a reply and a server push are the same
 * thing. `morph` is the default way to update a component: the handler asks for
 * a {@see rerender()}, the dispatcher re-renders the component's Twig with its
 * signed props (plus the handler's overrides) and the client morphs the
 * instance root by id. The small ops cover what does not need a re-render.
 *
 *   rerender (→ morph) · replace · append · prepend · remove · focus
 *   setText · setValue · setAttribute
 *   redirect · toast · dispatch (a browser event)
 *
 * Patches are safe by construction:
 *   - `$op` is one of a small allow-listed verb set;
 *   - `$target` references the component instance + (optional) part + (optional)
 *     `data-ui-patch-target` name. NO arbitrary CSS selectors. NO `document`
 *     / `body` / `html` targets;
 *   - `$value` is scalar (string/int/float/bool/null) when the op carries a value;
 *   - `$attribute`, when set, must be an allow-listed HTML attribute name.
 *
 * The full validation lives in `UiPatchValidator`; this DTO is just the
 * transport shape — readonly, no behavior beyond the JSON projection.
 *
 * Targeting addresses (all scoped to a single component instance):
 *   { instance }                  → component root
 *   { instance, part }            → [data-ui-part="<part>"] inside the root
 *   { instance, name }            → [data-ui-patch-target="<name>"] inside the root
 */
final readonly class UiResponsePatch
{
    public const OP_SET_TEXT      = 'setText';
    public const OP_SET_VALUE     = 'setValue';
    public const OP_SET_ATTRIBUTE = 'setAttribute';
    public const OP_MORPH         = 'morph';
    public const OP_REPLACE       = 'replace';
    public const OP_APPEND        = 'append';
    public const OP_PREPEND       = 'prepend';
    public const OP_REMOVE        = 'remove';
    public const OP_FOCUS         = 'focus';
    public const OP_REDIRECT      = 'redirect';
    public const OP_TOAST         = 'toast';
    public const OP_DISPATCH      = 'dispatch';
    public const OP_RESET         = 'reset';
    public const OP_OPEN          = 'open';
    public const OP_CLOSE         = 'close';
    public const OP_URL           = 'url';

    /**
     * An intent, never on the wire: the dispatcher turns it into `morph` by
     * re-rendering the signed component instance.
     */
    public const OP_RERENDER = 'rerender';

    /** @var list<string> the ops that may reach the browser */
    public const ALLOWED_OPS = [
        self::OP_SET_TEXT,
        self::OP_SET_VALUE,
        self::OP_SET_ATTRIBUTE,
        self::OP_MORPH,
        self::OP_REPLACE,
        self::OP_APPEND,
        self::OP_PREPEND,
        self::OP_REMOVE,
        self::OP_FOCUS,
        self::OP_REDIRECT,
        self::OP_TOAST,
        self::OP_DISPATCH,
        self::OP_RESET,
        self::OP_OPEN,
        self::OP_CLOSE,
        self::OP_URL,
    ];

    /** @var list<string> ops whose value is server-rendered HTML */
    public const HTML_OPS = [self::OP_MORPH, self::OP_REPLACE, self::OP_APPEND, self::OP_PREPEND];

    /** @var list<string> toast levels */
    public const TOAST_LEVELS = ['info', 'success', 'warning', 'error'];

    /** Attribute names accepted by setAttribute. Tight allow-list. */
    public const ALLOWED_ATTRIBUTES = [
        'aria-invalid',
        'aria-describedby',
        'data-state',
        'ui-state',
    ];

    public function __construct(
        public string $op,
        public string $targetInstance,
        public ?string $targetPart,
        public ?string $targetName,
        public mixed $value = null,
        public ?string $attribute = null,
        /** @var array<string, mixed> op-specific arguments (props, level, detail, replace) */
        public array $args = [],
    ) {}

    public static function setText(string $instance, string|int|float|bool|null $value, ?string $part = null, ?string $name = null): self
    {
        return new self(self::OP_SET_TEXT, $instance, $part, $name, $value);
    }

    public static function setValue(string $instance, string|int|float|bool|null $value, ?string $part = null, ?string $name = null): self
    {
        return new self(self::OP_SET_VALUE, $instance, $part, $name, $value);
    }

    public static function setAttribute(string $instance, string $attribute, string|int|float|bool|null $value, ?string $part = null, ?string $name = null): self
    {
        return new self(self::OP_SET_ATTRIBUTE, $instance, $part, $name, $value, $attribute);
    }

    /**
     * Re-render this component instance and morph it in place. `$props` are
     * merged over the props it was rendered with (which ride its signed
     * context), so a handler states only what changed.
     *
     * @param array<string, mixed> $props
     */
    public static function rerender(string $instance, array $props = []): self
    {
        return new self(self::OP_RERENDER, $instance, null, null, null, null, ['props' => $props]);
    }

    public static function morph(string $instance, string $html): self
    {
        return new self(self::OP_MORPH, $instance, null, null, $html);
    }

    public static function replace(string $instance, string $html, ?string $part = null, ?string $name = null): self
    {
        return new self(self::OP_REPLACE, $instance, $part, $name, $html);
    }

    public static function append(string $instance, string $html, ?string $part = null, ?string $name = null): self
    {
        return new self(self::OP_APPEND, $instance, $part, $name, $html);
    }

    public static function prepend(string $instance, string $html, ?string $part = null, ?string $name = null): self
    {
        return new self(self::OP_PREPEND, $instance, $part, $name, $html);
    }

    public static function remove(string $instance, ?string $part = null, ?string $name = null): self
    {
        return new self(self::OP_REMOVE, $instance, $part, $name);
    }

    public static function focus(string $instance, ?string $part = null, ?string $name = null): self
    {
        return new self(self::OP_FOCUS, $instance, $part, $name);
    }

    /** Restore a <form>'s controls to their rendered defaults. */
    public static function reset(string $instance, ?string $part = null, ?string $name = null): self
    {
        return new self(self::OP_RESET, $instance, $part, $name);
    }

    /**
     * Open an overlay: the modal / offcanvas behavior or native <dialog> at the
     * target (a part or named target of the instance).
     */
    public static function open(string $instance, ?string $part = null, ?string $name = null): self
    {
        return new self(self::OP_OPEN, $instance, $part, $name);
    }

    /**
     * Close an overlay. Aimed at the instance itself, it closes the overlay the
     * instance sits in — a form in a modal closing it after a successful save.
     */
    public static function close(string $instance, ?string $part = null, ?string $name = null): self
    {
        return new self(self::OP_CLOSE, $instance, $part, $name);
    }

    /**
     * Set (string) or drop (null) query parameters of the page's address,
     * rewriting the current history entry or pushing a new one.
     *
     * @param array<string, string|null> $params
     */
    public static function url(string $instance, array $params, bool $push = false): self
    {
        return new self(self::OP_URL, $instance, null, null, null, null, ['params' => $params, 'history' => $push ? 'push' : 'replace']);
    }

    /** Same-origin path only (`/…`). */
    public static function redirect(string $instance, string $path, bool $replace = false): self
    {
        return new self(self::OP_REDIRECT, $instance, null, null, $path, null, $replace ? ['replace' => true] : []);
    }

    /**
     * A redirect target the browser may follow: a same-origin path with a
     * single leading "/". An absolute or protocol-relative URL would let a
     * handler bounce the user off-site.
     */
    public static function isSameOriginPath(mixed $path): bool
    {
        return is_string($path) && preg_match('#\A/(?!/)[^\s\\\\]*\z#', $path) === 1;
    }

    public static function toast(string $instance, string $message, string $level = 'info', ?string $title = null): self
    {
        $args = ['level' => $level];
        if ($title !== null) {
            $args['title'] = $title;
        }
        return new self(self::OP_TOAST, $instance, null, null, $message, null, $args);
    }

    /**
     * Fire a browser CustomEvent from the instance root — how a component tells
     * the rest of the page something happened.
     *
     * @param array<string, string|int|float|bool|null> $detail
     */
    public static function dispatch(string $instance, string $event, array $detail = []): self
    {
        return new self(self::OP_DISPATCH, $instance, null, null, $event, null, $detail === [] ? [] : ['detail' => $detail]);
    }

    /**
     * Plain-array projection used by the dispatch handler for JSON encoding.
     * Compact keys keep the wire payload small.
     *
     * @return array<string, mixed>
     */
    public function toJsonShape(): array
    {
        $target = ['instance' => $this->targetInstance];
        if ($this->targetPart !== null) {
            $target['part'] = $this->targetPart;
        }
        if ($this->targetName !== null) {
            $target['name'] = $this->targetName;
        }

        $out = [
            'op' => $this->op,
            'target' => $target,
        ];

        if ($this->attribute !== null) {
            $out['attribute'] = $this->attribute;
        }

        if (in_array($this->op, [self::OP_REMOVE, self::OP_FOCUS], true)) {
            // No value.
        } elseif ($this->op !== self::OP_SET_ATTRIBUTE || $this->value !== null || $this->attribute !== null) {
            // setText/setValue always serialise `value`; setAttribute can also
            // carry a value (the new attribute value).
            $out['value'] = $this->value;
        }

        if ($this->args !== [] && $this->op !== self::OP_RERENDER) {
            $out['args'] = $this->args;
        }

        return $out;
    }
}
