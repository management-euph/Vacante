<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\HotelApiClientInterface;
use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Cron\Commands\HotelListSyncCommand;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelperInterface;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * hotel_list: a country that does not answer, or refuses, no longer aborts
 * the other countries, and its hotels are never counted missing from the
 * availability records (an outage is not a hotel gone).
 */
final class HotelListSyncRunTest extends TestCase
{
    /** @var list<string> */
    private array $sql = [];

    /** @var list<string> */
    private array $upserted = [];

    protected function setUp(): void
    {
        ConfigProvider::reset();
        DestinationScope::setCurrent(DestinationScope::fromRows([]));
        DbStub::reset();
        $this->sql = [];
        $this->upserted = [];
        DbStub::$query = function (string $query, mixed ...$params): int {
            $this->sql[] = db_quote($query, ...$params);

            return 1;
        };
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        ConfigProvider::reset();
        DbStub::reset();
    }

    /**
     * @param array<string, \SimpleXMLElement|\Throwable> $answers country => reply
     *
     * @return array<string, mixed>
     */
    private function sync(array $answers): array
    {
        $hotels = $this->createStub(HotelApiClientInterface::class);
        $hotels->method('getHotelList')->willReturnCallback(static function (string $country) use ($answers): \SimpleXMLElement {
            $answer = $answers[$country] ?? new \SimpleXMLElement('<hotel_list/>');
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });
        $api = $this->createStub(NovotonApiKitInterface::class);
        $api->method('hotels')->willReturn($hotels);

        $db = $this->createStub(DatabaseHelperInterface::class);
        $db->method('upsertHotels')->willReturnCallback(function (array $batch): array {
            foreach ($batch as $hotel) {
                $this->upserted[] = (string) $hotel['hotel_id'];
            }

            return ['inserted' => count($batch), 'updated' => 0];
        });
        $container = new Container();
        $container->override('databaseHelper', static fn (): DatabaseHelperInterface => $db);
        Container::setInstance($container);

        DbStub::$getArray = static fn (string $query, mixed ...$params): array => [
            ['hotel_id' => '501', 'product_id' => '0', 'city' => 'Halkidiki', 'country' => 'GREECE', 'created_at' => '2024-01-01 00:00:00'],
        ];

        ob_start();
        try {
            return (new HotelListSyncCommand($api, null))->execute();
        } finally {
            ob_end_clean();
        }
    }

    private static function greece(): \SimpleXMLElement
    {
        return new \SimpleXMLElement('<hotel_list><Hotel><IdHotel>501</IdHotel><Hotel>Sea View</Hotel><City>Halkidiki</City><Country>GREECE</Country></Hotel></hotel_list>');
    }

    public function testACountryWithoutAnAnswerDoesNotStopTheOthers(): void
    {
        $result = $this->sync(['BULGARIA' => new \RuntimeException('Connection timed out'), 'GREECE' => self::greece()]);

        self::assertFalse($result['success']);
        self::assertSame('No hotel list for: BULGARIA', $result['error']);
        self::assertSame(['501'], $this->upserted, 'Greece still synced');
        self::assertStringContainsString("('novoton', '501', 0, 'GREECE|Halkidiki'", implode("\n", $this->sql), 'and was recorded');
    }

    public function testARefusalCountsAsNoAnswer(): void
    {
        $result = $this->sync([
            'BULGARIA' => new \SimpleXMLElement('<error><err>Invalid username or password!</err></error>'),
            'GREECE' => self::greece(),
        ]);

        self::assertFalse($result['success']);
        self::assertSame('No hotel list for: BULGARIA', $result['error']);
    }

    public function testEveryCountryAnsweringIsASuccess(): void
    {
        $result = $this->sync(['BULGARIA' => self::greece(), 'GREECE' => self::greece()]);

        self::assertTrue($result['success']);
    }
}
