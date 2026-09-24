<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

/**
 * The excluded-resorts setting must read back as the resort names the
 * dashboard saved.
 *
 * The dashboard stores a JSON array; the getter split it on commas, so
 * `["ALBENA","VARNA"]` became `["ALBENA"` and `"VARNA"]` and
 * add_hotels_as_products excluded nothing, while the dashboard showed the
 * resorts as excluded.
 */
final class ExcludedResortsSettingTest extends TestCase
{
    public function testTheDashboardsJsonArrayReadsBackAsNames(): void
    {
        self::assertSame(['ALBENA', 'VARNA'], ConfigProvider::parseResortList('["ALBENA","VARNA"]'));
        self::assertSame(['ST.CONSTANTINE & ELENA'], ConfigProvider::parseResortList(json_encode(['ST.CONSTANTINE & ELENA']) ?: ''));
    }

    public function testTheDefaultEmptyArrayExcludesNothing(): void
    {
        self::assertSame([], ConfigProvider::parseResortList('[]'));
        self::assertSame([], ConfigProvider::parseResortList(''));
        self::assertSame([], ConfigProvider::parseResortList('   '));
    }

    public function testOlderCommaSeparatedValuesStillRead(): void
    {
        self::assertSame(['ALBENA', 'VARNA'], ConfigProvider::parseResortList('ALBENA, VARNA'));
        self::assertSame(['ALBENA'], ConfigProvider::parseResortList('ALBENA'));
    }

    public function testBlanksAndRepeatsAreDropped(): void
    {
        self::assertSame(['ALBENA', 'VARNA'], ConfigProvider::parseResortList('["ALBENA", " ", "VARNA", "ALBENA"]'));
        self::assertSame(['ALBENA', 'VARNA'], ConfigProvider::parseResortList('ALBENA,,VARNA,ALBENA'));
    }

    /** The getter goes through the parser, not its own split. */
    public function testTheGetterUsesTheParser(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/ConfigProvider.php');
        $start = strpos($src, 'public static function getExcludedResorts(');
        self::assertIsInt($start);
        $body = substr($src, $start, (int) strpos($src, '}', $start) - $start);

        self::assertStringContainsString('self::parseResortList(', $body);
        self::assertStringNotContainsString('explode(', $body);
    }
}
