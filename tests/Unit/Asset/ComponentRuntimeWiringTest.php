<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every component runtime is required by the markup it drives, and every key
 * used to require one resolves to a file that exists.
 *
 * These runtimes used to be GLOBAL: the grid engine, the calendar, the
 * date field and the collaborative-form transport were downloaded and parsed by
 * every page of every application whether or not one was rendered. Page scope
 * fixed that and moved the wiring into the templates — which trades a waste
 * that is merely expensive for a failure that is silent, so it needs pinning.
 *
 * Silent how: {@see \Semitexa\Ssr\Application\Service\Asset\AssetCollector::require()}
 * does NOT reject a key it has never heard of. It falls back to
 * `AssetEntry::fromKey()`, which infers a path from the key's own spelling. So
 * `platform-ui:js:calendar-runtme` is not an error — it is a `<script src>`
 * pointing at a file that was never built. The page renders, the component
 * renders, the server logs nothing, and the only evidence is a 404 in a browser
 * nobody is watching.
 *
 * The grid's own pairing is pinned in {@see GridRuntimeV2StaticAssertTest}; this
 * covers all four, plus the dependency keys declared in the manifest, which are
 * required through the same forgiving path.
 */
final class ComponentRuntimeWiringTest extends TestCase
{
    private const STATIC_DIR = __DIR__ . '/../../../src/Application/Static';

    private const TEMPLATE_DIR = __DIR__ . '/../../../resources/twig/components/runtime';

    /**
     * template => the keys its markup cannot work without.
     *
     * @var array<string, list<string>>
     */
    private const WIRING = [
        'grid-v2.html.twig' => ['platform-ui:js:grid-runtime-v2'],
        'collab-form.html.twig' => ['platform-ui:js:form-collab-runtime'],
        'calendar.html.twig' => ['platform-ui:js:calendar-runtime', 'platform-ui:css:calendar'],
        'date-field.html.twig' => ['platform-ui:js:date-field-runtime', 'platform-ui:css:date-field'],
    ];

    #[Test]
    public function each_component_template_requires_the_runtime_it_needs(): void
    {
        foreach (self::WIRING as $template => $keys) {
            $path = self::TEMPLATE_DIR . '/' . $template;
            self::assertFileExists($path);

            $source = (string) file_get_contents($path);

            foreach ($keys as $key) {
                self::assertStringContainsString(
                    "asset_require('" . $key . "')",
                    $source,
                    $template . ' emits markup that is inert without ' . $key . '.',
                );
            }
        }
    }

    /**
     * Nothing hands these out any more, so a page that does not render the
     * component must not be made to carry it. Page scope is the whole reason
     * this wiring exists; a key that drifts back to global silently undoes it.
     */
    #[Test]
    public function every_required_runtime_is_page_scoped(): void
    {
        $overrides = self::manifest()['overrides'] ?? [];
        self::assertIsArray($overrides);

        foreach (self::WIRING as $template => $keys) {
            foreach ($keys as $key) {
                $relative = self::pathForKey($key);
                self::assertArrayHasKey(
                    $relative,
                    $overrides,
                    $key . ' must be declared in assets.json, not inferred from its own spelling.',
                );
                self::assertSame(
                    'page',
                    $overrides[$relative]['scope'] ?? null,
                    $key . ' is required by ' . $template . '; global scope would put it on every page again.',
                );
            }
        }
    }

    /**
     * The keys, and the keys they pull in, name files that were actually built.
     *
     * calendar-runtime and date-field-runtime both declare a dependency on
     * calendar-dates, which the collector requires recursively through the same
     * forgiving path — so a dependency naming nothing is the same silent 404 one
     * level down, where it is even easier to miss.
     */
    #[Test]
    public function every_required_key_and_its_dependencies_name_a_real_file(): void
    {
        $overrides = self::manifest()['overrides'] ?? [];
        self::assertIsArray($overrides);

        $keys = [];
        foreach (self::WIRING as $required) {
            foreach ($required as $key) {
                $keys[$key] = true;

                foreach ($overrides[self::pathForKey($key)]['dependencies'] ?? [] as $dependency) {
                    $keys[$dependency] = true;
                }
            }
        }

        foreach (array_keys($keys) as $key) {
            self::assertFileExists(
                self::STATIC_DIR . '/' . self::pathForKey($key),
                $key . ' resolves to no file; requiring it emits a <script> or <link> that 404s.',
            );
        }
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents(self::STATIC_DIR . '/assets.json'), true);
        self::assertIsArray($manifest);

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }

    /** `platform-ui:js:calendar-runtime` => `js/calendar-runtime.js` — the inference the collector itself makes. */
    private static function pathForKey(string $key): string
    {
        $parts = explode(':', $key);
        self::assertCount(3, $parts, 'an asset key is {module}:{type}:{name}');

        return $parts[1] . '/' . $parts[2] . '.' . $parts[1];
    }
}
