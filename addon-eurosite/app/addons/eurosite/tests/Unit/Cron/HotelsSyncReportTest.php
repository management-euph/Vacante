<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Cron\Commands\HotelsSyncCommand;

/**
 * The `hotels` run report names each destination, not just its code:
 * "RO0218: 1 hotels" told an operator nothing; "Techirghiol RO0218" does.
 */
final class HotelsSyncReportTest extends TestCase
{
    public function testTheLineCarriesTheDestinationNameAndCode(): void
    {
        self::assertSame('  Techirghiol RO0218: 1 hotels', HotelsSyncCommand::cityLine('RO0218', 'Techirghiol', 1));
    }

    public function testACityWithoutASyncedNamePrintsItsCodeAlone(): void
    {
        self::assertSame('  RO2M: 3 hotels', HotelsSyncCommand::cityLine('RO2M', '', 3));
        self::assertSame('  RO2M: 3 hotels', HotelsSyncCommand::cityLine('RO2M', '   ', 3));
        // Some rows carry the code as their name; printing it twice is noise.
        self::assertSame('  RO2M: 3 hotels', HotelsSyncCommand::cityLine('RO2M', 'ro2m', 3));
    }

    /** The loop must hand the city row's name to the line, not just the code. */
    public function testTheSyncLoopUsesTheCityRowsName(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Cron/Commands/HotelsSyncCommand.php');

        self::assertStringContainsString("\$cityName = TypeCoerce::toString(\$cityRow['name'] ?? '');", $src);
        self::assertStringContainsString('self::cityLine($cityCode, $cityName, count($hotels))', $src);
    }
}
