# platform-ui primitives (v1)

Six primitives ship in v1. All are semantic HTML with CSS styling via `ui="<id>"` + modifiers. CSS is the stable contract; Twig macros in `resources/twig/primitives/` are optional DX.

## `ui="button"`

| Attribute | Values | Default |
|---|---|---|
| `ui-variant` | `solid` · `soft` · `ghost` | `solid` |
| `ui-tone` | `neutral` · `brand` · `success` · `warning` · `danger` | `brand` (solid) |
| `ui-size` | `sm` · `md` · `lg` | `md` |

States: `:hover`, `:active`, `:disabled` handled automatically.

```html
<button ui="button" ui-tone="danger">Delete</button>
<button ui="button" ui-variant="ghost">Cancel</button>
<a ui="button" href="/export" ui-variant="soft">Export</a>
```

## `ui="input"`

| Attribute | Values | Default |
|---|---|---|
| `ui-size` | `sm` · `md` · `lg` | `md` |
| `ui-state` | `default` · `invalid` | `default` |

Uses `color-mix(in oklab, ...)` for focus ring tinting against `--ui-accent-brand`.

## `ui="label"`

Form label with role-appropriate typography. `ui-size`: `sm`/`md`/`lg`.

## `ui="field-shell"`

Wraps label + input + optional `ui="error-text"`. When parent has `ui-state="invalid"`:
- Descendant `[ui="label"]` turns `--ui-state-danger`
- Descendant `[ui="input"]` border turns danger
- `[ui="error-text"]` becomes visible (hidden by default)

```html
<div ui="field-shell" ui-state="invalid">
    <label ui="label" for="email">Email</label>
    <input ui="input" id="email" type="email" ui-state="invalid">
    <span ui="error-text">Enter a valid email.</span>
</div>
```

## `ui="surface"`

Opinionated panel container. Defaults to panel background + subtle border + 1rem padding. Compose with `sx-padding`, `sx-radius`, `sx-surface` for variants.

## `ui="badge"`

| Attribute | Values | Default |
|---|---|---|
| `ui-variant` | `solid` · `soft` | `solid` |
| `ui-tone` | `neutral` · `brand` · `success` · `warning` · `danger` | `brand` |

## Deferred to v1.1+

`textarea`, `select`, `checkbox`, `radio`, `switch`, `hint`, `tag`, `divider`, `icon`, `toolbar`.

## Introspection

- `bin/semitexa platform-ui:css:explain button` — variants, tones, sizes, tokens referenced
- `Semitexa\PlatformUi\Primitive\PrimitiveRegistry::all()` — programmatic enumeration

## Runtime (attribute-driven)

`#[AsUiPrimitive]` declares a class as a Semitexa UI primitive. The lifecycle listener `BootPlatformUiRegistryListener` boots `UiPrimitiveRegistry` with `ClassDiscovery` at worker start; from that point primitives are discoverable by canonical name (e.g. `platform.button`) or short UI alias (e.g. `button`).

```php
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

#[AsUiPrimitive(
    name: 'platform.button',
    ui: 'button',
    template: '@platform-ui/primitives/runtime/button.html.twig',
    style: 'platform-ui:css:full',
)]
final class ButtonPrimitive {}
```

- `name` — canonical registry/debug identity. Used by handler resolution, signed contexts, manifests. Unique across the registry.
- `ui` — short CSS/markup alias for the `ui="..."` attribute. Unique across the registry. Derived from the last dot-segment of `name` when omitted.
- `template` — Twig template that renders the primitive. Receives `props` plus `_primitive: {name, ui}` in context.
- `style` / `script` — optional asset keys. Required through `AssetCollectorStore` at render time and deduplicated by the collector.

### `primitive()` Twig helper

`PlatformUiTwigExtension` registers `primitive(name, props)` via `#[AsTwigExtension]`:

```twig
{{ primitive('button', { text: 'Save', tone: 'brand', variant: 'solid' }) }}
{{ primitive('input', { name: 'email', placeholder: 'Email' }) }}
{{ primitive('badge', { text: 'Active', tone: 'success' }) }}
```

Both the canonical name and the ui alias resolve to the same primitive: `primitive('platform.button', ...)` ≡ `primitive('button', ...)`.

The rendered output carries stable root markers for future frontend-runtime scanning:

```html
<button ui="button" data-ui-primitive="platform.button" type="button" ui-tone="brand">Save</button>
```

### Primitive prop vocabulary

The current attribute-driven primitives accept this small vocabulary:

| primitive | accepted props |
|---|---|
| `button` | `text`, `tone` (`brand`/`neutral`/`success`/`warning`/`danger`), `variant` (`solid`/`soft`/`ghost`), `size` (`sm`/`md`/`lg`), `disabled`, `href`, `type` |
| `input`  | `name`, `id`, `type`, `value`, `placeholder`, `size`, `state` (`invalid`), `required`, `disabled`, `help`, `error` |
| `badge`  | `text`, `tone`, `variant` (`solid`/`soft`) |

Only `text` (and `href` on button) ever changes the rendered tag; everything else maps to a `ui-*` attribute that the active skin's `tokens.css` resolves. `error` on an input automatically sets `ui-state="invalid"`, `aria-invalid="true"`, and an inline danger-toned message; `help` renders muted help text with `aria-describedby`. Both wrap the input in a stack — bare inputs (no `help`/`error`) still emit a single `<input>` element so existing usage is preserved.

### Active skin/theme assumption

Platform-ui CSS reads every visual decision (color, radius, spacing, motion) from CSS custom properties prefixed `--ui-*`. Those properties are defined by the active **skin** at runtime (`/assets/skins/<slug>/tokens.css`). The active skin is determined by `semitexa/theme` from the (tenant, domain, locale) tuple. The project's `app` layout includes both `platform-ui:css:full` (auto-required via `requireGlobals()`) and the skin tokens link via `theme_skin_css()`, with tokens loaded **last** so they win the cascade.

### Local playground

`src/modules/UiPlayground` is the local development surface. Routes:

| Route | Demo |
|---|---|
| `/ui-playground` | Menu / dashboard |
| `/ui-playground/primitives/buttons` | Buttons — tones, variants, sizes, disabled, anchor |
| `/ui-playground/primitives/inputs` | Inputs — placeholder, sizes, help, error, disabled |
| `/ui-playground/primitives/badges` | Badges — tones, soft variant, in-context |
| `/ui-playground/foundation/colors` | Skin tokens — surfaces, accent/text, state, typography |

The playground only consumes public APIs — primitive declarations and the `primitive()` helper live in `semitexa/platform-ui`.

## Composition (UiPart + UiSlot)

The composition slice on top of primitives. A class becomes a Platform UI component by combining SSR's `#[AsComponent]` with one or more `#[UiPart]` / `#[UiSlot]` attributes:

```php
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\Ssr\Attribute\AsComponent;

#[AsComponent(name: 'platform.field', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
#[UiSlot(name: 'prefix')]
#[UiSlot(name: 'suffix')]
final class FieldComponent {}
```

- `#[UiPart(name, uses, defaults?)]` — `uses` is a **FQCN** of a class marked with `#[AsUiPrimitive]`; primitive aliases (`'input'`) are only accepted in Twig/demo surfaces. `defaults` is an optional prop map merged under caller props.
- `#[UiSlot(name, description?)]` — declares a caller-content hole. Slot values are passed as the third argument of the SSR `component()` Twig helper.

Both attributes are `IS_REPEATABLE`. Component rendering still flows through SSR's `ComponentRegistry::initialize()` + `ComponentRenderer::render($name, $props, $slots)` — the Platform UI side adds only the composition metadata, exposed through `UiComponentRegistry::get($name)` for introspection and tests.

### `FieldComponent` example

```twig
{{ component('platform.field', {
    label: 'Email address',
    name: 'email',
    type: 'email',
    placeholder: 'name@example.com',
    help: 'We use this for notifications.',
    required: true,
}) }}

{{ component('platform.field',
    { label: 'Search', name: 'q', placeholder: 'Search…' },
    { suffix: primitive('button', { text: 'Go', tone: 'brand', size: 'sm' }) }
) }}
```

Rendered output carries a stable root marker so future frontend runtimes can scan the DOM:

```html
<div data-ui-component="platform.field" ui-component="field" sx-layout="stack" sx-gap="1">
  <label for="email" ui-text="label">Email address <span aria-hidden="true">*</span></label>
  <input ui="input" data-ui-primitive="platform.input" type="email" name="email" id="email"
         placeholder="name@example.com" aria-describedby="email-help" required>
  <span id="email-help" ui-text="muted">We use this for notifications.</span>
</div>
```

`error` automatically sets `ui-state="invalid"`, `aria-invalid="true"`, and replaces the help line with a danger-toned error message bound through `aria-describedby`.

### Part prop resolution

Part props are resolved by `UiPartPropResolver` in a deterministic **four-step** order. Components declare a provider with `#[ProvidesUiPart(part: '…')]` on a public, non-static instance method returning `array`, and optionally a `bind` path on the part:

```php
use Semitexa\PlatformUi\Attribute\ProvidesUiPart;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\Ssr\Attribute\AsComponent;

#[AsComponent(name: 'platform.field', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(
    name: 'input',
    uses: InputPrimitive::class,
    defaults: ['type' => 'text'],
    bind: 'value',
)]
final class FieldComponent
{
    /** @param array<string, mixed> $props
     *  @return array<string, mixed> */
    #[ProvidesUiPart(part: 'input')]
    public function inputPart(array $props): array
    {
        // Structural props only — `value` is owned by the bind step.
        $id = $props['id'] ?? $props['name'] ?? null;
        $hasError = isset($props['error']) && $props['error'] !== '';
        return [
            'name' => $props['name'] ?? null,
            'id' => $id,
            'type' => $props['type'] ?? 'text',
            'placeholder' => $props['placeholder'] ?? null,
            'state' => $hasError ? 'invalid' : ($props['state'] ?? null),
            'required' => (bool) ($props['required'] ?? false),
            'disabled' => (bool) ($props['disabled'] ?? false),
            'aria_invalid' => $hasError ? true : null,
            'aria_describedby' => $hasError && $id ? "{$id}-error"
                : (isset($props['help']) && $id ? "{$id}-help" : null),
        ];
    }
}
```

**Resolution order (later steps overwrite earlier keys):**

1. `#[UiPart(defaults: [...])]` — declarative baseline declared on the part itself.
2. `#[ProvidesUiPart]` provider method result — invoked with the caller component props.
3. `#[UiPart(bind: '<path>')]` — bind-derived **`value`** (value-only in this slice). Walks the dot-segmented path through the caller component props. Resolved non-null values land on `$resolved['value']`; null/missing values leave whatever the provider set.
4. Caller `inputProps` overrides — passed by the component template via `ui_part_props('input', inputProps|default({}))`.

**Provider contract (enforced at metadata extraction):**

- `part` must reference an existing `#[UiPart]` on the same class.
- Only one provider per part; duplicates fail at registration.
- Provider must be `public`, non-static, non-abstract.
- Provider must declare return type `array` (or omit the return type entirely; the resolver still enforces `is_array()` at call time).
- Providers must be pure in this slice — no IO, no service calls, no database access.

**`UiPartPropResolver` API:**

```php
$resolver->resolve(
    UiComponentMetadata $metadata,
    string $partName,
    array $componentProps,
    array $overrides = [],
    ?object $componentInstance = null,
): array
```

The optional `$componentInstance` lets callers (e.g. an enhanced renderer) inject a container-built component instance. When omitted, the resolver instantiates the component class via reflection (works for any no-required-arg constructor — currently every Platform UI component).

**Twig helpers:**

Two helpers cover both rendering styles. Prefer **`ui_part()`** for new component templates — it renders + marks the part atomically:

```twig
{# preferred: one-shot render with explicit data-ui-part marker #}
{{ ui_part('input', inputProps|default({})) }}
```

`ui_part(partName, overrides = [])` resolves props through `UiPartPropResolver`, renders the underlying primitive via `PrimitiveRenderer`, and **injects `data-ui-part="<partName>"` as the first attribute on the rendered root tag** so the frontend runtime can resolve parts by **UiPart name** instead of conflating with the primitive's `ui` alias. Returns a `Markup`.

```twig
{# alternative: explicit prop map (legacy, still supported) #}
{%- set _input_props = ui_part_props('input', inputProps|default({})) -%}
{{ primitive('input', _input_props) }}
```

`ui_part_props()` returns just the resolved prop map (an array, not Markup) so callers can split resolution from rendering — useful when the same prop map needs to be inspected or passed through additional logic. Templates that use this path do **not** get the `data-ui-part` marker automatically; the frontend runtime falls back to matching `[ui="<part-name>"]` for them.

Both helpers read the current `_component.name` from the Twig context, look up the metadata in `UiComponentRegistry`, extract component props (every context key not prefixed with `_`), and call `UiPartPropResolver::resolve()`.

### Bind / value model

`#[UiPart(bind: '<path>')]` declares which **value path** inside the caller component props supplies the part's `value` prop. Bind is server-rendered projection only — no live updates, no event wiring.

**Value path syntax** (validated by `UiValuePath::parse()` at metadata extraction time):

```
^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$
```

- Each segment starts with a letter or underscore, then letters / digits / underscores.
- Segments are separated by exactly one dot.
- No empty segments, no leading/trailing dots, no double dots, no brackets, no wildcards, no spaces, no Twig delimiters, no PHP syntax.

| valid | invalid |
|---|---|
| `value` | `""` |
| `email` | `.value` |
| `user.email` | `value.` |
| `address.street` | `user..email` |
| `filters.search_text` | `user[email]` |
| `_private` | `user.*` |
| `user1.email2` | `user email` |
|  | `{{ value }}` |
|  | `1user`, `user.1email` |
|  | `$value` |
|  | `user-email` |

**Bind semantics in this slice:**

- Bind is **value-only** — only the `value` key of the resolved part-props map is touched. Future revisions may extend this to `checked` / `selected`.
- A bind path that resolves to `null` (missing segment / explicit-null entry / non-array intermediate) **does not clobber** the provider-supplied value. This makes bind safe to layer on top of a provider that already supplies a fallback.
- Nested access is supported. `bind: 'user.email'` walks `$props['user']['email']` and returns `null` if any segment is missing or if any intermediate value is not an array.
- The provider should typically **not** project `value` itself when the part is bound — bind owns the value key. Provider-supplied values still survive when bind resolves to null, useful for "show provider fallback when component has no value".

**FieldComponent bind example:**

```twig
{{ component('platform.field', {
    label: 'Email',
    name: 'email',
    value: 'hello@example.com',
}) }}
{# rendered: <input … name="email" id="email" type="text" value="hello@example.com"> #}

{{ component('platform.field', {
    label: 'Email',
    name: 'email',
    value: 'hello@example.com',
    inputProps: { value: 'override@example.com' },
}) }}
{# rendered: <input … name="email" value="override@example.com">  ← caller overrides win #}

{{ component('platform.field', { label: 'Email', name: 'email' }) }}
{# rendered: <input … name="email" id="email" type="text">  ← no value attribute when bind yields null #}
```

`inputProps.value` always wins because caller overrides are step 4. `inputProps` can also introduce any key the target primitive template emits (see the "inputProps behaviour" section below).

### Slots

Slots are caller-provided content holes. Pass them as the third argument of `component()`:

```twig
{{ component('platform.field',
    { label: 'URL', name: 'url' },
    { prefix: 'https://', suffix: primitive('button', { text: 'Save' }) }
) }}
```

The component template reads slots via SSR's `slot('prefix')` Twig function. Missing slots render nothing.

### `inputProps` behaviour on `FieldComponent`

`inputProps` is the caller-supplied explicit-override map merged onto the resolved input primitive props **after** the provider runs. Two important guarantees:

- Universal merge at the resolver: `inputProps` keys win over both `#[UiPart(defaults: …)]` and the provider's output.
- Display fidelity is bounded by what the target primitive template emits. The input primitive emits a fixed attribute set (`name`, `id`, `type`, `value`, `placeholder`, `size`, `state`, `required`, `disabled`, `aria_invalid`, `aria_describedby`). `inputProps` keys outside that set still land in the resolved map but won't appear in HTML unless the primitive template extends its emission rules.

### Events (`#[UiOn]`) — metadata only

`#[UiOn]` declares which component method is the **intended** handler for a given (part, event) pair. The attribute is **metadata only** in this slice: no DOM listener is wired, no HTTP transport endpoint is registered, no `UiInteractionDispatcher` exists, and the declared method is **not invoked at runtime**.

```php
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;

#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class FieldComponent
{
    #[UiOn(part: 'input', event: 'change')]
    public function onInputChanged(array $event): void
    {
        // Metadata only — do not invoke yet.
    }
}
```

**Attribute shape**

```php
#[Attribute(Attribute::TARGET_METHOD)]
final class UiOn {
    public function __construct(
        public string $part,        // must reference a #[UiPart] on the same class
        public string $event,       // /^[a-z][a-z0-9:_-]*$/
        public ?string $updates = null,  // optional UiValuePath; inherits from part.bind when omitted
    ) {}
}
```

**Valid event-name examples:** `change`, `input`, `click`, `blur`, `focus`, `submit`, `value:change`.
**Rejected:** empty string, uppercase, spaces, `onclick()`, `{{ value }}`, brackets, quotes, digit-first.

**`updates` vs `bind` (strict-compatibility mode):**

| part.bind | updates argument | resolved `updatesPath` |
|---|---|---|
| `value`      | omitted        | `value` (inherited) |
| `value`      | `value`        | `value` |
| `value`      | `other.path`   | **error** — strict mismatch |
| `user.email` | omitted        | `user.email` (inherited) |
| (none)       | omitted        | `null` |
| (none)       | `value`        | `value` |

When the part declares `bind: '<path>'` and the handler declares `updates: '<other-path>'`, the factory rejects the registration. This keeps the model deterministic until a future slice introduces multi-path updates.

**Validation rules (enforced at metadata extraction):**

- `part` must match an existing `#[UiPart]` on the same component.
- `event` must match `/^[a-z][a-z0-9:_-]*$/`.
- `updates` must parse via `UiValuePath` when provided.
- `updates` must equal `part.bind` when both are present (strict mode).
- Each `(part, event)` pair is unique within one component.
- The handler method must be `public`, non-static, non-abstract.
- One `#[UiOn]` per method.

**Metadata model**

`UiOnMetadata` records `componentName`, `class`, `partName`, `eventName`, `updatesPath` (a `UiValuePath` or `null`), `methodName`. It carries NO transport URL, NO handler-id — those belong to a future runtime slice and must not leak into the DOM. Access via `UiComponentMetadata::event($partName, $eventName)`, `::eventsForPart($partName)`, or the `events` map.

