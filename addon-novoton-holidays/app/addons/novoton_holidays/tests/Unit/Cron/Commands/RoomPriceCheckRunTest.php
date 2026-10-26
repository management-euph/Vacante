<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\DestinationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\NovotonHolidays\Cron\Commands\RoomPriceCheckCommand;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelper;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelperInterface;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\NovotonHolidays\Services\NovotonAvailabilityRecorder;
use Tygh\Addons\NovotonHolidays\Services\ResortCheckRunner;
use Tygh\Addons\NovotonHolidays\Services\ResortCheckSettings;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\ValueObjects\RequestDebugInfo;

/**
 * room_price, reworked into the weekly resort check
 * (docs/novoton-availability-gaps-plan.md §4.3).
 *
 * It asked every sold hotel on its own about one date 30 days ahead: up to
 * 500 calls a night, and out of season a seaside hotel read "no prices" for
 * months. Now each run asks a share of the sold resorts, one resort-level
 * call per date, and only answers count: a failed call changes neither
 * has_room_price nor the availability records.
 */
final class RoomPriceCheckRunTest extends TestCase
{
    private const string NOW = '2026-10-26 04:00:00';

    private const string EMPTY_DOCUMENT = "<?xml version=\"1.0\" encoding=\"windows-1251\"?>\n";

    /** @var list<string> availability records written, placeholders filled */
    private array $sql = [];

    /** @var list<array{0: list<string>, 1: list<string>}> has_room_price updates: [Y, N] */
    private array $flags = [];

    /** @var list<string> "RESORT check_in" per resort call */
    private array $calls = [];

    /** @var list<string> */
    private array $log = [];

    private string $lastRaw = '';

    private float $clock = 0.0;

    /** How long each resort call takes, for the time budget. */
    private float $secondsPerCall = 0.0;

