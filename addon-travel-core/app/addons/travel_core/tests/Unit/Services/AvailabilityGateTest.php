<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;
use Tygh\Addons\TravelCore\Services\AvailabilityGate;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * The availability gate (docs/novoton-availability-gaps-plan.md §7.3, §7.4,
 * §10): Report only changes nothing, the brake holds a run that would hide
 * too many, the admin can confirm it, and a product an admin set by hand is
 * the admin's. The SQL itself was run on MariaDB 10.11 (plan §16.10).
 */
final class AvailabilityGateTest extends TestCase
{
    /** @var list<string> the writes, as their first words */
    private array $writes = [];

    protected function setUp(): void
    {
        DbStub::reset();
        HotelAvailabilityRepository::resetEnsured();
        DbStub::$getField = static fn (string $query): string => str_contains($query, 'SHOW COLUMNS') ? 'present' : '';
        DbStub::$query = function (string $query): int {
            if (preg_match('/^\s*(UPDATE \S+|INSERT INTO \S+)/', $query, $m) === 1) {
                $this->writes[] = $m[1];
            }

            return 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        HotelAvailabilityRepository::resetEnsured();
    }

    /**
     * @param list<int> $toHide products the hide query finds
     * @param list<int> $toShow products the show query finds
     */
    private function products(array $toHide, array $toShow = [], int $visible = 400): void
    {
        DbStub::$getFields = static fn (string $query): array => str_contains($query, "p.status = 'A'") ? $toHide : $toShow;
        DbStub::$getField = static fn (string $query): string => match (true) {
            str_contains($query, 'SHOW COLUMNS') => 'present',
            str_contains($query, 'COUNT(DISTINCT') => (string) $visible,
            default => '',
        };
        DbStub::$getArray = static fn (): array => [];
    }

    /** @return list<string> the product status writes */
    private function productWrites(): array
    {
        return array_values(array_filter($this->writes, static fn (string $w): bool => $w === 'UPDATE ?:products'));
    }

    public function testReportOnlyCountsWhatItWouldHideAndChangesNothing(): void
    {
        $this->products([11, 12, 13]);

        $result = (new AvailabilityGate())->apply('novoton', '2027-02-15 06:00:00', false);

        self::assertSame(['hidden' => 0, 'shown' => 0, 'released' => 0, 'would_hide' => 3, 'braked' => 0], $result);
        self::assertSame([], array_filter($this->writes, static fn (string $w): bool => !str_starts_with($w, 'UPDATE ?:travel_hotel_availability')
            && !str_contains($w, 'CREATE')), 'no product, flag or log write');
        self::assertSame([], $this->productWrites());
    }

    public function testAutomaticHidesFlagFirstAndShowsProductFirst(): void
    {
        $this->products([11, 12], [20]);

        $result = (new AvailabilityGate())->apply('novoton', '2027-02-15 06:00:00', true);

        self::assertSame([1, 1], [$result['hidden'], $result['shown']], 'the affected rows: one product write each, 1 row in the stub');
        $hideFlag = array_search('UPDATE ?:travel_hotel_availability', $this->writes, true);
        $hide = array_search('UPDATE ?:products', $this->writes, true);
        self::assertIsInt($hideFlag);
        self::assertIsInt($hide);
        self::assertLessThan($hide, $hideFlag, 'hide: the flag goes first');
        self::assertCount(2, $this->productWrites(), 'one hide, one show');
    }

    public function testTheBrakeHoldsARunThatWouldHideTooMany(): void
    {
        $this->products(range(1, 30), [], 300);

        $held = (new AvailabilityGate())->apply('novoton', '2027-02-15 06:00:00', true);
        self::assertSame([0, 30, 30], [$held['hidden'], $held['braked'], $held['would_hide']], 'more than max(10, 5% of 300 = 15)');
        self::assertSame([], $this->productWrites());

        $confirmed = (new AvailabilityGate())->apply('novoton', '2027-02-15 07:00:00', true, brake: false, userId: 7);
        self::assertSame([1, 0], [$confirmed['hidden'], $confirmed['braked']], 'the admin confirmed the list');

        $this->writes = [];
        $this->products(range(1, 15), [], 300);
        self::assertSame(0, (new AvailabilityGate())->apply('novoton', '2027-02-22 06:00:00', true)['braked'], 'exactly the limit is allowed');
    }

    public function testOneProductNeverBrakesAndTheDailyStepNeverHides(): void
    {
        $this->products(range(1, 30), [5], 20);

        self::assertSame(0, (new AvailabilityGate())->apply('novoton', 'now', true, productId: 9)['braked'], "an admin's override on one product");

        $this->writes = [];
        $daily = (new AvailabilityGate())->apply('novoton', 'now', true, hide: false);
        self::assertSame([0, 0, 1], [$daily['would_hide'], $daily['hidden'], $daily['shown']]);
    }

    public function testBrakeLimitIsTenOrFivePercent(): void
    {
        self::assertSame([10, 10, 10, 11, 25, 50], array_map(AvailabilityGate::brakeLimit(...), [0, 60, 200, 201, 500, 1000]));
    }

    public function testOverridesWinOverTheState(): void
    {
        $hides = AvailabilityGate::hides();

        self::assertStringContainsString("a.override IN ('keep_hidden', 'closed')", $hides);
        self::assertStringContainsString("a.override <> 'keep_visible' AND a.state IN ('dormant', 'left_feed', 'closed')", $hides);
    }
}
