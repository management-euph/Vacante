<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\PricingApiClient;
use Tygh\Addons\NovotonHolidays\Helpers\BatchedPriceInfoSyncV2;
use Tygh\Addons\NovotonHolidays\Helpers\HotelPackageExtractor;
use Tygh\Addons\NovotonHolidays\Helpers\PriceInfoRefusals;
use Tygh\Addons\NovotonHolidays\Helpers\StateManagerInterface;
use Tygh\Addons\NovotonHolidays\Helpers\SyncLoggerInterface;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

/**
 * The price sync of 2026-10-19 logged, for hotel 476:
 *
 *     [476/1295781] ADMIRAL ***** +BEACH [SPO 1 - 2 NIGHTS] ...
 *     ERROR: Novoton refused priceinfo: Invalid parameter!
 *
 * Novoton refuses that package every time. Nothing remembered it, so the
 * package stayed "stale": every cron tick started an incremental run that
 * asked again, retried at the end, and logged the same error. A package Novoton
 * no longer lists for its hotel was asked the same way, every cycle, because
 * package rows are never deleted.
 */
#[CoversClass(BatchedPriceInfoSyncV2::class)]
#[CoversClass(PriceInfoRefusals::class)]
#[CoversClass(HotelPackageExtractor::class)]
final class BatchedPriceInfoSkipsTest extends TestCase
{
    private const string SPO = '476/1295781';

    /** @var list<string> what the sync printed */
    private array $output = [];

    /** @var list<string> every value written to the refusal store */
    private array $stored = [];

    protected function setUp(): void
    {
        DestinationScope::setCurrent(DestinationScope::fromRows([]));
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        DestinationScope::setCurrent(null);
    }

    private function sync(string $storedRefusals = ''): BatchedPriceInfoSyncV2
    {
        $this->output = [];
        $this->stored = [];
        $logger = $this->createStub(SyncLoggerInterface::class);
        $logger->method('output')->willReturnCallback(function (string $line): void {
            $this->output[] = $line;
        });
        $refusals = new PriceInfoRefusals(
            static fn (): string => $storedRefusals,
            function (string $json): void {
                $this->stored[] = $json;
            },
        );

        return new BatchedPriceInfoSyncV2($this->createStub(StateManagerInterface::class), $logger, $refusals);
    }

