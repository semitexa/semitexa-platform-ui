<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Calendar;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Payload\Request\CalendarEventSavePayload;

/**
 * Calendar datetime correctness at the SERVER boundary, not only in the JS
 * client. The browser sends UTC `...Z`, but a direct/alternate API caller may
 * submit a naive local string; interpreting that blindly as UTC lands the
 * event at the wrong hour for a non-UTC user. A naive value is now read in the
 * configured zone; an explicit offset stays authoritative; both normalise to
 * UTC for storage.
 */
final class CalendarEventSavePayloadTzTest extends TestCase
{
    private string $tzEnvBefore = '';

    protected function setUp(): void
    {
        $this->tzEnvBefore = (string) getenv('SEMITEXA_CALENDAR_DEFAULT_TZ');
    }

    protected function tearDown(): void
    {
        putenv('SEMITEXA_CALENDAR_DEFAULT_TZ');
        if ($this->tzEnvBefore !== '') {
            putenv('SEMITEXA_CALENDAR_DEFAULT_TZ=' . $this->tzEnvBefore);
        }
    }

    #[Test]
    public function an_explicit_utc_z_string_is_authoritative_regardless_of_assume(): void
    {
        $p = new CalendarEventSavePayload();
        $p->setStartsAt('2026-07-05T18:00:00Z');

        $utc = $p->getStartsAt(new \DateTimeZone('Europe/Kyiv'));

        self::assertSame('2026-07-05 18:00:00', $utc->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $utc->getTimezone()->getName());
    }

    #[Test]
    public function a_naive_string_is_interpreted_in_the_assumed_zone(): void
    {
        $p = new CalendarEventSavePayload();
        $p->setStartsAt('2026-07-05 18:00'); // no offset — Kyiv summer = UTC+3

        $utc = $p->getStartsAt(new \DateTimeZone('Europe/Kyiv'));

        self::assertSame('2026-07-05 15:00:00', $utc->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_naive_string_defaults_to_utc_when_no_zone_is_passed(): void
    {
        // Preserves the pre-change contract (browser sends UTC): naive == UTC.
        $p = new CalendarEventSavePayload();
        $p->setStartsAt('2026-07-05 18:00');

        self::assertSame('2026-07-05 18:00:00', $p->getStartsAt()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function default_zone_reads_the_env_and_falls_back_to_utc(): void
    {
        putenv('SEMITEXA_CALENDAR_DEFAULT_TZ');
        self::assertSame('UTC', CalendarEventSavePayload::defaultZone()->getName());

        putenv('SEMITEXA_CALENDAR_DEFAULT_TZ=Europe/Kyiv');
        self::assertSame('Europe/Kyiv', CalendarEventSavePayload::defaultZone()->getName());

        putenv('SEMITEXA_CALENDAR_DEFAULT_TZ=Not/AZone');
        self::assertSame('UTC', CalendarEventSavePayload::defaultZone()->getName(), 'invalid → UTC, never throws');
    }
}