**Twig helper for debug surfaces:** `ui_component_events('<component-name>')` returns a list of plain arrays (`{part, event, updates, method, runtime}`) for documentation panels. The helper is read-only and does NOT emit any DOM event-runtime attributes.

### Signed event manifest (render-time, inert)

Every Platform UI component render emits a per-instance **signed event manifest** as inert JSON. The manifest is built from the component's `#[UiOn]` metadata; each entry is signed with SSR's existing `SignedContext` substrate (`sc1.<base64url-claims>.<base64url-hmac>` over canonicalized JSON, HMAC-SHA256, TTL-bound). The signing secret is shared with the rest of the framework's signed-context substrate (`APP_SECRET`, with dev-mode fallback to a derivative of `APP_NAME|APP_HOST|APP_PORT`).

**What lands in the DOM:**

```html
<div data-ui-component="platform.field"
     data-ui-component-instance-id="uci_4f8a…"
     ui-component="field" sx-layout="stack" sx-gap="1">
  …input + label + help/error…
  <script type="application/json"
          data-ui-event-manifest="uci_4f8a…"
          data-ui-component="platform.field">
    {
      "v": 1,
      "c": "platform.field",
      "i": "uci_4f8a…",
      "events": [
        { "p": "input", "e": "change", "u": "value", "ctx": "sc1.<b64>.<hmac>" }
      ]
    }
  </script>
</div>
```

**What stays server-side:** the method name (`onInputChanged`), the class FQCN (`Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent`), and any handler resolution id. The future dispatcher resolves the method via `UiComponentRegistry::get($c)->event($p, $e)->methodName` — clients can never coerce a different method.

**Signed-claim payload** (decoded `ctx`):

```
{
  c:   string,   // component canonical name
  i:   string,   // per-render instance id
  p:   string,   // part name
  e:   string,   // event name
  u:   string,   // updates path — present only when the part declares bind
  iat: int,      // issued-at (unix seconds, added by SignedContext)
  exp: int       // expires-at (iat + ttl, default ttl = 300s)
}
```

**Helpers**

- `ui_component_instance(): string` — returns a fresh `uci_<16hex>` per render. Stamp it once at the top of a component template and pass it into `ui_event_manifest()` and the root `data-ui-component-instance-id` attribute.
- `ui_event_manifest(instanceId, ttlSeconds = null): Markup` — emits the `<script type="application/json" data-ui-event-manifest="…">` block for the component currently rendering. Reads the component identity from Twig's `_component` context; throws if called outside a Platform UI component. Returns an empty `events: []` array when no `#[UiOn]` declarations exist.

**Render-time service**

`UiEventManifestBuilder::build(UiComponentMetadata $metadata, string $instanceId, ?int $ttlSeconds = null): UiEventManifest` — pure, stateless. Calls `SignedContext::sign()` once per `#[UiOn]`. Returns a `UiEventManifest` value object with `toJsonShape()` for serialization.

**Inert by construction**

- No `<script>` ever contains executable JavaScript — the type is `application/json`, browsers parse it as data.
- No `onclick` / `onchange` / `oninput` / `data-ui-handler` / `data-ui-event-url` attributes are emitted anywhere.
- No method name or class FQCN appears in the rendered HTML.

### Frontend event runtime (capture-only)

Shipped in this slice. The runtime is a tiny IIFE (`packages/semitexa-platform-ui/src/Application/Static/js/event-runtime.js`) loaded globally via the asset manifest with `defer`. It scans the DOM for `<script type="application/json" data-ui-event-manifest>` blocks emitted by the server, attaches one document-level capture-phase delegated listener per distinct native event name across all manifests, and **captures matches locally**. It does not send anything anywhere.

**What the runtime does on every captured event:**

1. Walks up from `event.target` to the nearest `[data-ui-component-instance-id]` root.
2. Looks up the manifest for that instance id.
3. Iterates manifest entries; for each entry whose `e` matches the native event name, finds the part element. Lookup order: `[data-ui-part="<part-name>"]` (canonical, injected by `ui_part()`) → `[ui="<part-name>"]` (back-compat for templates that still call `primitive()` directly with `ui_part_props()`).
4. Builds a `captured` payload (see shape below).
5. Dispatches a `semitexa:ui-event:captured` CustomEvent on `document` with `detail = captured`.
6. Invokes every `window.SemitexaUi.onCapture(fn)` listener.

**What it does NOT do:**

- No `fetch` / `XMLHttpRequest` / `navigator.sendBeacon` / `WebSocket` / `EventSource`.
- No signature verification — `ctx` is treated as opaque and passed through verbatim.
- No `preventDefault` / `stopPropagation` — native browser behavior is preserved.
- No DOM mutation, no state changes, no UI patching.

**Public API:**

```js
window.SemitexaUi.version          // '1.0'
window.SemitexaUi.scan(root?)      // manual rescan; also auto-runs on DOMContentLoaded + MutationObserver
window.SemitexaUi.manifests()      // snapshot list of parsed manifests on the page
window.SemitexaUi.onCapture(fn)    // register listener; returns unsubscribe()
```

**Captured payload shape:**

```js
{
  component:       'platform.field',
  instanceId:      'uci_<16hex>',
  part:            'input',
  event:           'change',
  updates:         'value',          // or null when the part is unbound
  ctx:             'sc1.<b64>.<b64>',// opaque signed blob — exactly what the future server will receive
  value:           <part.value>,      // extracted from partEl.value or the value attribute
  originalEvent:   <DOM Event>,
  manifestVersion: 1
}
```

**Part-element lookup**: the runtime first looks for `[data-ui-part="<part-name>"]` inside the component root (canonical — emitted by the server-side `ui_part()` Twig helper), then falls back to `[ui="<part-name>"]` for legacy templates that render the primitive directly via `primitive()` + `ui_part_props()`. The canonical path decouples the UiPart name from the primitive's `ui` alias, so a part can be named freely (e.g. `UiPart(name: 'main', uses: InputPrimitive::class)`) without breaking the runtime.

### HTTP dispatch endpoint (ack + response patches)

Bridges captured frontend events to declared `#[UiOn]` handlers through a unified HTTP endpoint. The handler can return either a plain ack or a small list of safe DOM-patch instructions the frontend applies after dispatch.

**Endpoint:** `POST /__ui/dispatch`

Why not `/__ui/event`: SSR ships a foundation-layer placeholder at `/__ui/event` that accepts the framework-layer `UiEventEnvelope` shape (`schemaVersion`, `eventId`, `correlationId`, `semanticEvent`, `signedContext`, `timestamp`, …). Platform UI's dispatcher uses a *minimal* `{ctx, dispatchId, payload}` body — a layered concern that does not need the full framework-layer envelope. The two endpoints will be unified in a future framework-layer slice that introduces a `UiInteractionDispatcherInterface` contract.

**Request shape:**

```json
{
  "ctx": "sc1.<base64url-claims>.<base64url-hmac>",
  "dispatchId": "ui_evt_<32 hex>",
  "payload": { "value": "taras@example.com" }
}
```

- `ctx` is required.
- `dispatchId` is required. Must match `[A-Za-z0-9][A-Za-z0-9_-]{4,127}`. The frontend transport mints one fresh value per captured event with `crypto.getRandomValues` (format: `ui_evt_<32 hex>`).
- `payload` is optional and defaults to `{}`.
- `payload` **must not** carry any routing-flavored field. The `UiPayloadFieldGuard` walks the whole payload tree and rejects (400) on any key (normalized across camelCase/snake_case/kebab-case) matching: `handler`, `handlerId`, `handlerClass`, `handlerMethod`, `method`, `methodName`, `class`, `className`, `component`, `componentName`, `instance`, `instanceId`, `part`, `partName`, `event`, `eventName`, `updates`, `updatesPath`, `endpoint`, `url`, `route`, `action`, `controller`, `callback`, `dispatcher`, `payloadClass`, `authzScope`, `backendHandler`, plus `dispatchId`/`requestId`/`eventId` (those identifiers belong at the top level, not inside `payload`).

**Replay guard.** The dispatcher keys an entry by `sha256(ctx) + ':' + dispatchId`. The TTL is bounded by both the signed ctx's remaining lifetime and a server-side ceiling (currently 600s). A second request with the *same* `(ctx, dispatchId)` pair returns `409 duplicate_dispatch`. Crucially, the same `ctx` with a *different* `dispatchId` still works — the signed ctx is intentionally reusable inside its TTL so legitimate repeated user actions (e.g. successive `change` events on the same field) are not blocked. The replay guard claim is taken **after** ctx verification (so an invalid ctx never poisons the store) and **before** authorization (so a denied attempt still consumes its `dispatchId` — clients must mint a fresh id to retry).

**Authorization hook.** A pluggable `UiInteractionAuthorizerInterface` runs *after* the replay claim and *before* the `#[UiOn]` handler. The default `AllowAllUiInteractionAuthorizer` is wired by the package and allows every verified dispatch; apps swap it via `withServices(authorizer: …)` or a future container binding. A `false` return maps to `403 interaction_forbidden`; the handler is never invoked and no patches are returned.

**Success response (200):**

```json
{
  "ok": true,
  "handled": true,
  "kind": "ack",
  "dispatchId": "ui_evt_<32 hex>",
  "component": "platform.field",
  "instance": "uci_<hex>",
  "part": "input",
  "event": "change",
  "updates": "value",
  "debug": { "value": "taras@example.com", "instance": "uci_<hex>" },
  "patches": []
}
```

The server echoes `dispatchId` on both success and error responses (when it was parseable) so clients can correlate request, lifecycle event, and reply.

**Error responses (safe JSON; never leak class/method names or stack traces):**

| Status | `reason` token | Trigger |
|---|---|---|
| 400 | `empty_body` | Request body is empty |
| 400 | `malformed_json` | Body is not valid JSON |
| 400 | `body_not_object` | Body is a list/scalar, not a JSON object |
| 400 | `missing_ctx` | `ctx` is missing or empty |
| 400 | `missing_dispatch_id` | `dispatchId` is missing or empty |
| 400 | `invalid_dispatch_id` | `dispatchId` fails the format check |
| 400 | `payload_not_object` | `payload` is a list/scalar |
| 400 | `forbidden_payload_field` | Payload smuggled a routing-flavored key (path included in message) |
| 403 | `invalid_signed_ctx` | Signature verify failed OR ctx expired |
| 403 | `updates_path_mismatch` | Signed `u` claim doesn't equal the registered `#[UiOn]` updates path |
| 403 | `interaction_forbidden` | `UiInteractionAuthorizerInterface::authorize()` returned `false` |
| 503 | `ui_replay_store_not_shared` | Production-like env + the bound replay store reports `isShared() === false`. Operator must set `CACHE_DRIVER` to a shared driver (e.g. `redis`). |
| 404 | `unknown_component` | Signed component doesn't exist in `UiComponentRegistry` |
| 404 | `unknown_part` | Signed part doesn't exist on the component |
| 404 | `unknown_event` | Signed (part, event) pair has no `#[UiOn]` |
| 409 | `duplicate_dispatch` | `(ctx, dispatchId)` already processed (replay guard) |
| 422 | `missing_claim_<key>` | Signed context missing required claim |
| 422 | `cannot_instantiate_component` | Component constructor requires DI args |
| 422 | `handler_error` | Handler threw a non-`UiInteractionException` |
| 422 | `invalid_handler_return` | Handler returned something other than void / array / `UiInteractionResult` |
| 422 | `invalid_patch` / `invalid_patch_op` / `patch_instance_mismatch` / `invalid_patch_value` / `invalid_patch_attribute` / `invalid_patch_target_part` / `invalid_patch_target_name` | A handler returned a `UiInteractionResult::patch(...)` that fails server-side patch validation. Errors carry safe reason tokens — no class/method leaks. |
| 500 | `internal_error` | Truly unexpected failure |

Additional forbidden payload keys for the response-patch slice (rejected with `400 forbidden_payload_field`): `patch`, `patches`, `target`, `selector`, `html`, `script`.

**`UiInteractionDispatcher` API:**

```php
final class UiInteractionDispatcher
{
    public function __construct(
        UiPayloadFieldGuard              $payloadGuard   = new UiPayloadFieldGuard(),
        UiPatchValidator                 $patchValidator = new UiPatchValidator(),
        UiReplayStoreInterface           $replayStore    = new InMemoryUiReplayStore(),
        UiInteractionAuthorizerInterface $authorizer     = new AllowAllUiInteractionAuthorizer(),
    );
    public function dispatch(string $ctx, string $dispatchId, array $payload): UiInteractionResult;
}
```

Trust boundary:
- The signed ctx is the **only** source of (component, instance, part, event, updates) identity. Mismatches between signed claims and registry metadata fail closed.
- The request body's `payload` is treated as arbitrary user data **after** `UiPayloadFieldGuard` has scrubbed any routing-flavored keys (the guard runs **before** signature verification so a malformed/tampered ctx still emits a 400 if the payload tries to smuggle a handler).
- `dispatchId` is client-supplied and used only for replay-key construction — handlers see it on `UiInteractionEvent::$dispatchId` for correlation but MUST NOT base authorization or routing decisions on it.
- When the handler returns `UiInteractionResult::patch([...])`, every patch is validated against the signed claims' `instance` — handlers can only patch their own component instance.

**Service bindings (default).** Platform UI ships three `#[SatisfiesServiceContract]`-bound defaults:

| Interface | Default implementation | Module |
|---|---|---|
| `UiReplayStoreInterface` | `CacheBackedUiReplayStore` | semitexa-platform-ui |
| `UiInteractionAuthorizerInterface` | `AllowAllUiInteractionAuthorizer` | semitexa-platform-ui |
| `UiFieldRuleRegistryInterface` | `DefaultUiFieldRuleRegistry` | semitexa-platform-ui |

The Semitexa container resolves both contracts at boot via `ServiceContractRegistry`. `UiDispatchHandler` declares them as `#[InjectAsReadonly]` protected properties — the container fills them, the handler never news them up in production. The dispatcher is constructed inside the handler with the injected dependencies; there is no longer any `withServices()` plumbing on the production path.

**Override seam.** An application registers its own implementation by declaring a class with `#[SatisfiesServiceContract(of: UiInteractionAuthorizerInterface::class)]` (or `UiReplayStoreInterface::class`) inside a module that "extends" `semitexa-platform-ui`. The contract registry picks the descendant-module winner, so the app's class replaces the default automatically — no per-handler wiring required.

**Replay store implementations.**

- `CacheBackedUiReplayStore` — **production default**. Backed by `Semitexa\Cache\Domain\Contract\CacheManagerInterface` under the `ui-dispatch-replay` namespace; inherits the cache's process-shared semantics. `isShared()` reports `true` when the bound cache driver is `redis`, `valkey`, or `memcached`. With `CACHE_DRIVER=array` (the framework default), each Swoole worker has its own in-memory cache → `isShared()` reports `false` → the dispatcher refuses to invoke handlers in production-like environments.
- `InMemoryUiReplayStore` — test/dev fallback. Always reports `isShared() === false`. Used only by tests that construct `UiDispatchHandler` directly without a container. Apps and modules MUST NOT wire this with `#[SatisfiesServiceContract]`; it carries no such attribute on purpose.

**Runtime guard.** Before claiming a replay key, `UiInteractionDispatcher` calls `$replayStore->isShared()`. In production-like environments (`APP_ENV` is `prod` or `production`), a `false` return aborts the dispatch with `503 ui_replay_store_not_shared`. The handler is never invoked. In other environments (`dev`, `staging`, `test`, …) the guard is a no-op so local development with the in-memory store continues to work. The check runs *after* ctx verification (so a tampered ctx still surfaces the documented `403 invalid_signed_ctx`) and *before* the replay claim (so an unsafe store never accumulates orphan keys).

Why signed `ctx` is reusable but `dispatchId` is single-use: the signed `ctx` carries identity (`c, i, p, e, u, iat, exp`) so the dispatcher can resolve handlers — re-issuing it on every keystroke would force a server round-trip per character. The `dispatchId` is the *attempt* identifier and exists exclusively for replay deduplication: each captured event mints a fresh `crypto.getRandomValues`-derived id, so a network race or double-click produces two distinct ids and both succeed, but an exact replay (`ctx, dispatchId` pair) is rejected at the replay claim.

Why shared replay cache is required: with `CACHE_DRIVER=array`, two requests carrying the same `(ctx, dispatchId)` that land on different Swoole workers both see "no claim yet" in their per-worker arrays and both succeed. With `CACHE_DRIVER=redis` (or `valkey`/`memcached`), the claim is observable across every worker on the same node. **The shipped configuration on `semitexa-pl` sets `CACHE_DRIVER=redis` in `.env` for this reason.**

**`UiInteractionEvent` DTO** passed to handlers:

```php
final readonly class UiInteractionEvent
{
    public string $componentName;
    public string $instanceId;
    public string $partName;
    public string $eventName;
    public ?UiValuePath $updatesPath;
    public array $payload;       // already guard-scrubbed
    public int $issuedAt;
    public int $expiresAt;
    public array $claims;        // raw signed claims (server-side only)
    public string $dispatchId;   // per-attempt id; correlation only — do NOT route on it
}
```

**`UiInteractionResult` DTO:**

```php
final readonly class UiInteractionResult
{
    public const KIND_ACK   = 'ack';
    public const KIND_PATCH = 'patch';
    public string $kind;
    public array $debug;
    /** @var list<UiResponsePatch> */
    public array $patches;
    public static function ack(array $debug = []): self;
    public static function patch(array $patches, array $debug = []): self; // empty list ↦ kind=ack
}
```

Handlers may also return `void` (mapped to `ack()`), a bare `array` (mapped to `ack($array)`), or an explicit `UiInteractionResult`.

**`UiResponsePatch` DTO:**

```php
final readonly class UiResponsePatch
{
    public const OP_SET_TEXT      = 'setText';
    public const OP_SET_VALUE     = 'setValue';
    public const OP_SET_ATTRIBUTE = 'setAttribute';
    public const ALLOWED_OPS;        // [setText, setValue, setAttribute]
    public const ALLOWED_ATTRIBUTES; // [aria-invalid, aria-describedby, data-state, ui-state]

    public string $op;
    public string $targetInstance;   // must match signed claims' instance
    public ?string $targetPart;      // resolved as [data-ui-part="<name>"] inside the root
    public ?string $targetName;      // resolved as [data-ui-patch-target="<name>"] inside the root
    public mixed  $value;            // scalar / null
    public ?string $attribute;       // setAttribute only; must be in ALLOWED_ATTRIBUTES
}
```

