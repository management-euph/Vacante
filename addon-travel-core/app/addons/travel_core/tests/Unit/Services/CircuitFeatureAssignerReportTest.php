<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitFeatureFacts;
use Tygh\Addons\TravelCore\Services\CircuitFeatureAssigner;
use Tygh\Addons\TravelCore\Services\CircuitFeatureConfig;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * A mapped circuit detail is never silently skipped: assign() says, for
 * each one, that it was written or why not (no data, value not linked, a
 * style that cannot hold values), and makes no stray values on a feature
 * that cannot hold them.
 */
final class CircuitFeatureAssignerReportTest extends TestCase
{
    /** @var list<string> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        // feature 10: multiple checkboxes, 11: single checkbox (yes/no), 12: text
        $types = [10 => 'M', 11 => 'C', 12 => 'T'];
        DbStub::$getField = static function (string $q, ...$p) use ($types): mixed {
            return str_contains($q, 'feature_type FROM') ? ($types[(int) ($p[0] ?? 0)] ?? '') : null;
        };
        DbStub::$query = function (string $q, ...$p): int {
            $this->queries[] = $q;

            return str_contains($q, 'INSERT INTO ?:product_feature_variants') ? 99 : 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @param array<string, int> $features field => feature_id
     * @param array<string, array<array-key, int>> $values
     *
     * @return array{group_id: int, features: array<string, int>, values: array{transport: array<string, int>, month: array<int, int>}}
     */
    private static function config(array $features, array $values = []): array
    {
        return CircuitFeatureConfig::normalize(['features' => $features, 'values' => $values]);
    }

    private function createdVariants(): int
    {
        return count(array_filter($this->queries, static fn (string $q): bool => str_contains($q, 'INSERT INTO ?:product_feature_variants')));
    }

    public function testAFactWithNoFeatureChosenIsNotReported(): void
    {
        $assigner = new CircuitFeatureAssigner(self::config(['countries' => 10]));

        $assigner->assign(5, new CircuitFeatureFacts(transport: 'flight', nights: 7, countries: ['Malta']));

        self::assertSame(['countries' => CircuitFeatureAssigner::REPORT_WRITTEN], $assigner->report());
    }

    public function testMappedFactsWithNothingStoredSayNoData(): void
    {
        $assigner = new CircuitFeatureAssigner(self::config(['countries' => 10, 'departure_city' => 10, 'tags' => 10, 'included' => 10, 'duration' => 10, 'transport' => 10]));

        $written = $assigner->assign(5, new CircuitFeatureFacts());

        self::assertSame(0, $written);
        foreach (['countries', 'departure_city', 'tags', 'included', 'duration', 'transport'] as $field) {
            self::assertSame(CircuitFeatureAssigner::REPORT_NO_DATA, $assigner->report()[$field], $field);
        }
        self::assertSame(0, $this->createdVariants());
    }

    public function testATransportAndMonthsThatAreNotLinkedSaySo(): void
    {
        $assigner = new CircuitFeatureAssigner(self::config(['transport' => 10, 'month' => 10]));

        $assigner->assign(5, new CircuitFeatureFacts(transport: 'flight', months: [5, 6]));

        self::assertSame(CircuitFeatureAssigner::REPORT_NOT_LINKED, $assigner->report()['transport']);
        self::assertSame(CircuitFeatureAssigner::REPORT_NOT_LINKED, $assigner->report()['month']);
    }

    public function testLinkedTransportAndMonthsAreWritten(): void
    {
        $assigner = new CircuitFeatureAssigner(self::config(['transport' => 10, 'month' => 10], ['transport' => ['flight' => 501], 'month' => [5 => 605]]));

        $written = $assigner->assign(5, new CircuitFeatureFacts(transport: 'flight', months: [5]));

        self::assertSame(2, $written);
        self::assertSame(CircuitFeatureAssigner::REPORT_WRITTEN, $assigner->report()['transport']);
        self::assertSame(CircuitFeatureAssigner::REPORT_WRITTEN, $assigner->report()['month']);
    }

    public function testAMonthNotLinkedForTheCircuitSaysNoDepartureInALinkedMonth(): void
    {
        $assigner = new CircuitFeatureAssigner(self::config(['month' => 10], ['month' => [5 => 605]]));

        $assigner->assign(5, new CircuitFeatureFacts(months: [9]));

        self::assertSame(CircuitFeatureAssigner::REPORT_NO_MONTHS, $assigner->report()['month']);
    }

    public function testAFeatureStyleThatCannotHoldValuesMakesNoStrayValues(): void
    {
        $assigner = new CircuitFeatureAssigner(self::config(['countries' => 11, 'included' => 11], ['transport' => []]));

        $written = $assigner->assign(5, new CircuitFeatureFacts(countries: ['Malta'], included: ['Zbor']));

        self::assertSame(0, $written);
        self::assertSame(CircuitFeatureAssigner::REPORT_STYLE, $assigner->report()['countries']);
        self::assertSame(CircuitFeatureAssigner::REPORT_STYLE, $assigner->report()['included']);
        self::assertSame(0, $this->createdVariants(), 'no value is made on a single checkbox');
    }

    public function testAnOpenListWritesAndIsReportedWritten(): void
    {
        $assigner = new CircuitFeatureAssigner(self::config(['countries' => 10]));

        $written = $assigner->assign(5, new CircuitFeatureFacts(countries: ['Malta']));

        self::assertSame(1, $written);
        self::assertSame(CircuitFeatureAssigner::REPORT_WRITTEN, $assigner->report()['countries']);
        self::assertSame(1, $this->createdVariants(), 'the missing value Malta is made');
    }
}
