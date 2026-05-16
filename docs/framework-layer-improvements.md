# Semitexa Framework Layer Improvements for Platform UI

## 1. Purpose

This document isolates the Semitexa Framework Layer changes needed before the next-generation `semitexa/platform-ui` module is implemented.

The current design decision is:

**`AsUiPrimitive` and `AsComponent` are different public concepts. Primitives define atomic UI elements. Components define composed UI objects. Components declare their composition through `UiPart` and `UiSlot`. Both primitives and components may reuse shared runtime infrastructure for rendering, assets, frontend behavior, and signed events.**

This supersedes the earlier idea that a primitive should be an `AsComponent` subtype.

The accepted component lifecycle is:

- constructor props are immutable external input/configuration;
- mutable UI/runtime state lives in an explicit State DTO;
- optional `mount()` runs after instantiation and context preparation;
- `mount()` initializes state but does not execute write-side business operations;
- event/behavior handlers perform explicit state transitions.

## 2. Current foundation

`semitexa/ssr` already provides an important prototype:

- `#[AsComponent]` with `name`, `template`, `layout`, `cacheable`, `event`, `triggers`, and `script`;
- `ComponentRegistry`;
- `ComponentRenderer`;
- `component()` Twig function;
- `slot()` Twig function;
- `component_event_attrs()` Twig function;
- signed event manifests through `ComponentEventBridge`;
- delegated browser events through `component-events.js`;
- frontend behavior mounting through `component-runtime.js`;
- asset-key loading through `AssetCollector`.

This proves the runtime approach, but the public model should be refined:

- primitives should have their own `#[AsUiPrimitive]`;
- components should remain `#[AsComponent]`;
- the reusable pieces under the component system should become a shared UI runtime substrate where needed.

## 3. Required framework improvements

### 3.1 Shared UI runtime substrate

The framework should expose reusable services currently embedded in the component implementation:

- template rendering support;
- asset key validation and requirement;
- signed event manifest creation and verification;
- frontend trigger normalization;
- session binding and TTL validation;
- frontend runtime mount/re-scan behavior.

The public APIs can stay separate:

```text
AsUiPrimitive -> atomic element
AsComponent   -> composed object
```

But the internals should avoid duplicate implementations.

### 3.2 Primitive runtime support

Add the minimal framework support needed for `platform-ui` primitives:

- primitive registry bootstrapping through class discovery;
- primitive template rendering or element rendering;
- primitive script asset binding;
- primitive event manifest generation;
- primitive event envelopes through the same signing/session model as component events.

This does not mean adding `AsUiPrimitive` to `ssr`; the attribute can live in `platform-ui`. The framework only needs reusable runtime services.

### 3.3 Component composition metadata

Support component-level composition contracts:

```php
#[AsComponent(name: 'platform.field', template: '@platform-ui/components/field.twig')]
#[UiPart(name: 'label', uses: LabelPrimitive::class)]
#[UiPart(name: 'control', uses: InputPrimitive::class)]
#[UiPart(name: 'error', uses: TextPrimitive::class, optional: true)]
final class FieldComponent
{
}
```

```php
#[UiSlot(name: 'row-actions', accepts: [ButtonPrimitive::class, DropdownPrimitive::class], multiple: true)]
final class DataGridComponent
{
}
```

Framework needs:

- a convention for repeatable secondary attributes on component classes;
- metadata access for component class FQCNs;
- validation hooks so package registries can validate parts and slots;
- a way to instantiate component classes from caller/resource props;
- a part prop resolver that can merge defaults, bindings, provider methods, external providers, and template overrides.

`UiPart` and `UiSlot` may live in `platform-ui` initially. If many packages need them, they can move into SSR or a shared UI package later.

### 3.4 Component instance and part data model

Component classes should be real composition presenters, not empty marker classes.

The framework should support a lifecycle like:

