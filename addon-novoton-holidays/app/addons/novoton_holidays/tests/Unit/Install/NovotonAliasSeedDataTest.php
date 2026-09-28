<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Install\NovotonAliasSeedData;

/**
 * The alias seed data extracted from functions/install.php's
 * fn_novoton_holidays_seed_travel_aliases (its largest inert block) —
 * novoton's twin of sphinx's SphinxAliasSeedData. Shape + a few
 * load-bearing mappings the storefront labels depend on.
 */
final class NovotonAliasSeedDataTest extends TestCase
{
    public function testEveryAliasGroupMapsToNonEmptyCanonicalCodes(): void
    {
        foreach ([
            NovotonAliasSeedData::boardAliases(),
            NovotonAliasSeedData::roomAliases(),
            NovotonAliasSeedData::propertyTypeAliases(),
            NovotonAliasSeedData::hotelFacilityAliases(),
            NovotonAliasSeedData::roomFacilityAliases(),
        ] as $aliases) {
            self::assertNotSame([], $aliases);
            foreach ($aliases as $canonical) {
                self::assertNotSame('', $canonical);
            }
        }
    }

    public function testLoadBearingBoardMappingsSurviveTheExtraction(): void
    {
        $boards = NovotonAliasSeedData::boardAliases();

        self::assertContains('HB', $boards);
        self::assertContains('BB', $boards);
        self::assertContains('AI', $boards);
    }

    public function testTheSeederReadsTheDataClassInsteadOfInlineArrays(): void
    {
        $install = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Install/AliasSeeder.php');

        self::assertStringContainsString('NovotonAliasSeedData::boardAliases()', $install);
        self::assertStringContainsString('NovotonAliasSeedData::hotelFacilityAliases()', $install);
    }
}
