<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\CircuitFeatureAssigner;
use Tygh\Addons\TravelCore\Services\CircuitFeatureConfig;

/**
 * Feature Mappings › Circuits: the saved mapping's shape, and how a
 * circuit's months and nights find their feature values.
 */
final class CircuitFeatureMappingTest extends TestCase
{
    public function testAnEmptyMappingHasEveryKeyAndMapsNothing(): void
    {
        $config = CircuitFeatureConfig::normalize([]);

        self::assertSame(0, $config['group_id']);
        self::assertSame(CircuitFeatureConfig::FIELDS, array_keys($config['features']));
        self::assertSame(['flight' => 0, 'bus' => 0], $config['values']['transport']);
        self::assertSame(range(1, 12), array_keys($config['values']['month']));
        self::assertTrue(CircuitFeatureConfig::isEmpty($config));
    }

    public function testThePostedFormIsBroughtToTheStoredShape(): void
    {
        $config = CircuitFeatureConfig::normalize([
            'group_id' => '12',
            'features' => ['transport' => '31', 'month' => '34', 'unknown' => '99', 'duration' => '-4'],
            'values' => [
                'transport' => ['flight' => '501', 'ship' => '777'],
                'month' => ['5' => '605', '13' => '613', '6' => 'x'],
            ],
        ]);

        self::assertSame(12, $config['group_id']);
        self::assertSame(31, $config['features']['transport']);
        self::assertSame(34, $config['features']['month']);
        self::assertSame(0, $config['features']['duration'], 'a negative id is not a feature');
        self::assertArrayNotHasKey('unknown', $config['features']);
        self::assertSame(['flight' => 501, 'bus' => 0], $config['values']['transport'], 'only the known transports');
        self::assertSame(605, $config['values']['month'][5]);
        self::assertSame(0, $config['values']['month'][6]);
        self::assertArrayNotHasKey(13, $config['values']['month']);
        self::assertFalse(CircuitFeatureConfig::isEmpty($config));
    }

    public function testOnlyTheLinkedMonthsBecomeValuesInCalendarOrder(): void
    {
        $links = array_fill(1, 12, 0);
        $links[5] = 605;
        $links[6] = 606;
        $links[9] = 605; // two months linked to one value: it counts once

        self::assertSame([605, 606], CircuitFeatureAssigner::monthVariants([9, 6, 5, 5, 10, 0, 13], $links), 'October is not linked, 0 and 13 are not months');
        self::assertSame([], CircuitFeatureAssigner::monthVariants([], $links));
    }

    public function testTheNightsFindTheValueThatStartsWithTheirNumber(): void
    {
        $variants = ['17 nights' => 3, '7 nights' => 1, '7 nopți' => 2, 'One week' => 4];

        self::assertSame(1, CircuitFeatureAssigner::nightsVariant(7, $variants));
        self::assertSame(3, CircuitFeatureAssigner::nightsVariant(17, $variants), '17 is not 7 or 1');
        self::assertSame(0, CircuitFeatureAssigner::nightsVariant(1, $variants));
        self::assertSame(5, CircuitFeatureAssigner::nightsVariant(10, ['10' => 5]), 'a Number list holds bare numbers');
    }

    public function testTheLabelOfANewNightsValue(): void
    {
        self::assertSame('7 nights', CircuitFeatureAssigner::nightsLabel(7, 'en'));
        self::assertSame('1 night', CircuitFeatureAssigner::nightsLabel(1, 'en'));
        self::assertSame('7 nopți', CircuitFeatureAssigner::nightsLabel(7, 'ro'));
        self::assertSame('1 noapte', CircuitFeatureAssigner::nightsLabel(1, 'ro'));
    }

    public function testIncludedAndNotIncludedAreCircuitDetailsToMap(): void
    {
        self::assertContains('included', CircuitFeatureConfig::FIELDS);
        self::assertContains('not_included', CircuitFeatureConfig::FIELDS);

        $config = CircuitFeatureConfig::normalize(['features' => ['included' => '41', 'not_included' => '42']]);

        self::assertSame(41, $config['features']['included']);
        self::assertSame(42, $config['features']['not_included']);
    }

    public function testAValueNameFitsTheVariantNameColumn(): void
    {
        self::assertSame('Mic dejun si cina', CircuitFeatureAssigner::fit("  Mic dejun\n si   cina \t"), 'one line, trimmed');
        self::assertSame('', CircuitFeatureAssigner::fit('   '));

        $long = str_repeat('ă', 300);
        $fit = CircuitFeatureAssigner::fit($long);

        self::assertSame(255, mb_strlen($fit), 'cut to 255 characters, not bytes');
        self::assertStringEndsWith('…', $fit);
        self::assertNotSame($long, $fit);
        self::assertSame('x', CircuitFeatureAssigner::fit('x'), 'a short name is left as it is');
    }
}
