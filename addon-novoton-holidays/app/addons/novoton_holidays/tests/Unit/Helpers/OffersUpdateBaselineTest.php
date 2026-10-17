<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\OffersUpdateBaseline;

/**
 * offers_update read its "since" from a sync-log row the nightly cleanup
 * trims away, and refused to run without one — on a store that never had the
 * row it could never start. The baseline is now durable, and a first run
 * looks back a week.
 */
#[CoversClass(OffersUpdateBaseline::class)]
final class OffersUpdateBaselineTest extends TestCase
{
    private const int NOW = 1792152000; // 2026-10-16 12:00:00 UTC

    public function testTheStoredBaselineWins(): void
    {
        self::assertSame('2026-10-16 08:00:00', OffersUpdateBaseline::resolve('2026-10-16 08:00:00', '2026-09-01 10:00:00', self::NOW));
    }

    public function testTheLegacyLogRowIsUsedOnceWhenNothingIsStored(): void
    {
        self::assertSame('2026-09-01 10:00:00', OffersUpdateBaseline::resolve(null, '2026-09-01 10:00:00', self::NOW));
        self::assertSame('2026-09-01 10:00:00', OffersUpdateBaseline::resolve('', '2026-09-01 10:00:00', self::NOW));
    }

    public function testAFirstRunLooksBackAWeekInsteadOfRefusing(): void
    {
        self::assertSame(
            date('Y-m-d H:i:s', self::NOW - OffersUpdateBaseline::FIRST_RUN_DAYS * 86400),
            OffersUpdateBaseline::resolve(null, null, self::NOW),
        );
        self::assertSame(
            date('Y-m-d H:i:s', self::NOW - OffersUpdateBaseline::FIRST_RUN_DAYS * 86400),
            OffersUpdateBaseline::resolve('not a date', '', self::NOW),
            'an unreadable value is no baseline',
        );
    }

    public function testWithoutCsCartStorageNothingIsStoredOrRead(): void
    {
        if (function_exists('fn_get_storage_data')) {
            self::markTestSkipped('CS-Cart storage is loaded in this run');
        }

        OffersUpdateBaseline::advance('bulgaria', '2026-10-16 12:00:00');
        self::assertNull(OffersUpdateBaseline::get('BULGARIA'));
    }
}
