<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Install\AliasSeeder;

/**
 * Novoton's star aliases ('1'–'5') collided with its facility ids under the
 * old (api_source, api_value) alias key and were never written, so Novoton
 * hotels got no star rating. The seeder writes them and init.php re-runs it
 * as an admin self-heal whenever the seed data changes.
 */
final class AliasSeederTest extends TestCase
{
    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testSeedsAStarsGroup(): void
    {
        $src = (string) file_get_contents(self::addonRoot() . '/src/Install/AliasSeeder.php');

        self::assertMatchesRegularExpression("/seedGroup\\('stars',/", $src);
    }

    public function testFingerprintIsStable(): void
    {
        self::assertSame(AliasSeeder::fingerprint(), AliasSeeder::fingerprint());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', AliasSeeder::fingerprint());
    }

    public function testInitSelfHealsTheAliases(): void
    {
        $init = (string) file_get_contents(self::addonRoot() . '/init.php');

        self::assertStringContainsString("fn_travel_core_self_heal_due('novoton_aliases'", $init);
        self::assertStringContainsString('AliasSeeder::seed(', $init);
        self::assertStringContainsString("AREA === 'A'", $init);
    }

    public function testInstallDelegatesToTheSeeder(): void
    {
        $install = (string) file_get_contents(self::addonRoot() . '/functions/install.php');

        self::assertStringContainsString('AliasSeeder::seed(', $install);
    }
}
