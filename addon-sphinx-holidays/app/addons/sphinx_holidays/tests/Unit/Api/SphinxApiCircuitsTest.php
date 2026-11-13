<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * getCircuits() must forward the optional updated_since query parameter so the
 * nightly circuits cron can pull incrementally (only circuits changed since the
 * last successful run) — matching getHotels()/getDestinations().
 */
final class SphinxApiCircuitsTest extends TestCase
{
    public function testGetCircuitsOmitsUpdatedSinceByDefault(): void
    {
        $client = $this->createMock(SphinxHttpClient::class);
        $client->expects(self::once())
            ->method('get')
            ->with('/api/v1/static/circuits', ['page' => 2, 'per_page' => 500])
            ->willReturn(['data' => []]);

        $api = new SphinxApi($client);
        $api->getCircuits(2, 500);
    }

    public function testGetCircuitsForwardsUpdatedSince(): void
    {
        $client = $this->createMock(SphinxHttpClient::class);
        $client->expects(self::once())
            ->method('get')
            ->with('/api/v1/static/circuits', [
                'page' => 1,
                'per_page' => 1000,
                'updated_since' => '2026-07-01 08:30:00',
            ])
            ->willReturn(['data' => []]);

        $api = new SphinxApi($client);
        $api->getCircuits(1, 1000, '2026-07-01 08:30:00');
    }

    public function testGetCircuitAsksForOneCircuitById(): void
    {
        $client = $this->createMock(SphinxHttpClient::class);
        $client->expects(self::once())
            ->method('get')
            ->with('/api/v1/static/circuits/1674')
            ->willReturn(['data' => ['id' => 1674]]);

        $api = new SphinxApi($client);

        self::assertSame(['data' => ['id' => 1674]], $api->getCircuit(1674));
    }
}
