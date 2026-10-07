<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\DefaultUiFormSubmitActionRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionDiscovery;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionRegistry;
use Semitexa\PlatformUi\Attribute\AsFormSubmitAction;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;

/** tk-la-form-lifecycle: an attribute is the whole registration step. */
final class UiFormSubmitActionDiscoveryTest extends TestCase
{
    protected function tearDown(): void
    {
        UiFormSubmitActionRegistry::reset();
    }

    #[Test]
    public function an_attributed_action_is_found_and_resolved_first(): void
    {
        $actions = UiFormSubmitActionDiscovery::fromClasses([DiscoveredArticleAction::class], static fn (string $c): object => new $c());
        self::assertSame(['blog.article.create'], array_keys($actions));

        UiFormSubmitActionRegistry::setActive(new DefaultUiFormSubmitActionRegistry());
        UiFormSubmitActionRegistry::setDiscovered($actions);
        self::assertInstanceOf(DiscoveredArticleAction::class, UiFormSubmitActionRegistry::getActive()->resolve('blog.article.create'));
        self::assertContains('blog.article.create', UiFormSubmitActionRegistry::getActive()->knownActionNames());
    }

    #[Test]
    public function with_a_request_scope_the_action_is_resolved_for_each_submit(): void
    {
        $resolved = 0;
        $actions = UiFormSubmitActionDiscovery::fromClasses(
            [DiscoveredArticleAction::class],
            static function (string $c) use (&$resolved): object {
                $resolved++;
                return new $c();
            },
        );
        self::assertSame(0, $resolved, 'nothing is resolved at boot: there is no visitor yet');

        UiFormSubmitActionRegistry::setActive(new DefaultUiFormSubmitActionRegistry());
        UiFormSubmitActionRegistry::setDiscovered($actions);
        $first = UiFormSubmitActionRegistry::getActive()->resolve('blog.article.create');
        $second = UiFormSubmitActionRegistry::getActive()->resolve('blog.article.create');

        self::assertSame(2, $resolved, 'one resolution per submit: the request scope injects that visitor');
        self::assertNotSame($first, $second);
    }

    #[Test]
    public function a_name_that_disagrees_with_the_attribute_fails_the_boot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/name\(\) returns "something.else"/');
        UiFormSubmitActionDiscovery::fromClasses([MisnamedAction::class], static fn (string $c): object => new $c());
    }

    #[Test]
    public function a_duplicate_name_fails_the_boot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/declared twice/');
        UiFormSubmitActionDiscovery::fromClasses([DiscoveredArticleAction::class, DiscoveredArticleAction::class], static fn (string $c): object => new $c());
    }

    #[Test]
    public function a_class_that_is_not_an_action_fails_the_boot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/must implement/');
        UiFormSubmitActionDiscovery::fromClasses([NotAnAction::class], static fn (string $c): object => new $c());
    }
}

#[AsFormSubmitAction('blog.article.create')]
final class DiscoveredArticleAction implements UiFormSubmitActionInterface
{
    public function name(): string
    {
        return 'blog.article.create';
    }

    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult
    {
        return UiFormSubmitActionResult::accepted();
    }
}

#[AsFormSubmitAction('blog.article.update')]
final class MisnamedAction implements UiFormSubmitActionInterface
{
    public function name(): string
    {
        return 'something.else';
    }

    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult
    {
        return UiFormSubmitActionResult::accepted();
    }
}

#[AsFormSubmitAction('blog.article.delete')]
final class NotAnAction
{
}