1. caller/resource passes immutable component props;
2. runtime creates the component instance from those props;
3. runtime prepares component context;
4. runtime prepares or attaches an explicit State DTO for mutable UI/runtime state;
5. optional `mount()` runs after context preparation;
6. `mount()` derives initial state, normalizes input, resolves defaults, and prepares primitive values;
7. part resolver reads `UiPart` metadata;
8. part resolver reads immutable prop paths such as `prop.name`;
9. part resolver reads State DTO paths such as `state.email`;
10. part resolver calls component methods marked with `#[ProvidesUiPart]`;
11. part resolver calls external `UiPartDataProviderInterface` providers if configured;
12. final part props are passed into the primitive/component renderer.

This enables:

- initial primitive values from State DTOs;
- select/options data from explicit providers;
- component-owned defaults;
- template overrides without making Twig the data source.

The Framework Layer does not need to own `ProvidesUiPart` or `UiPartDataProviderInterface` immediately. It does need to expose enough rendering context, instantiation hooks, state attachment, and optional `mount()` invocation support for `platform-ui` to implement them cleanly.

Component constructors should not become service injection surfaces unless that matches existing Semitexa dependency injection conventions. Runtime services, read providers, and behavior handlers should be resolved through established Semitexa patterns.

### 3.5 Value binding and part event mapping

Support component-scoped value-change events:

```php
#[UiPart(name: 'email', uses: InputPrimitive::class, bind: 'state.email')]
#[UiOn(part: 'email', event: 'change', handler: EmailChangedHandler::class, payload: EmailChangedPayload::class)]
final class ContactFormComponent
{
    private ContactFormState $state;
}
```

Framework needs:

- signed event payloads that include component instance id, component name, part name, primitive name, semantic event name, binding name, and value;
- backend validation that the part exists;
- backend validation that the semantic event is allowed by `UiOn`;
- backend validation that the binding points to a valid State DTO or immutable prop path;
- backend validation that the payload DTO/schema is valid;
- typed handler routing through existing Semitexa handler conventions;
- a limited response model for `change` triggers.

This should reuse the current component event security model where possible.

MVP `change` responses should support:

- validation result;
- component state patch;
- part props patch;
- optional frontend instruction metadata.

`change` events are for validation, derivation, dependent-field updates, and local state updates. They should not perform persistence by default.

`submit` and `action` events are for command execution, persistence, and business operations.

If the existing event dispatcher cannot return a response, the Framework Layer should expose an interaction/behavior handler path on top of the same signed event substrate.

### 3.6 Unified UI Event Pipeline substrate

The Framework Layer should provide the shared runtime substrate for the Semitexa UI Event Pipeline. `platform-ui` owns the UI declarations; the Framework Layer owns generic secure transport and runtime services.

Required services:

- UI event registry access for discovered primitive/component event metadata;
- signed event context service;
- event envelope serializer/deserializer;
- event envelope validator;
- event handler resolver;
- response-capable interaction dispatcher;
- event response normalizer;
- HTTP event endpoint;
- SSE channel manager;
- SSE subscription authorization;
- SSE publish/dispatch service;
- frontend runtime metadata generator;
- event correlation id generator;
- replay/nonce/TTL validator;
- debug/introspection service hooks.

Canonical envelope support should include:

- schema version;
- event id;
- correlation id;
- semantic event name;
- optional native DOM event name;
- primitive id;
- part id;
- component id;
- component instance id;
- view/render id;
- binding path;
- payload;
- value and previous value;
- signed context;
- timestamp;
- transport metadata;
- optional target path for nested components.

MVP mandatory fields are schema, event id, correlation id, event name, source identity, payload, signed context, timestamp, and transport metadata.

The Framework Layer must not trust the frontend to choose backend handlers. Handler identity comes from server-side metadata verified by the signed context.

### 3.7 SSE and server-pushed updates

SSE is server-to-client only in the MVP.

Framework responsibilities:

