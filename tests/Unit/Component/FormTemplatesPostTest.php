<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A submit the runtime does not take, before its scripts ran or with scripting
 * off, is the browser's. A form with no method sends it as GET, and what a
 * visitor typed lands in the URL, in logs and in history: a live run on a fresh
 * project on 2026-11-07 saw GET /guestbook?name=Slow&message=...
 */
final class FormTemplatesPostTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        yield 'form' => ['components/runtime/form.html.twig'];
        yield 'collab form' => ['components/runtime/collab-form.html.twig'];
        yield 'form root' => ['primitives/runtime/form-root.html.twig'];
    }

    #[DataProvider('templates')]
    public function test_a_form_posts_what_the_runtime_did_not_take(string $template): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__, 3) . '/resources/twig/' . $template);
        $tag = preg_replace('/\{#.*?#\}/s', '', $twig) ?? '';

        // verify:accept-test-change every form tag is checked now, not only the first one
        self::assertGreaterThan(0, preg_match_all('/<form\b[^>]*>/s', $tag, $forms));
        foreach ($forms[0] as $form) {
            self::assertMatchesRegularExpression('/\smethod="post"/', $form, 'every form, not only the first');
        }
    }
}