    protected function setUp(): void
    {
        DbStub::reset();
        DbStub::$query = function (string $query, mixed ...$params): int {
            if (preg_match('/^\s*(CREATE|ALTER) TABLE/', $query) !== 1) {
                $this->sql[] = db_quote($query, ...$params);
            }

            return 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        Container::setInstance(null);
        ConfigProvider::reset();
        DestinationScope::setCurrent(null);
    }

    /** @return array<string, mixed> */
    private static function hotel(string $id, string $city, ?string $checked = '2026-10-19 04:00:00', string $country = 'BULGARIA'): array
    {
        return ['hotel_id' => $id, 'hotel_name' => 'Hotel ' . $id, 'city' => $city, 'country' => $country, 'product_id' => null, 'created_at' => '2024-01-01 00:00:00', 'last_price_check' => $checked];
    }

    /** @return list<array<string, mixed>> Sunny Beach 1, 2, 3 (checked first), Albena 4 */
    private static function bulgaria(): array
    {
        return [
            self::hotel('1', 'Sunny Beach'),
            self::hotel('2', 'Sunny Beach'),
            self::hotel('3', 'Sunny Beach'),
            self::hotel('4', 'Albena', '2026-10-20 04:00:00'),
        ];
    }

    /** A resort answer: one priced row per hotel, as Novoton sends several <room_price>, wrapped. */
    private static function offers(string ...$hotelIds): \SimpleXMLElement
    {
        $rows = '';
        foreach ($hotelIds as $id) {
            $rows .= "<room_price><IdHotel>{$id}</IdHotel><IdRoom>DBL 2+0</IdRoom><Board>BB</Board><Price>420.00</Price></room_price>";
        }

        return new \SimpleXMLElement('<room_prices>' . $rows . '</room_prices>');
    }

    /** Two dates: 2026-11-09 (14 days) and 2027-07-15. */
    private static function settings(int $cycleDays = 1, int $maxCalls = 100, int $units = 0): ResortCheckSettings
    {
        return new ResortCheckSettings([14], '07-15', 7, $cycleDays, $maxCalls, 1200, $units);
    }

    /**
     * @param list<array<string, mixed>> $hotels
     * @param array<string, list<string>> $resortLists country => resorts; a country missing here fails
     * @param \Closure(string, string): array{0: \SimpleXMLElement|false, 1?: string} $answer resort, check_in => [reply, raw body]
     * @param list<array<string, mixed>> $stored rows getHotelsForSync() finds
     */
    private function runner(array $hotels, array $resortLists, \Closure $answer, ?\Closure $batch = null, array $stored = []): ResortCheckRunner
    {
        $db = $this->createStub(DatabaseHelperInterface::class);
        $db->method('getHotelsForPriceCheck')->willReturn($hotels);
        $db->method('batchUpdateHasRoomPriceFlag')->willReturnCallback(function (array $with, array $without): int {
            $this->flags[] = [$with, $without];

            return count($with) + count($without);
        });
        $db->method('getHotelsForSync')->willReturnCallback(static fn (array $conditions): array => array_values(array_filter(
            $stored,
            static fn (array $row): bool => in_array($row['hotel_id'], (array) ($conditions['hotel_id'] ?? []), true),
        )));

        $destinations = $this->createStub(DestinationApiClientInterface::class);
        $destinations->method('getResortList')->willReturnCallback(static function (string $country) use ($resortLists): \SimpleXMLElement {
            if (!isset($resortLists[$country])) {
                throw new \RuntimeException('Operation timed out');
            }

            return new \SimpleXMLElement('<resort_list>' . implode('', array_map(static fn (string $r): string => "<Resort>{$r}</Resort>", $resortLists[$country])) . '</resort_list>');
        });

        $pricing = $this->createStub(PricingApiClientInterface::class);
        $pricing->method('getRoomPriceByResort')->willReturnCallback(function (array $params) use ($answer): \SimpleXMLElement|false {
            $this->calls[] = $params['resort'] . ' ' . $params['check_in'];
            $this->clock += $this->secondsPerCall;
            $reply = $answer((string) $params['resort'], (string) $params['check_in']);
            $this->lastRaw = $reply[1] ?? '';

            return $reply[0];
        });
        $pricing->method('getRoomPriceBatch')->willReturnCallback($batch ?? static function (): array {
            throw new \LogicException('no hotel is asked on its own here');
        });

        $api = $this->createStub(NovotonApiKitInterface::class);
        $api->method('pricing')->willReturn($pricing);
        $api->method('destinations')->willReturn($destinations);
        $api->method('debugInfo')->willReturnCallback(fn (): RequestDebugInfo => new RequestDebugInfo(lastResponseRaw: $this->lastRaw));

        return new ResortCheckRunner(
            $api,
            $db,
            new NovotonAvailabilityRecorder(),
            function (string $line): void {
                $this->log[] = $line;
            },
            fn (): float => $this->clock,
            static function (int $ms): void {
            },
        );
    }

    /** @return array{stats: array<string, mixed>, priced: array<string, list<string>>} */
    private static function check(ResortCheckRunner $runner, ?ResortCheckSettings $settings = null): array
    {
        return $runner->run($settings ?? self::settings(), new \DateTimeImmutable(self::NOW), '1 = 1');
    }

    public function testAWholeResortAnswerSetsEveryHotelsFlagAndChecksThemAll(): void
    {
        $result = self::check($this->runner(self::bulgaria(), ['BULGARIA' => ['SUNNY BEACH', 'ALBENA']], static fn (string $resort, string $checkIn): array => match ($resort) {
            'SUNNY BEACH' => [$checkIn === '2026-11-09' ? self::offers('1') : self::offers('1', '2')],
            default => [false, self::EMPTY_DOCUMENT], // Novoton's "no prices"
        }));

        self::assertSame(['SUNNY BEACH 2026-11-09', 'SUNNY BEACH 2027-07-15', 'ALBENA 2026-11-09', 'ALBENA 2027-07-15'], $this->calls, 'one call per resort and date, oldest check first');
        self::assertSame([[['1', '2'], ['3']], [[], ['4']]], $this->flags);

        self::assertCount(2, $this->sql);
        self::assertStringContainsString('offer_checked_at', $this->sql[0]);
        self::assertStringContainsString(
            "('novoton', '1', 0, 'BULGARIA|Sunny Beach', 'Hotel 1', '2026-10-26 04:00:00', '2026-11-09', '2026-10-26 04:00:00', '2027-07-15', NULL, '{\"2026-11\":\"2026-10-26\",\"2027-07\":\"2026-10-26\"}')",
            $this->sql[0],
        );
        self::assertStringContainsString("('novoton', '3', 0, 'BULGARIA|Sunny Beach', 'Hotel 3', '2026-10-26 04:00:00', NULL, NULL, NULL, NULL, NULL)", $this->sql[0], 'checked, no offer');
        self::assertStringContainsString("('novoton', '4', 0, 'BULGARIA|Albena', 'Hotel 4', '2026-10-26 04:00:00', NULL,", $this->sql[1]);

        $stats = $result['stats'];
        self::assertSame([2, 2, 0, 0, 2, 2, 0], [$stats['units'], $stats['checked'], $stats['partial'], $stats['unanswered'], $stats['with_prices'], $stats['without_prices'], $stats['invalid']]);
        self::assertSame(5, $stats['calls'], 'the resort list, then four room_price calls');
        self::assertSame(['BULGARIA' => ['NVT-1 | Hotel 1', 'NVT-2 | Hotel 2']], $result['priced']);
    }

    public function testADateThatDidNotAnswerKeepsTheFlagsOfHotelsWithoutAnOffer(): void
    {
        self::check($this->runner([self::hotel('1', 'Sunny Beach'), self::hotel('2', 'Sunny Beach')], ['BULGARIA' => ['Sunny Beach']], static function (string $resort, string $checkIn): array {
            if ($checkIn === '2026-11-09') {
                throw new \RuntimeException('Circuit breaker open');
            }

            return [self::offers('1')];
        }));

        self::assertSame([[['1'], []]], $this->flags, 'hotel 2 keeps its flag: the date that failed may have had its offer');
        self::assertCount(1, $this->sql);
        self::assertStringNotContainsString('offer_checked_at', $this->sql[0], 'part of an answer counts only its offers');
        self::assertStringContainsString("('novoton', '1', 0, 'BULGARIA|Sunny Beach', 'Hotel 1', '2026-10-26 04:00:00', '2027-07-15', NULL,", $this->sql[0]);
        self::assertContains('    no answer: 2026-11-09: Circuit breaker open', $this->log);
    }

    /** A refusal, a reply that does not parse and an <Error> are no answer; Novoton not answering twice ends the run. */
    public function testNoAnswerChangesNothingAndTwoSilentResortsEndTheRun(): void
    {
        $hotels = [self::hotel('1', 'Sunny Beach'), self::hotel('4', 'Albena', '2026-10-20 04:00:00'), self::hotel('5', 'Obzor', '2026-10-21 04:00:00')];
        $result = self::check($this->runner($hotels, ['BULGARIA' => ['Sunny Beach', 'Albena', 'Obzor']], static fn (string $resort, string $checkIn): array => match ($checkIn) {
            '2026-11-09' => [new \SimpleXMLElement('<error><err>Invalid username or password!</err></error>')],
            default => $resort === 'Albena'
                ? [new \SimpleXMLElement('<room_prices><Error>Service unavailable</Error></room_prices>')]
                : [false, 'Error: no connection to dabatase.'],
        }));

        self::assertSame([], $this->flags);
        self::assertSame([], $this->sql);
        self::assertSame(['Sunny Beach 2026-11-09', 'Sunny Beach 2027-07-15', 'Albena 2026-11-09', 'Albena 2027-07-15'], $this->calls, 'Obzor is not asked');
        self::assertSame(['silent', 2, 1], [$result['stats']['stopped'], $result['stats']['unanswered'], $result['stats']['left']]);
        self::assertSame(2, $result['stats']['invalid']);
    }

    public function testEachRunTakesItsShareLeastRecentlyCheckedFirst(): void
    {
        $hotels = [
            self::hotel('1', 'Sunny Beach', '2026-10-24 04:00:00'),
            self::hotel('4', 'Albena', '2026-10-25 04:00:00'),
            self::hotel('5', 'Obzor', null),
            self::hotel('6', 'Obzor', '2026-10-18 04:00:00'),
        ];
        $result = self::check($this->runner($hotels, ['BULGARIA' => ['Sunny Beach', 'Albena', 'Obzor']], static fn (): array => [self::offers()]), self::settings(cycleDays: 3));

        self::assertSame(['Obzor 2026-11-09', 'Obzor 2027-07-15'], $this->calls, 'one third of three resorts: the one with a hotel never checked');
        self::assertSame([3, 1], [$result['stats']['units'], $result['stats']['planned']]);
    }

    public function testTheTimeBudgetEndsTheRunBeforeAddProducts(): void
    {
        $hotels = [self::hotel('1', 'Sunny Beach'), self::hotel('4', 'Albena', '2026-10-20 04:00:00'), self::hotel('5', 'Obzor', '2026-10-21 04:00:00')];
        // Each call takes 350 s: a resort takes 700 s, a second would end past the 1,200 s budget.
        $this->secondsPerCall = 350.0;
        $result = self::check($this->runner($hotels, ['BULGARIA' => ['Sunny Beach', 'Albena', 'Obzor']], static fn (): array => [self::offers('1')]));

        self::assertSame(['Sunny Beach 2026-11-09', 'Sunny Beach 2027-07-15'], $this->calls);
        self::assertSame(['time', 2], [$result['stats']['stopped'], $result['stats']['left']]);
        self::assertContains('Stopped at the time budget (20 minutes): 2 units go first next run.', $this->log);
    }

    public function testTheCallBudgetIsNeverExceeded(): void
    {
        $hotels = [self::hotel('1', 'Sunny Beach'), self::hotel('4', 'Albena', '2026-10-20 04:00:00'), self::hotel('5', 'Obzor', '2026-10-21 04:00:00')];
        $runner = $this->runner($hotels, ['BULGARIA' => ['Sunny Beach', 'Albena', 'Obzor']], static fn (): array => [self::offers()]);

        $result = self::check($runner, self::settings(maxCalls: 5, units: 3));

        self::assertCount(4, $this->calls, 'the resort list and two resorts: 5 calls, a third would make 7');
        self::assertSame(['calls', 1, 5], [$result['stats']['stopped'], $result['stats']['left'], $result['stats']['calls']]);
    }

    public function testAHotelNoResortCoversIsAskedOnItsOwnAlwaysLive(): void
    {
        $batches = [];
        $runner = $this->runner([self::hotel('7', 'Kosharitsa')], ['BULGARIA' => ['Sunny Beach']], static fn (): array => [self::offers()], static function (array $requests, int $concurrency) use (&$batches): array {
            $batches[] = [array_keys($requests), $concurrency, array_column($requests, 'nocache'), array_column($requests, 'hotel_id')];

            return [
                '2026-11-09' => ['data' => false, 'rawXml' => '', 'unparsed' => self::EMPTY_DOCUMENT],
                '2027-07-15' => ['data' => new \SimpleXMLElement('<room_price><Price>380</Price></room_price>'), 'rawXml' => ''],
            ];
        });

        $result = self::check($runner);

        self::assertSame([[['2026-11-09', '2027-07-15'], ResortCheckRunner::HOTEL_CONCURRENCY, [true, true], ['7', '7']]], $batches);
        self::assertSame([], $this->calls, 'no resort call for it');
        self::assertSame([[['7'], []]], $this->flags);
        self::assertStringContainsString('offer_checked_at', $this->sql[0]);
        self::assertSame(1, $result['stats']['checked']);
    }

    /** Novoton answers for a hotel under a resort the store files it apart from: its offer counts all the same. */
    public function testAHotelFoundUnderAnotherResortCountsAsOffered(): void
    {
        $stored = [['hotel_id' => '50', 'hotel_name' => 'Hotel 50', 'city' => 'Ravda', 'country' => 'BULGARIA', 'product_id' => '900', 'created_at' => '2024-01-01 00:00:00']];
        $result = self::check($this->runner([self::hotel('1', 'Sunny Beach')], ['BULGARIA' => ['Sunny Beach']], static fn (): array => [self::offers('1', '50', '99999')], null, $stored));

        self::assertSame([[['1', '50'], []]], $this->flags, 'hotel 99999 is not in the store');
        self::assertCount(2, $this->sql);
        self::assertStringNotContainsString('offer_checked_at', $this->sql[1], 'its own resort was not checked');
        self::assertStringContainsString("('novoton', '50', 900, 'BULGARIA|Ravda', 'Hotel 50', '2026-10-26 04:00:00', '2027-07-15', NULL,", $this->sql[1]);
        self::assertSame(['BULGARIA' => ['NVT-1 | Hotel 1', 'NVT-50 | Hotel 50']], $result['priced']);
    }

    /** Its own resort said nothing, another resort's answer offered it: an offer wins, and each hotel counts once. */
    public function testAnOfferFoundLaterInTheRunWinsAndEachHotelCountsOnce(): void
    {
        $hotels = [self::hotel('50', 'Ravda', '2026-10-10 04:00:00'), self::hotel('1', 'Sunny Beach')];
        $result = self::check($this->runner($hotels, ['BULGARIA' => ['Ravda', 'Sunny Beach']], static fn (string $resort): array => $resort === 'Ravda'
            ? [false, self::EMPTY_DOCUMENT]
            : [self::offers('1', '50')]));

        self::assertSame([[[], ['50']], [['1', '50'], []]], $this->flags);
        self::assertSame([2, 0, 0], [$result['stats']['with_prices'], $result['stats']['without_prices'], $result['stats']['invalid']]);
        self::assertSame(['BULGARIA' => ['NVT-50 | Hotel 50', 'NVT-1 | Hotel 1']], $result['priced']);
    }

    public function testACountryWithoutItsResortListWaits(): void
    {
        $result = self::check($this->runner([...self::bulgaria(), self::hotel('9', 'Kassandra', null, 'GREECE')], ['BULGARIA' => ['Sunny Beach', 'Albena']], static fn (): array => [self::offers('1')]));

        self::assertSame(['GREECE'], $result['stats']['skipped_countries']);
        self::assertNotContains('9', array_merge(...array_merge(...$this->flags)));
        self::assertContains('GREECE: no resort list (Operation timed out); its hotels wait for the next run.', $this->log);
    }

    public function testAHotelWhosePricesStartLaterIsAskedAboutItsFirstWeek(): void
    {
        DbStub::$getArray = static fn (string $query): array => str_contains($query, 'contract_from > ')
            ? [['hotel_id' => '4', 'contract_from' => '2026-12-20']]
            : [];

        self::check($this->runner(self::bulgaria(), ['BULGARIA' => ['Sunny Beach', 'Albena']], static fn (): array => [self::offers()]));

        self::assertSame(
            ['Sunny Beach 2026-11-09', 'Sunny Beach 2027-07-15', 'Albena 2026-11-09', 'Albena 2027-07-15', 'Albena 2026-12-20'],
            $this->calls,
        );
    }

    /**
     * The room_price command over a stubbed Novoton, as the dispatcher runs it.
     *
     * @param array<string, mixed> $params
     *
     * @return array{result: array<string, mixed>, lines: list<string>}
     */
    private function command(\Closure $resortList, \Closure $resortAnswer, array $params = []): array
    {
        $db = $this->createStub(DatabaseHelperInterface::class);
        $db->method('getHotelsForPriceCheck')->willReturn([self::hotel('1', 'Sunny Beach')]);
        $destinations = $this->createStub(DestinationApiClientInterface::class);
        $destinations->method('getResortList')->willReturnCallback($resortList);
        $pricing = $this->createStub(PricingApiClientInterface::class);
        $pricing->method('getRoomPriceByResort')->willReturnCallback($resortAnswer);
        $api = $this->createStub(NovotonApiKitInterface::class);
        $api->method('pricing')->willReturn($pricing);
        $api->method('destinations')->willReturn($destinations);
        $api->method('debugInfo')->willReturn(new RequestDebugInfo());
        $container = new Container();
        $container->override('databaseHelper', static fn (): DatabaseHelperInterface => $db);
        Container::setInstance($container);
        DestinationScope::setCurrent(DestinationScope::fromRows([]));

        $lines = [];
        $command = new RoomPriceCheckCommand($api, null, $params);
        $command->setOutputCallback(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return ['result' => $command->execute(), 'lines' => $lines];
    }

    public function testTheJobFailsWhenNovotonAnsweredNothingAndSaysWhichParametersAreGone(): void
    {
        $run = $this->command(
            static fn (): \SimpleXMLElement => new \SimpleXMLElement('<resort_list><Resort>Sunny Beach</Resort></resort_list>'),
            static function (): never {
                throw new \RuntimeException('Connection refused');
            },
            ['check_in' => '2026-10-23', 'limit' => '500'],
        );

        self::assertFalse($run['result']['success']);
        self::assertSame('Novoton did not answer the availability check', $run['result']['error']);
        self::assertContains('NOTE: &check_in is no longer used: the check asks every resort on the dates in Settings -> Advanced.', $run['lines']);
        self::assertContains('NOTE: &limit is no longer used: the check asks every resort on the dates in Settings -> Advanced.', $run['lines']);
    }

    public function testTheJobFailsWhenNovotonListedNoResortAndSucceedsWhenItAnswered(): void
    {
        $refused = $this->command(
            static fn (): \SimpleXMLElement => new \SimpleXMLElement('<error><err>Invalid username or password!</err></error>'),
            static fn (): \SimpleXMLElement => self::offers('1'),
        );
        self::assertSame(['success' => false, 'error' => 'Novoton listed no resorts for BULGARIA'], array_intersect_key($refused['result'], ['success' => 1, 'error' => 1]));
        self::assertContains('BULGARIA: no resort list (Novoton: Invalid username or password!); its hotels wait for the next run.', $refused['lines']);

        $answered = $this->command(
            static fn (): \SimpleXMLElement => new \SimpleXMLElement('<resort_list><Resort>Sunny Beach</Resort></resort_list>'),
            static fn (): \SimpleXMLElement => self::offers('1'),
        );
        self::assertTrue($answered['result']['success']);
        self::assertContains('  NVT-1 | Hotel 1', $answered['lines'], 'the priced hotels, grouped by country');
    }

    public function testResponsesReadAsPricesNoPricesOrInvalid(): void
    {
        self::assertTrue(RoomPriceCheckCommand::hasPrices(new \SimpleXMLElement('<room_price><Room><Price>120</Price></Room></room_price>')));
        self::assertFalse(RoomPriceCheckCommand::hasPrices(new \SimpleXMLElement('<room_price></room_price>')));
        self::assertNull(RoomPriceCheckCommand::hasPrices(false));
        self::assertNull(RoomPriceCheckCommand::hasPrices(null));
    }

    /** Never-checked hotels first, then the oldest checks; each row says its city and when it was checked. */
    public function testHotelsComeLeastRecentlyCheckedFirst(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];

            return [];
        };

        (new DatabaseHelper())->getHotelsForPriceCheck(['country' => 'BULGARIA', 'not_a_column' => 'x'], 500);

        [$query, $params] = $captured;
        self::assertStringContainsString('SELECT hotel_id, hotel_name, city, country, product_id, created_at, last_price_check', $query);
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
