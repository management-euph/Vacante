<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\AliasCoverage;

/**
 * The Feature Mappings dashboard warns when an alias-using provider has no
 * aliases for a strict feature type (Novoton lost its star aliases to the
 * old alias key and its hotels silently got no star rating).
 */
final class AliasCoverageTest extends TestCase
{
    public function testReportsAProviderWithNoStarAliases(): void
    {
        $gaps = AliasCoverage::gaps([
            'novoton' => ['board' => 10, 'room_type' => 20, 'property_type' => 5, 'hotel_facility' => 80],
            'sphinx' => ['board' => 9, 'room_type' => 4, 'stars' => 15, 'property_type' => 3],
        ]);

        self::assertSame([['provider' => 'novoton', 'feature_type' => 'stars']], $gaps);
    }

    public function testSkipsProvidersWithoutAnyAlias(): void
    {
        self::assertSame([], AliasCoverage::gaps(['eurosite' => [], '' => ['board' => 1]]));
    }

    public function testGapsAreOrderedByProvider(): void
    {
        $gaps = AliasCoverage::gaps(
            ['sphinx' => ['board' => 1], 'novoton' => ['board' => 1]],
            ['board', 'stars'],
        );

        self::assertSame(['novoton', 'sphinx'], array_column($gaps, 'provider'));
    }
}
