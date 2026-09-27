<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Registry;

/**
 * Every customer-facing date follows Settings ▸ Appearance ▸ date format.
 *
 * Field report: one booking card showed "Check-in 07.09.2026" beside "Anulare
 * gratuită până la 08/28/2026". Four formatters were in play — the sidebar
 * hardcoded '%d.%m.%Y', novoton's terms followed the store setting but rewrote
 * its default, sphinx's terms hardcoded dd.mm.yyyy, and the cart card printed
 * "%a %d %b %Y". This is the one they all use now.
 *
 * CS-Cart's fn_date_format isn't loaded in unit tests, so these exercise the
 * strftime → PHP fallback; on a real store the same call goes through
 * fn_date_format, which additionally translates month and weekday names.
 */
final class DateHelperStoreFormatTest extends TestCase
{
    /** 2026-09-07 12:00 local — a Monday. Built at runtime so the assertions
     *  hold under whatever timezone PHP is configured with. */
    private static function noon(): int
    {
        return (int) strtotime('2026-09-07 12:00:00');
    }

    protected function setUp(): void
    {
        Registry::del('settings.Appearance.date_format');
    }

    protected function tearDown(): void
    {
        Registry::del('settings.Appearance.date_format');
    }

    public function testFollowsTheStoreSetting(): void
    {
        Registry::set('settings.Appearance.date_format', '%m/%d/%Y');
        self::assertSame('09/07/2026', DateHelper::formatStoreDate(self::noon()));

        Registry::set('settings.Appearance.date_format', '%d.%m.%Y');
        self::assertSame('07.09.2026', DateHelper::formatStoreDate(self::noon()));

        Registry::set('settings.Appearance.date_format', '%d %b %Y');
        self::assertSame('07 Sep 2026', DateHelper::formatStoreDate(self::noon()));
    }

    /**
     * The novoton terms formatter used to rewrite this exact default to
     * '%d.%m.%Y' on its own — which is how one card ended up with two formats.
     */
    public function testTheAdminDefaultIsNotSecondGuessed(): void
    {
        Registry::set('settings.Appearance.date_format', '%d %b %Y');
        self::assertSame(
            DateHelper::formatStoreDate(self::noon()),
            DateHelper::formatWith(self::noon(), '%d %b %Y'),
        );
    }

    public function testUnsetSettingFallsBackToTheEuropeanNumericForm(): void
    {
        self::assertSame('07.09.2026', DateHelper::formatStoreDate(self::noon()));
    }

    /** The weekday is a separate field on the sidebar, not part of the format. */
    public function testWeekdayIsFormattedIndependently(): void
    {
        Registry::set('settings.Appearance.date_format', '%m/%d/%Y');
        self::assertSame('Monday', DateHelper::formatStoreWeekday(self::noon()));
    }

    public function testAcceptsDateStringsAsWellAsTimestamps(): void
    {
        Registry::set('settings.Appearance.date_format', '%d.%m.%Y');
        self::assertSame('07.09.2026', DateHelper::formatStoreDate('2026-09-07'));
        self::assertSame('07.09.2026', DateHelper::formatStoreDate((string) self::noon()));
    }

    /**
     * Unparseable input is echoed back rather than rendered as the epoch — a
     * booking row with a blank date must not claim 01.01.1970.
     */
    public function testUnusableInputIsReturnedUnchanged(): void
    {
        self::assertSame('', DateHelper::formatStoreDate(''));
        self::assertSame('', DateHelper::formatStoreDate(null));
        self::assertSame('not a date', DateHelper::formatStoreDate('not a date'));
        self::assertNull(DateHelper::toTimestamp('0'));
        self::assertNull(DateHelper::toTimestamp(0));
        self::assertSame(self::noon(), DateHelper::toTimestamp(self::noon()));
    }

    public function testStrftimePatternsMapToPhpDatePatterns(): void
    {
        self::assertSame('d.m.Y', DateHelper::strftimeToPhp('%d.%m.%Y'));
        self::assertSame('j F Y, l', DateHelper::strftimeToPhp('%e %B %Y, %A'));
        self::assertSame('D d M y H:i', DateHelper::strftimeToPhp('%a %d %b %y %H:%M'));
    }

    /**
     * The sidebar prints the weekday on its own line; a store format that
     * already carries it ("Mon, 5 Oct 2026") must not print it twice.
     */
    public function testDetectsAWeekdayInTheStoreFormat(): void
    {
        self::assertTrue(DateHelper::formatHasWeekday('%a, %e %b %Y'));
        self::assertTrue(DateHelper::formatHasWeekday('%A %d.%m.%Y'));
        self::assertFalse(DateHelper::formatHasWeekday('%m/%d/%Y'));
        self::assertFalse(DateHelper::formatHasWeekday('%d.%m.%Y'));
    }
}
