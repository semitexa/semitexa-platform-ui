<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValue;

/** `required` must hold for every control kind: unchecked and empty both read as missing. */
final class UiFieldValueTest extends TestCase
{
    #[Test]
    public function every_control_value_reads_as_the_string_the_rules_check(): void
    {
        self::assertSame('abc', UiFieldValue::asString('abc'));
        self::assertSame('7', UiFieldValue::asString(7));
        self::assertSame('1', UiFieldValue::asString(true));
        self::assertSame('', UiFieldValue::asString(false));
        self::assertSame('', UiFieldValue::asString(null));
        self::assertSame('', UiFieldValue::asString([]));
        self::assertSame('php,ui', UiFieldValue::asString(['php', 'ui']));
    }
}
