# Next-Generation Semitexa UI Module Technical Design

## 1. Executive summary

The next-generation `semitexa/platform-ui` module should define a backend-first UI language for Semitexa.

The central distinction is:

- `#[AsUiPrimitive]` defines an atomic UI element: button, input, select, checkbox, badge, icon, panel, text.
- `#[AsComponent]` defines a composed UI object: form, field, data grid, filter panel, dashboard block, admin screen section.
- components are composed from primitives and other components through explicit attribute-level contracts.

This means a primitive is not a component subtype. A primitive and a component are different public concepts. They may share internal rendering, asset, event, and discovery infrastructure, but their semantics remain separate.

The existing `#[AsComponent]` prototype in `semitexa/ssr` is still important. It proves that Semitexa already has the right ingredients: attribute-driven classes, templates, script asset keys, frontend triggers, backend events, signed browser-to-backend event transport, and JavaScript runtime mounting. The UI module should reuse that infrastructure, not copy another framework.

The final design principle is:

**`AsUiPrimitive` defines the atomic UI vocabulary. `AsComponent` composes that vocabulary into feature-level UI structures. `UiPart` and `UiSlot` declare the composition contract. Twig renders declared parts; it does not invent the contract.**

## 2. Current state analysis

### 2.1 Existing `AsComponent` prototype

`semitexa/ssr` currently provides:

```php
#[Attribute(Attribute::TARGET_CLASS)]
class AsComponent
{
    public function __construct(
        public string $name,
        public ?string $template = null,
        public ?string $layout = null,
        public bool $cacheable = true,
        public ?string $event = null,
        public array $triggers = [],
        public ?string $script = null,
    ) {}
}
```

The implementation around it already solves several hard problems:

- `ComponentRegistry` discovers classes with `#[AsComponent]`.
- `ComponentRenderer` renders the template and injects component context.
- `ComponentRenderer` requires script assets through `AssetCollector`.
- `ComponentEventBridge` signs event manifests and annotates rendered roots.
- `component-events.js` delegates frontend triggers.
- `ComponentEventDispatchHandler` validates origin, trigger, event class, signature, TTL, and session binding before dispatching a typed event.
- `component-runtime.js` mounts asset-key JavaScript behavior by component name.

This is the prototype for the shared UI runtime substrate. It should inform primitives, but it should not collapse primitives and components into one public concept.

### 2.2 Existing `platform-ui` prototype

`semitexa/platform-ui` currently provides:

- CSS grammar attributes: `sx-*` for layout/composition and `ui-*` for primitive modifiers;
- six CSS-backed primitives: `button`, `input`, `label`, `field-shell`, `surface`, `badge`;
- `Primitive\Primitive` metadata DTO;
- `PrimitiveRegistry` with hard-coded defaults;
- `TwigExtractor` that scans static markup for `ui`, `ui-*`, and `sx-*`;
- `BundleCompiler` that builds CSS bundles from grammar slices and primitive IDs;
- docs for grammar, primitives, skin generation, prompt resolution, and SSR integration.

This prototype proves the token/CSS language, but it does not yet expose primitives as first-class framework declarations.

### 2.3 Existing theme and skin layer

`semitexa/theme` owns:

- theme manifests;
- theme discovery;
- theme inheritance;
- active theme/skin resolution;
- skin discovery under `/assets/skins/<slug>/tokens.css`;
- the semantic `--ui-*` token contract;
- Twig helpers such as `theme_layout()` and `theme_skin_css()`.

`semitexa/skins-base` owns:

- skin generation CLI;
- skin refinement CLI;
- prompt explanation CLI;
- default framework skin assets.

The UI module consumes this layer. It does not compute colors, generate skins, or select active skins.

### 2.4 Existing Semitexa architectural principles

The repository favors:

- attribute-driven registration;
- explicit PHP classes;
- typed payloads, resources, and events;
- class-based handlers and listeners;
- asset keys instead of anonymous frontend code;
- transparent backend-to-frontend contracts;
- templates as render surfaces, not hidden architecture.

The UI design should follow the same pattern.

## 3. Problems with the current prototype

The current `platform-ui` prototype is useful but incomplete:

1. Primitive identity lives in a hard-coded registry, not in PHP declarations.
2. Primitive frontend behavior is not declared at class level.
3. Primitive backend events are not declared at class level.
4. Primitive token usage and accessibility expectations are not explicit.
5. Components can only compose primitives implicitly through Twig markup.
6. LLMs and developers cannot reliably inspect which primitives a component is allowed to use.
7. Higher-level UI packages cannot safely depend on primitive contracts.
8. Component classes can look like empty markers instead of the place where component props, part defaults, primitive values, and part event mappings are made explicit.

The fix is not to treat primitives as components. The fix is to introduce a clean two-level model with a shared runtime substrate.

## 4. Proposed terminology

### UI Primitive

The smallest reusable UI declaration. Examples: button, input, select, checkbox, radio, switch, badge, icon, panel, text, divider.

A UI primitive:

- is declared by a PHP class;
- is marked with `#[AsUiPrimitive]`;
- has stable identity;
- has optional template or element rendering;
- owns allowed modifiers and states;
- declares optional JS behavior asset;
- declares optional frontend-to-backend events;
- consumes theme/skin tokens;
- can be used directly in static markup or through rendering helpers.

### Component

A composed UI object declared by `#[AsComponent]`. Examples: field, form, data grid, filter panel, dashboard block, admin section.

A component:

- is declared by a PHP class;
- has a template;
- may have JS behavior and backend events;
- declares named `UiPart` dependencies;
- declares optional `UiSlot` extension points;
- receives immutable input/configuration props from the caller or resource;
- owns an explicit State DTO for mutable UI/runtime state;
- may define an optional `mount()` hook for initialization;
- exposes initial values and default props for its parts;
- can coordinate explicit data providers;
- maps part-level frontend events into explicit backend handlers;
- composes primitives and other components.

The component class is therefore a composition presenter. It is not only metadata for a template, and it is not an application service.

### UI Composite

A reusable composition that is smaller than a feature-level component but larger than a primitive. Examples: field shell, button group, menu item row, input-with-addon.

For event purposes, a composite follows the same rules as a component: it exposes declared parts/events and does not allow hidden raw primitive event wiring.

### Component Props

Immutable external input/configuration passed to the component constructor. Constructor readonly properties are allowed for values such as `name`, `label`, `action`, `type`, feature flags, or static configuration.

Constructor props must not represent mutable UI state.

### Component State DTO

An explicit object that represents mutable UI/runtime state for a component instance.

Examples:

- current input value;
- validation status;
- derived option selection;
- dependent field visibility;
- local pending/busy flags.

Mutable primitive values should bind to the State DTO, not to constructor props.

### Mount Hook

An optional lifecycle method that runs after component instantiation and context preparation.

`mount()` may derive initial state, normalize input, resolve defaults, and prepare initial primitive values. It must not execute write-side business operations.

### UI Part

A named role inside a component that uses a primitive or another component.

Examples:

- `label` in a field component;
- `control` in a field component;
- `submit` in a form component;
- `search` in a data grid;
- `row-action` in a data grid.

The part name explains intent. This is stronger than a flat `uses: ['button', 'input']` list.

### UI Slot

A controlled extension point where the caller may provide allowed primitives/components.

Examples:

- `row-actions` in a data grid;
- `toolbar-actions` in an admin screen;
- `footer` in a modal.

### Template

A rendering surface. Templates place declared parts and slots. They should not invent undeclared primitive/component dependencies.

### Theme

A request-selected visual/template system.

### Skin

A concrete token implementation selected by a theme.

### Resource

A backend response object carrying render context. Resources remain outside `platform-ui`.

### UI Event

A Semitexa-owned interaction unit flowing through the UI Event Pipeline. A UI Event is not a raw browser callback and not a controller route. It has declared origin, payload, signed context, handler, transport, and response policy.

### Primitive Event

An event capability declared by a primitive. Example: `button.click`, `input.change`, `select.change`. It defines which semantic event the primitive can emit, which native DOM event may trigger it, which payload is allowed, and whether the event may reach the backend.

### Component Event

A component-scoped binding that gives meaning to a primitive or nested component event inside a specific component. Example: `FieldComponent` maps `control.change` to `FieldValueChangedHandler`.

### Semantic Event

A Semitexa event name that expresses UI intent. It may map to one or more native DOM events. Examples: `click`, `change`, `submit`, `rowClick`, `filterChanged`, `pageChanged`.

### Native DOM Event

A browser event such as `click`, `input`, `change`, `submit`, `focus`, or `blur`. The frontend runtime may observe native events, but backend declarations should bind to Semitexa semantic events.

### Event Binding

A declaration that maps a declared primitive/component event to a backend handler. In components this is expressed through `#[UiOn]`.

### Event Context

The runtime identity needed to process an event: primitive name, part name, component name, component instance id, view/render id, binding path, target path, user/session reference, and authorization scope.

### Event Payload

The typed data allowed for a UI Event. Payloads should be DTO-backed where possible and validated before handler execution.

