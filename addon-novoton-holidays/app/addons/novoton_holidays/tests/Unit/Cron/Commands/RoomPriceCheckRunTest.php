<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\NovotonHolidays\Cron\Commands\RoomPriceCheckCommand;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelper;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelperInterface;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * room_price: every hotel gets checked in turn, and the checks run in parallel.
 *
 * Two problems made it slow and incomplete:
 *  - it asked the API about one hotel at a time with a pause after each —
 *    ~1.5 s per hotel, 12+ minutes for the default 500;
 *  - it picked hotels ORDER BY hotel_name LIMIT 500, so every run re-checked
 *    the same first 500 of the alphabet and the rest (647 of 1,147 on the
 *    store) were never checked, never got has_room_price = 'Y', and never
 *    became products.
 */
final class RoomPriceCheckRunTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        DbStub::reset();
    }

    /** @return list<array{hotel_id: string, hotel_name: string, country: string}> */
    private static function hotels(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['hotel_id' => (string) $i, 'hotel_name' => 'Hotel ' . $i, 'country' => $i % 2 === 0 ? 'BULGARIA' : 'ALBANIA'];
        }

        return $out;
    }

    private static function xml(bool $withPrice): \SimpleXMLElement
    {
        return new \SimpleXMLElement($withPrice ? '<room_price><Room><Price>120</Price></Room></room_price>' : '<room_price></room_price>');
    }

    /**
     * @param list<array<string, mixed>> $hotels
     * @param array<string, \SimpleXMLElement|false> $answers hotel_id => response
     *
     * @return array{result: array<string, mixed>, batches: list<array{ids: list<string>, concurrency: int, nocache: bool}>, updates: list<array{0: list<string>, 1: list<string>}>, limit: int}
     */
    private function runCheck(array $hotels, array $answers, array $params = []): array
    {
        $seen = ['batches' => [], 'updates' => [], 'limit' => -1];

        $db = $this->createStub(DatabaseHelperInterface::class);
        $db->method('getHotelsForPriceCheck')->willReturnCallback(static function (array $conditions, int $limit) use ($hotels, &$seen): array {
            $seen['limit'] = $limit;

            return $limit > 0 ? array_slice($hotels, 0, $limit) : $hotels;
        });
        $db->method('batchUpdateHasRoomPriceFlag')->willReturnCallback(static function (array $with, array $without) use (&$seen): int {
            $seen['updates'][] = [$with, $without];

            return count($with) + count($without);
        });

        $pricing = $this->createStub(PricingApiClientInterface::class);
        $pricing->method('getRoomPriceBatch')->willReturnCallback(static function (array $requests, int $concurrency) use ($answers, &$seen): array {
            $seen['batches'][] = [
                'ids' => array_map('strval', array_keys($requests)),
                'concurrency' => $concurrency,
                'nocache' => array_reduce($requests, static fn (bool $all, array $r): bool => $all && ($r['nocache'] ?? false) === true, true),
            ];
            $out = [];
            foreach (array_keys($requests) as $id) {
                $out[(string) $id] = ['data' => $answers[(string) $id] ?? self::xml(false), 'rawXml' => ''];
            }

            return $out;
        });
        $pricing->method('getRoomPrice')->willThrowException(new \LogicException('the one-by-one call must not be used'));

        $api = $this->createStub(NovotonApiKitInterface::class);
        $api->method('pricing')->willReturn($pricing);

        $container = new Container();
        $container->override('databaseHelper', static fn (): DatabaseHelperInterface => $db);
        Container::setInstance($container);

        $cmd = new RoomPriceCheckCommand($api, null, $params + ['check_in' => '2026-10-23']);
        ob_start();
        try {
            $result = $cmd->execute();
        } finally {
            ob_end_clean();
        }

        return ['result' => $result] + $seen;
    }

    public function testHotelsAreCheckedInParallelBatchesAndAlwaysLive(): void
    {
        $run = $this->runCheck(self::hotels(60), []);

        self::assertCount(3, $run['batches'], '60 hotels → 3 batches of up to 25');
        self::assertSame(range(1, 25), array_map('intval', $run['batches'][0]['ids']));
        self::assertCount(10, $run['batches'][2]['ids']);
        foreach ($run['batches'] as $batch) {
            self::assertSame(RoomPriceCheckCommand::DEFAULT_CONCURRENCY, $batch['concurrency']);
            self::assertTrue($batch['nocache'], 'the cron must see the live API answer, never the cache');
        }
    }

    public function testEveryHotelsFlagIsSavedPerBatch(): void
    {
        $run = $this->runCheck(self::hotels(30), ['2' => self::xml(true), '27' => self::xml(true), '5' => false]);

        self::assertCount(2, $run['updates']);
        self::assertSame(['2'], $run['updates'][0][0]);
        self::assertCount(24, $run['updates'][0][1]);
        self::assertSame(['27'], $run['updates'][1][0]);

        $stats = $run['result']['stats'];
        self::assertSame(2, $stats['with_prices']);
        self::assertSame(28, $stats['without_prices']);
        self::assertSame(1, $stats['invalid'], 'a failed response counts as invalid, not as "no prices" alone');
        self::assertSame(['BULGARIA' => 1, 'ALBANIA' => 1], $stats['by_country']);
    }

    public function testConcurrencyAndLimitCanBeSetAndAreBounded(): void
    {
        self::assertSame(8, $this->runCheck(self::hotels(3), [], ['concurrency' => 8])['batches'][0]['concurrency']);
        self::assertSame(RoomPriceCheckCommand::MAX_CONCURRENCY, $this->runCheck(self::hotels(3), [], ['concurrency' => 999])['batches'][0]['concurrency']);
        self::assertSame(1, $this->runCheck(self::hotels(3), [], ['concurrency' => 0])['batches'][0]['concurrency']);

        self::assertSame(500, $this->runCheck(self::hotels(3), [])['limit']);
        self::assertSame(1500, $this->runCheck(self::hotels(3), [], ['limit' => 1500])['limit']);
    }

    public function testAFailedBatchMarksItsHotelsInvalidAndTheRunGoesOn(): void
    {
        $db = $this->createStub(DatabaseHelperInterface::class);
        $db->method('getHotelsForPriceCheck')->willReturn(self::hotels(30));
        $updates = [];
        $db->method('batchUpdateHasRoomPriceFlag')->willReturnCallback(static function (array $with, array $without) use (&$updates): int {
            $updates[] = [$with, $without];

            return 0;
        });

        $calls = 0;
        $pricing = $this->createStub(PricingApiClientInterface::class);
        $pricing->method('getRoomPriceBatch')->willReturnCallback(static function (array $requests) use (&$calls): array {
            if (++$calls === 1) {
                throw new \RuntimeException('circuit open');
            }

            return array_map(static fn (): array => ['data' => new \SimpleXMLElement('<r><Price>1</Price></r>'), 'rawXml' => ''], $requests);
        });
        $api = $this->createStub(NovotonApiKitInterface::class);
        $api->method('pricing')->willReturn($pricing);
        $container = new Container();
        $container->override('databaseHelper', static fn (): DatabaseHelperInterface => $db);
        Container::setInstance($container);

        ob_start();
        try {
            $result = (new RoomPriceCheckCommand($api, null, ['check_in' => '2026-10-23']))->execute();
        } finally {
            ob_end_clean();
        }

        self::assertSame(25, $result['stats']['invalid']);
        self::assertSame(5, $result['stats']['with_prices']);
        self::assertCount(2, $updates);
    }

    public function testResponsesReadAsPricesNoPricesOrInvalid(): void
    {
        self::assertTrue(RoomPriceCheckCommand::hasPrices(self::xml(true)));
        self::assertFalse(RoomPriceCheckCommand::hasPrices(self::xml(false)));
        self::assertNull(RoomPriceCheckCommand::hasPrices(false));
        self::assertNull(RoomPriceCheckCommand::hasPrices(null));
    }

    /** Never-checked hotels first, then the oldest checks: consecutive runs cover every hotel. */
    public function testHotelsComeLeastRecentlyCheckedFirst(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];

            return [];
        };

        (new DatabaseHelper())->getHotelsForPriceCheck(['country' => 'BULGARIA', 'not_a_column' => 'x'], 500);

        [$query, $params] = $captured;
        self::assertStringContainsString('ORDER BY (last_price_check IS NULL) DESC, last_price_check ASC, hotel_name', $query);
        self::assertStringContainsString('WHERE country = ?s', $query);
        self::assertStringNotContainsString('not_a_column', $query);
        self::assertStringContainsString('LIMIT ?i', $query);
        self::assertSame(['BULGARIA', 500], $params);
    }

    /** The batch call honours 'nocache' like the single call does. */
    public function testTheBatchCallCanSkipTheCache(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Api/PricingApiClient.php');
        $start = strpos($src, 'public function getRoomPriceBatch(');
        self::assertIsInt($start);
        $body = substr($src, $start, 2500);

        self::assertStringContainsString("\$cachedXml = empty(\$params['nocache']) ? \$this->getFromCache(Constants::API_FUNCTION_ROOM_PRICE, \$cacheKey) : null;", $body);
    }
}
