<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\FeatureMapRepositoryInterface;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * Travel groups are derived from facilities (TravelGroupResolver returns
 * travel_core's own codes), so no provider seeds aliases for them: without
 * an alias they must resolve by canonical code, or every provider's
 * family_friendly / pets_friendly lands in the unmapped list.
 */
final class FeatureMapperCodeResolveTest extends TestCase
{
    private const array ROW = ['map_id' => 7, 'feature_type' => 'travel_group', 'canonical_code' => 'family_friendly'];

    protected function tearDown(): void
    {
        FeatureMapper::setRepository(null);
        FeatureMapper::clearCache();
    }

    public function testATravelGroupWithoutAliasResolvesByCode(): void
    {
        $repo = $this->createMock(FeatureMapRepositoryInterface::class);
        $repo->method('findByAlias')->willReturn(null);
        $repo->expects(self::once())->method('findByCode')->with('travel_group', 'family_friendly')->willReturn(self::ROW);
        FeatureMapper::setRepository($repo);

        self::assertSame(7, FeatureMapper::resolve('novoton', 'travel_group', 'family_friendly')['map_id'] ?? null);
    }

    public function testOtherTypesStillNeedAnAlias(): void
    {
        $repo = $this->createMock(FeatureMapRepositoryInterface::class);
        $repo->method('findByAlias')->willReturn(null);
        $repo->expects(self::never())->method('findByCode');
        FeatureMapper::setRepository($repo);

        self::assertNull(FeatureMapper::resolve('novoton', 'stars', '3'));
    }
}