### Event Envelope

The normalized serialized message sent by the frontend runtime to the backend. It contains event identity, payload, event context, transport metadata, timestamp, correlation id, and signed context.

### Event Handler

An explicit backend handler for a UI Event. The frontend never chooses arbitrary handlers; it can only submit an envelope whose signed context was produced by the renderer.

### Event Transport

The mechanism used to move event envelopes and responses. MVP uses HTTP request/response for browser-to-server UI events and SSE for server-to-browser updates. WebSocket remains a future transport behind the same abstraction.

### Event Response

The normalized backend response to a UI Event. It may be no-op, validation errors, state patch, props patch, re-render instruction, frontend instruction, redirect, notification, SSE subscription update, command accepted, or error.

### State Patch

A backend-approved mutation to the component State DTO.

### Props Patch

A backend-approved update to rendered part/component props. This is used when a primitive's visual or behavioral props should change without redefining the component.

### Frontend Instruction

A small framework-recognized instruction for the frontend runtime, such as focus a part, clear a value, show a toast, open a modal, or subscribe to an SSE channel. It is not arbitrary JavaScript.

### SSE Channel

A server-to-client update stream declared by Semitexa metadata and authorized by signed/session context. SSE channels are used for pushed component updates, async command progress, and external changes.

### Event Correlation ID

A stable id linking the original frontend event, backend handler execution, HTTP response, logs, traces, and optional later SSE updates.

### Signed Event Context

A tamper-resistant token embedded by the renderer. It proves that a rendered primitive/component instance is allowed to emit a specific event to a specific backend binding within a TTL/session/authorization scope.

## 5. Proposed package responsibilities

### `semitexa/platform-ui`

Owns:

- `#[AsUiPrimitive]`;
- `#[UiPrimitiveEvent]` or equivalent event metadata;
- built-in primitive declarations;
- primitive templates;
- primitive CSS;
- primitive JS assets;
- primitive registry and introspection;
- base `UiPart` and `UiSlot` attributes if they are not moved into SSR;
- `ProvidesUiPart` for component-owned part props;
- `UiOn` for component-scoped part event mapping;
- `UiPartDataProviderInterface` for explicit primitive/component part data;
- primitive event declarations and payload schemas;
- UI event binding registry;
- UI event response DTOs used by Platform UI handlers;
- SSE channel declarations for UI components;
- part prop resolver for defaults, bindings, providers, and template overrides;
- component-composition validation rules;
- UI event validation and introspection commands;
- `primitive()` and `ui_part()` Twig helpers if they are not placed in SSR.

### `semitexa/ssr`

Owns shared runtime infrastructure:

- component rendering;
- template rendering;
- asset collection;
- JavaScript runtime mounting;
- signed event transport;
- signed event context services;
- UI event envelope serialization;
- response-capable interaction dispatch;
- SSE channel management;
- post-render hooks;
- component metadata access.

It should expose reusable services that primitives and components can both use.

### `semitexa/theme`

Owns theme/skin resolution and token contract.

### `semitexa/skins-base`

Owns skin generation/refinement CLI and default framework skin assets.

### Future packages

Future packages build components from primitives:

- `semitexa/forms-ui`;
- `semitexa/grid-ui`;
- `semitexa/admin-ui`;
- `semitexa/dashboard-ui`.

## 6. Proposed architecture

### 6.1 Two public attributes, one shared substrate

Public concepts:

```text
AsUiPrimitive  -> atomic UI vocabulary
AsComponent    -> composed UI object
```

Shared internal substrate:

```text
rendering
asset binding
signed event manifest
semantic event validation
frontend runtime mount
introspection
graph edges
```

This avoids semantic confusion while preserving implementation reuse.

### 6.2 Primitive declaration

```php
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Attribute\UiPrimitiveEvent;

#[AsUiPrimitive(
    name: 'button',
    element: 'button',
    template: '@platform-ui/primitives/button.twig',
    variants: ['solid', 'soft', 'ghost'],
    tones: ['neutral', 'brand', 'success', 'warning', 'danger'],
    sizes: ['sm', 'md', 'lg'],
    states: ['default', 'disabled', 'busy'],
    script: null,
    tokens: [
        '--ui-accent-brand',
        '--ui-accent-brand-contrast',
        '--ui-focus-ring',
        '--ui-radius-md',
    ],
    events: [
        new UiPrimitiveEvent(
            name: 'click',
            native: 'click',
            payload: ButtonClickPayload::class,
            response: UiEventResponseMode::Command,
        ),
    ],
)]
final class ButtonPrimitive
{
}
```

The primitive itself answers:

- what it is called;
- what element/template renders it;
- which modifiers are legal;
- which states are legal;
- which script enhances it;
- which UI events it can emit;
- which tokens affect it.

No `#[AsComponent]` is needed on the primitive.

### 6.3 Component declaration

```php
use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;

#[AsComponent(
    name: 'contacts.form',
    template: '@contacts/components/contact-form.twig',
    script: 'contacts:js:contact-form',
)]
#[UiPart(name: 'name', uses: InputPrimitive::class)]
#[UiPart(name: 'email', uses: InputPrimitive::class)]
#[UiPart(name: 'submit', uses: ButtonPrimitive::class)]
#[UiPart(name: 'cancel', uses: ButtonPrimitive::class, optional: true)]
#[UiSlot(name: 'footer-actions', accepts: [ButtonPrimitive::class], multiple: true)]
final class ContactFormComponent
{
}
```

The component declares its composition contract before Twig renders anything.

### 6.4 Why `UiPart` is required

A component should not merely say:

```php
uses: ['button', 'input']
```

That loses intent.

Instead:

```php
#[UiPart(name: 'submit', uses: ButtonPrimitive::class)]
#[UiPart(name: 'cancel', uses: ButtonPrimitive::class)]
#[UiPart(name: 'email', uses: InputPrimitive::class)]
```

Now Semitexa knows not only which primitive is used, but why it exists inside the component.

This improves:

- validation;
- documentation;
- LLM guidance;
- graph impact analysis;
- refactoring;
- component API design.

### 6.5 Role of the component class

The component class must not be an empty marker. It is the **composition presenter** for a composed UI object.

Its responsibilities:

- declare the component's public identity through `#[AsComponent]`;
- declare internal structure through `#[UiPart]`;
- declare extension points through `#[UiSlot]`;
- receive immutable component props from the resource or caller;
- own an explicit State DTO for mutable UI/runtime state;
- optionally initialize that state in `mount()`;
- expose initial values for parts from state or immutable props;
- resolve default props for each part;
- coordinate optional data providers;
- map frontend part events into explicit backend handlers;
- keep business logic outside the template.

It should not become a domain service or controller. Business behavior still belongs in Semitexa payload handlers, event listeners, repositories, and application services. The component class prepares UI data and UI behavior contracts.

Constructor readonly properties are valid for immutable external input/configuration only. Mutable UI values must live in a State DTO. `mount()` is an initialization hook, not a command handler. Event handlers are the explicit place for state transitions.

Example:

```php
final class FieldState
{
    public function __construct(
        public mixed $value = null,
        public ?string $error = null,
    ) {
    }
}

#[AsComponent(
    name: 'platform.field',
    template: '@platform-ui/components/field.twig',
)]
#[UiPart(name: 'label', uses: LabelPrimitive::class)]
#[UiPart(name: 'control', uses: InputPrimitive::class, bind: 'state.value')]
#[UiPart(name: 'error', uses: TextPrimitive::class, optional: true)]
#[UiOn(part: 'control', event: 'change', handler: FieldValueChangedHandler::class, payload: FieldValueChangedPayload::class)]
final class FieldComponent
{
    private FieldState $state;

    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
    ) {
    }

    public function mount(UiComponentContext $context): void
    {
        $this->state = new FieldState(
            value: $context->input('value'),
            error: $context->input('error'),
        );
    }

    #[ProvidesUiPart('label')]
    public function labelPart(): array
    {
        return [
            'for' => $this->name,
            'text' => $this->label,
        ];
    }

    #[ProvidesUiPart('control')]
    public function controlPart(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'value' => $this->state->value,
            'state' => $this->state->error === null ? 'default' : 'invalid',
        ];
    }

    #[ProvidesUiPart('error')]
    public function errorPart(): array
    {
        return [
            'text' => $this->state->error,
            'tone' => 'danger',
        ];
    }
}
```

This makes the class useful without hiding behavior in Twig.

### 6.6 Part prop resolution

When a template calls:

```twig
{{ ui_part('control', { placeholder: 'Email' }) }}
```

Semitexa resolves final primitive props in a deterministic order:

1. primitive defaults from `AsUiPrimitive`;
2. part defaults from `UiPart(defaults: ...)`;
3. immutable component props when explicitly addressed, for example `prop.name`;
4. component State DTO values bound through `UiPart(bind: 'state.value')`;
5. component provider method marked with `#[ProvidesUiPart('control')]`;
6. external data provider configured on the part;
7. explicit `ui_part()` template overrides.

