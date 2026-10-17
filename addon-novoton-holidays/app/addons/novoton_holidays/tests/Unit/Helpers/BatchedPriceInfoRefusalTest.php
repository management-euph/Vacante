<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\PricingApiClient;
use Tygh\Addons\NovotonHolidays\Helpers\BatchedPriceInfoSyncV2;
use Tygh\Addons\NovotonHolidays\Helpers\StateManagerInterface;
use Tygh\Addons\NovotonHolidays\Helpers\SyncLoggerInterface;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

/**
 * One night with a wrong API password: every package the price sync reached
 * had its priceinfo_data replaced by {"err":"Invalid username or password!"}
 * and was marked synced, so its seasons, "from" price and calendar were gone
 * until the next full cycle. A refused package now fails and keeps its data.
 */
#[CoversClass(BatchedPriceInfoSyncV2::class)]
final class BatchedPriceInfoRefusalTest extends TestCase
{
    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @return array{result: mixed, writes: list<string>}
     */
    private function syncOnePackage(string $reply): array
    {
        $writes = [];
        DbStub::$getField = static fn (): string => 'ADMIRAL ***** +BEACH';
        DbStub::$query = static function (string $query) use (&$writes): int {
            $writes[] = $query;

            return 1;
        };

        $http = $this->createStub(NovotonHttpClient::class);
        $http->method('sendRequest')->willReturn($reply);
        $api = $this->createStub(NovotonApi::class);
        $api->method('pricing')->willReturn(new PricingApiClient($http, new NovotonXmlParser(), null, false, new CommissionCalculator(0.0)));

        $sync = new BatchedPriceInfoSyncV2($this->createStub(StateManagerInterface::class), $this->createStub(SyncLoggerInterface::class));
        (new \ReflectionProperty($sync, 'api'))->setValue($sync, $api);
        $result = (new \ReflectionMethod($sync, 'processItem'))->invoke($sync, '476/1280335');

        return ['result' => $result, 'writes' => $writes];
    }

    public function testARefusedPackageFailsAndKeepsItsPrices(): void
    {
        $run = $this->syncOnePackage('<error><err>Invalid username or password!</err></error>');

        self::assertSame([], $run['writes'], 'priceinfo_data, needs_price_compute and synced_at stay as they were');
        self::assertIsArray($run['result']);
        self::assertFalse($run['result']['success']);
        self::assertIsString($run['result']['message']);
        self::assertStringContainsString('Invalid username or password!', $run['result']['message'], 'the sync log says why');
    }

    public function testAnAnsweredPackageIsStoredAsBefore(): void
    {
        $run = $this->syncOnePackage('<priceinfo><seasons><Season>1</Season><FromDate>2027-05-01</FromDate><ToDate>2027-09-30</ToDate></seasons></priceinfo>');

        self::assertCount(1, $run['writes']);
        self::assertStringContainsString('UPDATE ?:novoton_hotel_packages', $run['writes'][0]);
        self::assertIsArray($run['result']);
        self::assertTrue($run['result']['success']);
    }
}
