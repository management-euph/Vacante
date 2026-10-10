<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\EurositeFeatureAssigner;

/**
 * Eurosite products get the shared travel features (Feature Mappings). These
 * pin what each feature type receives from a hotel row; FeatureMapper then
 * resolves the codes through the "eurosite" aliases.
 */
final class EurositeFeatureAssignerTest extends TestCase
{
    public function testFacilityKeysFoldCaseDiacriticsAndPunctuation(): void
    {
        self::assertSame('aer conditionat', EurositeFeatureAssigner::facilityKey(' Aer condiționat. '));
        self::assertSame('piscina exterioara', EurositeFeatureAssigner::facilityKey('Piscină  exterioară'));
        self::assertSame('wi-fi', EurositeFeatureAssigner::facilityKey('Wi-fi'));
        self::assertSame('', EurositeFeatureAssigner::facilityKey(' ; '));
    }

    public function testAHotelRowGivesEveryFeatureItsCodes(): void
    {
        $codes = (new EurositeFeatureAssigner())->codes([
            'category' => 4,
            'hotel_class' => 'Vila',
            'name' => 'VILA MARIA',
            'meals' => 'Demipensiune|Mic dejun|Demipensiune',
            'city_name' => 'Mamaia',
            'payload_json' => json_encode(['facilities' => ['Aer conditionat', 'Wi-fi', 'Parcare gratuita', 'aer condiționat']]),
        ]);

        self::assertSame(['4'], $codes['stars']);
        self::assertSame(['villa'], $codes['property_type']);
        self::assertSame(['HB', 'BB'], $codes['board']);
        self::assertSame(['Mamaia'], $codes['resort']);
        self::assertSame(['aer conditionat', 'wi-fi', 'parcare gratuita'], array_keys($codes['facilities']));
        self::assertSame('Wi-fi', $codes['facilities']['wi-fi'], 'the operator label is kept for the Unmapped page');
    }

    public function testWithoutAClassTheNameDecidesThePropertyType(): void
    {
        $assigner = new EurositeFeatureAssigner();

        self::assertSame(['guest_house'], $assigner->codes(['name' => 'Pensiunea Casa Albă'])['property_type']);
        self::assertSame(['hotel'], $assigner->codes(['name' => 'Parc'])['property_type']);
    }

    public function testAnEmptyRowAssignsNothing(): void
    {
        $codes = (new EurositeFeatureAssigner())->codes(['category' => 0]);

        self::assertSame([], $codes['stars']);
        self::assertSame([], $codes['board']);
        self::assertSame([], $codes['resort']);
        self::assertSame([], $codes['facilities']);
    }
}
