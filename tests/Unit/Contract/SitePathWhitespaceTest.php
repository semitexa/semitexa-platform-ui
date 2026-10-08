<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Tree\NavigateTreeAction;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;

/**
 * The server check and the emitted JSON Schema pattern must agree on a site
 * path. The schema's \s includes Unicode whitespace; PCRE's \s without /u does
 * not, so "/a<U+00A0>b" passed the server and failed the schema.
 */
final class SitePathWhitespaceTest extends TestCase
{
    #[Test]
    public function a_path_with_unicode_whitespace_is_refused_by_the_prop(): void
    {
        $prop = new UiProp('href', sitePath: true);

        $this->expectException(\InvalidArgumentException::class);
        $prop->validate("/a\u{00A0}b", 'href');
    }

    #[Test]
    public function a_path_with_unicode_whitespace_is_refused_by_navigate(): void
    {
        $errors = (new NavigateTreeAction())->check(['kind' => 'navigate', 'to' => "/a\u{2003}b"], '/actions/go');

        self::assertCount(1, $errors);
        self::assertSame('tree.action_target', $errors[0]->code);
    }

    #[Test]
    public function an_ordinary_site_path_still_passes_both(): void
    {
        (new UiProp('href', sitePath: true))->validate('/orders?create', 'href');

        self::assertSame([], (new NavigateTreeAction())->check(['kind' => 'navigate', 'to' => '/orders?create'], '/actions/go'));
    }
}
