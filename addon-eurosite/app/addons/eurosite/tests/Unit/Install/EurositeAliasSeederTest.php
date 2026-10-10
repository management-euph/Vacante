<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Install\EurositeAliasSeeder;
use Tygh\Addons\Eurosite\Services\EurositeFeatureAssigner;
use Tygh\Addons\Eurosite\Services\FacilityTextParser;

/**
 * The seed data behind Eurosite's Feature Mappings aliases.
 */
final class EurositeAliasSeederTest extends TestCase
{
    /** @return array<string, true> "type:code" seeded by travel_core's fn_travel_core_seed_feature_map() */
    private static function travelCoreCodes(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 7) . '/addon-travel-core/app/addons/travel_core/func.php');
        preg_match_all("/\\['(\\w+)',\\s*'([^']+)'/", $src, $m, PREG_SET_ORDER);
        $codes = [];
        foreach ($m as $row) {
            $codes[$row[1] . ':' . $row[2]] = true;
        }

        return $codes;
    }

    public function testFacilityKeysAreInTheFormTheAssignerLooksUp(): void
    {
        foreach (array_keys(EurositeAliasSeeder::FACILITIES) as $key) {
            self::assertSame($key, EurositeFeatureAssigner::facilityKey($key), "{$key}: never matches a label");
        }
    }

    public function testEverySeededCodeExistsInTravelCore(): void
    {
        $codes = self::travelCoreCodes();
        self::assertArrayHasKey('stars:3', $codes, 'the travel_core seed list was not parsed');

        foreach (EurositeAliasSeeder::IDENTITY as $type => $list) {
            foreach ($list as $code) {
                self::assertArrayHasKey("{$type}:{$code}", $codes);
            }
        }
        foreach (EurositeAliasSeeder::FACILITIES as $label => $code) {
            $known = isset($codes["hotel_facility:{$code}"]) || isset($codes["room_facility:{$code}"]) || isset($codes["beach_access:{$code}"]);
            self::assertTrue($known, "{$label} => {$code}: no such facility mapping");
        }
    }

    public function testTheLiveFacilityListIsFullyAliased(): void
    {
        // Live SCANDINAVIA CM (RO0451): "aer conditionat, cablu tv, restaurant,
        // bar, Wi-fi, grup sanitar propriu, terasa, parcare gratuita".
        $raw = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/product_info_RO0451_description_det.txt');
        $labels = FacilityTextParser::facilities($raw);
        self::assertNotSame([], $labels);

        foreach ($labels as $label) {
            self::assertArrayHasKey(EurositeFeatureAssigner::facilityKey($label), EurositeAliasSeeder::FACILITIES, $label);
        }
    }

    public function testInitSelfHealsTheAliasesAndProductsGetFeatures(): void
    {
        $root = dirname(__DIR__, 3);
        $init = (string) file_get_contents($root . '/init.php');
        self::assertStringContainsString("fn_travel_core_self_heal_due('eurosite_aliases'", $init);
        self::assertStringContainsString('EurositeAliasSeeder::seed(', $init);

        $container = (string) file_get_contents($root . '/src/Services/Container.php');
        self::assertStringContainsString('new EurositeProductFactory(self::hotels(), new EurositeFeatureAssigner())', $container);
    }
}
