# SSR integration

`platform-ui` is a `semitexa-module`. `semitexa/ssr` auto-discovers its assets via `src/Application/Static/assets.json` (v2 manifest).

## Mode A — Static bundle (v1, ships today)

### Build

```bash
bin/semitexa platform-ui:css:build
```

Produces two static files:

- `src/Application/Static/css/full.css` — all grammar + primitives + baseline + default skin (~12KB raw, **2.6KB gzipped**)
- `src/Application/Static/css/baseline.css` — reset + typography + tokens only (~2.6KB raw, **1.1KB gzipped**)

Ssr discovers them via manifest; they become available as:

- `platform-ui:css:full` — scope=module, priority=40
- `platform-ui:css:baseline` — scope=page, priority=30

### Use

In a layout template:
```html
<link rel="stylesheet" href="/assets/platform-ui/css/full.css">
```

Or via ssr asset pipeline in a handler:
```php
$collector->requireModule('platform-ui');
```

### Regenerate after skin change

```bash
bin/semitexa skins:generate balanced "#hex" --name=default --write
bin/semitexa platform-ui:css:build
```

## Mode B — Dynamic per-route (post-hook)

The full pipeline exists in `platform-ui` but needs a post-Twig-render hook in ssr to produce per-request bundles. See [semitexa/semitexa-ssr#51](https://github.com/semitexa/semitexa-ssr/issues/51).

When available, the pattern will be:
1. `TwigExtractor` scans rendered HTML for `sx-*`/`ui-*` usage (per-request `SliceRegistry`)
2. `BundleCompiler` assembles minimal CSS from used slices
3. Content-hashed for cache-busting
4. Registered via new `AssetCollector::inlineCss()` API

Expected size: 2–5KB gzipped per route instead of 2.6KB monolithic.

## Safelist for dynamic templates

When Twig conditionals produce `sx-*`/`ui-*` values that can't be seen by static scans:

```php
$safelist = new Safelist();
$safelist->addGlobalSlice('sx-tone:warning');
$safelist->addRoutePrimitive('/dashboard', 'badge');
$safelist->apply($registry, currentRoute: $path);
```

## Introspection

- `bin/semitexa platform-ui:css:inspect <template>` — scans file, shows grammar slices, primitives, unresolved items, and final bundle size