Targeting addresses (ALL scoped to a single component instance — no global/`document` selectors):

| target shape                          | resolves to                                                             |
| ---                                   | ---                                                                     |
| `{ instance }`                        | the component root                                                      |
| `{ instance, part }`                  | `[data-ui-part="<part>"]` inside the root                               |
| `{ instance, name }`                  | `[data-ui-patch-target="<name>"]` inside the root                       |

`UiPatchValidator` (server-side) enforces every rule above and ALSO that `target.instance === claims.i`. The frontend re-checks the same invariants before mutating the DOM.

**FieldComponent handler (returns a server-ack patch):**

```php
#[UiOn(part: 'input', event: 'change')]
public function onInputChanged(UiInteractionEvent $event): UiInteractionResult
{
    return UiInteractionResult::patch(
        patches: [
            new UiResponsePatch(
                op: UiResponsePatch::OP_SET_TEXT,
                targetInstance: $event->instanceId,
                targetPart: null,
                targetName: 'server-ack',
                value: 'Server received: ' . (string) $event->value(),
            ),
        ],
        debug: ['value' => $event->value(), 'instance' => $event->instanceId],
    );
}
```

The `server-ack` `<span data-ui-patch-target="server-ack">` is **opt-in** per render — only emitted when the caller passes `showServerAckTarget: true` to `component('platform.field', ...)`. When the target is absent, the frontend applier emits a `semitexa:ui-patch:failed` lifecycle event for that patch and does nothing — the DOM stays unchanged.

**Response JSON (success with patches):**

```json
{
  "ok": true,
  "handled": true,
  "kind": "patch",
  "component": "platform.field",
  "instance": "uci_<hex>",
  "part": "input",
  "event": "change",
  "updates": "value",
  "debug": { "value": "taras@example.com", "instance": "uci_<hex>" },
  "patches": [
    {
      "op": "setText",
      "target": { "instance": "uci_<hex>", "name": "server-ack" },
      "value": "Server received: taras@example.com"
    }
  ]
}
```

For ack-only responses the `patches` field is `[]` and `kind` stays `"ack"`.

### Frontend transport bridge (opt-in)

`window.SemitexaUi.transport.attach({ endpoint })` subscribes the capture pipeline to an HTTP endpoint. Until `attach` is called, the runtime makes **zero** network requests — `fetch(` lives only inside `transport.attach`'s closure body.

```js
// Opt-in transport hookup (per page).
const detach = window.SemitexaUi.transport.attach({ endpoint: '/__ui/dispatch' });
// later: detach();
```

Wire body sent on every capture: **exactly** `{ ctx, dispatchId, payload: { value } }`. The `dispatchId` is freshly minted per captured event with `crypto.getRandomValues` (format: `ui_evt_<32 hex>`), so a network race or double-click produces two distinct ids and both go through; only an *exact* `(ctx, dispatchId)` replay is rejected with `409`. Never component, instance, part, event, handler, method, class, endpoint, url, action, dispatcher fields.

Lifecycle CustomEvents on `document` (every detail carries `dispatchId` for correlation):
- `semitexa:ui-event:dispatching`  (before fetch; `detail = {captured, dispatchId, endpoint}`)
- `semitexa:ui-event:dispatched`   (on 2xx; `detail = {captured, dispatchId, status, response}`)
- `semitexa:ui-event:failed`       (on non-2xx or thrown; `detail = {captured, dispatchId, status?, response?, error?, phase}`)
- `semitexa:ui-patch:applied`      (one per successfully applied response patch; `detail = {patch, captured, index}`)
- `semitexa:ui-patch:failed`       (one per response patch that could not apply; `detail = {patch, captured, index, reason}`)

The bridge does **not** call `preventDefault` / `stopPropagation`.

### Frontend response-patch applier

When the server response includes a non-empty `patches` array, the bridge runs each patch through a tight safe applier with these invariants:

- Allowed ops are exactly `setText`, `setValue`, `setAttribute`. Anything else → `semitexa:ui-patch:failed` with `reason: "invalid_op"`.
- `setAttribute` is gated by the `aria-invalid` / `aria-describedby` / `data-state` / `ui-state` allow-list.
- The component root is located by `document.querySelector('[data-ui-component-instance-id="<safe-id>"]')`. The patch target is then resolved by `rootEl.querySelector(...)` for `[data-ui-part="<safe-name>"]` or `[data-ui-patch-target="<safe-name>"]`. The applier never accepts a caller-provided CSS selector and never traverses outside the component root.
- The applier uses `textContent`, `element.value`, `setAttribute` / `removeAttribute` only. It never touches `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval`, or the `Function` constructor.
- Patch identifiers (instance, part, name) must match `/^[A-Za-z_][A-Za-z0-9_-]*$/`. Anything else fails before any DOM lookup.
- One failed patch never breaks the rest of the batch — each patch fires its own `:applied` or `:failed` event.
- The bridge also double-checks `target.instance === captured.instanceId` before applying — defense in depth against a tampered response.

### SSE server-push channel

> **Retired.** The standalone platform-ui patch-stream subsystem (its own
> route, channel-token auth, per-channel Redis queue, connection limiter and
> subscription authorizer) has been **removed**. All UI streaming now rides the
> single canonical KISS stream `GET /__semitexa_kiss` (served by SSR's
> `AsyncResourceSseServer::handleSse`). The page opens that one stream via
> `ui_page_sse_session_meta(...)`; components never open their own.

What survives is the part that was always shared: there is **one** patch shape
(`UiResponsePatch`) and **one** safe applier on the frontend. Server-pushed
patches arrive as canonical typed `ui.patch` frames on the KISS `EventSource`
and flow through the same `applyOnePatch` engine the request/response transport
uses. The frontend bridge (`window.SemitexaUi.sse.attach({url})`) is only ever
attached to a `/__semitexa_kiss` URL; it still emits the `semitexa:ui-sse:*`
lifecycle CustomEvents on `document`. The retired `{v, patches[]}` envelope is
tolerated defensively but is no longer produced.

### Field validation (server-side rules DSL)

`FieldComponent` validates its value through a small server-side rules DSL on the `input.change` event. Rules are declared per-instance via the `rules` prop, normalized at render time, **signed into the event manifest's ctx claim** as `cfg.r`, and read back from the verified ctx by the handler. The client cannot change rules through the request payload — the field guard rejects `payload.rules` / `payload.r` / `payload.cfg` / `payload.config`.

**Rule spec DSL** (developer-facing, in `rules` prop):

```php
component('platform.field', {
    label: 'Username',
    name: 'username',
    required: true,
    showValidationTarget: true,
    rules: [
        'required',           // parameterless rule: string form
        ['minLength', 3],     // parametrized rule: [name, params…]
        ['maxLength', 20],
    ],
})
```

**Built-in rules** (default registry; apps can register more — see "Custom rule registry" below):

| Rule name | Params | Behaviour | Message on failure |
|---|---|---|---|
| `required` | — | Rejects empty / whitespace-only. | `This field is required.` |
| `minLength` | int min ≥ 0 | Trims, compares `mb_strlen` ≥ min. **Empty values pass** — pair with `required`. | `Please enter at least {min} characters.` |
| `maxLength` | int max ≥ 0 | Compares `mb_strlen` ≤ max. Does NOT trim. Empty passes. | `Please enter no more than {max} characters.` |
| `sameAsField` | string `siblingFieldName`, optional string `customMessage` | Cross-field comparator. Compares the current trimmed scalar value against `formValue(siblingFieldName)`. Both empty → pass. Current non-empty + sibling missing → `Please complete the related field first.` (sentinel, not overridable). Mismatch → `customMessage` if provided, else `Values must match.`. See "Cross-field validation" below. | `Values must match.` (default) |

**Ordering**: first-failure-wins. The first rule whose `validate()` returns a non-null result short-circuits the pipeline. When all rules pass the validator returns a valid result with the configured success message (default: `Looks good.`).

**Rule interface** — `Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface`:

```php
interface UiFieldValidationRuleInterface
{
    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult;
}
```

Implementations MUST be pure: no IO, no globals, no Twig, no services. The validator (`UiFieldValidator::validate(value, rules, context)`) is stateless and runs sync — async / cross-field rules are future-work.

**Responsibility split** (this slice formalised the seam):