Later layers may override earlier layers, but validators should warn when an override violates the primitive contract.

This gives developers a clean rule: component class owns defaults and values; Twig may only provide local rendering overrides.

Binding rules:

- mutable primitive values should bind to `state.*`;
- immutable constructor props may be read as `prop.*`;
- bare binding names may be supported as a migration shorthand, but strict mode should prefer explicit `state.*` or `prop.*`;
- `bind` must not point to a service, repository, or template variable.

### 6.7 Data providers

Some primitives need data: select options, autocomplete candidates, menu items, grid rows, dashboard metrics.

Data should not be embedded in primitive templates. It should come from explicit providers.

Part-level provider:

```php
#[UiPart(
    name: 'country',
    uses: SelectPrimitive::class,
    provider: CountryOptionsProvider::class,
)]
final class AddressFormComponent
{
}
```

Provider contract:

```php
interface UiPartDataProviderInterface
{
    public function provide(UiPartContext $context): array;
}
```

Provider output is merged into part props during part prop resolution.

Primitive-specific providers are also possible. For example, `SelectPrimitive` may document that it accepts `options`, and a component part may provide those options. The primitive does not know where options came from.

Rules:

- provider classes are explicit service classes;
- providers receive a typed context, not global state;
- providers return structured props/data only;
- providers do not render HTML;
- providers do not mutate component state;
- providers are read-oriented;
- providers may use read-model services, query services, read-only repositories, projections, or deterministic context-based providers;
- providers must not execute write-side application services, commands, persistence operations, or business mutations.

If a provider needs business data, expose that data through a read-model/query abstraction first. Write-side application services belong in event handlers, submit handlers, command handlers, or explicit backend behavior handlers.

### 6.8 Value binding and change events

Value binding connects a component State DTO path or immutable prop path to a primitive part.

Example:

```php
#[UiPart(name: 'email', uses: InputPrimitive::class, bind: 'state.email')]
#[UiOn(part: 'email', event: 'change', handler: EmailChangedHandler::class, payload: EmailChangedPayload::class)]
final class ContactFormComponent
{
    private ContactFormState $state;
}
```

The binding means:

- initial primitive value comes from `$component->state->email`;
- the rendered primitive receives `value`;
- frontend changes include `part=email`, `binding=state.email`, and the new value;
- backend validates that the part exists and is bound;
- backend routes the declared typed event to a behavior handler and returns the allowed UI response.

The event payload should include:

```json
{
  "component": "contacts.form",
  "part": "email",
  "binding": "state.email",
  "value": "person@example.test",
  "previous": "old@example.test"
}
```

For MVP, backend value-change events should support a limited response model:

- validation result;
- component state patch;
- part props patch;
- optional frontend instruction metadata.

MVP value-change events should not perform persistence by default.

The distinction is strict:

- `change` events handle validation, derivation, dependent-field updates, and local state updates;
- `submit` and `action` events handle command execution, persistence, and business operations.

## 7. Proposed attributes

### 7.1 `AsUiPrimitive`

Initial shape:

```php
#[Attribute(Attribute::TARGET_CLASS)]
final class AsUiPrimitive
{
    public function __construct(
        public string $name,
        public ?string $element = null,
        public ?string $template = null,
        public ?string $script = null,
        public array $variants = [],
        public array $tones = [],
        public array $sizes = [],
        public array $states = [],
        public array $tokens = [],
        public array $events = [],
        public ?string $accessibility = null,
    ) {}
}
```

Rules:

- `name` is the primitive identity and default `ui` CSS identity.
- either `element` or `template` must be present;
- `script` must be a valid asset key if present;
- `events` must be `UiPrimitiveEvent` declarations;
- token names must belong to the active token contract.

### 7.2 `UiPrimitiveEvent`

```php
final readonly class UiPrimitiveEvent
{
    public function __construct(
        public string $name,
        public string|array|null $native = null,
        public ?string $payload = null,
        public UiEventMode $mode = UiEventMode::Backend,
        public UiEventTransport $transport = UiEventTransport::Http,
        public UiEventResponseMode $response = UiEventResponseMode::Patch,
        public array $value = [],
        public ?int $debounceMs = null,
        public ?string $description = null,
    ) {}
}
```

This declares what the primitive can emit. It does not decide which backend handler will process the event inside a component.

Rules:

- `name` is the Semitexa semantic event name;
- `native` maps the semantic event to browser DOM events when needed;
- `payload` points to an optional payload DTO/schema;
- `mode` may be `Local`, `Backend`, or `Broadcast`;
- `transport` defines the default outbound transport;
- `response` defines whether the primitive expects no response, patches, command acknowledgement, or async updates.

### 7.3 `UiPart`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class UiPart
{
    public function __construct(
        public string $name,
        public string $uses,
        public bool $optional = false,
        public bool $multiple = false,
        public array $defaults = [],
        public ?string $bind = null,
        public ?string $provider = null,
        public ?string $description = null,
    ) {}
}
```

`uses` may point to:

- a class marked with `#[AsUiPrimitive]`;
- a class marked with `#[AsComponent]`;
- later, an interface or abstract component family if needed.

`bind` links the part to a component State DTO path or immutable prop path. Prefer explicit paths such as `state.email` or `prop.name`. `provider` points to a class that implements `UiPartDataProviderInterface`.

### 7.4 `UiSlot`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class UiSlot
{
    public function __construct(
        public string $name,
        public array $accepts = [],
        public bool $required = false,
        public bool $multiple = false,
        public ?string $description = null,
    ) {}
}
```

Slots are caller-facing extension points. Parts are component-owned internals.

### 7.5 `ProvidesUiPart`

```php
#[Attribute(Attribute::TARGET_METHOD)]
final class ProvidesUiPart
{
    public function __construct(
        public string $name,
    ) {}
}
```

This marks a method on the component class as a provider for one part's props. It keeps part data close to the component class without pushing data logic into Twig.

Provider methods may read immutable props and the component State DTO. They should be deterministic for the current component instance, return structured props only, and avoid persistence, command execution, or write-side business behavior.

### 7.6 `UiOn`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class UiOn
{
    public function __construct(
        public string $part,
        public string $event,
        public string $handler,
        public ?string $payload = null,
        public UiEventTransport $transport = UiEventTransport::Http,
        public UiEventResponseMode $response = UiEventResponseMode::Patch,
        public array $context = [],
        public ?string $sse = null,
        public array $staticPayload = [],
    ) {}
}
```

`UiOn` maps a declared primitive/component event from a declared part into an explicit backend handler. It is component-scoped, so the same primitive can have different meaning in different components.

Handler resolution:

- if `handler` is a class name, it must be a resolvable handler service;
- if `handler` is a method name, it resolves to a method on the component class;
- handler ambiguity is a validation failure;
- missing handlers are validation failures;
- the referenced part must use a primitive/component that declares the event.

Preferred production style is a separate handler class for traceability and dependency injection. Component methods are acceptable for small local UI state transitions when the behavior remains obvious.

### 7.6.1 `UiEventMode`, `UiEventTransport`, and `UiEventResponseMode`

```php
enum UiEventMode: string
{
    case Local = 'local';
    case Backend = 'backend';
    case Broadcast = 'broadcast';
}

enum UiEventTransport: string
{
    case Http = 'http';
    case Sse = 'sse';
    case WebSocket = 'websocket';
}

enum UiEventResponseMode: string
{
    case None = 'none';
    case Patch = 'patch';
    case Rerender = 'rerender';
    case Command = 'command';
    case Async = 'async';
}
```

Browser-to-backend events use HTTP in the MVP. SSE is server-to-browser only in the MVP. WebSocket is reserved for future transport implementations.

### 7.6.2 `UiEventHandlerInterface`

```php
interface UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse;
}
```

Concrete handlers may use more specific payload DTOs and may receive the current component State DTO if the framework can resolve it safely:

```php
final class EmailChangedHandler
{
    public function handle(
        EmailChangedPayload $payload,
        UiEventContext $context,
        ContactFormState $state,
    ): UiEventResponse {
        // UI state transition only.
    }
}
```

### 7.6.3 `UiSseChannel`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class UiSseChannel
{
    public function __construct(
        public string $name,
        public ?string $payload = null,
        public array $scope = [],
        public bool $autoSubscribe = false,
        public ?string $description = null,
    ) {}
}
```

`UiSseChannel` declares an authorized server-push channel for a component. It does not define backend handlers and it does not allow skins or templates to invent subscriptions.

### 7.7 `UiPartDataProviderInterface`

```php
interface UiPartDataProviderInterface
{
    public function provide(UiPartContext $context): array;
}
```

Providers return structured part props/data. They do not render HTML.

External providers are read-side collaborators. They may call query/read-model/projection services or repositories in read-only mode. They must not call write-side application services or commands.

### 7.8 `AsComponent`

`AsComponent` remains the attribute for complex UI objects. It should not be used on atomic primitives.

It can retain its current runtime fields:

- `name`;
- `template`;
- `layout`;
- `cacheable`;
- `event`;
- `triggers`;
- `script`.

For Platform UI, component event bindings should use `UiOn`. Existing `event` and `triggers` fields can remain for SSR backward compatibility, but new UI components should prefer the unified UI Event Pipeline.

## 8. Primitive lifecycle

1. A primitive class is marked with `#[AsUiPrimitive]`.
2. `UiPrimitiveRegistry` discovers it.
3. The registry validates name, element/template, script, events, modifiers, tokens, and accessibility metadata.
4. A template may render it directly through static markup or through `primitive()`.
5. Static markup uses the primitive CSS contract:

```twig
<button ui="button" ui-tone="brand">Save</button>
```

6. Rendered primitive helper uses metadata:

```twig
{{ primitive('button', { label: 'Save', tone: 'brand' }) }}
```

7. If the primitive declares a script, the shared asset runtime requires it.
8. If the primitive declares events, the renderer exposes event capabilities to the UI Event Pipeline.
9. CSS consumes active skin tokens loaded through `theme_skin_css()`.

## 9. Component lifecycle

1. A component class is marked with `#[AsComponent]`.
2. The component declares internal parts with `#[UiPart]`.
3. The component declares external extension points with `#[UiSlot]`.
4. The component may declare part providers with `#[ProvidesUiPart]`.
5. The component may declare part event mappings with `#[UiOn]`.
6. `ComponentRegistry` discovers the component.
7. `UiCompositionRegistry` reads its parts, slots, providers, and event mappings.
8. At render time the component instance is created from immutable caller/resource props.
9. A component State DTO is prepared for mutable UI/runtime state.
10. Optional `mount()` runs after component context preparation.
11. `mount()` may derive initial state, normalize input, resolve defaults, and prepare primitive values.
12. The part resolver derives part props from primitive defaults, part defaults, immutable props, State DTO bindings, provider methods, external providers, and template overrides.
13. The component template renders declared parts via `ui_part()`.
14. The template renders slots via `ui_slot()`.
15. The validator checks that template usage matches the attribute contract.
16. The renderer emits signed event context for each backend-bound `UiOn`.
17. The component can declare its own script/runtime metadata through `AsComponent`.

Lifecycle boundaries:

- constructor props are immutable external input/configuration;
- State DTO is the only place for mutable UI/runtime state;
- `mount()` is initialization only and must not perform write-side business operations;
- event handlers perform explicit state transitions;
- services should follow existing Semitexa dependency injection conventions, not turn component constructors into service locators.

## 10. Rendering pipeline

### 10.1 Primitive direct markup

Fast static path for primitive documentation, playgrounds, low-level examples, and prototypes:

```twig
<span ui="badge" ui-tone="success" ui-variant="soft">Active</span>
```

Real component templates should use declared `ui_part()` calls instead of raw primitive markup.

### 10.2 Primitive helper

Contract-aware primitive rendering:

```twig
{{ primitive('badge', {
    text: 'Active',
    tone: 'success',
    variant: 'soft'
}) }}
```

### 10.3 Component with parts

Component declaration:

```php
final class FieldState
{
    public function __construct(
        public mixed $value = null,
        public ?string $error = null,
    ) {
    }
}

#[AsComponent(
    name: 'platform.field',
    template: '@platform-ui/components/field.twig',
)]
#[UiPart(name: 'label', uses: LabelPrimitive::class)]
#[UiPart(name: 'control', uses: InputPrimitive::class, bind: 'state.value')]
#[UiPart(name: 'error', uses: TextPrimitive::class, optional: true)]
#[UiOn(part: 'control', event: 'change', handler: FieldValueChangedHandler::class, payload: FieldValueChangedPayload::class)]
final class FieldComponent
{
    private FieldState $state;

    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
    ) {
    }

    public function mount(UiComponentContext $context): void
    {
        $this->state = new FieldState(
            value: $context->input('value'),
            error: $context->input('error'),
        );
    }

    #[ProvidesUiPart('label')]
    public function labelPart(): array
    {
        return ['for' => $this->name, 'text' => $this->label];
    }

    #[ProvidesUiPart('control')]
    public function controlPart(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'value' => $this->state->value,
            'state' => $this->state->error === null ? 'default' : 'invalid',
        ];
    }

    #[ProvidesUiPart('error')]
    public function errorPart(): array
    {
        return ['text' => $this->state->error, 'tone' => 'danger'];
    }
}
```

Component template:

```twig
<div data-ui-component="platform.field" data-state="{{ state.error ? 'invalid' : 'default' }}">
    {{ ui_part('label') }}
    {{ ui_part('control', { placeholder: placeholder|default(null) }) }}

    {% if state.error %}
        {{ ui_part('error') }}
    {% endif %}
</div>
```

### 10.4 Component with slots

```php
#[AsComponent(
    name: 'platform.data-grid',
    template: '@platform-ui/components/data-grid.twig',
    script: 'platform-ui:js:data-grid',
)]
#[UiPart(name: 'search', uses: InputPrimitive::class, optional: true)]
#[UiPart(name: 'export', uses: ButtonPrimitive::class, optional: true)]
#[UiSlot(name: 'row-actions', accepts: [ButtonPrimitive::class, DropdownPrimitive::class], multiple: true)]
final class DataGridComponent
{
}
```

Template:

```twig
<section data-ui-component="platform.data-grid">
    <header>
        {{ ui_part('search', { type: 'search', name: 'q', value: q }) }}
        {{ ui_part('export', { text: 'Export', variant: 'soft' }) }}
    </header>

    <table>
        {% for row in rows %}
            <tr>
                <td>{{ row.name }}</td>
                <td>{{ ui_slot('row-actions', { row: row }) }}</td>
            </tr>
        {% endfor %}
    </table>
</section>
```

## 11. Theme and skin integration

Theme/skin integration remains token-based.

Rules:

- primitives consume `--ui-*` tokens;
- components compose primitives and may add layout CSS;
- skins provide token values;
- themes select skins and templates;
- LLM skin generation may produce token values only;
- LLMs must not invent primitive names, component parts, slots, scripts, event bindings, or backend handlers.

Layout usage:

```twig
<link rel="stylesheet" href="/assets/platform-ui/css/full.css">
<link rel="stylesheet" href="{{ theme_skin_css() }}">
```

Primitive CSS:

```css
[ui="button"][ui-tone="brand"] {
    background: var(--ui-accent-brand);
    color: var(--ui-accent-brand-contrast);
    border-radius: var(--ui-radius-md, 0.5rem);
}
```

## 12. Unified UI Event Pipeline

The Semitexa UI Event Pipeline is the framework-native path for interactive UI.

Developers declare:

1. which events a primitive can emit;
2. which component part owns a rendered primitive;
3. which backend handler processes each event;
4. which response model is expected.

Semitexa wires:

1. rendered metadata;
2. signed event context;
3. frontend listener attachment;
4. normalized event envelope creation;
5. HTTP transport;
6. backend validation and handler resolution;
7. response normalization;
8. frontend patch/instruction application;
9. optional SSE updates.

There is no hidden "JavaScript calls controller URL" model. The frontend runtime can only submit event envelopes that were enabled by framework-rendered, signed metadata.

### 12.1 Primitive Event Declaration

Primitives declare event capabilities through `UiPrimitiveEvent` metadata inside `AsUiPrimitive`.

MVP should not add a separate `#[EmitsUiEvent]` attribute. The primitive already has one identity attribute, and event capabilities are part of that primitive contract.

```php
#[AsUiPrimitive(
    name: 'button',
    element: 'button',
    events: [
        new UiPrimitiveEvent(
            name: 'click',
            native: 'click',
            payload: ButtonClickPayload::class,
            mode: UiEventMode::Backend,
            transport: UiEventTransport::Http,
            response: UiEventResponseMode::Command,
        ),
    ],
)]
final class ButtonPrimitive
{
}
```

```php
#[AsUiPrimitive(
    name: 'input',
    element: 'input',
    events: [
        new UiPrimitiveEvent(
            name: 'change',
            native: 'change',
            payload: InputValueChangedPayload::class,
            value: ['property' => 'value'],
            response: UiEventResponseMode::Patch,
        ),
        new UiPrimitiveEvent(
            name: 'input',
            native: 'input',
            payload: InputValueChangedPayload::class,
            value: ['property' => 'value'],
            debounceMs: 250,
            response: UiEventResponseMode::Patch,
        ),
        new UiPrimitiveEvent(name: 'focus', native: 'focus', mode: UiEventMode::Local, response: UiEventResponseMode::None),
        new UiPrimitiveEvent(name: 'blur', native: 'blur', mode: UiEventMode::Local, response: UiEventResponseMode::None),
    ],
)]
final class InputPrimitive
{
}
```

A primitive event declaration answers:

- semantic event name;
- native DOM event source;
- allowed payload DTO/schema;
- value extraction rule;
- local/backend/broadcast mode;
- default transport;
- expected response mode;
- debounce/throttle hints.

It does not decide which component handler receives the event.

### 12.2 Component Event Binding