    /**
     * @return list<string>
     */
    private function itemsOf(BatchedPriceInfoSyncV2 $sync, string $syncType): array
    {
        $items = (new \ReflectionMethod($sync, 'getItemsToSync'))->invoke($sync, $syncType, []);
        self::assertIsArray($items);

        return array_values(array_map('strval', $items));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function packageRows(array $rows): void
    {
        DbStub::$getArray = static fn (string $query): array => str_contains($query, 'novoton_hotel_packages') ? $rows : [];
    }

    /**
     * Runs processItem() against a priceinfo reply; returns how often Novoton was asked.
     *
     * @return array{0: array<array-key, mixed>, 1: int}
     */
    private function process(BatchedPriceInfoSyncV2 $sync, string $item, string $reply, int &$calls): array
    {
        DbStub::$getField = static fn (): string => 'ADMIRAL ***** +BEACH [SPO 1 - 2 NIGHTS]';
        DbStub::$query = static fn (): int => 1;
        $http = $this->createStub(NovotonHttpClient::class);
        $http->method('sendRequest')->willReturnCallback(static function () use ($reply, &$calls): string {
            $calls++;

            return $reply;
        });
        $api = $this->createStub(NovotonApi::class);
        $api->method('pricing')->willReturn(new PricingApiClient($http, new NovotonXmlParser(), null, false, new CommissionCalculator(0.0)));
        (new \ReflectionProperty($sync, 'api'))->setValue($sync, $api);

        $result = (new \ReflectionMethod($sync, 'processItem'))->invoke($sync, $item);
        self::assertIsArray($result);

        return [$result, $calls];
    }

    public function testAPackageNovotonDroppedIsNotAsked(): void
    {
        // hotel 476's latest hotelinfo (2026-10-17) lists 1280335 and 1297221.
        $listed = '[{"IdCont":"1280335","PackageName":"ADMIRAL ***** +BEACH"},{"IdCont":"1297221","PackageName":"ADMIRAL ***** +BEACH 2027"}]';
        self::packageRows([
            ['hotel_id' => '476', 'package_id' => '1280335', 'created_at' => '2026-03-01 10:00:00', 'hotelinfo_synced_at' => '2026-10-17 03:00:00', 'listed_packages' => $listed],
            ['hotel_id' => '476', 'package_id' => '1295781', 'created_at' => '2026-09-15 10:00:00', 'hotelinfo_synced_at' => '2026-10-17 03:00:00', 'listed_packages' => $listed],
            ['hotel_id' => '476', 'package_id' => '1299999', 'created_at' => '2026-10-18 09:00:00', 'hotelinfo_synced_at' => '2026-10-17 03:00:00', 'listed_packages' => $listed],
            ['hotel_id' => '900', 'package_id' => '1', 'created_at' => '2026-01-01 00:00:00', 'hotelinfo_synced_at' => null, 'listed_packages' => null],
        ]);

        $sync = $this->sync();

        self::assertSame(['476/1280335', '476/1299999', '900/1'], $this->itemsOf($sync, 'full'), '1295781 was known before that hotelinfo and is not in it; 1299999 came after it; hotel 900 has no list');
        self::assertContains('Skipped 1 packages Novoton no longer lists for their hotel (kept for their past seasons).', $this->output);
    }

    public function testARefusedPackageIsRememberedAndTheRetryDoesNotAskAgain(): void
    {
        $sync = $this->sync();
        $calls = 0;

        [$first] = $this->process($sync, self::SPO, '<error><err>Invalid parameter!</err></error>', $calls);
        [$retry, $callsAfterRetry] = $this->process($sync, self::SPO, '<error><err>Invalid parameter!</err></error>', $calls);

        self::assertFalse($first['success']);
        self::assertSame('refused: Invalid parameter!', $first['message']);
        self::assertSame(1, $callsAfterRetry, 'the end-of-run retry would get the same refusal');
        self::assertFalse($retry['success']);
        self::assertCount(1, $this->stored, 'recorded once');
        $record = json_decode($this->stored[0], true);
        self::assertIsArray($record);
        self::assertSame('Invalid parameter!', $record[self::SPO]['reason'] ?? null);
        self::assertContains('REFUSED: Novoton gives no price list for this package (Invalid parameter!). Its stored prices stay; the next full sync asks again.', $this->output);
    }

    public function testAnIncrementalRunLeavesARecentlyRefusedPackageAloneAndAFullRunAsksAgain(): void
    {
        $stored = (string) json_encode([self::SPO => ['at' => time() - 86400, 'reason' => 'Invalid parameter!']]);
        self::packageRows([['hotel_id' => '476', 'package_id' => '1295781']]);
        DbStub::$getField = static fn (string $query): ?string => str_contains($query, 'novoton_sync_log') ? date('Y-m-d H:i:s', time() - 2 * 86400) : null;

        $sync = $this->sync($stored);
        $type = (new \ReflectionMethod($sync, 'determineSyncType'))->invoke($sync, []);

        self::assertSame('none', $type, 'its only stale package was refused yesterday: no run every cron tick');
        self::assertContains('Skipped 1 packages Novoton refused in the last 7 days; the next full sync asks again.', $this->output);
        self::assertSame([self::SPO], $this->itemsOf($this->sync($stored), 'full'));
    }

    public function testARefusalOfTheAccountIsNotRememberedPerPackage(): void
    {
        $sync = $this->sync();
        $calls = 0;

        [$result] = $this->process($sync, self::SPO, '<error><err>Invalid username or password!</err></error>', $calls);

        self::assertSame([], $this->stored, 'once the password is fixed, every package is asked again at once');
        self::assertIsString($result['message']);
        self::assertStringContainsString('Novoton refused priceinfo: Invalid username or password!', $result['message']);
    }

    public function testAnAnswerClearsAnEarlierRefusal(): void
    {
        $sync = $this->sync((string) json_encode([self::SPO => ['at' => time() - 86400, 'reason' => 'Invalid parameter!']]));
        $calls = 0;

        [$result] = $this->process($sync, self::SPO, '<priceinfo><seasons><Season>1</Season><FromDate>2027-05-01</FromDate><ToDate>2027-09-30</ToDate></seasons></priceinfo>', $calls);

        self::assertTrue($result['success']);
        self::assertSame([''], $this->stored, 'the record is gone (an empty value deletes the storage row)');
    }

    public function testOnlyARefusalAboutThePackageCounts(): void
    {
        self::assertTrue(PriceInfoRefusals::isAboutThePackage('Invalid parameter!'));
        self::assertTrue(PriceInfoRefusals::isAboutThePackage(' invalid parameter '));
        self::assertFalse(PriceInfoRefusals::isAboutThePackage('Invalid username or password!'));
        self::assertFalse(PriceInfoRefusals::isAboutThePackage('no reason given'));
    }

    public function testOldRefusalsAreDroppedWhenTheListIsWritten(): void
    {
        $written = [];
        $now = 1_800_000_000;
        $refusals = new PriceInfoRefusals(
            static fn (): string => (string) json_encode(['476/1' => ['at' => $now - 40 * 86400, 'reason' => 'Invalid parameter!']]),
            static function (string $json) use (&$written): void {
                $written[] = $json;
            },
        );

        $refusals->record('476/2', 'Invalid parameter!', $now);

        self::assertSame(['476/2'], array_keys((array) json_decode($written[0], true)));
        self::assertSame(['476/2' => 'Invalid parameter!'], $refusals->refusedWithin(7 * 86400, $now));
    }

    public function testTheListedIdsOfAStoredHotelinfo(): void
    {
        self::assertSame(['1280335'], HotelPackageExtractor::listedIdsFromJson('{"IdCont":"1280335","PackageName":"ADMIRAL"}'), 'one <packages> is an object');
        self::assertSame(['1', '2', '3'], HotelPackageExtractor::listedIdsFromJson('[{"IdCont":"1"},{"IdCont":"2","Package":{"IdCont":"3"}}]'), 'nested <Package> too');
        self::assertNull(HotelPackageExtractor::listedIdsFromJson(null), 'no hotelinfo stored');
        self::assertNull(HotelPackageExtractor::listedIdsFromJson(''));
        self::assertNull(HotelPackageExtractor::listedIdsFromJson('null'));
        self::assertNull(HotelPackageExtractor::listedIdsFromJson('{"err":"Invalid username or password!"}'), 'a stored refusal lists nothing we can trust');
    }
}