- **Parser** (`UiFieldRuleParser`) — security perimeter for the DSL surface. Rejects closures, callables, service names, class FQCNs, non-scalar parameters, non-list outer shape, non-string rule names. **Does NOT validate rule names against the registry** (that's a registry concern). `parseAll()` returns specs after the structural checks; `parseAllToWire()` additionally invokes the registry to validate names + params at render time before emitting the wire shape. The constructor requires a `UiFieldRuleRegistryInterface` explicitly — there is no silent fallback to `DefaultUiFieldRuleRegistry`, so a caller wired against the wrong registry fails loudly instead of validating against the wrong rule set.
- **Registry** (`UiFieldRuleRegistryInterface`) — single source of truth for which rule names exist, what their parameters look like, and which concrete class implements each. The default (`DefaultUiFieldRuleRegistry`) owns the three built-ins. Apps replace the registry via `SatisfiesServiceContract` to add their own rules.
- **Rule object** (`UiFieldValidationRuleInterface`) — pure value-level check, returns `null` for pass / `UiFieldValidationResult::invalid(...)` for fail.

Malformed specs throw `UiFieldValidationRuleException` at render time so misconfigurations surface in dev as a clear Twig error. At dispatch time the handler maps the exception to a safe `422 invalid_validation_rule` response without leaking class FQCNs.

**Custom rule registry** (this slice). Apps add their own rules by binding a custom `UiFieldRuleRegistryInterface` implementation:

```php
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\PlatformUi\Application\Service\Validation\DefaultUiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;

#[SatisfiesServiceContract(of: UiFieldRuleRegistryInterface::class)]
final class AppFieldRuleRegistry implements UiFieldRuleRegistryInterface
{
    private DefaultUiFieldRuleRegistry $builtins;

    public function __construct()
    {
        // Compose with the default to inherit required / minLength / maxLength.
        $this->builtins = new DefaultUiFieldRuleRegistry();
    }

    public function resolve(UiFieldRuleSpec $spec): UiFieldValidationRuleInterface
    {
        return match ($spec->name) {
            'slug'   => new SlugRule(),
            'domain' => new DomainRule(),
            default  => $this->builtins->resolve($spec),
        };
    }

    public function knownRuleNames(): array
    {
        return [...$this->builtins->knownRuleNames(), 'slug', 'domain'];
    }
}
```

Custom rule names are signed into `cfg.r` exactly like built-ins — the wire shape is shape-agnostic about which names a registry knows about. The security perimeter contract is unchanged: **never** instantiate a class derived from `$spec->name` via reflection / `class_exists` / service lookup. Use a fixed `match` (or equivalent allow-list) so an attacker can't smuggle FQCNs through a rule name.

**Override granularity (this slice — full registry replacement only)**: apps register one registry that wins under the contract registry's module-order rule. Multi-provider rule contribution (several modules each adding their own rules without coordinating) is future work — track it under "Future runtime steps".

**FieldComponent integration** (DI-resolved as of this slice). The container-bound `UiFieldRuleRegistryInterface` is now plumbed through every real runtime path:

- **Boot**: `BootPlatformUiRegistryListener` is instantiated by the container with the container-bound winner of `UiFieldRuleRegistryInterface`. On `WorkerStartAfterContainer` the listener calls `UiFieldRuleRegistry::setActive($registry)`, stashing the active registry in a worker-scoped static holder (same pattern as `UiPrimitiveRegistry` / `UiComponentRegistry`).
- **Render time**: the `ui_field_rules()` Twig helper instantiates `UiFieldRuleParser` with `UiFieldRuleRegistry::getActive()`. Custom rule names from a bound registry now pass through `rules:` props at template compile time.
- **Dispatch time**: `UiDispatchHandler` injects `UiFieldRuleRegistryInterface` via `#[InjectAsReadonly]` and passes it to `UiInteractionDispatcher` (new optional `ruleRegistry` ctor arg). After the dispatcher instantiates a component, it checks `instanceof UsesUiFieldRuleRegistry` and calls `withFieldRuleRegistry($activeRegistry)`. `FieldComponent` implements that interface — its `onInputChanged()` resolves the wire-shape rules through the registry the dispatcher provided.

**The `UsesUiFieldRuleRegistry` interface** (opt-in bridge):

```php
interface UsesUiFieldRuleRegistry
{
    public function withFieldRuleRegistry(UiFieldRuleRegistryInterface $registry): static;
}
```

Why a setter-style bridge and not constructor injection: `FieldComponent` is still instantiated outside the DI container (by `UiInteractionDispatcher::instantiate()` via reflection's `newInstance()`). Documented in the package boundary audit (gray area G1). Once Semitexa lands container-managed component instances, the bridge can drop in favour of `#[InjectAsReadonly]` directly — the public interface keeps the same name. Components that don't need validation rules don't implement the interface; the dispatcher's `instanceof` check skips them.

**Standalone fallback**: code paths that bypass bootstrap (unit tests constructing `FieldComponent` directly, or `FieldComponent::validate(string)` called for ad-hoc validation) lazily-default to a fresh `DefaultUiFieldRuleRegistry` via `UiFieldRuleRegistry::getActive()`. The behaviour matches "production with no custom binding" — only built-ins resolve.

**Test seam**: `UiFieldRuleRegistry::setActive($custom)` overrides the active registry without going through the container; `reset()` restores the lazy-default. Use this to drive end-to-end paths (render-time `ui_field_rules` + dispatch-time `FieldComponent`) with a custom rule set in tests. See `tests/Integration/UiFieldRuleRegistryWiringTest.php` for the full end-to-end pattern.

**Signed ctx**: the event manifest now carries an optional `cfg` claim (server-trusted per-event configuration). `FieldComponent`'s template computes the wire spec via `ui_field_rules(rules)` and threads it into `ui_event_manifest()`:

```twig
{%- set _wire = ui_field_rules(rules|default([])) -%}
{%- set _cfg = _wire is empty ? {} : {'input.change': {'r': _wire}} -%}
{{ ui_event_manifest(_ui_instance, null, _cfg) }}
```

The dispatcher's HMAC verification authenticates the rules; tampering with `cfg.r` breaks the signature and surfaces as `403 invalid_signed_ctx`. The handler reads the verified rules via `UiInteractionEvent::rules()`.

**Backward compatibility**: a FieldComponent rendered without the `rules` prop falls back to `FieldComponent::DEFAULT_RULES` (`['required', ['minLength', 3]]`), preserving the demo behaviour the previous slice shipped. Existing tests, manifests built before this slice (without `cfg.r`), and external callers that don't know about rules all continue to work.

**Result type** — `Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult`:

```php
final readonly class UiFieldValidationResult
{
    public const STATE_VALID = 'valid';
    public const STATE_INVALID = 'invalid';
    public const VALIDATION_TARGET_NAME = 'validation-message';

    public string  $state;     // 'valid' | 'invalid'
    public ?string $message;

    public static function valid(?string $message = null): self;
    public static function invalid(string $message): self; // message MUST be non-empty
    public function isValid(): bool;
    public function toPatches(string $instanceId): array;  // list<UiResponsePatch>
}
```

**Result type** — `Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult`:

```php
final readonly class UiFieldValidationResult
{
    public const STATE_VALID = 'valid';
    public const STATE_INVALID = 'invalid';
    public const VALIDATION_TARGET_NAME = 'validation-message';

    public string  $state;     // 'valid' | 'invalid'
    public ?string $message;

    public static function valid(?string $message = null): self;
    public static function invalid(string $message): self; // message MUST be non-empty
    public function isValid(): bool;
    public function toPatches(string $instanceId): array;  // list<UiResponsePatch>
}
```

`toPatches()` emits the existing `UiResponsePatch` shape — no new operations, no new attributes, no new targets. For an **invalid** result:

1. `setAttribute` on the `input` UiPart: `attribute=aria-invalid`, `value='true'`.
2. `setAttribute` on the `input` UiPart: `attribute=ui-state`, `value='invalid'`.
3. `setText` on the `validation-message` named target: the diagnostic message.

For a **valid** result the first patch sets `value=null` (the applier calls `removeAttribute` for `null`), the second sets `ui-state=valid`, and the third sets the positive message. A valid result with `message=null` skips the third patch entirely.

**Render-time opt-in**: set `showValidationTarget: true` on `FieldComponent` to render `<span data-ui-patch-target="validation-message" id="<field-id>-validation" aria-live="polite">`. When this prop is set, the input's server-rendered `aria-describedby` is automatically extended to include the validation-message id (space-separated list per the HTML spec) so screen readers announce the diagnostic alongside the field's existing help/error text.

**Handler contract**: `FieldComponent::onInputChanged()` reads the signed rule list from `$event->rules()`, resolves them via `UiFieldRuleParser::resolveFromWire()`, runs them through `UiFieldValidator`, calls `toPatches()` against `$event->instanceId`, and appends the legacy `server-ack` setText (preserved so the older "Backend dispatch" demo keeps working). The dispatcher's `UiPatchValidator` accepts every patch because each one pins to `$event->instanceId`, uses an allow-listed op, and uses an allow-listed attribute name.

**What this slice does NOT introduce**: a form engine, form submission, multi-field rules, async validation, schema validation, persistence, a client-side mirror, a custom-rule registry (apps can only use the three built-ins for now), or any new patch operation. Future-work items list how those layers can sit on top of this seam.

### Cross-field validation (`sameAsField`)

`sameAsField` is the first cross-field built-in. It compares the current field's value against a sibling field's value read from a sanitised client-submitted snapshot.

```php
component('platform.field', {
    label: 'Access code',
    name: 'access_code',
    rules: ['required', ['minLength', 4]],
    showValidationTarget: true,
})

component('platform.field', {
    label: 'Confirm access code',
    name: 'confirm_access_code',
    rules: [
        'required',
        ['sameAsField', 'access_code', 'Codes must match.'],
    ],
    showValidationTarget: true,
})
```

**Behaviour**:

- Comparison is on the trimmed scalar projection of each side. `int 1` and `string "1"` compare equal — JSON transport may stringify scalars.
- Both sides trim to empty → pass. Pair with `required` if empties should fail.
- Current non-empty + sibling missing from the snapshot → `Please complete the related field first.` (sentinel diagnostic; custom message does NOT override this case).
- Values differ → `customMessage` if provided, else `Values must match.`.
- Sibling name must match the safe identifier shape `[A-Za-z_][A-Za-z0-9_-]*` — the registry rejects bad shapes at resolve time.

**Wire shape (`payload.form.values` snapshot)**:

```jsonc
{
  "ctx": "sc1.…",
  "dispatchId": "ui_evt_…",
  "payload": {
    "value": "abcd",                                  // current field value
    "form": {                                         // NEW, optional
      "values": {                                     // NEW
        "access_code": "abcd",
        "confirm_access_code": "abcd"
      }
    }
  }
}
```

Sanitised by `UiFormPayloadSnapshot` at the dispatch boundary:

- Keys must match `[A-Za-z_][A-Za-z0-9_-]*` (same shape `data-ui-field-name` uses).
- Values must be scalar or `null`. Arrays / objects → `400 invalid_form_snapshot_value`.
- At most **50** keys per snapshot → `400 form_snapshot_too_large`.
- Each scalar value bounded to **4096** characters (mb-length) → `400 form_snapshot_value_too_long`.
- `payload.form.rules` / `payload.form.cfg` / any other routing-flavored key anywhere in the payload tree → `400 forbidden_payload_field` (existing `UiPayloadFieldGuard` recursive scan).

**Field-name policy note**: because `UiPayloadFieldGuard` walks the entire payload tree and rejects routing-flavored keys (`event`, `handler`, `method`, `class`, `component`, `instance`, `part`, `updates`, `endpoint`, `url`, `route`, `action`, `controller`, `callback`, `dispatcher`, `payloadclass`, `authzscope`, `backendhandler`, `patch`, `patches`, `target`, `selector`, `html`, `script`, `dispatchid`, `requestid`, `eventid`, `rules`, `r`, `cfg`, `config`), a developer-defined form field named with one of those tokens (case-insensitive, hyphen/underscore-insensitive) is rejected even inside `payload.form.values`. This is the "safest" policy from the slice spec — the small UX cost (rename one field) buys uniform protection against future smuggling shapes.

**Signed rule config**:

- The rule spec list, including the sibling field name, is signed into `cfg.r` at render time. The client CANNOT change which field a rule targets — tampering breaks the HMAC.
- The current field's safe `name` prop is additionally signed into `cfg.fn`. The handler self-merges `$event->value()` into `$event->formValues[$fn]` so self-referencing rules behave predictably AND so a frontend that forgot to include the current field still sees the canonical "current" value.
- `payload.rules` / `payload.r` / `payload.cfg` are rejected with `400 forbidden_payload_field` by the existing guard.

**Validation context**:

`UiFieldValidationContext` now carries a `formValues` map (sanitised). Rules read sibling values through `$context->formValue('siblingName')`. The map is **untrusted UX-feedback input** — never authoritative state.

**Trust boundary — re-read with every change**:

1. **Rules are signed.** They cannot be added, removed, or retargeted by the client.
2. **Snapshot values are client-submitted.** A user could lie about the sibling value to silence a validation message. `sameAsField` (and any future cross-field rule) is therefore **UX-feedback only**.
3. **Final persistence is out of scope for this slice.** When the real submit pipeline lands, it MUST revalidate the whole submitted payload against authoritative state (server-rendered fields, persistent form state, or fresh queries) before touching the database. The cross-field result returned by `/__ui/dispatch` is *not* a green light to persist.

**Debug surface**:

Dispatch responses surface the SHAPE of the snapshot in `debug.form.{snapshotFields, snapshotSize}` — key list + count. **Values never appear in `debug`** so operator logs stay uniform regardless of field sensitivity.

**Sensitive value handling**:

The existing dispatch handler still emits a `server-ack` setText patch with the dispatched value (preserved from the original dispatch demo). That makes `sameAsField` unsafe to use with password-shaped fields out of the box — the playground demo uses generic `access_code` / `confirm_access_code` fields instead. A future slice may introduce per-field "sensitive" metadata that suppresses the ack echo; this slice deliberately does not because the broader sensitive-field problem (logs, debug surfaces, error messages) needs its own design pass.

**Limitations of this slice**:

- One cross-field rule (`sameAsField`). No `greaterThan`, `lessThan`, `requiredIf`, `compareDate`, etc. — they are obvious follow-ups but kept out to keep the trust-boundary discussion focused.
- No async / database / remote validation.
- No multi-step forms, no real submit, no persistence, no CSRF.
- No client-side rule mirror. `UiFieldValidator` stays server-only.
- No cross-tab state sync.

### Form composition (`platform.form`)

A minimal composition container for grouping fields and surfacing a *client-local* aggregate of their server-validated state. **Not a form engine** — no real submit, no persistence, no CSRF, no server-side form-state store, no cross-field rules, no async validation. Field rules continue to run server-side through `FieldComponent`'s existing pipeline; the form layer never re-evaluates rules.

```twig
{% set _fields %}
    {{ component('platform.field', {
        label: 'Username',
        name: 'username',
        showValidationTarget: true,
        rules: ['required', ['minLength', 3], ['maxLength', 20]],
    }) }}
    {{ component('platform.field', {
        label: 'Display name',
        name: 'display_name',
        showValidationTarget: true,
        rules: [['maxLength', 12]],
    }) }}
{% endset %}

{{ component('platform.form', {
    title: 'Sign-up details',
    description: 'Validation runs server-side; the aggregate is client-local.',
    showStatus: true,
    showSubmit: true,
    submitText: 'Create account',
}, {
    content: _fields,
}) }}
```

**Component props** (all optional):

| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | string | `null` | Rendered as `<h2 ui-text="title">` when set. |
| `description` | string | `null` | Muted paragraph beneath the title. |
| `showStatus` | bool | `true` | Renders the `data-ui-patch-target="form-status"` target. |
| `statusInitialMessage` | string | `'No fields validated yet.'` | Text shown before any field has validated. |
| `showSubmit` | bool | `false` | Renders a `platform.button` shell — **visual only**, no submit pipeline. |
| `submitText` | string | `'Submit'` | Button label. |
| `ariaLabel` | string | `null` | Accessible name when the visual title is absent. |

**Slot**: `content` — caller-provided markup, typically one or more `FieldComponent`s. Passed as the third argument of `component('platform.form', props, { content: … })`.

**Rendered shape**:

```html
<div data-ui-component="platform.form"
     data-ui-component-instance-id="uci_<16hex>"
     data-ui-form-aggregate="1"
     ui-component="form"
     sx-layout="stack" sx-gap="4"
     role="group">
  <h2 ui-text="title">Sign-up details</h2>
  <p ui-text="muted">Validation runs server-side…</p>
  <div data-ui-form-fields sx-layout="stack" sx-gap="3">
    <!-- caller's content slot — usually one or more FieldComponents -->
  </div>
  <div data-ui-patch-target="form-status" aria-live="polite" role="status" ui-text="muted">
    No fields validated yet.
  </div>
  <!-- optional visual submit -->
</div>
```

**Field-name marker**. `FieldComponent` now stamps `data-ui-field-name="<name>"` on its root **only when the `name` prop matches the safe identifier shape `[A-Za-z_][A-Za-z0-9_-]*`** — the same pattern the patch validator accepts for target names. Anything else is dropped silently. Anonymous fields fall back to the field's `data-ui-component-instance-id` as the aggregate key so they still aggregate distinctly.

**Aggregation runtime** (in `event-runtime.js`):

After every successful dispatch response, the transport bridge calls `updateFormAggregate(response, captured)`:

1. Reads `response.debug.validation.{state,message}` — the shape `FieldComponent::onInputChanged()` already returns. **No new wire shape.**
2. Resolves the enclosing form root by walking up the DOM from the field root, looking for the nearest ancestor matching `[data-ui-form-aggregate="1"][data-ui-component-instance-id]`. If no form root is found, aggregation is a graceful no-op.
3. Updates a module-local map `{ formInstance → { fields: { fieldKey → {state, message} }, lastAt } }`. The key is `data-ui-field-name` when set, the field's instance id otherwise. **Repeated updates for the same field overwrite — never duplicate — the entry.**
4. Computes a summary: `{knownCount, invalidCount, validCount, aggregateState, message}`. `aggregateState` is `'invalid'` if any known field is invalid, `'valid'` if all known fields are valid, `'pending'` before the first field validates.
5. Synthesises **two patches** targeting the form root:
    - `{op: 'setText', target: {instance: <form>, name: 'form-status'}, value: <message>}`
    - `{op: 'setAttribute', target: {instance: <form>}, attribute: 'ui-state', value: <aggregateState>}`
6. Feeds them through the existing `applyOnePatch` — the **same safe applier** the dispatch transport and the SSE bridge use. No new mutation engine, no new patch op, no expansion of the attribute allow-list.
7. Dispatches a `semitexa:ui-form:aggregate` CustomEvent on `document` so consumers can mirror the snapshot without re-deriving it.

**Public API** (kept narrow):

- `window.SemitexaUi.forms.snapshot(formInstance?)` — returns `{formInstance, fields, summary}` for one form, or a map keyed by form instance when called without an argument. `null` for an unknown id.
- `window.SemitexaUi.forms.reset(formInstance?)` — drops one form's aggregate, or all of them.

**Aggregation status messages** (verbatim contract, pinned by tests):

| Aggregate state | Message |
|---|---|
| no fields known | `No fields validated yet.` |
| 1 invalid field | `1 field needs attention.` |
| N invalid fields | `<N> fields need attention.` |
| 1 valid field, 0 invalid | `1 field validated — looks good.` |
| N valid fields, 0 invalid | `All <N> validated fields look good.` |

**What this slice does NOT introduce**:

- No real form submit, no persistence, no session-backed form state, no server-side form state store.
- No CSRF / submit pipeline / file uploads / multi-step navigation.
- No cross-field rules, no field dependency rules, no schema validation.
- No client-side rule mirror. `UiFieldValidator` stays server-only.
- No async validation, no WebSockets, no new SSE semantics.
- No HTML patches, no `innerHTML`, no `eval`, no arbitrary selectors. Same allow-list as before.
- No new `UiResponsePatch` op. Aggregation reuses `setText` + `setAttribute`.
- No `disabled` attribute mutation — `disabled` remains off the patch allow-list on purpose. The submit button is visual only in this slice.
- No persistent server-side form snapshot. State is per-page-load, per-tab. A reload resets the aggregate; broadcasting (e.g. via SSE) is future work.

### Form submit pipeline (authoritative final validation)

FormComponent now declares `#[UiOn(part: 'form', event: 'submit')]` so the dispatcher routes a verified submit ctx to `FormComponent::onSubmit`. Submit is the **authoritative** counterpart to the input-change cross-field path: it revalidates every signed field rule against the submitted snapshot before returning a result.

**Caller API**:

```twig
{{ component('platform.form', {
    title: 'Submit validation demo',
    showStatus: true,
    showSubmit: true,
    submitText: 'Validate form',
    fields: [
        {
            name: 'access_code',
            label: 'Access code',
            required: true,
            rules: ['required', ['minLength', 4]],
        },
        {
            name: 'confirm_access_code',
            label: 'Confirm access code',
            required: true,
            rules: [
                'required',
                ['sameAsField', 'access_code', 'Codes must match.'],
            ],
        },
    ],
}, { content: _fields }) }}
```

The `fields` prop is the **server-owned** definition of which fields participate in submit, with what labels and which rules. It is normalised through `UiFormSubmitConfigParser` at render time (delegating to the active `UiFieldRuleParser`) and signed into `cfg.f` of the submit ctx.

#### `autoFields` — derive cfg.f from slotted FieldComponents

`FormComponent` also accepts `autoFields: true`, which derives `cfg.f` directly from the FieldComponents rendered inside the form's `content` slot — no manual `fields` prop required. Mechanism:

1. The form template opens a **render-scope collector frame** (`UiFormSubmitDefinitionCollector::open()`) **before** rendering the content slot.
2. The slot output is captured into a Twig variable, so every slotted FieldComponent runs while the frame is open.
3. Each FieldComponent's template calls `ui_form_field_register({ n, i, r, l, q })` after computing its own instance id and normalised rule wire. Safe-named fields (matching `[A-Za-z_][A-Za-z0-9_-]*`) register into the active frame; unsafe / anonymous names render normally but do not register.
4. After the slot has rendered, `ui_form_resolve_submit_fields(frameToken, autoFields, manualFields)` closes the frame, returns the collected wire shape, and the form template signs it into `cfg.f` as usual.

The collector is a **process-local stack** of frames keyed by an opaque token. Nested forms push their own frame; outer fields stay isolated from inner ones. The token returned from `open()` must match the one passed to `close()` — a mismatch fails loud so a template bug surfaces rather than silently leaking definitions across renders. `reset()` is the explicit recovery hook for tests and worker boundaries.

**Caller-side DX**:

```twig
{% set _fields %}
    {{ component('platform.field', {
        name: 'access_code',
        label: 'Access code',
        required: true,
        showValidationTarget: true,
        rules: ['required', ['minLength', 4]],
    }) }}
    {{ component('platform.field', {
        name: 'confirm_access_code',
        label: 'Confirm access code',
        required: true,
        showValidationTarget: true,
        rules: ['required', ['sameAsField', 'access_code', 'Codes must match.']],
    }) }}
{% endset %}

{{ component('platform.form', {
    showStatus: true,
    showSubmit: true,
    submitText: 'Validate form',
    autoFields: true,
}, { content: _fields }) }}
```

Field instance ids in cfg.f.i are the **same** uci_… ids emitted on each FieldComponent's `data-ui-component-instance-id` — no caller-side bookkeeping. Per-field submit projection works automatically.

**autoFields behaviour matrix**:

| `autoFields` | `fields` prop | Result |
|---|---|---|
| `true` | not provided or empty | cfg.f derived from collected FieldComponents |
| `true` | non-empty | **throws `UiComponentRegistryException` at render time** (ambiguous; pick one) |
| `false` (default) | non-empty | cfg.f parsed from manual `fields` (legacy path) |
| `false` (default) | not provided or empty | no cfg.f signed; submit handler returns `no_signed_fields` |

The collector frame opens unconditionally (so a no-op happens whether or not autoFields is on) and always closes — manual-mode renders simply collect an empty list and discard it.

#### `submitAction` — typed submit-action seam

`FormComponent` accepts an optional `submitAction` prop naming a server-registered action. When the form's authoritative validation passes, the form template-time signed action name (`cfg.a`) is resolved through `UiFormSubmitActionRegistryInterface`, the action is invoked with a typed `UiFormSubmitActionContext`, and its `UiFormSubmitActionResult` becomes the final form-status + ui-state.

```twig
{{ component('platform.form', {
    autoFields: true,
    showSubmit: true,
    submitText: 'Validate form',
    submitAction: 'platform.demo.accept',
}, { content: _fields }) }}
```

**Action contract** (`Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface`):

```php
interface UiFormSubmitActionInterface
{
    public function name(): string;
    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult;
}
```

**Action context** — read-only value object carrying:
- `formInstanceId` (the form's render-time uci_ id);
- `actionName` (the resolved registry name);
- `dispatchId` (correlation only — dispatcher already consumed it for replay protection);
- `values` (sanitised `payload.form.values` snapshot — still client-controlled, treat as untrusted);
- `fields` (signed `UiFormSubmitFieldDefinition` list);
- `submitResult` (authoritative validation summary — always `valid` by the time `handle()` is called).

The context never includes the raw `SignedContext` token, the `Request` object, container services, or secrets.

**Action result** — `{accepted: bool, message: string, debug?: array, extraPatches?: list<UiResponsePatch>}`. Two factories:
- `UiFormSubmitActionResult::accepted($message)` → `accepted=true`;
- `UiFormSubmitActionResult::rejected($message)` → `accepted=false`.

The message becomes `setText form-status`; `accepted` maps to `setAttribute ui-state = valid|invalid`. Extra patches are validated through the same `UiPatchValidator` the rest of the pipeline uses — actions cannot target unsigned instances or use unallow-listed ops.

**Registry contract** (`UiFormSubmitActionRegistryInterface`):

```php
interface UiFormSubmitActionRegistryInterface
{
    public function resolve(string $actionName): UiFormSubmitActionInterface;
    public function knownActionNames(): array;
}
```

Apps register their own implementation with `#[SatisfiesServiceContract(of: UiFormSubmitActionRegistryInterface::class)]` in a module that "extends" `semitexa-platform-ui`. Compose with `DefaultUiFormSubmitActionRegistry` to inherit `platform.demo.accept`:

```php
#[SatisfiesServiceContract(of: UiFormSubmitActionRegistryInterface::class)]
final class AppFormSubmitActionRegistry implements UiFormSubmitActionRegistryInterface
{
    public function __construct(private DefaultUiFormSubmitActionRegistry $builtins = new DefaultUiFormSubmitActionRegistry()) {}

    public function resolve(string $actionName): UiFormSubmitActionInterface
    {
        return match ($actionName) {
            'app.signup.preview' => new SignupPreviewAction(),
            default              => $this->builtins->resolve($actionName),
        };
    }

    public function knownActionNames(): array
    {
        return [...$this->builtins->knownActionNames(), 'app.signup.preview'];
    }
}
```

The registry MUST resolve through a fixed `match` — NEVER `new $name(...)` or `class_exists($name)`. Custom registries MUST honour the same perimeter.

**Behaviour matrix**:

| Validation | `submitAction` | Result |
|---|---|---|
| valid | absent | existing accepted summary (`"Form is valid. Submit accepted."`); no `debug.action` key |
| valid | registered name | action invoked; `form-status` ← action message; `ui-state` ← `valid` if accepted, `invalid` if rejected; `debug.action` carries `{name, accepted, message}` |
| invalid | absent | existing invalid summary |
| invalid | registered name | action **not invoked**; existing invalid summary; `debug.action` ← `{name, invoked: false, reason: 'validation_invalid'}` |
| any | unknown name | render-time `UiFormSubmitActionException` (the form fails to render in dev) |
| any | unsafe shape | render-time `UiFormSubmitActionException` |
| any | tampered `cfg.a` | dispatcher returns `403 invalid_signed_ctx` (HMAC fails) |
| any | client payload smuggling | `400 forbidden_payload_field` (UiPayloadFieldGuard) |

`payload.action`, `payload.submitAction`, `payload.form.submitAction` and case-variant siblings (`submit_action`, `submit-action`) are all forbidden in the request body.

**Built-in actions**:

- **`platform.demo.accept`** (`PlatformDemoAcceptAction`) — inert. Returns `UiFormSubmitActionResult::accepted('Demo action accepted. No data was persisted.')` with safe debug counts only. Does not persist, send email, redirect, or echo raw values.
- **`platform.demo.storeContact`** (`PlatformDemoStoreContactAction`) — first persistent demo. Allow-lists `contact_name` / `contact_message` / `contact_topic` from the sanitised snapshot, trims them, drops empties, and saves a `UiFormDemoSubmissionRecord` through `UiFormDemoSubmissionRepositoryInterface` (cache-backed, 24h TTL). Returns `'Demo submission saved. No external side effects were performed.'` with `debug.action.detail = {stored, submissionId, storedFieldCount}` (never raw values).
- **`platform.demo.storeContactDb`** (`PlatformDemoStoreContactDbAction`) — first **database-backed** demo. Identical sanitisation contract as the cache-backed sibling, but persists through `UiFormDatabaseDemoSubmissionRepositoryInterface` — production-bound to `UiFormDemoSubmissionDbRepository` (ORM-backed, `#[SatisfiesRepositoryContract]`, table `platform_ui_demo_submissions`). Returns `'Demo submission saved to the database. No external side effects were performed.'` with `debug.action.detail = {stored, submissionId, storage: "database", storedFieldCount}`. Durable until you delete the row — still demo storage, not a real CRM.

**First project-side business action** (lives outside this package, in the UiPlayground module):

- **`ui-playground.lead.store`** (`Semitexa\Modules\UiPlayground\Application\Service\Submit\Action\UiPlaygroundStoreLeadAction`) — first **project-owned** business action. Proves the seven-gate pipeline is extensible from the project side without touching the package. Allow-lists `lead_name` / `lead_company` / `lead_message`, trims, drops empties + non-scalars, generates a `uilead_<16hex>` id, and saves a `UiPlaygroundLeadSubmissionRecord` through the project-side `UiPlaygroundLeadSubmissionRepositoryInterface` (ORM-backed, table `ui_playground_leads`). Returns `'Lead request saved. No email or external side effects were performed.'` with `debug.action.detail = {stored, leadId, storage: "database", storedFieldCount}` (never raw values). No email, no redirect, no external API, no export, no edit/delete, no async — those are explicit future slices.

  **Read-only admin listing** at `GET /ui-playground/admin/leads`. Mirrors the package-side demo-submissions diagnostic listing shape: project-side `LeadAdminPayload` / `LeadAdminHandler` / `LeadAdminResponse` + `lead-admin.html.twig`. Reads through `UiPlaygroundLeadSubmissionRepositoryInterface` (`DEFAULT_RECENT_LIMIT = 25`, `MAX_RECENT_LIMIT = 100`, newest-first via `ORDER BY submitted_at DESC, id DESC`). View-model is `{id, actionName, formInstanceId, submittedAt, leadName, leadCompany, leadMessagePreview (≤160+ellipsis), storedFieldCount}` — `values_json` never reaches the template; tokens / ctx / dispatchId / debug never reach the page. **No search, no edit, no delete, no export** — explicit non-goals.

  **Cursor pagination + search/filter**. Same keyset-pagination + filter-fingerprint pattern as the package-side demo listing, project-namespaced to avoid coupling to demo-submission naming:

  | Query param | Default | Behaviour |
  |---|---|---|
  | `limit`  | `25` (`DEFAULT_RECENT_LIMIT`) | Clamped server-side to `[1, MAX_RECENT_LIMIT]` (`100`). Non-numeric / empty / negative / whitespace falls back to the default. `0` clamps up to `1`. |
  | `cursor` | _(absent)_ | Opaque base64url token returned by the previous page's `nextCursor`. Empty / missing → first page. Malformed → HTTP 400 `invalid_cursor` with the safe template state and no repository read. Cursor binds to the filter combination it was issued under. |
  | `q`      | _(absent)_ | Bounded diagnostic-grade search term (max `100` UTF-8 characters; longer → HTTP 400 `invalid_search_query`). Trimmed; empty / whitespace → treated as absent. Case-insensitive substring match against the allow-listed lead fields (`lead_name`, `lead_company`, `lead_message`). Not a full-text engine. |
  | `action` | _(absent)_ | Optional allow-listed action filter. The only accepted value today is `ui-playground.lead.store` — the listing surfaces project-side rows only. Any other value (including `platform.demo.*`) → HTTP 400 `invalid_action_filter` and the bad value is never echoed back. |

  **Cursor shape**: `UiPlaygroundLeadSubmissionCursor` is a project-side counterpart of the demo-side `UiFormDemoSubmissionCursor`. Wire format: `base64url(JSON {"s": <int>, "i": "<id>" [, "f": "<16hex>"]})` where the `id` regex enforces `uilead_[a-f0-9]{16}` — distinct from the demo cursor's `uifs_…` shape, so the two listings cannot trade cursors (pinned by a unit test). The optional `f` key is the filter fingerprint, present only when the cursor was minted under a filtered listing. Tight key whitelist (only `{s,i}` or `{s,i,f}`), strict `json_decode(..., JSON_THROW_ON_ERROR)`, type-checked, no `serialize`/`unserialize`/`eval`. Any deviation throws `UiPlaygroundLeadSubmissionCursorException` (reason `invalid_cursor`) with a fixed safe message that never echoes the bad input back.

  **Handler gate ordering** (security-significant):
  1. **Authorization** runs FIRST. A denied caller never sees a 400 for malformed q/action/cursor (no decode oracle on the deny path), and the repository is never read.
  2. **Search criteria** (`q` + `action` + `limit`) are parsed and validated next. Oversize `q` → 400 `invalid_search_query`. Unknown `action` → 400 `invalid_action_filter`. No raw bad input is echoed back; the form re-renders empty.
  3. **Cursor decode** runs only after criteria validation passes. Malformed cursor → 400 `invalid_cursor`. Still no repository read.
  4. **Cursor / filter binding**: the cursor's optional `filterFingerprint` MUST equal the criteria's `fingerprint()`. Mismatch → 400 `invalid_cursor`. A cursor issued under a filtered listing is not reusable as an unfiltered cursor and vice-versa.
  5. **Repository read** runs only after every gate passes — `paginate()` when the criteria is unfiltered, `searchPage()` otherwise.

  **Search semantics & SQL safety**. The ORM impl binds the search term via a parameterised LIKE against the serialised `values_json` column: `WHERE values_json LIKE ? ESCAPE '\\'`. User-supplied `%` / `_` / `\` are escaped in `escapeLike()` before binding so a literal `%` in the search term cannot turn into a wildcard. The escape character `\` is escaped FIRST so a trailing `\` cannot escape the closing `%` of the bound pattern. The `action_name` filter binds via the typed `where(Operator::Equals)` helper, not raw SQL. The search term is NEVER concatenated into SQL — it travels as a `?` placeholder value.

  **Filter fingerprint binding** (cursor v2): the `f` key is the first 16 hex characters of `sha256(query|action)` over the canonical case-folded form. Acceptance:
   - v1 cursor (no `f`) decodes as `filterFingerprint = null`; accepted only for unfiltered requests.
   - v2 cursor with `f = X` accepted only when the active criteria's `fingerprint()` equals `X`.
   - Any other combination → HTTP 400 `invalid_cursor`. Makes splicing a cursor from one filter onto another impossible without inverting sha256.

  **Repository contract**: `paginate(?cursor, $limit)` and `searchPage(criteria, ?cursor)` both fetch `limit + 1` rows for cheap `hasMore` detection, trim, and build `nextCursor` from the LAST returned record when more rows remain. The next-cursor inherits the active criteria's fingerprint (or `null` when unfiltered). `recent($limit)` is `paginate(null, $limit)->records` — same semantics, simpler surface for ergonomic callers.

  **Template Next-page link**: rendered only when `paginationHasMore && paginationNextCursor !== null`. Preserves the active `q` / `action` / `limit` alongside the encoded cursor (URL-encoded by Twig's `url_encode`). The 400 states render "← Back to the first page" links. No JavaScript, no AJAX, no infinite scroll, no "Previous" link in this slice.

  **Access control** for the lead listing is its own project-side seam: `UiPlaygroundLeadAdminAuthorizerInterface`, default `AllowAllUiPlaygroundLeadAdminAuthorizer` (`#[SatisfiesServiceContract]`), opt-in `ConfigurableUiPlaygroundLeadAdminAuthorizer` gated by the env flag `UIPLAYGROUND_LEAD_ADMIN_ENABLED` (truthy = `1`/`true`/`yes`/`on`/`enabled`, case-insensitive after trim; anything else denies with `reason: lead_admin_disabled`). Worker-scoped static holder `UiPlaygroundLeadAdminAuthorizer::{getActive, setActive, reset}`, seeded by the project-side `BootUiPlaygroundRegistryListener` from the container-bound winner. Denial → HTTP 403 + safe template state (`reason` + message; no row data). The denial message NEVER echoes the bad env value, the flag name, or any class FQCN.

  **Registry wiring**: a project-side `UiPlaygroundFormSubmitActionRegistry` implements `UiFormSubmitActionRegistryInterface` and is discovered as the active winner via `#[SatisfiesServiceContract(of: UiFormSubmitActionRegistryInterface::class)]`. It resolves `ui-playground.lead.store` to its own action and delegates every other name to `DefaultUiFormSubmitActionRegistry` — so every existing `platform.demo.*` action keeps working unchanged. **Constructor caveat**: the container instantiates `#[SatisfiesServiceContract]` winners via `newInstanceWithoutConstructor()`, so a composite registry's `__construct` is NOT invoked at container build. Any composed default-registry instance MUST be lazy-initialised (e.g. `private ?DefaultUiFormSubmitActionRegistry $builtins = null;` plus a `$this->builtins ??= new ...` accessor) — initialising the property from `__construct` leaves it uninitialised at runtime and the first `resolve()` call throws a typed-property fatal.

  **Boot listener**: a project-side `BootUiPlaygroundRegistryListener` (`AsServerLifecycleListener` phase `WorkerStartAfterContainer`, priority `0` so it fires AFTER the package's `-5`) stashes the container-bound `UiPlaygroundLeadSubmissionRepositoryInterface` winner in the project's worker-scoped static holder. The composite registry pulls the active repo from that holder lazily at action-resolve time, so the action class itself stays free of container access.

  > **Superseded (One Way Phase 6).** Everything in this `platform.grid`
  > section describes the v1 grid apparatus — `GridComponent`,
  > `grid.html.twig`, `grid-runtime.js`, `UiGridDataResponse`,
  > `GridRuntimeStaticAssertTest` — which was DELETED in the One Way Phase 6
  > sweep. The replacement is the contract-driven `platform.grid-v2` shell
  > (`resources/twig/components/runtime/grid-v2.html.twig`) +
  > `grid-runtime-v2.js`: grids boot from the route's OPTIONS contract and
  > render the canonical `{data, meta}` collection envelope (pull or SSE).
  > The description below is retained as historical design context only.

  **`platform.grid` — reusable interactive grid shell**. A minimal package-level component. **Two consumers** now drive it through identical client-side code: the lead admin listing (`/ui-playground/admin/leads`) and the demo-submissions diagnostic listing (`/ui-playground/admin/demo-submissions`). Each consumer owns its own data endpoint, criteria, cursor, authorizer, and (for leads) SSE topic + publisher — the grid component owns only the shell + the runtime contract.

  - **Component**: `Semitexa\PlatformUi\Application\Component\Builtin\GridComponent` (`#[AsComponent(name: 'platform.grid', template: '@platform-ui/components/runtime/grid.html.twig', cacheable: true)]`). Slots: `warning`, `filters`, `filterState`, `footer` — all caller-owned. The component owns ONLY the grid shell (root + data-* attrs + hidden refresh marker + table headers from `columns` + initial-rows fallback tbody + Next-link with fallback href + inline JSON bundle). It deliberately does NOT know query semantics, authorization, repository, cursor internals, or SSE topic internals.

  - **Caller props**: `gridId` (required), `instanceId` (optional, falls back to `ui_component_instance()`), `dataUrl` (required), `sseUrl` (optional), `refreshMarker` (defaults to `grid-refresh-marker`; callers set this to whatever name their server-side publisher targets — the lead listing uses `lead-grid-refresh-marker`), `columns` (list of `{key, label, style?, sortAsc?, sortDesc?}` — the runtime renders rows in this exact order, ignoring extra keys; `sortAsc` + `sortDesc` opt a column into the sortable-header UI), `initialRows` + `initialPagination` (server-rendered fallback), `initialQuery` + `initialAction` + `initialSort` + `sortParam` + `pageFallbackUrl` (no-JS Next-link + sort-toggle href composition; `sortParam` defaults to `sort` and matches the caller's data-endpoint contract), `emptyMessage` (empty-state copy).

  - **Sortable column headers** (lead grid + demo-submissions grid, both at parity). When a column map carries BOTH `sortAsc` + `sortDesc` allow-listed tokens, the template renders the header label as `<a data-ui-grid-sort data-ui-grid-sort-asc="..." data-ui-grid-sort-desc="...">` with an `aria-sort="ascending|descending|none"` on the surrounding `<th>` and a small toggle indicator (`▲`/`▼`/`↕`). The toggle `href` is composed server-side from `pageFallbackUrl` + active filter state + the *other* direction's token (so the no-JS path works without JS). The runtime intercepts clicks, flips between `sortAsc` and `sortDesc` based on the current `state.sort`, clears the cursor, mirrors the new sort into the caller-owned hidden `<input name="sort">` inside the filter form, updates the aria-sort + toggle-href + indicator glyph immediately, and reloads. The tokens are SERVER-OWNED and ALLOW-LISTED — the runtime never invents one. Per-grid allow-lists (both grids identical in this slice): `submittedAt_desc` (default) + `submittedAt_asc`. Sortable column for both grids: ONLY `submittedAt`. **Demo-submissions Sort VO** is the package-side `Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionSort` — same allow-list shape as the project-side lead Sort VO, with `invalid_sort` rejection routed through the package-side `UiFormDemoSubmissionSearchException`. Out-of-scope this slice: contact-field / lead_message / `id_*` sorting, multi-sort, client-side sort. **The shared `UiGridFilterState` envelope is UNCHANGED** (Option B): sort travels as a hidden form input, not as part of the on-wire filter envelope.

  - **Runtime**: `src/Application/Static/js/grid-runtime.js`, declared in the package `assets.json` (`scope: global`, `position: body`, `priority: 70`, `defer: true`). Namespace: `window.SemitexaUi.grid` (`bootAll()` is the explicit re-discovery hook; auto-runs on `DOMContentLoaded`). Reads the inline `<script type="application/json" data-ui-grid-bundle>` block for column order + refresh-marker name + page-fallback URL.

  - **Runtime safety**: DOM mutations use `createElement` + `textContent` exclusively. Cell attributes go through `setAttribute` with a literal allow-list (`style`, `ui-text`, `data-ui-grid-row-id`); the per-column `style` string is sourced from the server-rendered bundle, NOT from the JSON envelope. Row keys filtered through the bundle's `columns` allow-list — extra keys ignored. JSON envelope shape-checked before any DOM update; deviation surfaces an error banner. **No `innerHTML`, no `eval`, no `Function` constructor, no `document.write`, no script-tag rendering, no arbitrary selectors from server payload, no client-side dataset cache.** Pinned by `GridRuntimeStaticAssertTest`.

  - **Generic SSE refresh** (per-grid configurable). The runtime listens for `semitexa:ui-sse:patch-applied` CustomEvents and reloads when `patch.target.name === <refreshMarker>` AND `patch.target.instance === <grid-root-instance-id>`. The marker name is read from the grid root's `data-ui-grid-refresh-marker` attribute, so each grid can use its own marker namespace without runtime changes.

  - **Lead listing migration** (`src/modules/UiPlayground/src/Application/View/templates/pages/lead-admin.html.twig`): now invokes `component('platform.grid', {gridId, instanceId, dataUrl, sseUrl, refreshMarker: 'lead-grid-refresh-marker', pageFallbackUrl, emptyMessage, columns: […], initialRows, initialPagination, initialQuery, initialAction}, {filters: <form>, filterState: <p>, footer: <p>})`. The lead admin handler still owns the SSE channel mint + topic subscribe + bundle push; the grid component template just renders the shell.

  - **Project-side runtime removed**. The previous `src/modules/UiPlayground/src/Application/Static/js/lead-grid-runtime.js` + its `assets.json` are gone — the package runtime covers it via the configurable refresh-marker name. Project module no longer needs an asset manifest.

  - **Demo-submissions migration** (`src/modules/UiPlayground/src/Application/View/templates/pages/demo-submissions-admin.html.twig`): the OK branch invokes `component('platform.grid', {gridId: 'platform-ui.demo-submissions', dataUrl: '/ui-playground/admin/demo-submissions/grid-data', sseUrl: null, pageFallbackUrl, emptyMessage, columns: [submittedAt (sortable), id, actionName, contactName, contactTopic, contactMessagePreview], initialRows: submissions, initialPagination, initialQuery, initialAction, initialSort, sortParam}, {filters: <form (incl. hidden sort input)>, filterState: <p>})`. JSON data endpoint at `GET /ui-playground/admin/demo-submissions/grid-data` (`DemoSubmissionsAdminGridDataPayload` + `DemoSubmissionsAdminGridDataHandler`) reuses the package-side `UiFormDemoSubmissionListCriteria`, `UiFormDemoSubmissionCursor`, `UiFormDemoSubmissionSort`, `UiFormDatabaseDemoSubmissionRepositoryInterface`, and `UiDemoSubmissionAdminAuthorizerInterface` verbatim — same 5-gate ordering, same envelope shape as the lead grid-data endpoint. Accepts `?sort=` with the allow-listed tokens (`submittedAt_desc` default, `submittedAt_asc`); unknown tokens → `400` with `reason: invalid_sort` and NO repository read. The cursor's filter fingerprint binds the active sort token so cross-sort cursor reuse → `400 invalid_cursor`. **No SSE for demo-submissions in this slice**: `sseUrl: null` on the grid root → the package runtime renders the grid dynamically (filter + sort + Next) but skips SSE attach; the demo listing is refresh-on-action, not live-refresh.
  - **Grid id namespacing**: `platform-ui.demo-submissions` (the underlying records are package-owned demo data) vs. `ui-playground.leads` (project-owned business data). The grid id is opaque to the runtime; the convention helps operators correlate the two grids in mixed deployments.

  - **Shared envelope contract** (`Semitexa\PlatformUi\Domain\Model\Grid\UiGridDataResponse`): both grid-data handlers shape their JSON envelopes through one tiny static factory:

    ```php
    UiGridDataResponse::success(
        gridId:     'platform-ui.demo-submissions',
        rows:       $rows,                                 // already projected by the handler
        pagination: new UiGridPaginationData($page->limit, $page->hasMore, $page->nextCursor?->encode()),
        filters:    new UiGridFilterState($criteria->query, $criteria->actionName),
    );
    UiGridDataResponse::error('invalid_cursor', 'Pagination cursor is invalid.');
    ```

    The helper owns ONLY the on-wire envelope shape (key list + key order pinned by `UiGridDataResponseTest`). It does NOT authorize, query, parse criteria, decode cursors, project rows, or pick HTTP statuses — every one of those concerns stays in the handler. The `UiGridPaginationData` + `UiGridFilterState` DTOs are pure data carriers (no validation, no normalisation); handlers feed already-canonical values. A future grid-data endpoint that wants to participate in the `platform.grid` contract MUST go through `UiGridDataResponse` so the on-wire envelope cannot drift across consumers. **This is an envelope contract, not a generic grid-data-provider framework** — there is no shared repository, no shared criteria, no shared SSE policy; those decisions stay with each consumer.

  > **Superseded.** The original project-side SSE refresh plumbing below was
  > built against the now-retired platform-ui patch-stream subsystem
  > (channel-token + per-channel patch publisher). With all UI streaming unified
  > on the canonical KISS stream, that plumbing is superseded; the description is
  > retained as historical design context only.

  The original SSE refresh plumbing (topic registry, publisher wrapper, store-action call) was:

  - **JSON data endpoint** at `GET /ui-playground/admin/leads/grid-data` (`LeadAdminGridDataPayload` + `LeadAdminGridDataHandler`). Returns the safe envelope `{ok, gridId: "ui-playground.leads", rows[...], pagination: {limit, hasMore, nextCursor}, filters: {q, action}}` — the envelope key list + key order is byte-identical to the demo-submissions grid (Option B: sort travels as a query parameter / form input, not in the response). Accepts `?sort=` with the allow-listed tokens (`submittedAt_desc` default, `submittedAt_asc`); unknown tokens → `400` with `reason: invalid_sort` and NO repository read. The cursor's filter fingerprint binds the active sort token, so a cursor minted under one sort cannot be re-used under another (`400 invalid_cursor`). Same 5-gate ordering as the page handler (authorize → criteria → cursor → fingerprint → repo). Error envelope on any failure: `{ok: false, reason, message}` with 400/403 status. **Never carries `values_json`, tokens, ctx, dispatchId, debug, or class FQCNs** (pinned by a canary integration test).

  - **Topic registry** for SSE refresh — the retired framework patch publisher was strictly point-to-point (per-channel Redis LIST), so the project introduced a small subscription layer: `UiPlaygroundLeadGridRefreshTopicInterface` (cache-backed default + in-memory test fallback) holds a map `{channelId → (instanceId, expiresAt)}` under namespace `ui-playground-lead-grid-refresh`. The lead admin page handler subscribed its freshly minted SSE channel + grid root instance id on each render (TTL 600s). Stale entries are pruned on every read; the single-map storage shape is bounded by the cache key's namespace TTL.

  - **Refresh publisher** (`UiPlaygroundLeadGridRefreshPublisherInterface`, default `DefaultUiPlaygroundLeadGridRefreshPublisher` — `#[SatisfiesServiceContract]`, container-managed, the framework patch publisher property-injected). After `UiPlaygroundStoreLeadAction::handle()` saves a row, the action calls `publishRefresh()` which iterates the topic's subscribers and publishes a `setText` patch to each: `targetName: 'lead-grid-refresh-marker'`, `value: (string) time()`. Patch fan-out is best-effort — both the publisher and the action wrap the publish call in `try {…} catch (\Throwable)` so a stale channel id, dead Redis connection, or contract-violating custom publisher cannot un-do the save.

  - **Refresh-marker patch shape**. Fixed: `op=setText`, `targetInstance=<grid-root-instance-id>`, `targetName='lead-grid-refresh-marker'`, `value='<unix-ts>'`. **Never carries lead values, leadId, or operator-internal jargon** (pinned by `refresh_signal_carries_no_lead_values` — the publisher's contract method is parameterless, so lead-value data has nowhere to flow through it).

  - **Pagination footer UX** (added after the original RC; ships in both grids because the component is shared):
    - **Previous button** (`<button data-ui-grid-prev>`). Hidden on first paint; the runtime reveals it whenever the client-side cursor-history stack grows past page 1. No server-side fallback href — cursors are forward-only and the server doesn't keep history; on the no-JS path users still see only the Next link, identical to the original RC behavior.
    - **Visited-page numbered buttons** (`<span data-ui-grid-pages>` populated with `<button data-ui-grid-page="N">` one per visible visited page). The runtime renders buttons only for pages whose cursor it has actually observed (page 1 is the implicit `null` cursor) AND only within the configured sliding window (see **Sliding window** below). Clicking page `N` re-uses the stored cursor for that page; there is no jump-to-arbitrary-page because the cursor model has no total-count or `page=N` support.
    - **Sliding window** (configurable). Caller prop: `paginationWindowSize` (default `7`, clamped server-side AND client-side to `[1, 25]`; non-numeric / missing values fall back to `7`). Emitted onto the grid root as `data-ui-grid-pagination-window-size="<N>"`. The runtime's pure helper `computePageWindow(currentPage, knownPages, windowSize)` (exposed on `window.SemitexaUi.grid` for console debugging / future Node-side tests) returns the inclusive `[start, end]` page range to render. The window centers the active page when possible, slides toward the start near page 1, and toward the end near `knownPages`. It NEVER extends past `state.cursors.length` — fabricating buttons for unknown future pages would offer cursors we don't have. Examples (with `windowSize=5`, `knownPages=20`): `page 1 → 1..5`, `page 4 → 2..6`, `page 8 → 6..10`, `page 20 → 16..20`. When `knownPages < windowSize`, the window simply shrinks to fit (page 1 of 3 known → `1..3`). When `knownPages = 0` (the empty-state branch the table-wrap already hides), the window is `{0, 0}` and the pagination footer collapses.
    - **Ellipsis markers** (`<span aria-hidden="true">…</span>`) appear on either side of the visible window when there are visited pages outside it. They are deliberately NOT buttons and do NOT carry `data-ui-grid-page` — the click delegator looks for `closest('[data-ui-grid-page]')`, so an ellipsis can never resolve to a navigation. Their only role is the visual hint "more known history exists" — they're decorative.
    - **Current-page indicator** (`<span data-ui-grid-page-indicator>`). Updated via `textContent` on every reload — `Page <N>`, suffixed with `· more available` when `pagination.hasMore` is true. **Never** displayed as `Page N of M` because `M` is unknown.
    - **State preservation**: Previous / numbered-page navigation preserves `q`, `action`, `sort`, and `limit` unchanged. The Next link continues to do the same.
    - **Reset triggers**: any filter-form submit, any opt-in `data-ui-grid-reload-on-change` change (typically the page-size select), and any sort-header click invoke `resetPaginationHistory()` — clears `state.cursor`, resets `state.cursors` to `[null]`, and sets `state.page = 1`. A cross-criteria cursor would either be rejected by the server-side fingerprint guard (for `q` / `action` / `sort` changes) or land mid-stream (for `limit` changes); the early reset means the UI never offers a misleading Previous / numbered button that would behave inconsistently.
    - **Cursor security unchanged**: the existing `UiFormDemoSubmissionCursor` / `UiPlaygroundLeadSubmissionCursor` shape regex + filter-fingerprint binding still gate every server-side cursor request. Stored client-side cursors are opaque to the runtime — they're treated as strings and pushed onto / popped off the history stack without inspection. Server-side rejection ( `400 invalid_cursor`) is preserved end-to-end.
    - **Safe DOM**: every button + indicator update goes through `createElement` + `textContent` + `setAttribute` only. No `innerHTML`, no `eval`, no `Function` constructor, no `document.write` — pinned by `GridRuntimeStaticAssertTest`.

  **Explicit non-goals** for the grid (each is a separate future slice): row selection, inline edit, bulk actions, export, virtual scroll, client-side dataset cache, client-side query engine, **arbitrary jump-to-page** (page=N), **total-count display** (would require an extra repo call per request and a contract change), multi-column sort, client-side sort, `id_*` / `lead_message` / `contactName_*` / `contactTopic_*` / `contactMessage_*` sort tokens, demo-submissions SSE refresh. Column sort UI for both grids (lead + demo-submissions, `submittedAt_*` only on each) has shipped in two parity-matched slices — see the **Sortable column headers** bullet above for the contract. Previous-button + visited-page-button + current-page-indicator pagination has shipped (see **Pagination footer UX** above); arbitrary jump-to-page remains deferred.

**Demo submission repository** (`UiFormDemoSubmissionRepositoryInterface`):

```php
interface UiFormDemoSubmissionRepositoryInterface
{
    public function save(UiFormDemoSubmissionRecord $record): string;       // returns id verbatim
    public function find(string $id): ?UiFormDemoSubmissionRecord;          // test + safe diagnostic
    public function isShared(): bool;
    public function diagnosticName(): string;
}
```

Default impl: **`CacheBackedUiFormDemoSubmissionRepository`** (`#[SatisfiesServiceContract]`), namespace `ui-form-demo-submissions`, **24h TTL** (`CacheBackedUiFormDemoSubmissionRepository::TTL_SECONDS = 86400`). Lazy-default fallback: `InMemoryUiFormDemoSubmissionRepository` (worker-local; used in tests and single-worker dev). Worker-scoped static holder: `UiFormDemoSubmissionRepository::{getActive, setActive, reset}`, populated by `BootPlatformUiRegistryListener` mirroring the rule / action / authorizer / policy / CSRF-store pattern.

**Database-backed sibling** (`UiFormDatabaseDemoSubmissionRepositoryInterface`):

```php
interface UiFormDatabaseDemoSubmissionRepositoryInterface
{
    public function save(UiFormDemoSubmissionRecord $record): string;
    public function find(string $id): ?UiFormDemoSubmissionRecord;
    public function isShared(): bool;
    public function diagnosticName(): string;
}
```

Same shape as the cache variant — separate interface so the cache-backed action and the database-backed action stay strictly orthogonal (neither one silently re-targets the other). Production default: **`UiFormDemoSubmissionDbRepository`** (`#[SatisfiesRepositoryContract]`, ORM-managed via `OrmManager`). Lazy-default fallback: `InMemoryUiFormDatabaseDemoSubmissionRepository` (worker-local, used in tests). Worker-scoped static holder: `UiFormDatabaseDemoSubmissionRepository::{getActive, setActive, reset}`.

**Table**: `platform_ui_demo_submissions`. Columns:

| Column             | Type                        | Notes                                                                  |
|---|---|---|
| `id`               | `VARCHAR(32)` PK manual     | `uifs_<16hex>` — caller-supplied                                       |
| `form_instance_id` | `VARCHAR(80)`               | `uci_<…>` of the rendered form                                         |
| `action_name`      | `VARCHAR(128)`              | `platform.demo.storeContactDb`                                         |
| `submitted_at`     | `DATETIME`                  | server-stamped at insert                                               |
| `values_json`      | `LONGTEXT`                  | `json_encode` of the action's allow-listed values map (deterministic)  |
| `created_at`       | `DATETIME`                  | from `HasTimestamps` trait                                             |
| `updated_at`       | `DATETIME`                  | from `HasTimestamps` trait                                             |

Schema is kept in sync by `bin/semitexa orm:sync` (run as part of `bin/semitexa update`). The table is registered through the standard `#[FromTable]` + `#[Column]` attributes on `UiFormDemoSubmissionResource`; no manual SQL migrations.

The same `UiFormDemoSubmissionRecord` readonly value object is exchanged at the public interface boundary — both repositories implement `save(Record)` + `find(id): ?Record` so dispatch tests can assert exact stored shapes regardless of which sink received the row.

#### Read-only diagnostic listing (`/ui-playground/admin/demo-submissions`)

The `UiFormDatabaseDemoSubmissionRepositoryInterface` exposes two bounded read-only listing methods:

```php
public function recent(int $limit = self::DEFAULT_RECENT_LIMIT): array;
public function paginate(
    ?UiFormDemoSubmissionCursor $cursor = null,
    int $limit = self::DEFAULT_RECENT_LIMIT,
): UiFormDemoSubmissionPage;
// DEFAULT_RECENT_LIMIT = 25, MAX_RECENT_LIMIT = 100
```

Implementations clamp `$limit` to `[1, MAX_RECENT_LIMIT]` and return rows newest-first (`ORDER BY submitted_at DESC, id DESC` — the `id` tie-breaker keeps the ordering deterministic when multiple rows share the same `submitted_at` second). `recent($n)` is the simple "first page only" surface, equivalent to `paginate(null, $n)->records`. No `findAll()`, no filter-by-action, no date-range scan, no search — those are explicit future work.

**Keyset pagination**. `paginate()` advances via an opaque cursor — never an offset. Implementations fetch `$limit + 1` rows to detect `hasMore`, then trim. The returned `UiFormDemoSubmissionPage` carries `{records, nextCursor, limit, hasMore}`:

```php
final readonly class UiFormDemoSubmissionPage
{
    /** @var list<UiFormDemoSubmissionRecord> */
    public array $records;
    public ?UiFormDemoSubmissionCursor $nextCursor;
    public int  $limit;
    public bool $hasMore;
}
```

`nextCursor` is set when `hasMore && records !== []`, pointing at the LAST returned record so the next page can resume strictly after it under the compound predicate `(submitted_at, id) < (cursor.submittedAt, cursor.id)`.

**Cursor shape**. `UiFormDemoSubmissionCursor` is opaque to callers — its only public surface is `encode(): string` (the wire form) and `decode(string): self` / `tryFromString(?string): ?self`. The encoded cursor is `base64url(JSON {s: submittedAt, i: id})` with no padding, no signing, no operator-internal fields. Decode is strict: base64url alphabet only, `base64_decode(..., true)`, `json_decode(depth: 4, JSON_THROW_ON_ERROR)`, tight key whitelist (`['i', 's']` only), `int`/`string` type checks, and the `id` regex (`/\Auifs_[a-f0-9]{16}\z/`) — any deviation throws `UiFormDemoSubmissionCursorException(reasonCode: 'invalid_cursor')`. The codec never calls `unserialize` or `eval`. Exception messages are a fixed string — they never echo the bad cursor back.

The cursor carries no secrets — only an id and a timestamp that are already visible on the listing page. No signing is required because (a) the cursor reveals nothing the listing doesn't already reveal, and (b) the strict shape validation rejects every malformed input at parse time before any repository read happens.

This drives a tiny dev-facing diagnostic page registered in the **UiPlayground module** at `GET /ui-playground/admin/demo-submissions`. The page shows the most recent rows from `platform_ui_demo_submissions` with the same safety guarantees the rest of the submit pipeline maintains:

- Twig autoescapes every value (`<script>alert(1)</script>` renders as literal text).
- Message previews are server-truncated to 160 characters (single `…` ellipsis appended).
- The view-model is a flat array (`{id, actionName, formInstanceId, submittedAt, contactName, contactTopic, contactMessagePreview, storedFieldCount}`). Raw `values_json` never reaches the template.
- The page deliberately does NOT surface CSRF token ids, raw tokens, signed-ctx blobs, dispatchIds, raw payload bytes, or debug internals — they are not in the table to begin with, and the projection never invents them.

**Access control**: `UiDemoSubmissionAdminAuthorizerInterface` (throw-on-deny, mirrors the action authorizer / security policy seams). Default impl is `ConfigurableUiDemoSubmissionAdminAuthorizer` (`#[SatisfiesServiceContract]`) — deny-by-default unless `PLATFORM_UI_DEMO_ADMIN_ENABLED` is explicitly truthy. Dev playground deployments that intentionally want open diagnostics can bind `AllowAllUiDemoSubmissionAdminAuthorizer` themselves or install it through the worker-scoped static holder. Worker-scoped static holder + Boot listener wiring match the surrounding patterns. Denial returns HTTP 403 with a safe template state — never the bad caller's identity, never class FQCNs.

**Protected mode (default built-in)**: the package ships the env-gated authorizer as the default:

```php
#[SatisfiesServiceContract(of: UiDemoSubmissionAdminAuthorizerInterface::class)]
final class ConfigurableUiDemoSubmissionAdminAuthorizer
    implements UiDemoSubmissionAdminAuthorizerInterface
{
    public const ENV_FLAG = 'PLATFORM_UI_DEMO_ADMIN_ENABLED';
    // ...
}
```

Apps that need a different decision can replace the default either via their own boot listener call —

```php
UiDemoSubmissionAdminAuthorizer::setActive(
    new AllowAllUiDemoSubmissionAdminAuthorizer(), // dev-only open diagnostics
);
```

— or by binding their own implementation through `#[SatisfiesServiceContract(of: UiDemoSubmissionAdminAuthorizerInterface::class)]` (e.g. a permission-based authorizer composed with the configurable one).

Flag matrix (case-insensitive after trim): `1` / `true` / `yes` / `on` / `enabled` → allow. Anything else (unset, empty, `0`, `false`, `off`, `disabled`, random text, whitespace) → deny with `UiDemoSubmissionAdminAuthorizationException(reasonCode: 'demo_admin_disabled')`. The message is a fixed string — it never echoes the env value, the flag name, or any class FQCN back. Pinned by `ConfigurableUiDemoSubmissionAdminAuthorizerTest`.

Denial paths converge on the same handler behaviour: HTTP 403, repository **NOT** read, no rows rendered, safe denial copy + reason code in the template. The reason code distinguishes the cause:

| Reason code           | Source                                                          |
|---|---|
| `demo_admin_forbidden` | default `UiDemoSubmissionAdminAuthorizationException()`         |
| `demo_admin_disabled`  | `ConfigurableUiDemoSubmissionAdminAuthorizer` — env flag absent or falsey |
| `role_required` / any  | custom application authorizer                                   |

**Diagnostic listing query parameters**:

| Param | Default | Behaviour |
|---|---|---|
| `limit` | `25` (`DEFAULT_RECENT_LIMIT`) | Clamped server-side to `[1, MAX_RECENT_LIMIT]` (`MAX_RECENT_LIMIT = 100`). Non-numeric / empty / negative / whitespace falls back to the default. `0` clamps up to `1`. Both the handler and the repository clamp — the handler value is surfaced to the template so the rendered "page size" matches what the user receives. |
| `cursor` | _(absent)_ | Opaque base64url token returned by the previous page's `nextCursor`. Empty / missing → first page. Malformed → HTTP 400 with the safe template state (no submissions rendered, repository never read). Cursor binds to the filter combination it was issued under (see *Cursor / filter binding* below). |
| `q` | _(absent)_ | Bounded diagnostic-grade search term (max `100` UTF-8 characters; longer → HTTP 400 `invalid_search_query`). Trimmed; empty / whitespace → treated as absent. Case-insensitive substring match against the allow-listed contact fields (`contact_name`, `contact_topic`, `contact_message`). Not a full-text engine. |
| `action` | _(absent)_ | Optional allow-listed action filter. The only accepted value today is `platform.demo.storeContactDb` — the listing surfaces DB-backed rows only. Any other value → HTTP 400 `invalid_action_filter` and the bad value is never echoed back. |

**Gate ordering inside the handler** (security-significant):

1. **Authorization** runs FIRST. A denied caller never sees a 400 for malformed q / action / cursor input (no decode oracle on the deny path). The repository is never read.
2. **Search criteria** (`q` + `action` + `limit`) are parsed and validated next. Oversize `q` → 400 `invalid_search_query`. Unknown `action` → 400 `invalid_action_filter`. No raw bad input is echoed back; the form re-renders empty.
3. **Cursor decode** runs only after criteria validation passes. Malformed cursor → 400 `invalid_cursor`. Still no repository read.
4. **Cursor / filter binding**: the cursor's optional `filterFingerprint` MUST equal the criteria's `fingerprint()`. Mismatch → 400 `invalid_cursor`. A cursor issued under a filtered listing is not reusable as an unfiltered cursor and vice-versa.
5. **Repository read** runs only after every gate passes — `paginate()` when the criteria is unfiltered, `searchPage()` otherwise.

**Search semantics & SQL safety**.

The ORM impl binds the search term via a parameterised LIKE against the serialised `values_json` column:

```sql
WHERE values_json LIKE ? ESCAPE '\\'
```

User-supplied `%` and `_` characters are escaped before binding so a literal `%` in the search term cannot turn into a wildcard. The escape character `\` is itself escaped first so a trailing `\` in the user input cannot escape the closing `%` of the bound pattern. The search term is NEVER concatenated into SQL — it travels as a `?` placeholder value. The `action_name` filter binds via the typed `where(Operator::Equals)` helper, not raw SQL.

This is **diagnostic-grade** search:

- It substring-matches against the entire JSON-serialised values blob — there's no per-field index.
- It scans up to `limit + 1` rows, never the whole table.
- It does NOT support phrase queries, stemming, ranking, or fuzzy matching. Use a real search engine for any of those.

**Cursor / filter binding (filter fingerprint)**.

The cursor's wire format gained an optional `f` key:

```jsonc
// unfiltered listing                 // filtered listing (q="alpha")
{"s": 1778900000, "i": "uifs_…"}      {"s": 1778900000, "i": "uifs_…", "f": "<16 hex>"}
```

`f` is the first 16 hex characters of `sha256(query|action)` over the canonical case-folded form (`mb_strtolower(trim(q ?? ''))` + `|` + `action ?? ''`). It is a tamper-resistance prefix, not a secret — knowing `f` doesn't help an attacker because the listing is already public-by-route under the active authorizer.

Acceptance rules:

- A v1 (2-key) cursor decodes as `filterFingerprint=null`. It is accepted only when the active criteria is unfiltered (`f === null`).
- A v2 (3-key) cursor with `f=X` is accepted only when the active criteria's `fingerprint()` equals `X`.
- Any other combination → HTTP 400 `invalid_cursor`. The repository is never read.

This makes "splice a cursor from listing A onto listing B" impossible — an operator who wanted to walk a filtered keyset under a different filter would have to forge a matching fingerprint, which means knowing the canonical form of the new filter's `(q, action)` tuple.

The `Next page →` link in the template preserves the active `q` / `action` / `limit` alongside the encoded cursor so following pagination keeps the same filter context end-to-end.

**Privacy guarantees** (unchanged across this slice):

- The repository's `save()` path still only stores the four documented columns (`id` / `form_instance_id` / `action_name` / `submitted_at` / `values_json`) — no tokens, no signed-ctx blob, no dispatchId, no debug, no payload bytes.
- The handler's projection still emits only `{id, actionName, formInstanceId, submittedAt, contactName, contactTopic, contactMessagePreview, storedFieldCount}`. Raw `values_json` is never surfaced.
- The bad-search / bad-cursor states render a safe banner with a stable reason code; the bad user input is never echoed back.
- Twig autoescape still wraps every value at render time — even an `<script>alert(1)</script>` in `contact_name`, `contact_message`, or the `q` parameter renders as literal text, never markup.

**What this slice does NOT implement** (explicit non-goals): per-field search (`field`), date-range query, per-user view, edit, delete, export, soft-delete, undo, RBAC matrix, admin UI for the cache-backed repository, list endpoint for the `find()` method beyond this one route, "Previous page" link / backward keyset, jump-to-page / total-count display, persistent cursor history, full-text engine, ranking / scoring, search-as-you-type.

**Stored record shape** (`UiFormDemoSubmissionRecord`):

```php
final readonly class UiFormDemoSubmissionRecord {
    public string $id;              // 'uifs_<16hex>' generated by the action
    public string $formInstanceId;  // 'uci_<…>' of the rendered form
    public string $actionName;      // 'platform.demo.storeContact'
    public int    $submittedAt;     // Unix timestamp
    public array  $values;          // allow-listed sanitised values only
}
```

The record carries no tokens, no signed-ctx blob, no dispatchId, no request payload, no debug internals. The repository is a dumb sink — sanitisation lives in the action.

**Safety gate ordering before storage** (canonical pipeline):

1. signed-ctx HMAC verification;
2. dispatchId replay claim;
3. dispatcher-level `UiInteractionAuthorizerInterface`;
4. authoritative server-side field validation;
5. `UiFormSubmitActionRegistryInterface` resolves the action by signed name;
6. `UiFormSubmitActionAuthorizerInterface` allows the attempt;
7. `UiFormSubmitSecurityPolicyInterface` verifies + **consumes** the one-time CSRF token.

Only after all seven pass does the action's `handle()` run. Invalid submits / replay / authz denial / CSRF failures never reach storage — pinned by the dispatch test matrix (one record per valid submit; zero records after any gate fails).

**Demo-grade limitations**:

- The default `platform.demo.storeContact` action uses the cache-backed demo repository with a 24-hour TTL — records evaporate; abandoned demo deployments do not accumulate data.
- The alternate `platform.demo.storeContactDb` action uses the DB-backed demo repository/table (`platform_ui_demo_submissions`) and persists rows until the consuming app's database retention policy removes them. Operators can override that behaviour by binding `UiFormDatabaseDemoSubmissionRepositoryInterface` or replacing the DB action wiring.
- The action does NOT send email, redirect, call external APIs, create accounts, or run any other business action.
- Real persistence (audit, retention, queryability) is a separate slice with its own storage contract.

#### Submit action authorizer + security policy seams

The action seam is gated by two dedicated seams that run AFTER authoritative field validation and BEFORE the action's `handle()`:

1. **`UiFormSubmitActionAuthorizerInterface::authorize(UiFormSubmitActionAuthorizationContext)`** — application-level identity / role / rate-limit decision. Default `AllowAllUiFormSubmitActionAuthorizer` is a no-op so the demo flow works unchanged.
2. **`UiFormSubmitSecurityPolicyInterface::verify(UiFormSubmitSecurityContext)`** — submit-shaped CSRF / session / token check. Default is now **`CacheBackedUiFormSubmitSecurityPolicy`** — a one-time nonce bound to the rendered form via the signed ctx (`cfg.s = {k, t}`) and an HMAC-stored cache entry. See "Submit CSRF policy" below for the full token flow. `SignedContextOnlyUiFormSubmitSecurityPolicy` stays available as an explicit opt-in fallback for environments without a shared cache or for tests that want to bypass CSRF.

Both seams use a **throw-on-deny** convention (the dispatcher-level `UiInteractionAuthorizerInterface` returns bool because it has no need for a per-decision reason channel — these seams do, so they raise typed exceptions instead):

```php
interface UiFormSubmitActionAuthorizerInterface
{
    /** @throws UiFormSubmitActionAuthorizationException on deny. */
    public function authorize(UiFormSubmitActionAuthorizationContext $context): void;
}

interface UiFormSubmitSecurityPolicyInterface
{
    /** @throws UiFormSubmitSecurityPolicyException on policy failure. */
    public function verify(UiFormSubmitSecurityContext $context): void;
}
```

Both exceptions carry a `reasonCode` (`role_required`, `rate_limited`, `csrf_verification_failed`, `session_required`, `submit_security_failed`, …) plus a user-facing `message`. FormComponent catches them and emits **the same two form-level patches** as a normal action (`setText form-status` + `setAttribute ui-state=invalid`) — no class names, no raw values, no patches outside the existing allow-list. Debug surface:

```jsonc
"action": {
  "name":    "platform.demo.accept",
  "invoked": false,
  "reason":  "action_forbidden",       // or "submit_security_failed"
  "detail":  "role_required",          // the exception's reasonCode
  "message": "You do not have permission to run this action."
}
```

**Override seam**: apps bind their own implementations via `#[SatisfiesServiceContract(of: ...)]` in a module that "extends" semitexa-platform-ui. The contract registry picks the descendant-module winner; `BootPlatformUiRegistryListener` stashes them in `UiFormSubmitActionAuthorizer` / `UiFormSubmitSecurityPolicy` (worker-scoped static holders, mirroring the rule-registry pattern).

#### Submit CSRF policy (nonce-backed, one-time consume)

The default security policy is **`CacheBackedUiFormSubmitSecurityPolicy`**. It binds a one-time token to each rendered form-with-action:

1. **Render time.** Form template calls `ui_form_issue_submit_csrf($actionName)` (only when `submitAction` is set). The helper asks the active `UiFormSubmitCsrfTokenStoreInterface` to mint a fresh `{id, raw}` pair. The store keeps ONLY `hash_hmac('sha256', raw, id)` against `id` in a namespaced cache (`ui-form-submit-csrf`), with a TTL bounded by the form ctx lifetime (default 600 s / 10 min). The pair is signed into `cfg.s = {k: <id>, t: <raw>}` of the submit ctx.

2. **Dispatch time.** FormComponent reads `event->config['s']` and passes it as the new `UiFormSubmitSecurityContext::$securityConfig` field to the policy. The policy:
   - asserts `cfg.s.k` matches `uicsrf_[a-f0-9]{16}` and `cfg.s.t` matches `[a-f0-9]{32}`;
   - calls `UiFormSubmitCsrfTokenStoreInterface::consume($k, $t)` which atomically verifies HMAC + removes the entry;
   - returns void on success, throws `UiFormSubmitSecurityPolicyException(reasonCode: 'csrf_verification_failed', message: 'Submit security check failed. Please reload the form and try again.')` on any failure (missing / expired / wrong / already consumed — all collapse to the same surface, no side-channel).

3. **One-time consume semantics.** The policy runs AFTER field validation + the action authorizer. So:
   - **invalid submits** never reach `consume()` → the token survives → the user can fix the form and resubmit;
   - **authorizer-denied submits** never reach `consume()` → token survives;
   - **valid + authorized submits** consume the token regardless of whether the action itself rejects (acceptable — the user already saw a server response, which is enough to invalidate the bearer secret).
   - A second submit attempt with the same `cfg.s` after a successful first one fails CSRF — the user reloads the form to mint a fresh token. The playground demo exercises this end-to-end.

**Token store**: `UiFormSubmitCsrfTokenStoreInterface` (`issue($ttl) → UiFormSubmitCsrfTokenHandle{id, raw}` + `consume($id, $rawToken): bool` + `isShared(): bool` + `diagnosticName(): string`). Default impl is `CacheBackedUiFormSubmitCsrfTokenStore` (`#[SatisfiesServiceContract]`, namespaced through `CacheManagerInterface`, observable across all workers sharing the cache backend). Lazy-default fallback is `InMemoryUiFormSubmitCsrfTokenStore` for tests / single-worker dev (NOT safe across Swoole workers).

**Trust perimeter**:
- Cache stores ONLY the HMAC hash. A leaked cache snapshot cannot replay a token because the raw value is never persisted and HMAC keys each hash with the token id.
- Failure messages never echo the bad token id or value.
- `cfg.s` carries only `{k, t}`: no session id, no cache key format details, no class FQCNs, no service ids.
- `payload.csrf` / `payload.csrfToken` / `payload.csrf_token` (top-level + form-nested) remain forbidden by `UiPayloadFieldGuard` from the previous slice — the client cannot smuggle the token through anywhere except the signed ctx, where the HMAC binds it.

**Known limitations** (call-outs in `primitives.md` limitations list):
- This is a **nonce-backed**, **not yet session-bound** policy. The token is bound to the rendered form via the signed ctx; it is not bound to a session cookie. A leaked full-page HTML (with the signed ctx + token) can be submitted from any UA until consumed. True session binding lands when Semitexa exposes a stable request-scoped seam reachable from reflection-instantiated components.
- No CSRF token rotation across multiple forms on the same page — each `FormComponent` instance mints its own independent token.
- TTL is a fixed default (600 s) at the helper level; future work threads a configurable TTL through.

**Override seam**: apps that want a stricter policy bind their own implementation with `#[SatisfiesServiceContract(of: UiFormSubmitSecurityPolicyInterface::class)]` (and optionally their own token store). The `BootPlatformUiRegistryListener` stashes the container-bound winners in the matching static holders.

**Submit ordering** (single canonical pipeline):

1. parse signed `cfg.f`;
2. parse signed `cfg.a` (optional);
3. validate every signed field → `UiFormSubmitResult`;
4. **if invalid**: emit per-field + summary patches; authorizer / policy / action NEVER run; `debug.action.reason = 'validation_invalid'`;
5. **if valid && cfg.a is set**:
   a. resolve action via registry;
   b. run authorizer → may throw `UiFormSubmitActionAuthorizationException` → safe denial patches + `debug.action.reason = 'action_forbidden'`;
   c. run security policy → may throw `UiFormSubmitSecurityPolicyException` → safe denial patches + `debug.action.reason = 'submit_security_failed'`;
   d. invoke action's `handle()` → form-status uses the action's message + state.
6. **if valid && no cfg.a**: emit per-field + summary patches with the standard "Form is valid. Submit accepted." message.

Patch order is fully stable: per-field first, form-level last, action extras (if any) appended after. Denials NEVER produce action extras.

**Payload guard** rejects the corresponding smuggling attempts: `payload.action`, `payload.submitAction`, `payload.csrf`, `payload.csrfToken`, `payload.security`, `payload.policy`, `payload.authorization`, `payload.authz` (and case / separator variants) all return `400 forbidden_payload_field`. Single-letter `a` is intentionally NOT in the forbidden list because legitimate form field names could collide; the signed `cfg.a` is the only canonical channel.

**Trust perimeter** (auto vs. manual is identical):

- Field definitions are **server-rendered**. The collector stores PHP value objects (`UiFormSubmitFieldDefinition`); no client payload, no DOM scanning at submit time.
- Rules are normalised through the active `UiFieldRuleRegistry` at render time. Unknown rule names / malformed params fail in the template, not at dispatch.
- The collector defensively re-runs the wire validation through `UiFormSubmitConfigParser::parseSignedWire()` before signing, so duplicate field names / duplicate instance ids / unsafe instance ids cannot reach `cfg.f`.
- No raw values, no class names, no service / method names enter the metadata.

**Rendered shape**:

```html
<div data-ui-component="platform.form"
     data-ui-component-instance-id="uci_..."
     data-ui-form-aggregate="1"
     ui-component="form"
     role="group">
  <h2>title</h2>
  <p>description</p>
  <form data-ui-part="form" action="#" novalidate>
    <div data-ui-form-fields>...content slot...</div>
    <div data-ui-patch-target="form-status">...</div>
    <div data-ui-form-submit-row>
      <button data-ui-primitive="platform.button" type="submit" ui-tone="brand">Validate form</button>
    </div>
  </form>
  <script type="application/json" data-ui-event-manifest="...">{"v":1,"c":"platform.form","i":"uci_...","events":[{"p":"form","e":"submit","ctx":"sc1.…"}]}</script>
</div>
```

**Signed submit ctx — claim shape**:

```jsonc
{
  "c": "platform.form",
  "i": "uci_<form-instance>",
  "p": "form",
  "e": "submit",
  "cfg": {
    "f": [
      {"n":"access_code","r":[{"n":"required"},{"n":"minLength","p":[4]}],"l":"Access code","q":true},
      {"n":"confirm_access_code","r":[{"n":"required"},{"n":"sameAsField","p":["access_code","Codes must match."]}],"l":"Confirm access code","q":true}
    ]
  },
  "iat": ..., "exp": ...
}
```

Each field entry uses the compact single-letter wire shape: `n` (name), `r` (rules wire), `l` (optional label), `q` (optional required flag).

**Wire payload (submit dispatch)**:

```jsonc
{
  "ctx": "sc1.…",                    // signed form-submit ctx
  "dispatchId": "ui_evt_<hex>",
  "payload": {
    "value": null,                   // form-level events have no single value
    "form": {                        // sanitised by UiFormPayloadSnapshot
      "values": {
        "access_code":         "abcd",
        "confirm_access_code": "abcd"
      }
    }
  }
}
```

Smuggling attempts (`payload.rules`, `payload.cfg`, `payload.form.rules`, `payload.form.cfg`, any routing-flavored key) are rejected with `400 forbidden_payload_field` by the existing `UiPayloadFieldGuard`.

**Authoritative final validation flow**:

1. `UiInteractionDispatcher` verifies the submit ctx, resolves the handler through `UiComponentRegistry::get('platform.form')->event('form','submit')`, and instantiates `FormComponent`.
2. `UiFormPayloadSnapshot::extract($payload)` produces the sanitised `formValues` map, which the dispatcher attaches to the event.
3. `FormComponent::onSubmit` reads `$event->config['f']` (the signed field list) through `UiFormSubmitConfigParser::parseSignedWire`. The list is the **authoritative** input to validation — client values feed it, the client cannot change it.
4. For every signed field: the handler instantiates the rule chain via `UiFieldRuleParser::resolveFromWire`, builds a `UiFieldValidationContext` carrying the full `formValues` (so cross-field rules like `sameAsField` see siblings), and runs `UiFieldValidator`.
5. The handler aggregates `{name, state, message}` per field into `UiFormSubmitResult::fromFieldResults(...)`.
6. The result projects to **two patches only** — `setText` on `form-status`, `setAttribute` `ui-state` on the form root.

**Result + status messages** (verbatim contract, pinned by tests):

| Case | Message |
|---|---|
| `totalCount === 0` (no signed fields) | `Form has no fields.` |
| All fields valid | `Form is valid. Submit accepted.` |
| 1 field invalid | `1 field needs attention.` |
| N fields invalid | `<N> fields need attention.` |

**Debug surface** — safe-to-log shape, never echoes submitted values:

```jsonc
"debug": {
  "instance": "uci_...",
  "submit": {
    "valid":        false,
    "totalCount":   2,
    "validCount":   1,
    "invalidCount": 1,
    "fields": [
      {"name":"access_code",         "state":"valid",   "message":"Looks good."},
      {"name":"confirm_access_code", "state":"invalid", "message":"Codes must match."}
    ],
    "message": "1 field needs attention."
  },
  "form": {
    "snapshotFields": ["access_code", "confirm_access_code"],
    "snapshotSize":   2
  }
}
```

**Frontend submit capture**:

`event-runtime.js` extends its existing event delegation:

- The native `submit` event on an element matching `data-ui-part="form"` is captured (capture phase), and `ev.preventDefault()` is called **exactly there** — no other native event has its default suppressed. The single guarded `preventDefault` callsite is pinned by `EventRuntimeAssetTest`.
- `collectFormValuesSnapshot` now handles both cases: captured instance IS the form root (submit dispatch), or captured instance is a field inside a form root (input-change dispatch). Walks the same `[data-ui-form-aggregate="1"][data-ui-component-instance-id]` ancestor query in both directions.
- The wire body for submit is the same `{ctx, dispatchId, payload}` envelope every other dispatch uses; `payload.value` is `null` (the form element has no `.value`), `payload.form.values` carries the snapshot.

**Security / trust boundary**:

- Submit is **authoritative** within the demo: the response distinguishes accepted from rejected based on signed rules + sanitised values.
- Submit does **NOT** trust client-side aggregate state, validation messages, or any boolean the client claims. Counts and per-field outcomes come from running the server-owned rule chain.
- Submit does **NOT** persist anything. Persistence in a future slice must add authorization, CSRF/session policy if relevant, and storage-specific validation on top of this seam.
- Submit response **never echoes submitted values** — only counts + per-field state/message + the snapshot field-key set.
- The signed `cfg.f` shape, the parser, the result projection, and the patch allow-list are all the same trust perimeter the rest of the validation stack uses.

**Limitations of this slice**:

- No persistence. No business action. No redirect. No real account creation / email send.
- Automatic slot introspection now resolves field definitions for FieldComponents inside the content slot (`autoFields: true`). Fields rendered outside the slot, or anonymous / unsafe-named FieldComponents, are not discovered — caller must use the manual `fields` prop instead. No multi-pass component tree reflection.
- Submit action seam, action authorizer seam, CSRF/security policy seam, and the first persistent demo action are all in place. Built-in defaults are `platform.demo.accept` (no-op), `platform.demo.storeContact` (cache-backed demo storage), `AllowAllUiFormSubmitActionAuthorizer`, `CacheBackedUiFormSubmitSecurityPolicy`, and `CacheBackedUiFormDemoSubmissionRepository`. Real persistent business actions (DB-backed, audit-aware, with bespoke authorization) are an explicit future slice.
- CSRF policy is in place via `CacheBackedUiFormSubmitSecurityPolicy` (one-time nonce, HMAC-stored in a namespaced cache, bound to the rendered form via `cfg.s`). It is **nonce-backed, not session-bound** — a token issued for a rendered form survives across user agents until consumed. Full session binding lands when Semitexa exposes a request-scoped seam reachable from reflection-instantiated components.
- No redirect / file upload / async / database action variants in `UiFormSubmitActionResult`.
- No request metadata (session id, auth identity, IP) in the authorization / security contexts yet — a separate slice once Semitexa lands a stable convention for passing it into UI handlers.
- No async / database / remote validation.
- No multi-step forms, no durable form state, no cross-tab state sync.
- No new patch op, no new attribute allow-list entry. `disabled` is still off the allow-list — submit button cannot be disabled through a patch in this slice.

### Per-field submit projection (signed `cfg.f.i`)

Submit now emits **per-field validation patches** in addition to the form-level summary. Each signed field definition can carry an `instanceId` matching `UiInstanceIdGenerator::SAFE_ID_PATTERN` (`uci_[A-Za-z0-9_-]{1,64}`). When `cfg.f[*].i` is present, the handler projects that field's `UiFieldValidationResult::toPatches($i)` output — the SAME shape `FieldComponent::onInputChanged` already emits (aria-invalid + ui-state + validation-message). Patches are ordered per-field first, form-level last, so the visible form-status reflects the aggregate after every field DOM has been updated.

**Caller API** (extends the existing submit demo):

```twig
{% set _field_defs = [
    {
        name: 'access_code',
        instanceId: 'uci_submit_access_code',     # signed into cfg.f.i
        label: 'Access code',
        required: true,
        rules: ['required', ['minLength', 4]],
    },
    {
        name: 'confirm_access_code',
        instanceId: 'uci_submit_confirm_access_code',
        label: 'Confirm access code',
        required: true,
        rules: ['required', ['sameAsField', 'access_code', 'Codes must match.']],
    },
] %}

{% set _fields %}
    {{ component('platform.field', {
        name: 'access_code',
        instanceId: 'uci_submit_access_code',     # SAME id on field render
        showValidationTarget: true,
        rules: ['required', ['minLength', 4]],
    }) }}
    {{ component('platform.field', {
        name: 'confirm_access_code',
        instanceId: 'uci_submit_confirm_access_code',
        showValidationTarget: true,
        rules: ['required', ['sameAsField', 'access_code', 'Codes must match.']],
    }) }}
{% endset %}

{{ component('platform.form', {
    fields: _field_defs,
    showSubmit: true,
    submitText: 'Validate form',
}, { content: _fields }) }}
```

**FieldComponent `instanceId` prop**:

The `instanceId` prop is optional. When absent, the field generates a fresh `uci_<16hex>` id (unchanged behaviour). When present and matching `UiInstanceIdGenerator::SAFE_ID_PATTERN`, the field uses it consistently:

- on `data-ui-component-instance-id` (root attribute),
- in the signed event manifest's `i` claim (so the input-change ctx pins to the same id),
- in `data-ui-event-manifest`.

A render-time `ui_component_instance_for(override)` Twig helper handles the override + safe-id validation. An unsafe override raises `UiComponentRegistryException` at render time — developer mistakes surface immediately as a Twig error rather than at dispatch.

**Wire shape change** (additive — `i` is optional):

```jsonc
"cfg": {
  "f": [
    {
      "n": "access_code",
      "i": "uci_submit_access_code",                 // NEW (optional)
      "r": [{"n":"required"},{"n":"minLength","p":[4]}],
      "l": "Access code",
      "q": true
    },
    {
      "n": "confirm_access_code",
      "i": "uci_submit_confirm_access_code",         // NEW (optional)
      "r": [{"n":"required"},{"n":"sameAsField","p":["access_code","Codes must match."]}],
      "l": "Confirm access code",
      "q": true
    }
  ]
}
```

Old submit ctxs (rendered before this slice) keep working: when `cfg.f[*].i` is absent the handler skips per-field projection and emits only the form-level summary.

**Patch allow-list — instance-id authority**:

`UiPatchValidator` now accepts an optional `additionalAllowedInstances: list<string>` argument. `UiInteractionDispatcher` walks the verified `cfg` claim, collects every value matching `UiInstanceIdGenerator::SAFE_ID_PATTERN`, and passes the resulting set to the validator alongside the primary signed instance. The rule is: a handler may patch ANY instance that itself survived HMAC verification.

- The walk is generic (no FormComponent-specific knowledge in the dispatcher) and produces no false positives because the safe-id regex (`uci_` + alphanumerics/underscores/hyphens, bounded) is a tight subset of arbitrary strings.
- A patch targeting any unsigned instance still returns `422 patch_instance_mismatch`.
- The client cannot retarget patches through the payload — the payload has no instance-id surface, and `payload.form.values` is sanitised to safe identifier keys → scalar values only.
- Tampering `cfg.f[*].i` invalidates the signed ctx → `403 invalid_signed_ctx`.

**Result + patches** (per-field invalid example):

```jsonc
{
  "patches": [
    // access_code (valid)
    {"op":"setAttribute","target":{"instance":"uci_submit_access_code","part":"input"},"attribute":"aria-invalid","value":null},
    {"op":"setAttribute","target":{"instance":"uci_submit_access_code","part":"input"},"attribute":"ui-state","value":"valid"},
    {"op":"setText","target":{"instance":"uci_submit_access_code","name":"validation-message"},"value":"Looks good."},
    // confirm_access_code (invalid — sameAsField mismatch)
    {"op":"setAttribute","target":{"instance":"uci_submit_confirm_access_code","part":"input"},"attribute":"aria-invalid","value":"true"},
    {"op":"setAttribute","target":{"instance":"uci_submit_confirm_access_code","part":"input"},"attribute":"ui-state","value":"invalid"},
    {"op":"setText","target":{"instance":"uci_submit_confirm_access_code","name":"validation-message"},"value":"Codes must match."},
    // form-level summary LAST
    {"op":"setText","target":{"instance":"uci_form_...","name":"form-status"},"value":"1 field needs attention."},
    {"op":"setAttribute","target":{"instance":"uci_form_..."},"attribute":"ui-state","value":"invalid"}
  ],
  "debug": {
    "instance": "uci_form_...",
    "submit": {
      "valid": false, "totalCount": 2, "validCount": 1, "invalidCount": 1,
      "fields": [
        {"name":"access_code","state":"valid","message":"Looks good."},
        {"name":"confirm_access_code","state":"invalid","message":"Codes must match."}
      ],
      "message": "1 field needs attention.",
      "projectedFieldInstances": ["uci_submit_access_code","uci_submit_confirm_access_code"]
    },
    "form": {"snapshotFields": ["access_code","confirm_access_code"], "snapshotSize": 2}
  }
}
```

**Limitations of this slice**:

- Per-field projection is **opt-in** — when a field def lacks `instanceId`, only the form-level summary is emitted for that field. Backward-compatible by design.
- With `autoFields: true`, slotted FieldComponents auto-register their instance id into cfg.f.i — no caller-side `instanceId` plumbing required. The manual `fields` path still supports an explicit `instanceId` for bespoke wiring; both share the same trust perimeter.
- No new patch op, no new attribute allow-list entry. `disabled` remains off the allow-list — submit button cannot be disabled mid-flight.
- No persistence, no business action, no redirect, no CSRF / session policy.
- No multi-step forms, no async validation, no durable form state.
- Frontend runtime is unchanged. The patches go through the same safe applier the field input-change path already uses.

### Playground

`/ui-playground/components/field` now walks through thirteen scenarios: **Event metadata (declaration-only)** table, **Signed event manifest (inert JSON)**, **Frontend capture (no backend dispatch)** with a live capture log, **Backend dispatch — response patches** with a dispatch + patch lifecycle log and a visible server-ack target updated by a `setText` patch, **Server push — SSE patches** with Connect / Push / Disconnect buttons that exercise the full publish→subscribe→apply path, **Server validation — response patches** with `showValidationTarget: true` and live aria-invalid / ui-state / validation-message updates as the user types, bind, basic field, help, error, disabled, slots (prefix/suffix with primitives), `inputProps` caller overrides, and a multi-field fake form layout. The backend-dispatch, SSE, and validation demos are the only places on the playground that opt the transport bridges in and update visible patch targets; regular pages do not POST, subscribe, or validate automatically.

`/ui-playground/components/form` shows the **minimal FormComponent aggregation** slice end-to-end: three `FieldComponent`s inside a single `platform.form`, each with its own signed rule list. As the user types, every change triggers a dispatch; the runtime updates the field's own validation patches and then refreshes the form-level `form-status` text + `ui-state` attribute via two synthesised patches through the existing safe applier. The page exposes the live `forms.snapshot()` data and a dispatch log so the aggregate transition (`pending` → `invalid` → `valid`) is visible without DevTools. A second section, **Authoritative submit validation — per-field projection**, demonstrates the submit pipeline with two `access_code` fields wired with stable `instanceId`s (`uci_submit_access_code` / `uci_submit_confirm_access_code`) threaded into both the `fields` prop and each `platform.field` render. Pressing **Validate form** dispatches the signed submit ctx; the server runs every rule against the submitted snapshot and returns per-field patches (aria-invalid + ui-state + validation-message) AND the form-level summary — same patch shape, no persistence.

## Current limitations

- **SSE server-push channel is wired** (this slice). Patches can arrive via the dispatch response or pushed as canonical `ui.patch` frames over the single KISS stream (`/__semitexa_kiss`); both transports reuse the same `UiResponsePatch` shape and the same safe applier. SSE messages target DOM nodes that already exist inside the component instance — no node creation, no `innerHTML`, no arbitrary selectors.
- Patch op allow-list covers `setText`, `setValue`, `setAttribute` only. No `setHtml`, no class-list mutations, no node insertion/removal — those are future-slice concerns and intentionally absent.
- `setAttribute` is restricted to four allow-listed attribute names (`aria-invalid`, `aria-describedby`, `data-state`, `ui-state`). Anything else is rejected server-side and again by the frontend applier.
- A patch whose target element is missing in the rendered DOM (e.g. the caller did not pass `showServerAckTarget: true`) is a graceful no-op — the bridge emits `semitexa:ui-patch:failed` with `reason: "target_not_found"` and the rest of the batch continues.
- Dispatch responses are still **ack-style**: a single JSON body with optional `patches[]`. Streaming `/__ui/dispatch` responses (chunked patches over the dispatch transport) is not on the roadmap — clients that want streaming use the SSE channel.
- **Replay guard is wired through DI and runtime-checked**: `UiReplayStoreInterface` resolves to `CacheBackedUiReplayStore` by default via `SatisfiesServiceContract`, and the dispatcher's runtime guard refuses to invoke handlers in production-like environments when the bound store reports `isShared() === false`. The 503 `ui_replay_store_not_shared` response tells operators exactly what to fix. With `CACHE_DRIVER=redis` (this project's shipped config), replay protection is global across Swoole workers — verified live with 10/10 same-`(ctx,dispatchId)` requests returning 409.
- **Authorization hook is wired through DI**: `UiInteractionAuthorizerInterface` resolves to `AllowAllUiInteractionAuthorizer` by default via `SatisfiesServiceContract`. Apps override by registering their own implementation in a module that "extends" `semitexa-platform-ui`; the contract registry's module-order winner picks the descendant. No per-handler wiring required.
- No **full anti-abuse system**. Replay protection is exclusively `(ctx, dispatchId)` deduplication: a malicious client holding a valid `ctx` can mint as many fresh `dispatchId`s as it wants within the ctx TTL. Bot/abuse mitigation (rate limiting, captcha, behavioural heuristics) is a separate concern that should sit in pipeline middleware, not in the dispatcher.
- No **full policy / RBAC matrix**. The authorizer hook is a single yes/no decision per (component, instance, part, event). Richer policy expressions / role-aware scopes land in a later slice; the seam is intentionally narrow for now.
- No **replay nonce inside SignedContext**. Replay protection is exclusively `(ctx, dispatchId)` — the signed `ctx` is reusable within its TTL. Embedding a nonce in the signed context would force the server to mint a new ctx per dispatch, breaking opt-in transport bridging and complicating SSR.
- No **per-handler validation pipeline**. Handlers receive the raw (guard-scrubbed) payload; richer per-event payload schemas land later.
- No **DI-managed components yet**. Components must have a no-required-arg constructor. If they don't, the dispatcher returns 422 `cannot_instantiate_component` — by design, not a regression.
- The transport bridge is **opt-in per page**. The runtime never auto-attaches. This keeps non-event pages no-network and lets each surface decide its own dispatch contract.
- `SignedContext::sign` adds a TTL (default 300s). Captured events for an expired ctx will return 403 `invalid_signed_ctx`. Re-rendering the component reissues the ctx; no in-place re-sign API exists yet.
- Bind is **server-rendered projection only** — no client-side two-way binding, no live updates.
- Bind currently projects **`value` only**. `checked` / `selected` are not wired yet.
- `ProvidesUiPart` methods are pure projections; no IO, no service calls, no external data providers.
- `value` extraction on the wire body is intentionally minimal — `partEl.value` if present, then the `value` attribute. Composite values (e.g. `<select multiple>`, `<input type="file">`) are out of scope for this slice.
- Manifest entries currently target `value`-bound events only.
- The legacy `Semitexa\PlatformUi\Primitive\PrimitiveRegistry` coexists with the new attribute-driven registry; consumers should prefer the attribute-driven path.

### Future runtime steps (post-this-slice)

1. **Multi-provider custom rule contribution**: replace the full-registry-replacement model (this slice) with a contributor pattern so several modules can each add their own rules without coordinating on a single registry implementation. Rules continue to be sync + pure.
2. **Container-managed component instances**: replace `UiInteractionDispatcher::instantiate()`'s reflection-based `newInstance()` with a container-aware path so components can declare `#[InjectAsReadonly]` dependencies directly. Today components opt into the `UsesUiFieldRuleRegistry` bridge — once container-managed instantiation lands, the bridge can drop and rule-registry-aware components use property injection like every other Platform UI service.
3. **Multi-field / cross-field validation** — `FieldComponent`'s rule today sees only its own value. A future slice introduces a server-side validation context that can read related fields (e.g. password + confirm-password) without storing state.
4. **Richer patch ops**: `setClass` / `addClass` / `removeClass`, `setHidden`, conditional `setText` with templating, and a `redirect(...)` variant on `UiInteractionResult` — all under the same allow-list + instance-scoping invariants.
5. **DI-managed component instantiation**: container-aware resolution so handlers can declare typed dependencies in their component constructor.
6. **Atomic cache primitive** in `CacheManagerInterface` (SETNX / `add`) so `CacheBackedUiReplayStore::claim` can drop its get-then-put race window.
7. **SSE delivery semantics upgrade**: at-least-once via Redis pub/sub fanout (today the bridge uses LPOP — at-most-once per claim), plus reconnect with `Last-Event-ID` so a transient drop does not lose patches.
8. **SSE channel revocation**: an out-of-band revocation set (Redis key with token id) so an issued channel token can be invalidated before its TTL expires.
9. **Persistent component state** — a server-side projection a handler can read/mutate, with SSE deltas published over the canonical KISS stream. The validation result type from this slice is the smallest shape that fits — the persistent state layer will wrap it, not replace it. (Patch shape stays the same.)
10. **Framework-layer unification**: a `UiInteractionDispatcherInterface` contract so SSR's `/__ui/event` can delegate to platform-ui's dispatcher; the two endpoints collapse into one. (The streaming half of this unification is **done** — all UI streaming now rides the single `/__semitexa_kiss` stream on `AsyncResourceSseServer`.)