- authorize SSE subscriptions using session/signed context;
- issue channel subscription tokens;
- resume streams using `Last-Event-ID` where available;
- include correlation ids on pushed updates when they originate from an HTTP event;
- route pushed updates to component instance and target path;
- reject or ignore stale component/view instances;
- expose a publish API for handlers and async jobs;
- keep WebSocket as a future transport behind the same abstraction.

Frontend events should still be submitted over HTTP in the MVP. SSE carries async updates, pushed patches, command progress, and external read-model changes back to the browser.

### 3.8 Read-oriented part data providers

External part data providers are read-side collaborators.

They may use:

- read-model services;
- query services;
- repositories used in read-only mode;
- projection/read-side providers;
- deterministic context-based providers.

They must not directly execute write-side application services, commands, persistence operations, or business mutations.

If a provider needs business data, expose that data through a read-model/query abstraction. Write-side application services belong in event handlers, submit handlers, command handlers, or explicit backend behavior handlers.

### 3.9 Template helpers for declared parts and slots

Add or enable helpers:

```twig
{{ ui_part('submit', { text: 'Save', tone: 'brand' }) }}
{{ ui_slot('row-actions', { row: row }) }}
```

Important rule:

Twig places declared parts. Twig should not be the source of truth for composition.

The helper must resolve the active component context, find the declared part/slot, validate it, and render the target primitive or component.

### 3.10 Template validation hook

The framework should support validating component templates against declared composition metadata.

Validation should detect:

- `ui_part('x')` when no `#[UiPart(name: 'x')]` exists;
- `ui_slot('x')` when no `#[UiSlot(name: 'x')]` exists;
- required parts that are never rendered;
- slot content that violates `accepts`;
- `UiPart::bind` references a missing State DTO path, immutable prop path, or declared value;
- `UiPart::provider` does not implement the expected provider interface;
- `#[ProvidesUiPart]` references a missing part;
- `#[UiOn]` references a missing part or invalid event declaration;
- `#[UiOn]` references an event not declared by the target primitive/component;
- `#[UiOn]` handler does not exist or is ambiguous;
- `#[UiOn]` handler payload type does not match the event payload schema;
- `#[UiOn]` handler return type is invalid for the declared response mode;
- primitive direct usage inside real component templates;
- raw `ui="..."` usage inside real component templates;
- inline browser handlers or arbitrary AJAX data-action attributes inside real component templates;
- invalid script asset keys;
- invalid SSE channel declarations;
- event response patches that target missing parts/state paths.

This validator can start as a `platform-ui` command and later integrate into `ai:verify`.

Strict rule: real component templates must render declared parts and slots, not raw primitive identifiers. Raw primitive rendering is acceptable in primitive docs, playgrounds, low-level examples, and prototypes.

### 3.11 Event substrate reuse

For MVP, do not create a completely separate browser event system.

Instead:

- extract reusable signing/verification/session/TTL behavior from `ComponentEventBridge` if necessary;
- support primitive event manifests and component event manifests with the same security model;
- support canonical UI event envelopes;
- support server-side handler resolution from signed metadata;
- support response normalization;
- support response-capable interaction handling for `change` events;
- support SSE channel authorization and pushed updates;
- keep the current component event endpoint if it can accept both shapes cleanly;
- only add a primitive-specific endpoint if the payload shape diverges meaningfully.

The goal is one security model, not necessarily one public endpoint forever.

### 3.12 Frontend runtime shape

The existing `component-runtime.js` proves the idempotent mount pattern. Platform UI may need a clearer namespace:

```js
window.SemitexaUi.primitive('disclosure', mount);
window.SemitexaUi.component('platform.data-grid', mount);
```

Internally this can reuse the same scan/mount implementation.

Framework needs:

- stable root data attributes;
- re-scan after `semitexa:block:rendered`;
- idempotent mounting;
- asset-driven script inclusion;
- event metadata discovery;
- envelope construction;
- HTTP event submission;
- response application;
- SSE subscription and update routing.