Components bind declared part events to explicit backend handlers through `UiOn`.

```php
#[AsComponent(name: 'platform.field', template: '@platform-ui/components/field.twig')]
#[UiPart(name: 'control', uses: InputPrimitive::class, bind: 'state.value')]
#[UiOn(
    part: 'control',
    event: 'change',
    handler: FieldValueChangedHandler::class,
    payload: FieldValueChangedPayload::class,
    response: UiEventResponseMode::Patch,
)]
final class FieldComponent
{
    private FieldState $state;
}
```

The binding is readable without opening Twig:

- part `control`;
- primitive/component used by the part;
- event `change`;
- payload DTO;
- backend handler;
- response model.

Handler resolution rules:

- `UiOn::part` must reference a declared `UiPart`;
- the part target must declare the event through `UiPrimitiveEvent` or equivalent component event metadata;
- `handler` must resolve to exactly one backend callable;
- handler classes are preferred for production code;
- component methods are allowed for small local UI state transitions;
- conflicting handlers for the same component instance, part, and event are validation failures;
- missing handlers are validation failures.

Nested components use a target path. A child component can handle its own event, or the parent can bind to a child part/slot event only through an explicit declared exposed event. Parent templates cannot silently intercept child primitive events.

Direct `primitive()` event binding may be allowed only for low-level examples, playgrounds, or standalone backend-rendered views, and only through a server-created `UiEventBinding` object. Real components must use `UiPart` + `UiOn` so the event map remains inspectable before Twig is read.

### 12.3 Event Envelope

The frontend runtime sends a canonical `UiEventEnvelope`.

Mandatory MVP shape:

```json
{
  "schema": "semitexa.ui-event/v1",
  "eventId": "evt_01JZ...",
  "correlationId": "corr_01JZ...",
  "event": "change",
  "nativeEvent": "change",
  "source": {
    "primitive": "input",
    "part": "control",
    "component": "platform.field",
    "componentInstanceId": "cmp_01JZ...",
    "viewId": "view_01JZ...",
    "renderId": "render_01JZ...",
    "targetPath": ["platform.field", "control"]
  },
  "binding": "state.value",
  "payload": {
    "value": "person@example.test"
  },
  "value": "person@example.test",
  "previousValue": "old@example.test",
  "transport": {
    "name": "http",
    "expects": "patch"
  },
  "context": {
    "signed": "base64url-signed-context",
    "expiresAt": "2026-05-11T10:30:00Z"
  },
  "timestamp": "2026-05-11T10:20:00Z"
}
```

Future-facing fields:

- client sequence;
- idempotency key;
- offline retry marker;
- originating SSE channel;
- optimistic patch id;
- cross-component transaction id.

The envelope is deterministic, serializable, and safe to log in redacted form. Sensitive payload fields must be redacted by schema.

### 12.4 Signed Event Context

The renderer embeds signed context for each backend-bound event binding.

Signed context includes:

- component name;
- component instance id;
- view/render id;
- part name;
- primitive/component source;
- semantic event name;
- allowed payload schema;
- allowed handler id;
- allowed transport;
- expected response mode;
- binding path;
- session/user reference;
- authorization scope;
- TTL and nonce.

The frontend cannot alter handler, part, primitive, binding, response mode, or transport without invalidating the signature.

### 12.5 Frontend Runtime Responsibilities

The frontend runtime is generic. It does not contain business logic.

Responsibilities:

- scan rendered roots for Semitexa UI event metadata;
- attach listeners for declared native DOM events;
- support custom semantic events emitted by registered JS bindings;
- collect value and payload data according to primitive event schemas;
- build a `UiEventEnvelope`;
- attach signed context;
- select the declared transport;
- send HTTP event requests;
- correlate HTTP responses and later SSE updates;
- apply validation results, state patches, props patches, re-render instructions, frontend instructions, redirects, notifications, and subscription updates;
- subscribe to authorized SSE channels;
- route pushed updates to the correct component/part instance;
- emit debug events in development mode.

It must not:

- call arbitrary controller URLs;
- choose backend handlers;
- bypass signed context;
- contain business logic;
- invent undeclared primitive events.

### 12.6 Backend Runtime Responsibilities

Backend responsibilities:

- receive `UiEventEnvelope`;
- verify schema version;
- verify signed event context;
- verify CSRF/session/user context;
- verify TTL, nonce, and replay rules;
- resolve view/render/component instance context;
- resolve primitive, part, binding, and target path;
- validate that the primitive/component declares the event;
- validate payload DTO/schema;
- resolve exactly one backend handler;
- enforce authorization;
- execute the handler;
- normalize `UiEventResponse`;
- publish optional SSE updates;
- return patches/instructions/errors to the frontend.

The frontend is never trusted to decide which backend method is called.

### 12.7 Event Transport Model

MVP transport:

- browser-to-server UI events use HTTP request/response;
- server-to-browser pushed updates use SSE;
- WebSocket remains a future transport behind `UiEventTransport`.

HTTP is best for user-originated interactions because it is simple, debuggable, CSRF-aware, and response-capable.

SSE is server-to-client only in the MVP. It is used for:

- async command progress;
- pushed component updates;
- background read-model/projection changes;
- multi-user data grid/dashboard refreshes.

Correlation:

- every HTTP event has `correlationId`;
- a handler may return `command accepted` with the same correlation id;
- later SSE messages may reference that correlation id;
- debug tooling can trace the full path.

SSE subscription:

```php
#[UiSseChannel(
    name: 'orders.grid',
    payload: OrdersGridUpdatedPayload::class,
    scope: ['tenant', 'user'],
    autoSubscribe: true,
)]
#[AsComponent(name: 'orders.grid', template: '@orders/components/grid.twig')]
final class OrdersGridComponent
{
}
```

SSE security:

- channel subscriptions require signed/session context;
- component-specific channels include component instance/view scope;
- unauthorized subscription attempts fail before stream registration;
- reconnects use `Last-Event-ID` where available;
- stale component instances ignore or reject pushed updates;
- each pushed update includes target component/part path.

### 12.8 Event Response Model

Canonical response:

```php
final readonly class UiEventResponse
{
    public function __construct(
        public UiEventResponseStatus $status = UiEventResponseStatus::Ok,
        public ?ValidationResult $validation = null,
        public array $statePatch = [],
        public array $partPropsPatch = [],
        public array $componentPropsPatch = [],
        public array $rerender = [],
        public array $frontend = [],
        public array $sse = [],
        public ?RedirectInstruction $redirect = null,
        public ?NotificationInstruction $notification = null,
        public ?string $correlationId = null,
        public ?UiEventError $error = null,
    ) {
    }
}
```

Supported response kinds:

- no-op;
- validation errors;
- State DTO patch;
- part props patch;
- component props patch;
- re-render instruction;
- frontend instruction;
- redirect instruction;
- notification/toast instruction;
- SSE subscription update;
- command accepted / async processing started;
- error response.

Rules:

- `change` events validate, derive, update local state, and update dependent parts;
- `submit` and `action` events may execute commands, persistence, or business operations;
- async commands return `command accepted` and may publish later SSE updates;
- notify-only events use `UiEventResponseMode::None`;
- server-pushed updates use the same patch/instruction response language.

### 12.9 Backend Handler Model

Handlers are explicit backend behavior owners.

Button click:

```php
final readonly class SaveClickedPayload
{
    public function __construct(
        public string $intent = 'save',
    ) {}
}

final class SaveClickedHandler
{
    public function handle(SaveClickedPayload $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::commandAccepted($context->correlationId);
    }
}
```

Input change validation:

```php
final readonly class EmailChangedPayload
{
    public function __construct(
        public string $value,
        public ?string $previous = null,
    ) {}
}

final class EmailChangedHandler
{
    public function handle(
        EmailChangedPayload $payload,
        UiEventContext $context,
        ContactFormState $state,
    ): UiEventResponse {
        $result = EmailValidator::validate($payload->value);

        return UiEventResponse::patch(
            validation: $result,
            state: ['email' => $payload->value],
            parts: [
                'email-error' => [
                    'text' => $result->firstError('email'),
                    'hidden' => $result->isValid(),
                ],
            ],
        );
    }
}
```

Form submit:

```php
#[UiPart(name: 'form', uses: FormComposite::class)]
#[UiOn(
    part: 'form',
    event: 'submit',
    handler: RegisterUserSubmitHandler::class,
    payload: RegisterUserPayload::class,
    response: UiEventResponseMode::Command,
)]
final class RegistrationFormComponent
{
}
```

Submit handlers are allowed to call command handlers/application services after authorization and validation. Change handlers are not.

Dependent field update:

```php
final class CountryChangedHandler
{
    public function handle(CountryChangedPayload $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::patch(
            state: ['country' => $payload->value, 'city' => null],
            parts: [
                'city' => [
                    'options' => $this->cities->forCountry($payload->value),
                    'disabled' => false,
                ],
            ],
        );
    }
}
```

Data grid paging/sorting:

