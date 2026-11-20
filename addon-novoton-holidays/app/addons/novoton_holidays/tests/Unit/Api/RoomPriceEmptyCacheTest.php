<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\PricingApiClient;
use Tygh\Addons\NovotonHolidays\Api\RequestGate;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Services\CacheServiceInterface;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

/**
 * Only answers with prices were cached, so every repeated search of dates
 * without availability asked Novoton again: up to 20 calls each. "No
 * availability" is now cached for the same few minutes; a refusal (a wrong
 * password) never is.
 */
final class RoomPriceEmptyCacheTest extends TestCase
{
    private function client(CacheServiceInterface $cache, string $answer): PricingApiClient
    {
        $http = new NovotonHttpClient(['api_url' => 'http://127.0.0.1:9', 'api_key' => 'k'], null, new RequestGate('/nonexistent-dir/novoton_gate.json'));

        return new class ($http, new NovotonXmlParser(), $cache, true, new CommissionCalculator(0.0), $answer) extends PricingApiClient {
            public function __construct(NovotonHttpClient $http, NovotonXmlParser $parser, CacheServiceInterface $cache, bool $enable, CommissionCalculator $commission, private readonly string $answer)
            {
                parent::__construct($http, $parser, $cache, $enable, $commission);
            }

            #[\Override]
            protected function callApi(string $function, string $xml, string $lang = 'UK'): string
            {
                return $this->answer;
            }
        };
    }

    private const array ASK = ['hotel_id' => '476', 'check_in' => '2027-07-10', 'check_out' => '2027-07-17', 'adults' => 2];

    public function testAnAnswerWithoutAvailabilityIsCached(): void
    {
        $cache = $this->createMock(CacheServiceInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::once())->method('set');

        $this->client($cache, '<room_price_RS></room_price_RS>')->getRoomPrice(self::ASK);
    }

    public function testARefusalIsNeverCached(): void
    {
        $cache = $this->createMock(CacheServiceInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::never())->method('set');

        $this->client($cache, '<error><err>Invalid username or password!</err></error>')->getRoomPrice(self::ASK);
    }
}