### 3.13 SSR post-render hook

Route-specific CSS extraction still needs a hook after final Twig render and before response emission.

Needed for:

- scanning direct `ui="..."`, `ui-*`, and `sx-*` attributes;
- scanning rendered primitive/component output;
- compiling route-specific CSS bundles;
- attaching generated CSS to the response.

This is not required for the primitive/component MVP, because static `full.css` already works.

### 3.14 Generated or inline CSS asset support

If route-specific CSS lands, SSR needs an official API:

```php
$collector->inlineCss(
    css: $css,
    key: 'platform-ui:route:' . $hash,
    priority: 40,
);
```

or:

```php
$collector->requireGeneratedCss(
    key: 'platform-ui:route:' . $hash,
    css: $css,
    priority: 40,
);
```

This can be postponed until route-specific extraction becomes active work.

### 3.15 Graph and introspection support

Future graph tooling should understand:

- classes marked with `#[AsUiPrimitive]`;
- classes marked with `#[AsComponent]`;
- `#[UiPart]` edges from component to primitive/component;
- `#[UiSlot]` accepted types;
- `#[ProvidesUiPart]` part data provider methods;
- `#[UiOn]` part event mappings;
- primitive event declarations;
- UI event payload DTOs;
- UI event handler classes/methods;
- event response modes;
- SSE channel declarations;
- component State DTO paths and value bindings;
- Twig `primitive()`, `ui_part()`, and `ui_slot()` calls;
- direct `ui="..."` usage;
- script asset links;
- backend event links.

This is important for Semitexa-style impact analysis and LLM safety.

## 4. Package ownership

### `semitexa/platform-ui`

Should own:

- `AsUiPrimitive`;
- `UiPrimitiveEvent`;
- `UiPart`;
- `UiSlot`;
- `ProvidesUiPart`;
- `UiOn`;
- `UiPrimitiveEvent`;
- `UiSseChannel`;
- `UiPartDataProviderInterface`;
- primitive event declarations;
- component event binding declarations;
- primitive registry;
- composition registry;
- UI event binding registry;
- part prop resolver;
- component State DTO conventions;
- `mount()` integration contract from the Platform UI side;
- read-oriented provider validation;
- value-change response DTO/contract;
- Platform UI response DTO conveniences;
- primitive/component validation commands;
- event declaration and binding validation commands;
- `primitive()`, `ui_part()`, and `ui_slot()` helpers if they are not moved into SSR;
- built-in primitive templates/CSS/JS;
- base composed components such as field and search form.

### `semitexa/ssr`

Should own:

- `AsComponent`;
- component rendering;
- component metadata access;
- component instantiation hooks;
- component context preparation;
- optional `mount()` invocation hook;
- signed event context service;
- UI event envelope serializer/deserializer;
- HTTP UI event endpoint;
- event handler resolver substrate;
- response normalizer;
- response-capable interaction/behavior dispatch for `change` events if plain event dispatch cannot return data;
- SSE channel manager;
- frontend runtime metadata generator;
- reusable template rendering substrate;
- reusable asset binding substrate;
- reusable signed browser-event substrate;
- frontend runtime mount/re-scan substrate;
- post-render hook;
- generated/inline CSS support if needed.

### `semitexa/core`

Should own:

- events;
- event listeners;
- payload/resource/handler conventions;
- write-side command/business behavior conventions;
- authorization conventions;
- session/CSRF conventions;
- class discovery primitives if already there.

Avoid UI-specific concepts in core.

### `semitexa/theme`

Should own:

- theme resolution;
- skin resolution;
- skin discovery;
- token contract.

No major changes are required for primitive/component composition.

## 5. Implementation sequence

### Step 1: Primitive declarations in `platform-ui`

Acceptance criteria:

- existing six primitives are declared as classes with `#[AsUiPrimitive]`;
- direct `ui="..."` markup still works for primitive docs, playgrounds, low-level examples, and prototypes;
- `primitive('button', props)` can render a primitive;
- primitive registry can explain modifiers, states, tokens, script, and events;
- primitive event declarations include payload, native event, mode, transport, and response metadata.

### Step 2: Shared runtime extraction where needed

Acceptance criteria:

- primitive rendering can require script assets;
- primitive events can use the same signing/session model as component events;
- UI event envelopes can be serialized/deserialized;
- signed event context can be generated and verified;
- no duplicate event security logic is introduced.

### Step 3: Composition attributes

Acceptance criteria:

- `UiPart` and `UiSlot` exist;
- `ProvidesUiPart` and `UiOn` exist;
- `UiPartDataProviderInterface` exists;
- component classes can declare named parts and slots;
- registries validate that referenced classes exist and are valid primitives/components;
- part bindings resolve against explicit `state.*` or `prop.*` paths;
- provider methods and external providers can inject primitive props;
- external providers are validated as read-oriented collaborators.

### Step 3.5: UI event binding registry

Acceptance criteria:

- `UiOn` binds declared part events to explicit handlers;
- handler classes/methods resolve deterministically;
- conflicting or missing handlers fail validation;
- handler payload DTOs match primitive/component event payload declarations;
- response modes are validated;
- component event maps can be dumped for debugging.

### Step 4: Component data and event mapping

Acceptance criteria:

- component instances can receive immutable caller/resource props;
- mutable UI state is represented by explicit State DTOs;
- optional `mount()` can prepare initial State DTO values;
- initial primitive values can come from State DTO bindings;
- part provider methods can provide primitive props;
- external providers can provide part data, such as select options;
- `UiOn` can route value-change events to explicit backend handlers;
- `change` handlers can return validation, state patch, part props patch, and optional frontend metadata;
- ordinary `change` handlers do not perform persistence by default.

### Step 4.5: UI event transport and response runtime

Acceptance criteria:

- frontend runtime captures declared events without manual JavaScript request code;
- frontend runtime builds canonical `UiEventEnvelope`;
- backend endpoint validates signed context, envelope schema, authorization, and payload;
- backend resolver executes exactly one handler;
- backend normalizes `UiEventResponse`;
- frontend runtime applies validation, state patch, props patch, frontend instruction, redirect, notification, and error responses.

### Step 5: Template helpers and validator

Acceptance criteria:

- `ui_part()` renders declared parts;
- `ui_slot()` renders declared slots;
- validator catches undeclared part/slot usage;
- validator rejects raw primitive usage inside real component templates;
- validator checks binds, providers, `UiOn` mappings, event payloads, handlers, response modes, and patch targets.

### Step 6: Component examples

Acceptance criteria:

- implement `platform.field`;
- implement `platform.search-form`;
- prove a component can compose primitives only through declared parts;
- prove initial values come from State DTOs prepared by `mount()`;
- prove provider data can flow into primitives;
- prove value-change events route to explicit backend handlers and return limited UI responses;
- prove ordinary change events do not perform persistence;
- prove button click, input change, form submit, dependent field update, and data grid paging/sorting examples;
- prove debug command output can trace primitive -> part -> handler -> response;
- prove docs and introspection are clear for humans and LLMs.

### Step 7: Minimal SSE support

Acceptance criteria:

- component can declare authorized SSE channels;
- frontend can subscribe using signed/session context;
- pushed updates include correlation id and target path;
- frontend applies pushed patches/instructions to the correct component instance;
- stale component instances ignore or reject updates.

### Step 8: Later SSR enhancements

Acceptance criteria:

- post-render hook supports route CSS extraction;
- generated/inline CSS API exists;
- graph tooling can trace primitive/component/part/slot edges.

## 6. What to implement now

Implement now:

1. `AsUiPrimitive`;
2. primitive registry;
3. `primitive()` helper;
4. `UiPart`;
5. `UiSlot`;
6. `ProvidesUiPart`;
7. `UiOn`;
8. `UiPartDataProviderInterface`;
9. `UiPrimitiveEvent`;
10. `UiSseChannel`;
11. composition registry;
12. UI event binding registry;
13. part prop resolver;
14. State DTO and optional `mount()` lifecycle contract;
15. signed event context service;
16. canonical event envelope support;
17. HTTP UI event endpoint;
18. handler resolver;
19. response normalizer;
20. limited value-change response contract;
21. minimal SSE channel manager if feasible;
22. `ui_part()` and `ui_slot()` helpers;
23. strict template validator;
24. event debug/introspection commands;
25. one or two composed component examples.

## 7. What to postpone

Postpone:

- full route-specific CSS extraction;
- generated CSS storage;
- full fragment DOM patching;
- persistence from ordinary value-change events;
- full WebSocket transport;
- offline event queueing;
- advanced optimistic UI;
- cross-tab synchronization;
- distributed event replay;
- visual event debugger;
- complex multi-component transactions;
- typed prop DTOs for all primitives;
- async data providers and remote option loading;
- LLM-generated component trees;
- moving `UiPart`/`UiSlot` into SSR before the pattern proves stable.

## 8. Risks

- **Primitive/component confusion.** Keep public attributes separate.
- **Twig-only composition.** Reject this. Twig renders declared parts; attributes define the contract.
- **Attribute overload.** `UiPart` should declare structure and intent, not layout details.
- **Runtime duplication.** Share internals, but keep domain concepts distinct.
- **Raw primitive leakage.** Real component templates must not bypass declared parts with raw `ui="..."`.
- **Component class overreach.** Component classes prepare UI data and map UI events; they should not become application services.
- **Provider overreach.** Part data providers are read-side collaborators only. Write-side behavior belongs in handlers.
- **Response-model mismatch.** If the existing event dispatcher is fire-and-forget, value-change interactions need a response-capable behavior handler path.
- **Handler ambiguity.** Multiple handlers for one component/part/event must fail validation.
- **Frontend trust.** The frontend must never choose handlers or target backend URLs.
- **SSE authorization.** Component-specific channels need signed/session-scoped subscription checks.
- **Transport creep.** WebSocket should not be required for the MVP.

## 9. Open decisions

1. Should `AsUiPrimitive::name` also be CSS `ui`, or should there be a separate `ui` field?
2. Should `UiPart::uses` use FQCNs only, or allow primitive/component names?
3. Should primitive event transport reuse the current component endpoint or get a primitive-specific endpoint later?
4. Should `UiPart` and `UiSlot` stay in `platform-ui` or move to SSR once stable?
5. What exact interface should expose response-capable interaction handling if existing event dispatch remains fire-and-forget?
6. Should `UiOn` allow component methods in production strict mode, or require handler classes outside prototypes?
7. Should minimal SSE support be part of the first MVP, or the immediate follow-up after HTTP events?

## 10. Verification status

Markdown/design work for this iteration is complete.

`ai:verify` was executed for:

```bash
bin/semitexa ai:verify --files=packages/semitexa-platform-ui/docs/technical-design.md,packages/semitexa-platform-ui/docs/framework-layer-improvements.md --json
```

Verification failed because of pre-existing `module_structure` violations in `packages/semitexa-platform-ui`, not because of the Markdown design content.

Known status:

- first detected issue: `packages/semitexa-platform-ui/eval` is treated as an unknown package-root directory;
- total known module structure errors: 19;
- these should be handled as a separate cleanup task before implementation work starts.

## 11. Relationship to the UI technical design

This document supports [technical-design.md](technical-design.md).

The technical design defines the public model. This file lists framework-layer changes needed to make that model consistent, explicit, and implementable without duplicating Semitexa runtime machinery.