```php
#[UiOn(part: 'grid', event: 'pageChanged', handler: OrdersPageChangedHandler::class, payload: GridPagePayload::class)]
#[UiOn(part: 'grid', event: 'sortChanged', handler: OrdersSortChangedHandler::class, payload: GridSortPayload::class)]
final class OrdersGridComponent
{
}
```

SSE update:

```php
final class ImportStartedHandler
{
    public function handle(ImportStartedPayload $payload, UiEventContext $context): UiEventResponse
    {
        $jobId = $this->commands->startImport($payload->fileId);

        return UiEventResponse::accepted(
            correlationId: $context->correlationId,
            frontend: [
                FrontendInstruction::subscribe('imports.' . $jobId),
            ],
        );
    }
}
```

Later, the backend publishes an SSE message with the same correlation id and a target component/part path.

### 12.10 Rendering Integration

When a primitive/component is rendered, the renderer determines:

- which primitive events exist;
- which component `UiOn` bindings apply;
- whether the rendered instance is interactive or static;
- which signed event contexts are needed;
- which data attributes/metadata roots are emitted;
- whether an SSE subscription token should be emitted;
- which scripts/runtime assets must be required.

Twig usage stays clean:

```twig
<form data-ui-component="registration.form">
    {{ ui_part('email') }}
    {{ ui_part('submit') }}
</form>
```

The developer does not write JavaScript request plumbing. The rendered `email` and `submit` parts carry signed event metadata produced by Semitexa.

### 12.11 Themes, Skins, and LLM-Generated Assets

Themes and skins may change:

- CSS variables;
- layout classes;
- visual density;
- animation style;
- styling metadata.

Themes and skins must not:

- redefine backend handlers;
- introduce arbitrary event bindings;
- inject arbitrary transport URLs;
- add undeclared scripts that emit backend-bound events;
- change signed event context.

LLM-generated skins are safe only if they remain inside the token/CSS/declared capability boundary. If a skin needs to influence interaction style, it should use safe declared capabilities such as CSS state classes, not backend event wiring.

### 12.12 Security Requirements

Security rules:

- every backend-bound event requires signed event context;
- component instance references are tamper-proof;
- event envelopes require CSRF/session validation;
- payloads are validated against declared DTO/schema;
- events are whitelisted by primitive/component declarations;
- handler ids are resolved from server-side metadata only;
- arbitrary backend method calls are impossible;
- part/primitive ids from the browser are verified against signed context;
- stale render/component instances are rejected or ignored;
- nonce/TTL rules protect against replay;
- authorization is checked before handler execution;
- SSE channels require signed/session authorization;
- SSE messages target valid component/part paths;
- LLM-generated skins cannot add backend-bound event behavior.

### 12.13 Debugging and Traceability

Developer tooling should answer:

- which primitive emitted this event?
- which component part owns it?
- which backend handler processes it?
- what payload was sent?
- what state changed?
- what response was returned?
- was SSE involved?
- why was an event rejected?

Proposed commands:

```bash
bin/semitexa ui:event:primitive button --json
bin/semitexa ui:event:component platform.field --json
bin/semitexa ui:event:trace corr_01JZ... --json
bin/semitexa ui:event:validate platform.field --json
bin/semitexa ui:sse:channels platform.orders-grid --json
```

Debug output should include:

- event registry inspection;
- primitive event schema dump;
- component event map dump;
- handler resolution trace;
- signed context summary;
- envelope debug view with redaction;
- SSE channel trace;
- validation failure diagnostics;
- dev-mode warnings for undeclared or ambiguous event mappings.

## 13. JavaScript binding model

Custom JavaScript bindings are optional progressive enhancement. They do not replace the UI Event Pipeline.

Primitive behavior:

```php
#[AsUiPrimitive(
    name: 'disclosure',
    element: 'button',
    script: 'platform-ui:js:disclosure',
    states: ['open', 'closed'],
)]
final class DisclosurePrimitive
{
}
```

Component behavior:

```php
#[AsComponent(
    name: 'platform.data-grid',
    template: '@platform-ui/components/data-grid.twig',
    script: 'platform-ui:js:data-grid',
)]
final class DataGridComponent
{
}
```

Both use asset keys. Both should mount through a shared frontend runtime. The runtime may expose separate registration names for clarity:

```js
window.SemitexaUi.primitive('disclosure', function (root, context) {
    // Local UX enhancement only.
});

window.SemitexaUi.component('platform.data-grid', function (root, context) {
    // Local composed behavior only.
});
```

Internally this can reuse `component-runtime.js` concepts: idempotent mounting, root scanning, and block re-scan after `semitexa:block:rendered`.

Allowed JavaScript binding responsibilities:

- enhance local UX;
- transform local DOM behavior;
- provide visual feedback;
- debounce/throttle declared event emission;
- normalize browser behavior;
- emit declared semantic events through `window.SemitexaUi.emit(root, eventName, payload)`;
- integrate with the generic runtime.

Forbidden responsibilities:

- manually call arbitrary controller URLs;
- choose backend handlers;
- bypass signed event context;
- define hidden backend behavior;
- contain business logic;
- emit undeclared backend-bound events;
- conflict with primitive event declarations.

## 14. Extension points for future components

Future packages should declare component structure through parts and slots.

Form package:

```php
#[AsComponent(name: 'forms.email-field', template: '@forms/components/email-field.twig')]
#[UiPart(name: 'field', uses: FieldComponent::class)]
#[UiPart(name: 'control', uses: InputPrimitive::class)]
final class EmailFieldComponent
{
}
```

Grid package:

```php
#[AsComponent(name: 'grid.table', template: '@grid/components/table.twig')]
#[UiPart(name: 'search', uses: InputPrimitive::class, optional: true)]
#[UiPart(name: 'page-size', uses: SelectPrimitive::class, optional: true)]
#[UiSlot(name: 'row-actions', accepts: [ButtonPrimitive::class, DropdownPrimitive::class], multiple: true)]
final class TableComponent
{
}
```

Admin package:

```php
#[AsComponent(name: 'admin.index-screen', template: '@admin/components/index-screen.twig')]
#[UiPart(name: 'filters', uses: FilterPanelComponent::class, optional: true)]
#[UiPart(name: 'grid', uses: TableComponent::class)]
#[UiSlot(name: 'primary-actions', accepts: [ButtonPrimitive::class], multiple: true)]
final class IndexScreenComponent
{
}
```

## 15. Validation and introspection

This is where the design becomes safe for developers and LLMs.

Validation rules:

- `AsUiPrimitive` names are unique.
- `AsComponent` names are unique.
- each primitive event name is unique within a primitive;
- each primitive event payload DTO/schema is valid;
- each primitive event transport and response mode is supported;
- each `UiPart::uses` points to an existing primitive or component class;
- each `UiSlot::accepts` entry points to an existing primitive or component class;
- each `UiPart::bind` points to an existing State DTO path, immutable prop path, or declared value;
- each `UiPart::provider` implements `UiPartDataProviderInterface`;
- each `#[ProvidesUiPart]` method references a declared part;
- each `#[UiOn]` references a declared part and an event declared by that part's primitive/component;
- each `#[UiOn]` handler exists and resolves to exactly one callable;
- each `#[UiOn]` handler signature is valid;
- each `#[UiOn]` handler payload type matches the primitive/component event payload schema;
- each `#[UiOn]` handler return type is valid for its response mode;
- each `#[UiSseChannel]` declaration has a valid payload and authorization scope;
- component templates may only call `ui_part()` for declared parts;
- component templates may only call `ui_slot()` for declared slots;
- required parts should be rendered by the template;
- undeclared `primitive()` usage inside a component template fails validation for real components;
- direct raw `ui="..."` inside component templates fails validation for real components;
- inline browser handlers such as `onclick`, `onchange`, or arbitrary AJAX data-action attributes fail validation inside real component templates;
- raw primitive rendering remains acceptable in primitive documentation, playgrounds, low-level examples, and prototypes;
- scripts must be valid asset keys;
- rendered event metadata must be signable;
- event response patches must target valid component/part/state paths;
- raw undeclared frontend handlers are not used inside component templates.

Introspection command:

```bash
bin/semitexa ui:component:explain contacts.form --json
bin/semitexa ui:event:component contacts.form --json
```

Possible output:

```json
{
  "component": "contacts.form",
  "template": "@contacts/components/contact-form.twig",
  "parts": {
    "name": { "uses": "Semitexa\\PlatformUi\\Primitive\\InputPrimitive", "kind": "primitive" },
    "email": { "uses": "Semitexa\\PlatformUi\\Primitive\\InputPrimitive", "kind": "primitive" },
    "submit": { "uses": "Semitexa\\PlatformUi\\Primitive\\ButtonPrimitive", "kind": "primitive" }
  },
  "slots": {
    "footer-actions": { "accepts": ["button"], "multiple": true }
  },
  "events": {
    "query.change": {
      "primitive": "input",
      "payload": "SearchQueryChangedPayload",
      "handler": "SearchQueryChangedHandler",
      "transport": "http",
      "response": "patch"
    }
  }
}
```

