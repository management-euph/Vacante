<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Providers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Providers\SphinxCircuitProductProvider;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * A product is a Sphinx circuit when a sold row of ?:sphinx_circuits is
 * linked to it: its page then gets the circuit bar (board 16.3), with the
 * departures the circuits sync stored.
 */
#[CoversClass(SphinxCircuitProductProvider::class)]
final class SphinxCircuitProductProviderTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private static array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        self::$queries = [];
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** @param array<string, mixed> $row what the query answers */
    private static function answer(array $row): void
    {
        DbStub::$getRow = static function (string $sql, mixed ...$args) use ($row): array {
            self::$queries[] = [$sql, $args];

            return $row;
        };
    }

    public function testALinkedCircuitIsClaimedWithItsDepartures(): void
    {
        self::answer([
            'circuit_id' => '17', 'name' => 'Creta clasică', 'duration_days' => '5', 'duration_nights' => '4',
            'departure_ids' => '[111,222,333]', 'departure_names' => 'Cluj-Napoca, București, Iași',
        ]);

        $circuit = (new SphinxCircuitProductProvider())->resolveCircuit(110, 'SPXC17');

        self::assertNotNull($circuit);
        self::assertSame('sphinx', $circuit->providerName);
        self::assertSame('17', $circuit->circuitId);
        self::assertSame([5, 4], [$circuit->durationDays, $circuit->durationNights]);
        self::assertSame([
            ['id' => '111', 'name' => 'Cluj-Napoca'],
            ['id' => '222', 'name' => 'București'],
            ['id' => '333', 'name' => 'Iași'],
        ], $circuit->departures);
        self::assertSame('222', $circuit->defaultDepartureId());
        self::assertStringContainsString("sync_status = 'active'", self::$queries[0][0], 'a circuit no longer sold is not claimed');
        self::assertSame([110], self::$queries[0][1]);
    }

    public function testAProductThatIsNoCircuitIsNotClaimed(): void
    {
        self::answer([]);

        self::assertNull((new SphinxCircuitProductProvider())->resolveCircuit(110, 'SPX7'));
        self::assertNull((new SphinxCircuitProductProvider())->resolveCircuit(0, ''), 'no product, no query');
        self::assertCount(1, self::$queries);
    }

    /** A name with ", " in it breaks the pairing: a lone id keeps the whole text, several get none. */
    public function testNamesThatDoNotPairAreNotGuessed(): void
    {
        self::assertSame([['id' => '1', 'name' => 'Cluj, Napoca']], SphinxCircuitProductProvider::departures('[1]', 'Cluj, Napoca'));
        self::assertSame(
            [['id' => '1', 'name' => ''], ['id' => '2', 'name' => '']],
            SphinxCircuitProductProvider::departures('[1,2]', 'Cluj, Napoca, București'),
        );
        self::assertSame([], SphinxCircuitProductProvider::departures(null, ''));
    }
}
