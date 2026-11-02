<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;
use Tygh\Addons\TravelCore\Services\ClosedHotelRedirects;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * A closed hotel's page redirects (301) (docs/novoton-availability-gaps-plan.md
 * §7.4, §9): from the day the admin set, in Automatic mode, while the product
 * is Hidden and nobody still needs the page, to the admin's product or the
 * resort page. The storefront only reads what the daily run stored.
 */
final class ClosedHotelRedirectsTest extends TestCase
{
    /** @var array<string, string> storage_data */
    private array $storage = [];

    /** @var list<string> */
    private array $sql = [];

    protected function setUp(): void
    {
        DbStub::reset();
        HotelAvailabilityRepository::resetEnsured();
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
        HotelAvailabilityRepository::resetEnsured();
    }

    /** @param list<string> $automatic */
    private function redirects(array $automatic = ['novoton']): ClosedHotelRedirects
    {
        return new ClosedHotelRedirects(
            fn (string $key): string => $this->storage[$key] ?? '',
            function (string $key, string $value): void {
                $this->storage[$key] = $value;
            },
            static fn (): array => $automatic,
            static fn (string $dispatch): string => 'https://store.test/?dispatch=' . $dispatch,
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-08-20 04:00:00');
    }

    public function testTheStorefrontRedirectsOnlyWhatTheDailyRunStored(): void
    {
        $this->storage[ClosedHotelRedirects::STORAGE_KEY] = '{"active":{"15":"category:7","16":"product:40"},"waiting":{"17":"bookings"}}';
        $redirects = $this->redirects();

        self::assertSame('https://store.test/?dispatch=categories.view?category_id=7', $redirects->urlFor(15));
        self::assertSame('https://store.test/?dispatch=products.view?product_id=40', $redirects->urlFor(16));
        self::assertNull($redirects->urlFor(17), 'waiting');
        self::assertNull($redirects->urlFor(18), 'not closed');

        $this->storage[ClosedHotelRedirects::STORAGE_KEY] = 'not json';
        self::assertNull($this->redirects()->urlFor(15));
    }

    public function testTheDailyRunWorksOutWhoRedirectsAndWhoWaits(): void
    {
        DbStub::$getArray = static fn (): array => [
            ['provider' => 'novoton', 'hotel_id' => '1', 'product_id' => '11', 'redirect_to' => '', 'status' => 'H'],
            ['provider' => 'novoton', 'hotel_id' => '2', 'product_id' => '12', 'redirect_to' => 'product:40', 'status' => 'H'],
            ['provider' => 'novoton', 'hotel_id' => '3', 'product_id' => '13', 'redirect_to' => '', 'status' => 'A'],
            ['provider' => 'novoton', 'hotel_id' => '4', 'product_id' => '14', 'redirect_to' => '', 'status' => 'H'],
            ['provider' => 'novoton', 'hotel_id' => '5', 'product_id' => '15', 'redirect_to' => '', 'status' => 'H'],
            ['provider' => 'novoton', 'hotel_id' => '6', 'product_id' => '16', 'redirect_to' => 'product:41', 'status' => 'H'],
        ];
        DbStub::$getField = static function (string $query, mixed ...$params): string {
            return match (true) {
                str_contains($query, 'SHOW COLUMNS') => 'present',
                // Hotel 4 has a stay still to come; product 15 a balance to pay.
                str_contains($query, 'FROM ?:travel_bookings') => ($params[1] ?? '') === '4' ? '1' : '0',
                str_contains($query, 'FROM ?:travel_balances') => ($params[0] ?? 0) === 15 ? '1' : '0',
                // Product 40 is active, 41 is not; product 16 has no active resort page.
                str_contains($query, 'SELECT status FROM ?:products') => ($params[0] ?? 0) === 40 ? 'A' : 'D',
                str_contains($query, 'FROM ?:products_categories') => ($params[0] ?? 0) === 16 ? '' : '7',
                default => '',
            };
        };

        $result = $this->redirects()->refresh(self::now());

        self::assertSame([11 => 'category:7', 12 => 'product:40'], $result['active']);
        self::assertSame([13 => 'not_hidden', 14 => 'bookings', 15 => 'balance', 16 => 'no_target'], $result['waiting']);
        self::assertStringContainsString('"active":{"11":"category:7","12":"product:40"}', $this->storage[ClosedHotelRedirects::STORAGE_KEY]);
    }

    public function testInReportOnlyNothingRedirects(): void
    {
        $this->storage[ClosedHotelRedirects::STORAGE_KEY] = '{"active":{"15":"category:7"}}';

        $result = $this->redirects([])->refresh(self::now());

        self::assertSame(['active' => [], 'waiting' => []], $result);
        self::assertNull($this->redirects([])->urlFor(15), 'switching Automatic off stops the redirects at the next run');
    }

    public function testTheAdminSetsWhereAndFromWhen(): void
    {
        DbStub::$getRow = static fn (string $query, mixed ...$params): array => match ($params[1] ?? '') {
            '476' => ['product_id' => '15', 'state' => 'closed', 'override' => 'closed'],
            '477' => ['product_id' => '16', 'state' => 'dormant', 'override' => 'keep_hidden'],
            default => [],
        };
        DbStub::$getField = static function (string $query, mixed ...$params): string {
            return match (true) {
                str_contains($query, 'SHOW COLUMNS') => 'present',
                str_contains($query, 'WHERE product_code = ?s') => match ($params[0] ?? '') {
                    'SPX900' => '40',
                    'NVT476' => '15',
                    'NVT480' => '18',
                    default => '',
                },
                // NVT480 is closed too: no chains.
                str_contains($query, 'override = ?s') => ($params[0] ?? 0) === 18 ? '1' : '0',
                default => '',
            };
        };
        $redirects = $this->redirects();

        self::assertSame('unknown', $redirects->configure('novoton', '999', '', '2027-08-20', 7, self::now()));
        self::assertSame('not_closed', $redirects->configure('novoton', '477', '', '2027-08-20', 7, self::now()));
        self::assertSame('bad_date', $redirects->configure('novoton', '476', '', '2027-02-30', 7, self::now()));
        self::assertSame('bad_target', $redirects->configure('novoton', '476', 'NOPE', '2027-08-20', 7, self::now()));
        self::assertSame('bad_target', $redirects->configure('novoton', '476', 'NVT476', '2027-08-20', 7, self::now()), 'not to itself');
        self::assertSame('bad_target', $redirects->configure('novoton', '476', 'NVT480', '2027-08-20', 7, self::now()), 'not to another closed hotel');
        self::assertSame([], $this->sql);

        self::assertSame('saved', $redirects->configure('novoton', '476', ' SPX900 ', '2027-08-20', 7, self::now()));
        $sql = implode("\n", $this->sql);
        self::assertStringContainsString("SET redirect_to = 'product:40', redirect_from = '2027-08-20' WHERE provider = 'novoton' AND hotel_id = '476'", $sql);
        self::assertStringContainsString("'redirect_set', 7, '2027-08-20 04:00:00'", $sql);
        self::assertArrayHasKey(ClosedHotelRedirects::STORAGE_KEY, $this->storage, 'applied at once');
    }

    public function testThePageSaysWhatEachClosedHotelsRedirectDoes(): void
    {
        $this->storage[ClosedHotelRedirects::STORAGE_KEY] = '{"active":{"11":"category:7","16":"category:7"},"waiting":{"12":"bookings"}}';
        DbStub::$getHashSingleArray = static fn (): array => [40 => 'SPX900', 41 => 'SPX901'];
        $rows = [
            ['provider' => 'novoton', 'product_id' => '11', 'override' => 'closed', 'redirect_to' => '', 'redirect_from' => '2027-08-01'],
            ['provider' => 'novoton', 'product_id' => '12', 'override' => 'closed', 'redirect_to' => 'product:40', 'redirect_from' => '2027-08-01'],
            ['provider' => 'novoton', 'product_id' => '13', 'override' => 'closed', 'redirect_to' => '', 'redirect_from' => '2028-02-01'],
            ['provider' => 'eurosite', 'product_id' => '14', 'override' => 'closed', 'redirect_to' => '', 'redirect_from' => '2027-08-01'],
            ['provider' => 'novoton', 'product_id' => '15', 'override' => 'keep_visible', 'redirect_to' => '', 'redirect_from' => null],
            // The admin's product is no longer active: the page goes to the resort page.
            ['provider' => 'novoton', 'product_id' => '16', 'override' => 'closed', 'redirect_to' => 'product:41', 'redirect_from' => '2027-08-01'],
        ];

        $lines = $this->redirects()->describe($rows, ['novoton' => true]);

        self::assertSame([
            11 => ['status' => 'active', 'why' => '', 'from' => '2027-08-01', 'code' => '', 'target' => ''],
            12 => ['status' => 'waiting', 'why' => 'bookings', 'from' => '2027-08-01', 'code' => 'SPX900', 'target' => 'SPX900'],
            13 => ['status' => 'scheduled', 'why' => '', 'from' => '2028-02-01', 'code' => '', 'target' => ''],
            14 => ['status' => 'report', 'why' => '', 'from' => '2027-08-01', 'code' => '', 'target' => ''],
            16 => ['status' => 'active', 'why' => '', 'from' => '2027-08-01', 'code' => 'SPX901', 'target' => ''],
        ], $lines, 'a hotel that is not closed has no line');
    }

    public function testAFailedRefreshKeepsTheLastListAndThrowsNothing(): void
    {
        $this->storage[ClosedHotelRedirects::STORAGE_KEY] = '{"active":{"11":"category:7"}}';
        DbStub::$getArray = static function (): never {
            throw new \RuntimeException('connection lost');
        };

        self::assertSame(['active' => [11 => 'category:7'], 'waiting' => []], $this->redirects()->refresh(self::now()));
        self::assertSame('{"active":{"11":"category:7"}}', $this->storage[ClosedHotelRedirects::STORAGE_KEY]);
    }
}