LLM workflow:

1. inspect component contract;
2. inspect primitive contracts;
3. edit template only within declared parts/slots;
4. run validator.

Semitexa Way rule: component templates bind to named parts, not raw primitive identifiers. This avoids hidden coupling, broken bindings, and uncontrolled template behavior.

Validation ownership:

- `platform-ui` validates primitive declarations, component parts/slots, event bindings, payload DTO references, handler references, response patch targets, raw primitive usage, and template-level event metadata;
- Framework Layer validates signed context, CSRF/session/user context, authorization hooks, replay protection, stale render ids, envelope schema, transport availability, and SSE channel subscription authorization.

## 16. Example declarations

Primitive:

```php
#[AsUiPrimitive(
    name: 'input',
    element: 'input',
    template: '@platform-ui/primitives/input.twig',
    sizes: ['sm', 'md', 'lg'],
    states: ['default', 'invalid', 'disabled'],
    tokens: ['--ui-border-subtle', '--ui-focus-ring', '--ui-state-danger'],
)]
final class InputPrimitive
{
}
```

Component:

```php
final class SearchFormState
{
    public function __construct(
        public string $query = '',
    ) {
    }
}

#[AsComponent(
    name: 'platform.search-form',
    template: '@platform-ui/components/search-form.twig',
)]
#[UiPart(name: 'query', uses: InputPrimitive::class, bind: 'state.query')]
#[UiPart(name: 'submit', uses: ButtonPrimitive::class)]
#[UiPart(name: 'clear', uses: ButtonPrimitive::class, optional: true)]
#[UiOn(part: 'query', event: 'change', handler: SearchQueryChangedHandler::class, payload: SearchQueryChangedPayload::class)]
final class SearchFormComponent
{
    private SearchFormState $state;

    public function __construct(
        public readonly string $action,
        public readonly string $placeholder = 'Search',
    ) {
    }

    public function mount(UiComponentContext $context): void
    {
        $this->state = new SearchFormState(
            query: (string) $context->input('query', ''),
        );
    }

    #[ProvidesUiPart('query')]
    public function queryPart(): array
    {
        return [
            'type' => 'search',
            'name' => 'q',
            'value' => $this->state->query,
            'placeholder' => $this->placeholder,
        ];
    }

    #[ProvidesUiPart('submit')]
    public function submitPart(): array
    {
        return ['text' => 'Search', 'tone' => 'brand'];
    }

    #[ProvidesUiPart('clear')]
    public function clearPart(): array
    {
        return ['text' => 'Clear', 'variant' => 'ghost', 'href' => $this->action];
    }
}
```

Template:

```twig
<form action="{{ action }}" method="get" sx-layout="cluster" sx-gap="2">
    {{ ui_part('query') }}

    {{ ui_part('submit') }}

    {% if state.query %}
        {{ ui_part('clear') }}
    {% endif %}
</form>
```

## 17. UI Event Examples

### 17.1 Primitive Declaring `click`

```php
final readonly class ButtonClickPayload
{
    public function __construct(
        public ?string $value = null,
    ) {}
}

#[AsUiPrimitive(
    name: 'button',
    element: 'button',
    events: [
        new UiPrimitiveEvent(
            name: 'click',
            native: 'click',
            payload: ButtonClickPayload::class,
            response: UiEventResponseMode::Command,
        ),
    ],
)]
final class ButtonPrimitive
{
}
```

### 17.2 Button Part Binding `click`

```php
#[AsComponent(name: 'profile.actions', template: '@profile/components/actions.twig')]
#[UiPart(name: 'save', uses: ButtonPrimitive::class)]
#[UiOn(
    part: 'save',
    event: 'click',
    handler: SaveProfileClickedHandler::class,
    payload: ButtonClickPayload::class,
    response: UiEventResponseMode::Command,
)]
final class ProfileActionsComponent
{
}
```

```php
final class SaveProfileClickedHandler
{
    public function handle(ButtonClickPayload $payload, UiEventContext $context): UiEventResponse
    {
        $this->authorization->assertCan('profile.save', $context->user());

        return UiEventResponse::commandAccepted($context->correlationId);
    }
}
```

### 17.3 Input `change` Bound To State And Validator

```php
final readonly class SearchQueryChangedPayload
{
    public function __construct(
        public string $value,
        public ?string $previous = null,
    ) {}
}

#[AsComponent(name: 'platform.search-form', template: '@platform-ui/components/search-form.twig')]
#[UiPart(name: 'query', uses: InputPrimitive::class, bind: 'state.query')]
#[UiPart(name: 'clear', uses: ButtonPrimitive::class, optional: true)]
#[UiOn(
    part: 'query',
    event: 'change',
    handler: SearchQueryChangedHandler::class,
    payload: SearchQueryChangedPayload::class,
    response: UiEventResponseMode::Patch,
)]
final class SearchFormComponent
{
    private SearchFormState $state;
}
```

```php
final class SearchQueryChangedHandler
{
    public function handle(SearchQueryChangedPayload $payload, UiEventContext $context): UiEventResponse
    {
        $validation = SearchQueryValidation::validate($payload->value);

        return UiEventResponse::patch(
            validation: $validation,
            state: ['query' => $payload->value],
            parts: [
                'clear' => ['hidden' => $payload->value === ''],
            ],
        );
    }
}
```

### 17.4 Form Submit

```php
final readonly class RegisterUserPayload
{
    public function __construct(
        public string $email,
        public string $name,
    ) {}
}

#[AsComponent(name: 'users.register-form', template: '@users/components/register-form.twig')]
#[UiPart(name: 'form', uses: FormComposite::class)]
#[UiPart(name: 'email', uses: InputPrimitive::class, bind: 'state.email')]
#[UiPart(name: 'name', uses: InputPrimitive::class, bind: 'state.name')]
#[UiPart(name: 'submit', uses: ButtonPrimitive::class)]
#[UiOn(
    part: 'form',
    event: 'submit',
    handler: RegisterUserSubmitHandler::class,
    payload: RegisterUserPayload::class,
    response: UiEventResponseMode::Command,
)]
final class RegisterUserFormComponent
{
    private RegisterUserState $state;
}
```

Submit/action handlers are allowed to execute commands after explicit authorization and validation.

### 17.5 Dependent Field Update

```php
final class CountryChangedHandler
{
    public function handle(CountryChangedPayload $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::patch(
            state: ['country' => $payload->value, 'city' => null],
            parts: [
                'city' => [
                    'options' => $this->cityOptions->forCountry($payload->value),
                    'disabled' => false,
                ],
            ],
        );
    }
}
```

The handler reads data through a query/read-model collaborator. It does not persist.

### 17.6 Backend Handler Returning Validation Errors

```php
return UiEventResponse::patch(
    validation: ValidationResult::invalid([
        'email' => 'Enter a valid email address.',
    ]),
    parts: [
        'email-error' => ['text' => 'Enter a valid email address.', 'hidden' => false],
    ],
);
```

### 17.7 Backend Handler Returning State Patch

```php
return UiEventResponse::patch(
    state: ['page' => 3, 'sort' => '-createdAt'],
    parts: [
        'rows' => ['items' => $this->orders->page(3, '-createdAt')],
    ],
);
```

### 17.8 Data Grid Pagination And Sorting

```php
final readonly class GridPagePayload
{
    public function __construct(
        public int $page,
        public int $pageSize,
    ) {}
}

final readonly class GridSortPayload
{
    public function __construct(
        public string $column,
        public string $direction,
    ) {}
}

#[AsComponent(name: 'orders.grid', template: '@orders/components/grid.twig')]
#[UiPart(name: 'grid', uses: DataGridComposite::class)]
#[UiOn(part: 'grid', event: 'pageChanged', handler: OrdersPageChangedHandler::class, payload: GridPagePayload::class)]
#[UiOn(part: 'grid', event: 'sortChanged', handler: OrdersSortChangedHandler::class, payload: GridSortPayload::class)]
final class OrdersGridComponent
{
}
```

```php
final class OrdersPageChangedHandler
{
    public function handle(GridPagePayload $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::patch(
            state: ['page' => $payload->page, 'pageSize' => $payload->pageSize],
            parts: [
                'grid' => ['rows' => $this->orders->page($payload->page, $payload->pageSize)],
            ],
        );
    }
}
```

### 17.9 Backend Handler Triggering SSE Update

```php
final class OrdersExportStartedHandler
{
    public function handle(OrdersExportPayload $payload, UiEventContext $context): UiEventResponse
    {
        $jobId = $this->commands->startExport($payload->filters);

        return UiEventResponse::accepted(
            correlationId: $context->correlationId,
            frontend: [
                FrontendInstruction::subscribe('exports.' . $jobId),
                FrontendInstruction::toast('Export started.'),
            ],
        );
    }
}
```

Later SSE update:

```json
{
  "schema": "semitexa.ui-sse/v1",
  "correlationId": "corr_01JZ...",
  "channel": "exports.job_123",
  "targetPath": ["orders.grid", "toolbar"],
  "response": {
    "notification": { "type": "success", "message": "Export is ready." },
    "frontend": [{ "type": "download", "url": "/exports/job_123.csv" }]
  }
}
```

### 17.10 Twig Usage Without Manual JavaScript Requests

```twig
{% set form_body %}
    {{ ui_part('email') }}
    {{ ui_part('name') }}
    {{ ui_part('submit', { text: 'Create account' }) }}
{% endset %}

{{ ui_part('form', { content: form_body }) }}
```

The template places declared parts. Semitexa renders the event metadata, frontend runtime listeners, signed context, and transport wiring.

### 17.11 Debugging Event Resolution

```bash
bin/semitexa ui:event:component users.register-form --json
```

Expected diagnostic shape:

```json
{
  "component": "users.register-form",
  "events": {
    "submit.click": {
      "primitive": "button",
      "payload": "RegisterUserPayload",
      "handler": "RegisterUserSubmitHandler",
      "transport": "http",
      "response": "command",
      "signedContext": "rendered"
    }
  }
}
```

## 18. MVP implementation plan

### Phase 1: Primitive declarations

- Add `AsUiPrimitive`.
- Convert the existing six primitives into classes.
- Keep existing CSS and Twig templates.
- Add `UiPrimitiveRegistry`.
- Add `primitive()` Twig helper.
- Keep direct `ui="..."` markup valid for primitive docs, playgrounds, low-level examples, and prototypes.

### Phase 2: Shared runtime substrate

- Extract reusable pieces from current component runtime where needed.
- Support primitive script asset binding.
- Support primitive event declarations and event manifest generation.
- Add signed event context generation.
- Add canonical `UiEventEnvelope` serializer.
- Add HTTP event endpoint for backend-bound UI events.
- Keep implementation compatible with current component event bridge.

### Phase 3: Component composition contract

- Add `UiPart`.
- Add `UiSlot`.
- Add `ProvidesUiPart`.
- Add `UiOn`.
- Add `UiPartDataProviderInterface`.
- Add `UiCompositionRegistry`.
- Add `ui_part()` and `ui_slot()` Twig helpers.
- Add validation for declared parts/slots.

### Phase 3.5: Component class data model

- Define how component instances are created from caller/resource props.
- Define immutable constructor props versus mutable State DTOs.
- Define optional `mount()` lifecycle hook.
- Define deterministic part prop resolution order.
- Support `UiPart(bind: ...)`.
- Support provider methods on the component class.
- Support external part data providers.
- Support component-scoped value-change events through `UiOn`.
- Support limited value-change responses: validation, state patch, part props patch, and frontend instruction metadata.
- Support explicit handler resolution for `UiOn`.
- Support primitive event payload validation.

### Phase 4: Component examples

- Implement `platform.field`.
- Implement `platform.search-form`.
- Implement one button click action.
- Implement one form submit action.
- Implement one dependent-field update.
- Prove component templates render declared primitive parts.
- Prove initial primitive values can come from State DTOs prepared by `mount()`.
- Prove a provider can inject primitive data, such as select options.
- Prove a value-change event routes to an explicit backend handler and returns a limited UI response.
- Prove handler resolution is visible through introspection.
- Prove validators catch undeclared part usage.
- Prove validators reject raw `ui="..."` usage inside real component templates.

### Phase 5: Introspection and LLM safety

- Add `ui:primitive:explain`.
- Add `ui:component:explain`.
- Add `ui:event:primitive`.
- Add `ui:event:component`.
- Add `ui:event:validate`.
- Add strict-mode validator for templates.
- Document LLM workflow.

### Phase 5.5: Minimal SSE support

- Add authorized component SSE channel declaration.
- Add frontend SSE subscription metadata.
- Add correlation id support between HTTP events and later SSE updates.
- Add server-pushed patch/instruction application for a component target path.

### Phase 6: CSS extraction

- Keep static `full.css`.
- Later consume SSR post-render hook for route-specific bundles.

## 19. Future roadmap

- Add primitives: select, checkbox, radio, switch, textarea, icon, divider, dropdown trigger, modal trigger.
- Add components: field, search form, filter panel, modal, toolbar, data grid shell.
- Add typed prop schemas if arrays become too loose.
- Add richer data provider types for async option loading, filtered datasets, and paginated read models.
- Add richer event response effects beyond the MVP response model.
- Add graph edges for primitive/component/part/slot dependencies.
- Add richer transport implementations behind `UiEventTransport`.
- Add LLM-assisted component composition only after validators are strict.

## 20. Risks and trade-offs

- **Twig-only composition is too implicit.** `UiPart` and `UiSlot` prevent templates from becoming the hidden architecture.
- **Attribute overload.** Keep attributes small and contractual. Do not put full layout logic in attributes.
- **Runtime duplication.** Share rendering/assets/events internally, but keep public concepts separate.
- **LLM confusion.** LLMs must inspect attributes before editing templates.
- **Raw primitive leakage.** Real component templates must not bypass declared parts with raw `ui="..."`.
- **Loose props.** Arrays are acceptable for MVP; typed prop schemas can follow.
- **Component class overreach.** Component classes prepare UI data and map UI events; they should not absorb persistence or application-service responsibilities.
- **Provider overreach.** Data providers are read-side collaborators only. Write-side behavior belongs in explicit handlers.
- **Event magic.** The frontend runtime must stay generic; handler ownership lives in server-side declarations.
- **Transport overreach.** HTTP plus SSE is enough for MVP. WebSocket should remain behind the transport abstraction.

## 21. Open questions

1. Should `AsUiPrimitive::name` also be the CSS `ui` identity, or should a separate `ui` field exist?
2. Should primitive renderer use `primitive()` only, or should direct `ui="..."` be introspected as first-class usage?
3. Should `UiPart::uses` require FQCNs, short primitive names, or support both?
4. Should `UiPart` support prop schemas in MVP?
5. Should `UiSlot` accept only primitive/component classes, or also named part contracts?
6. Should the shared event substrate stay in SSR or move to a lower-level framework package?
7. Should `UiOn` allow component methods in production strict mode, or require handler classes outside playground/prototype mode?
8. Should SSE be implemented in MVP or land immediately after HTTP event responses?

## 22. Framework layer improvement proposal

Detailed prerequisite framework changes are tracked separately in [framework-layer-improvements.md](framework-layer-improvements.md).

After the latest design correction, the framework should provide a shared UI runtime substrate, not force primitives to be `AsComponent`.

Required direction:

1. Keep `AsComponent` for composed UI objects.
2. Add or expose reusable rendering/asset/event services that `AsUiPrimitive` can consume.
3. Support `UiPart` and `UiSlot` composition metadata.
4. Add template validation hooks for `ui_part()` and `ui_slot()`.
5. Add graph/introspection support for primitives, components, parts, slots, events, and scripts.

## 23. Minimal MVP boundary

MVP includes:

- `AsUiPrimitive`;
- existing six primitives as primitive classes;
- `primitive()` helper;
- `UiPart`;
- `UiSlot`;
- `ui_part()` helper;
- `ui_slot()` helper;
- immutable component props and explicit State DTOs;
- optional `mount()` hook for initial state preparation;
- State DTO values as initial primitive value source;
- component part provider methods;
- read-oriented external part data providers;
- component-scoped value-change event mapping;
- primitive event declarations;
- `UiOn` handler binding and resolution;
- signed event context;
- canonical `UiEventEnvelope`;
- HTTP-based event submission;
- limited value-change response model;
- validation result, state patch, and part props patch responses;
- minimal frontend runtime for declared event capture and response application;
- basic SSE channel declaration and server-pushed updates if feasible;
- one or two composed components using declared parts;
- primitive/component introspection commands;
- event registry/dump commands;
- validator for declared part/slot usage;
- validator for event declarations, payloads, handlers, signed context, and response patch targets;
- strict rejection of raw primitive usage inside real component templates;
- existing static CSS bundle.

MVP excludes:

- separate frontend framework;
- LLM-generated component trees;
- full route-specific CSS extraction;
- full fragment DOM patching;
- persistence from ordinary value-change events;
- full WebSocket transport;
- complex offline queueing;
- advanced optimistic UI;
- cross-tab synchronization;
- distributed event replay;
- visual event debugger;
- complex multi-component transactions;
- typed prop DTOs for every primitive;
- theme override policy for primitive templates.

## 24. Verification and package readiness

Markdown/design work is complete for this iteration.

`ai:verify` was executed for:

```bash
bin/semitexa ai:verify --files=packages/semitexa-platform-ui/docs/technical-design.md,packages/semitexa-platform-ui/docs/framework-layer-improvements.md --json
```

Verification failed because of pre-existing `module_structure` violations in `packages/semitexa-platform-ui`, not because of the Markdown design content.

Known status:

- first detected issue: `packages/semitexa-platform-ui/eval` is treated as an unknown package-root directory;
- total known module structure errors: 19;
- these should be handled as a separate cleanup task before implementation work starts.
