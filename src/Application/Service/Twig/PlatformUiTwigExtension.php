<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Twig;

use Semitexa\PlatformUi\Application\Service\Collaboration\CollabManifestBuilder;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Component\UiPartPropResolver;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiAuthState;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiTransportModePolicy;
use Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder;
use Semitexa\PlatformUi\Application\Service\Event\UiInstanceIdGenerator;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleParser;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitCsrfTokenStore;
use Semitexa\PlatformUi\Application\Service\Validation\UiFormSubmitConfigParser;
use Semitexa\PlatformUi\Application\Service\Validation\UiFormSubmitDefinitionExtractor;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionException;
use Semitexa\PlatformUi\Application\Service\Primitive\PrimitiveRenderer;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Attribute\AsTwigExtension;
use Twig\Markup;

#[AsTwigExtension]
final class PlatformUiTwigExtension
{
    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction(
            'primitive',
            static function (string $name, array $props = []): Markup {
                $renderer = new PrimitiveRenderer();
                return new Markup($renderer->render($name, $props), 'UTF-8');
            },
            ['is_safe' => ['html']],
        );

        /**
         * ui_part_props(partName, overrides = [])
         *
         * Resolves the final prop map for one declared part of the
         * component currently being rendered. The current component
         * comes from the Twig context's `_component` key, which SSR's
         * ComponentRenderer populates automatically. Caller component
         * props are extracted from the context by stripping framework
         * keys (anything starting with `_`).
         *
         * Returns an array (NOT a Markup) — callers feed it straight
         * into `primitive(partName, ui_part_props('partName'))`.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_part_props',
            static function (array $context, string $partName, array $overrides = []): array {
                $component = $context['_component'] ?? null;
                if (!is_array($component) || !isset($component['name']) || !is_string($component['name'])) {
                    throw new UiComponentRegistryException(
                        'ui_part_props() called outside a Platform UI component render context.',
                    );
                }

                $metadata = UiComponentRegistry::get($component['name']);
                if ($metadata === null) {
                    throw new UiComponentRegistryException(sprintf(
                        'Component "%s" is not registered in UiComponentRegistry — declare #[UiPart] / #[UiSlot] on it or use the primitive() helper directly.',
                        $component['name'],
                    ));
                }

                $componentProps = [];
                foreach ($context as $key => $value) {
                    if (is_string($key) && $key !== '' && $key[0] !== '_') {
                        $componentProps[$key] = $value;
                    }
                }

                return (new UiPartPropResolver())->resolve(
                    $metadata,
                    $partName,
                    $componentProps,
                    $overrides,
                );
            },
            ['needs_context' => true],
        );

        /**
         * ui_part(partName, overrides = [])
         *
         * One-shot render of a declared component part. Equivalent to:
         *
         *     {{ primitive(<part-primitive>, ui_part_props(partName, overrides)) }}
         *
         * plus the all-important `data-ui-part="<partName>"` marker injected
         * onto the rendered primitive's root tag, so the frontend runtime
         * resolves parts by UiPart name instead of conflating with the
         * primitive's `ui` alias.
         *
         * Returns a Markup. The current component identity comes from the
         * Twig context's `_component` key, the part metadata comes from
         * UiComponentRegistry, and the primitive name comes from
         * UiPartMetadata::$primitiveName (cached at registration).
         */
        TwigExtensionRegistry::registerFunction(
            'ui_part',
            static function (array $context, string $partName, array $overrides = []): Markup {
                $component = $context['_component'] ?? null;
                if (!is_array($component) || !isset($component['name']) || !is_string($component['name'])) {
                    throw new UiComponentRegistryException(
                        'ui_part() called outside a Platform UI component render context.',
                    );
                }

                $metadata = UiComponentRegistry::get($component['name']);
                if ($metadata === null) {
                    throw new UiComponentRegistryException(sprintf(
                        'Component "%s" is not registered in UiComponentRegistry.',
                        $component['name'],
                    ));
                }

                $part = $metadata->part($partName);
                if ($part === null) {
                    throw new UiComponentRegistryException(sprintf(
                        'Component "%s" has no part named "%s".',
                        $metadata->name,
                        $partName,
                    ));
                }

                $componentProps = [];
                foreach ($context as $key => $value) {
                    if (is_string($key) && $key !== '' && $key[0] !== '_') {
                        $componentProps[$key] = $value;
                    }
                }

                $resolved = (new UiPartPropResolver())->resolve(
                    $metadata,
                    $partName,
                    $componentProps,
                    $overrides,
                );

                $renderer = new PrimitiveRenderer();
                $html = $renderer->render($part->primitiveName, $resolved);

                $markerAttr = sprintf(
                    'data-ui-part="%s"',
                    htmlspecialchars($partName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );

                // Inject the marker right after the opening tag's element
                // name on the rendered primitive's root. Tight regex anchored
                // at the first `<tagname` so embedded `<` inside attribute
                // values cannot match.
                $count = 0;
                $injected = preg_replace(
                    '/^(\s*<[a-zA-Z][a-zA-Z0-9-]*)(?=\s|>|\/>)/',
                    '$1 ' . str_replace('\\', '\\\\', str_replace('$', '\\$', $markerAttr)),
                    $html,
                    1,
                    $count,
                );
                if ($injected === null || $count !== 1) {
                    throw new UiComponentRegistryException(sprintf(
                        'ui_part("%s"): failed to inject data-ui-part marker into rendered "%s" primitive output.',
                        $partName,
                        $part->primitiveName,
                    ));
                }

                return new Markup($injected, 'UTF-8');
            },
            ['needs_context' => true, 'is_safe' => ['html']],
        );

        /**
         * ui_component_events(componentName)
         *
         * Read-only introspection helper. Returns the declared
         * #[UiOn] event metadata as a list of plain arrays — safe to
         * iterate in templates for documentation/debug panels. This is
         * NOT a runtime hook; the data does not become DOM event wiring.
         *
         * Each entry shape:
         *   {
         *     part:    string,
         *     event:   string,
         *     updates: ?string,   // dot-path or null
         *     method:  string,    // PHP method name
         *     runtime: 'metadata-only',
         *   }
         */
        /**
         * ui_component_instance()
         *
         * Returns a fresh per-render Platform UI instance id (e.g.
         * "uci_<16hex>"). Stamp it once at the top of a component template,
         * pass it to `ui_event_manifest()`, and emit it on the root as
         * `data-ui-component-instance-id="…"` so future runtime can pair
         * the manifest script with its DOM root.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_component_instance',
            static fn (): string => (new UiInstanceIdGenerator())->next(),
        );

        /**
         * ui_component_instance_for(override)
         *
         * Resolves a Platform UI component instance id with an
         * optional caller-supplied override. Behaviour:
         *
         *   - $override === null  → generate a fresh `uci_<16hex>` id
         *                          (same as ui_component_instance()).
         *   - $override is a safe string matching
         *     UiInstanceIdGenerator::SAFE_ID_PATTERN
         *                          → return it verbatim. The instance
         *                          id is then both server-rendered
         *                          onto the component root AND signed
         *                          into the event manifest's ctx so
         *                          downstream submit pipelines can
         *                          project per-field patches back at
         *                          the same id.
         *   - anything else        → throw UiComponentRegistryException
         *                          so the caller sees the validation
         *                          failure as a render-time Twig
         *                          error (clear surface for developer
         *                          mistakes).
         *
         * Stable ids are useful when an enclosing component (today:
         * FormComponent's submit cfg.f.i) needs to address the inner
         * component instance before its render runs. Tests and the
         * playground form-submit demo use this seam.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_component_instance_for',
            static function (mixed $override = null): string {
                if ($override === null) {
                    return (new UiInstanceIdGenerator())->next();
                }
                if (!UiInstanceIdGenerator::isSafe($override)) {
                    throw new UiComponentRegistryException(
                        'ui_component_instance_for() override must match the safe instance-id shape (' . UiInstanceIdGenerator::SAFE_ID_PATTERN . ').',
                    );
                }
                /** @var string $override */
                return $override;
            },
        );

        /**
         * ui_event_manifest(instanceId, ttlSeconds = null)
         *
         * Builds the signed UI event manifest for the component currently
         * being rendered and serialises it as a `<script type="application/json"
         * data-ui-event-manifest="<instanceId>">…</script>` block. The
         * block carries no executable JavaScript — it's pure data. The
         * future runtime is expected to find and parse it.
         *
         * Emits an empty `<script>` (no events) when the component has
         * no #[UiOn] declarations, so the future runtime can rely on the
         * tag's presence as a "component is event-aware" marker.
         *
         * Returns a Markup so Twig does not double-escape the JSON.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_event_manifest',
            static function (
                array $context,
                string $instanceId,
                ?int $ttlSeconds = null,
                array $eventConfig = [],
                ?string $dp = null,
            ): Markup {
                if ($instanceId === '') {
                    throw new UiComponentRegistryException(
                        'ui_event_manifest() called without an instance id.',
                    );
                }

                $component = $context['_component'] ?? null;
                if (!is_array($component) || !isset($component['name']) || !is_string($component['name'])) {
                    throw new UiComponentRegistryException(
                        'ui_event_manifest() called outside a Platform UI component render context.',
                    );
                }

                $metadata = UiComponentRegistry::get($component['name']);
                if ($metadata === null) {
                    throw new UiComponentRegistryException(sprintf(
                        'Component "%s" is not registered in UiComponentRegistry.',
                        $component['name'],
                    ));
                }

                // When the page has opted in to the canonical SSE
                // patch stream by calling `ui_page_sse_session()` /
                // `ui_page_sse_session_meta()`, the same id is folded
                // into every signed ctx on the page as the `sub`
                // claim. The dispatcher reads it after verification
                // and publishes `ui.patch` messages over
                // `/__semitexa_kiss?session_id=<sub>`. Pages that
                // never minted a session keep `sub` absent — the
                // dispatcher then falls back to inline patches,
                // preserving the pre-canonical-SSE behaviour.
                //
                // When the caller passes `dp:`, the FQCN of a class
                // implementing UiPartDataProviderInterface is folded
                // into every signed ctx so the dispatcher can resolve
                // and invoke the read-side data provider for filter /
                // sort / pagination flows without trusting any client-
                // supplied class name.
                $manifest = (new UiEventManifestBuilder())->build(
                    metadata: $metadata,
                    instanceId: $instanceId,
                    ttlSeconds: $ttlSeconds,
                    eventConfig: $eventConfig,
                    subscriberChannelId: PlatformUiSseSessionState::current(),
                    dataProviderClass: $dp,
                    externalBindings: UiComponentRegistry::externalBindingsFor($metadata->name),
                );

                $json = json_encode(
                    $manifest->toJsonShape(),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );

                // `</script>` inside JSON would break the parser; encode the
                // closing-tag sequence defensively.
                $json = str_replace('</', '<\\/', $json);

                $instanceAttr = htmlspecialchars($instanceId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $componentAttr = htmlspecialchars($manifest->componentName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                $html = sprintf(
                    '<script type="application/json" data-ui-event-manifest="%s" data-ui-component="%s">%s</script>',
                    $instanceAttr,
                    $componentAttr,
                    $json,
                );

                return new Markup($html, 'UTF-8');
            },
            ['needs_context' => true, 'is_safe' => ['html']],
        );

        /**
         * ui_collab_manifest(componentName, instanceId, formKey, recordId, mode, fields)
         *
         * Collaborative Form Data · Phase 3 — emits the signed collab manifest
         * `<script type="application/json" data-ui-collab-manifest>{…}</script>`
         * a collaborative form drops into the DOM for `form-collab-runtime.js`.
         * The document-feed sibling of `ui_event_manifest()`: where that mints
         * the per-event signed blobs for a component's #[UiOn] events, this
         * mints the read feed token (`cfg.scope/mode`) PLUS the per-event write
         * tokens the inbound collaboration handler routes by — see
         * {@see CollabManifestBuilder}. The block is pure data (no executable
         * JS); the runtime finds it by the `data-ui-collab-manifest` marker and
         * connects `/__ui/form-doc`.
         *
         * Placed INSIDE the form's component root (the element carrying
         * `data-ui-component-instance-id`) so the runtime resolves the root via
         * `closest()`, exactly as the event runtime does for its manifest.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_collab_manifest',
            static function (
                string $componentName,
                string $instanceId,
                string $formKey,
                string $recordId,
                string $mode,
                array $fields = [],
                ?int $ttlSeconds = null,
            ): Markup {
                $manifest = (new CollabManifestBuilder())->build(
                    componentName: $componentName,
                    instanceId: $instanceId,
                    formKey: $formKey,
                    recordId: $recordId,
                    mode: $mode,
                    fields: array_values($fields),
                    ttlSeconds: $ttlSeconds,
                );

                $json = json_encode(
                    $manifest,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );

                // `</script>` inside JSON would break the parser; encode the
                // closing-tag sequence defensively (mirrors ui_event_manifest).
                $json = str_replace('</', '<\\/', $json);

                $instanceAttr = htmlspecialchars($instanceId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                $html = sprintf(
                    '<script type="application/json" data-ui-collab-manifest="%s">%s</script>',
                    $instanceAttr,
                    $json,
                );

                return new Markup($html, 'UTF-8');
            },
            ['is_safe' => ['html']],
        );

        /**
         * ui_field_rules(rawRules)
         *
         * Validates a developer-authored rule spec list and returns
         * the compact JSON wire shape ready to be embedded in
         * `ui_event_manifest()`'s eventConfig argument. Throws
         * UiFieldValidationRuleException (which surfaces as a clear
         * Twig error in dev) when the spec is malformed — unknown
         * rule name, non-scalar param, wrong param count, closures,
         * service names, etc.
         *
         * Used by FieldComponent's template:
         *
         *   {%- set _r = ui_field_rules(rules|default([])) -%}
         *   {%- set _cfg = _r is empty ? {} : {'input.change': {r: _r}} -%}
         *   {{ ui_event_manifest(_ui_instance, null, _cfg) }}
         *
         * Returns a plain PHP array so Twig serialises it correctly
         * downstream.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_field_rules',
            static function (array $rawRules): array {
                if ($rawRules === []) {
                    return [];
                }
                // Resolve the ACTIVE rule registry — set at boot time
                // by BootPlatformUiRegistryListener with the
                // container-bound winner of UiFieldRuleRegistryInterface.
                // Tests override via UiFieldRuleRegistry::setActive(...).
                // Lazy-defaults to a fresh DefaultUiFieldRuleRegistry
                // when bootstrap was bypassed (standalone unit tests).
                $registry = UiFieldRuleRegistry::getActive();
                return (new UiFieldRuleParser($registry))->parseAllToWire($rawRules);
            },
        );

        /**
         * ui_form_submit_fields(rawFields)
         *
         * Renders the developer-facing `fields: [...]` prop on
         * FormComponent down to the compact wire shape that gets
         * signed into the submit ctx's `cfg.f` claim. Mirrors
         * `ui_field_rules()` but emits the wider definition shape
         * (name + rules + label + required) UiFormSubmitConfig
         * carries.
         *
         * Returns a plain array. Empty input → empty array; callers
         * skip the manifest cfg entirely in that case.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_form_submit_fields',
            static function (array $rawFields): array {
                if ($rawFields === []) {
                    return [];
                }
                $registry = UiFieldRuleRegistry::getActive();
                $parser   = new \Semitexa\PlatformUi\Application\Service\Validation\UiFormSubmitConfigParser(
                    new UiFieldRuleParser($registry),
                );
                return $parser->parse($rawFields)->toWireShape();
            },
        );

        /**
         * ui_form_field_submit_marker(def)
         *
         * Emit the inert metadata marker FieldComponent stamps into
         * its rendered output so an enclosing FormComponent with
         * `autoFields: true` can extract the field's submit definition
         * at render time. Shape mirrors cfg.f wire shape (single-letter
         * keys, no class / service names, no raw values).
         *
         * The marker is a `<script type="application/json">` block —
         * inert in the browser — and is stripped from the form's
         * final output by the extractor so it never reaches the DOM.
         * Emits the empty string when the supplied definition fails
         * the safe-name / safe-id guard (anonymous / unsafe-named
         * fields contribute nothing).
         *
         * Returns Markup so Twig's `autoescape: html` does not
         * double-escape the JSON.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_form_field_submit_marker',
            static function (array $def): Markup {
                $name = $def['n'] ?? null;
                if (!is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/', $name) !== 1) {
                    return new Markup('', 'UTF-8');
                }
                $instanceId = $def['i'] ?? null;
                if (!is_string($instanceId) || !UiInstanceIdGenerator::isSafe($instanceId)) {
                    return new Markup('', 'UTF-8');
                }
                $rules = $def['r'] ?? [];
                if (!is_array($rules)) {
                    $rules = [];
                }
                $payload = ['n' => $name, 'i' => $instanceId, 'r' => $rules];
                $rawLabel = $def['l'] ?? null;
                if (is_string($rawLabel) && $rawLabel !== '') {
                    $payload['l'] = $rawLabel;
                }
                if (!empty($def['q'])) {
                    $payload['q'] = true;
                }
                try {
                    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return new Markup('', 'UTF-8');
                }
                // Escape any `</` sequence so the JSON payload cannot
                // prematurely close the surrounding <script>.
                $json = str_replace('</', '<\\/', $json);
                return new Markup(
                    '<script type="application/json" data-ui-field-submit-definition>' . $json . '</script>',
                    'UTF-8',
                );
            },
            ['is_safe' => ['html']],
        );

        /**
         * ui_form_resolve_submit_fields(slotHtml, autoFields, manualFields = null)
         *
         * Resolve the final cfg.f wire shape FormComponent's template
         * signs into the submit ctx.
         *
         * Behaviour matrix:
         *   - autoFields=true,  manualFields empty/null  → extract
         *     definitions from the marker tags inside the captured
         *     slot HTML, validate through
         *     UiFormSubmitConfigParser::parseSignedWire (duplicate
         *     names / duplicate instance ids / shape checks).
         *   - autoFields=true,  manualFields non-empty   → throw — the
         *     caller asked for both auto and manual which is
         *     ambiguous; fail loud at render time.
         *   - autoFields=false, manualFields empty/null  → return an
         *     empty list (preserves "no signed fields" behaviour).
         *   - autoFields=false, manualFields non-empty   → parse the
         *     manual `fields` prop the existing way.
         *
         * @param mixed $manualFields
         */
        TwigExtensionRegistry::registerFunction(
            'ui_form_resolve_submit_fields',
            static function (string $slotHtml, bool $autoFields, $manualFields = null): array {
                $manualList = is_array($manualFields) ? $manualFields : [];
                $manualNonEmpty = $manualList !== [];

                if ($autoFields) {
                    if ($manualNonEmpty) {
                        throw new UiComponentRegistryException(
                            'FormComponent: autoFields=true is mutually exclusive with the `fields` prop — provide one or the other, not both.',
                        );
                    }
                    $registry = UiFieldRuleRegistry::getActive();
                    $extractor = new UiFormSubmitDefinitionExtractor(
                        new UiFormSubmitConfigParser(new UiFieldRuleParser($registry)),
                    );
                    return $extractor->extract($slotHtml)['config']->toWireShape();
                }

                if (!$manualNonEmpty) {
                    return [];
                }
                $registry = UiFieldRuleRegistry::getActive();
                $parser = new UiFormSubmitConfigParser(new UiFieldRuleParser($registry));
                return $parser->parse($manualList)->toWireShape();
            },
        );

        /**
         * ui_form_resolve_submit_action(name)
         *
         * Validate and return the action name FormComponent's
         * template signs into `cfg.a`. The name comes from the
         * developer-facing `submitAction` prop on FormComponent:
         *
         *   {{ component('platform.form', {autoFields: true, submitAction: 'platform.demo.accept'}, ...) }}
         *
         * Behaviour:
         *   - null / empty string → returns null; cfg.a is omitted and
         *     no action is invoked at dispatch time.
         *   - non-string / unsafe shape → throws UiFormSubmitActionException
         *     at render time so developers see the failure in dev.
         *   - safe-shape string unknown to the active registry →
         *     throws the same typed exception with the list of known
         *     action names.
         *   - safe-shape known action → returns the name verbatim,
         *     ready to embed under `cfg.a` in the signed event
         *     manifest.
         *
         * The registry resolves the name through a fixed match — it
         * NEVER reflects a class FQCN out of the name. The Twig layer
         * therefore never sees an FQCN / service id even if a
         * customer registry mis-implements its `resolve()`.
         *
         * @param mixed $name
         */
        TwigExtensionRegistry::registerFunction(
            'ui_form_resolve_submit_action',
            static function (mixed $name = null): ?string {
                if ($name === null || $name === '') {
                    return null;
                }
                if (!is_string($name)) {
                    throw new UiFormSubmitActionException(
                        'FormComponent `submitAction` prop must be a string action name.',
                    );
                }
                if (preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]{0,127}\z/', $name) !== 1) {
                    throw new UiFormSubmitActionException(
                        'FormComponent `submitAction` must match [A-Za-z_][A-Za-z0-9_.-]{0,127}.',
                        $name,
                    );
                }
                // Resolve from the active registry — throws
                // UiFormSubmitActionException on unknown name, which
                // Twig surfaces as a clear render-time error.
                UiFormSubmitActionRegistry::getActive()->resolve($name);
                return $name;
            },
        );

        /**
         * ui_form_strip_submit_markers(html)
         *
         * Return $html with every
         * `<script data-ui-field-submit-definition>…</script>` marker
         * removed. Idempotent — cheap to call on HTML with none.
         * FormComponent's template calls this on its captured slot
         * HTML so markers are consumed exactly once and the final DOM
         * stays clean.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_form_strip_submit_markers',
            static function (string $html): string {
                return (new UiFormSubmitDefinitionExtractor())->stripMarkers($html);
            },
        );

        /**
         * ui_form_issue_submit_csrf(actionName, ttlSeconds = null)
         *
         * Issue a one-time CSRF token through the active
         * UiFormSubmitCsrfTokenStore. Returns the compact `{k, t}`
         * map FormComponent's template signs into `cfg.s` of the
         * submit ctx:
         *
         *   - `k` (string) : token id (`uicsrf_<16hex>`). Acts as the
         *                    cache key (with namespace).
         *   - `t` (string) : raw 128-bit token (32 hex chars). The
         *                    store keeps only its HMAC hash.
         *
         * Behaviour:
         *   - `$actionName === null` → returns `null`. Forms without a
         *     `submitAction` do not issue tokens; the policy is
         *     therefore never invoked for them.
         *   - non-empty action  → mint a fresh `{k, t}` pair. The
         *     same pair is signed verbatim into the submit ctx so
         *     the dispatch path can present it back.
         *
         * Default TTL: 600s (10 min) — chosen to comfortably outlive
         * a typical user fill time without inflating cache pressure.
         * Callers can pass a tighter `$ttlSeconds`.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_form_issue_submit_csrf',
            static function (?string $actionName = null, ?int $ttlSeconds = null): ?array {
                if ($actionName === null || $actionName === '') {
                    return null;
                }
                $ttl = $ttlSeconds ?? 600;
                $handle = UiFormSubmitCsrfTokenStore::getActive()->issue($ttl);
                return ['k' => $handle->id, 't' => $handle->raw];
            },
        );

        /**
         * ui_page_sse_session()
         *
         * Opt-in helper that mints (or returns) the canonical SSE
         * subscriber channel id shared by every platform-ui component
         * on the page. Call this BEFORE any platform-ui component
         * renders so the `ui_event_manifest()` helper picks it up and
         * folds it into the signed `sub` claim.
         *
         * Returned value matches `[A-Za-z0-9][A-Za-z0-9_-]{0,127}`
         * (`sse_<32 hex>` in practice). It is NOT a secret — KISS
         * routes incoming subscribers on this id directly, so we
         * publish it to the page in the open. Defence-in-depth comes
         * from the signed ctx: a request for `/__ui/event` with a
         * forged `sub` would also need a valid HMAC over the rest of
         * the claim set, which the client cannot mint.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_page_sse_session',
            static fn (): string => PlatformUiSseSessionState::mintIfAbsent(),
        );

        /**
         * ui_page_sse_session_meta($mode = null)
         *
         * Render-side counterpart to {@see self::ui_page_sse_session()}:
         * mints (or returns) the session id and emits TWO inert meta
         * tags the frontend event runtime scans for:
         *
         *   <meta name="semitexa-ui-sse-session"  content="<id>">
         *   <meta name="semitexa-ui-transport-mode" content="drain|live">
         *
         * The transport mode is resolved through
         * {@see PlatformUiTransportModePolicy} with this precedence:
         *
         *   1. explicit $mode argument (string `'drain'` | `'live'`)
         *   2. env default SEMITEXA_UI_TRANSPORT_MODE (same allow-list)
         *   3. auth-derived default — authenticated → live, guest → drain
         *      (auth bit supplied per request via PlatformUiAuthState; an
         *      app with no AuthCheck bridge stays on the drain fallback)
         *   4. hard fallback drain (safe for public/guest pages)
         *
         * Drain pages do NOT auto-open an EventSource on
         * DOMContentLoaded — the runtime opens
         * `/__semitexa_kiss?session_id=<id>&mode=drain` only after a
         * canonical UI event response reports `streamedPatchCount > 0`.
         * Live pages auto-open `…&mode=live` on DOMContentLoaded and
         * hold the stream open. Both modes share the same session id
         * and the same `sub` claim threading through
         * `ui_event_manifest()`.
         *
         * Pages that DO NOT call this helper get no meta tag, no
         * canonical SSE auto-open, and no `sub` claim in their signed
         * ctxs — the dispatcher then keeps returning inline patches
         * exactly as it did before the canonical stream existed.
         *
         * Invalid $mode (non-string, unknown value) or invalid env
         * value raises UiTransportModeException at render time so
         * deployments fail fast rather than silently widening the
         * surface to live. See PlatformUiTransportModePolicy for the
         * exact error surface.
         *
         * Returns Markup so Twig's autoescape: html does not double-
         * escape the attribute values.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_page_sse_session_meta',
            static function (?string $mode = null): Markup {
                // The auth bit is OPTIONAL request-scoped state pushed in
                // by the consuming app's AuthCheck bridge; null (no bridge)
                // leaves the policy on its drain default. Reading it here —
                // at the request-scoped render boundary — keeps
                // PlatformUiTransportModePolicy itself pure and auth-agnostic.
                $resolved = (new PlatformUiTransportModePolicy())
                    ->resolve($mode, PlatformUiAuthState::current());
                $id = PlatformUiSseSessionState::mintIfAbsent();
                $idAttr = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $modeAttr = htmlspecialchars($resolved->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return new Markup(
                    '<meta name="semitexa-ui-sse-session" content="' . $idAttr . '">'
                    . '<meta name="semitexa-ui-transport-mode" content="' . $modeAttr . '">',
                    'UTF-8',
                );
            },
            ['is_safe' => ['html']],
        );

        TwigExtensionRegistry::registerFunction(
            'ui_component_events',
            static function (string $componentName): array {
                $metadata = UiComponentRegistry::get($componentName);
                if ($metadata === null) {
                    return [];
                }
                $rows = [];
                foreach ($metadata->events as $event) {
                    $rows[] = [
                        'part' => $event->partName,
                        'event' => $event->eventName,
                        'updates' => $event->updatesPath !== null ? (string) $event->updatesPath : null,
                        'method' => $event->methodName,
                        'runtime' => 'metadata-only',
                    ];
                }
                return $rows;
            },
        );
    }
}
