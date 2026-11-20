<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\CircuitProductFactory;
use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * The circuit rows the features read carry no departure columns (the
 * departures live in Travel Core's table), and the circuits sync keeps
 * linked products' descriptions in step with Sphinx.
 */
final class CircuitColumnsAndDescriptionsTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testTheFeatureRowsReadNoDepartureColumns(): void
    {
        $asked = [];
        DbStub::$getArray = static function (string $q, ...$p) use (&$asked): array {
            $asked[] = $q;

            return [];
        };
        DbStub::$getRow = static function (string $q, ...$p) use (&$asked): array {
            $asked[] = $q;

            return [];
        };
        $repo = new CircuitRepository();

        $repo->findLinkedForFeatures();
        $repo->findForFeatures(5);

        self::assertCount(2, $asked, 'no column check before the rows');
        foreach ($asked as $query) {
            self::assertStringNotContainsString('departure_', $query);
            self::assertStringContainsString('details_json', $query);
        }
    }

    public function testLinkedProductsGetTheNewSummaryAndDescriptionInEveryLanguage(): void
    {
        $writes = [];
        DbStub::$getHashSingleArray = static fn (string $q, array $fields, ...$p): array => [5 => 100, 6 => 200];
        DbStub::$query = static function (string $q, ...$p) use (&$writes): int {
            $writes[] = [$q, $p];

            return 1;
        };
        $factory = new CircuitProductFactory(new CircuitRepository());

        $changed = $factory->refreshDescriptions([
            ['circuit_id' => 5, 'summary' => 'Rezumat', 'description' => 'Descriere'],
            ['circuit_id' => 6, 'summary' => '', 'description' => ''],
            ['circuit_id' => 7, 'summary' => 'Fara produs', 'description' => 'x'],
        ], ['ro', 'en']);

        self::assertSame(1, $changed, 'circuit 6 has no text, circuit 7 has no product');
        self::assertCount(1, $writes);
        self::assertStringContainsString('UPDATE ?:product_descriptions SET short_description', $writes[0][0]);
        self::assertSame('Rezumat', $writes[0][1][0]);
        self::assertSame("<p>Rezumat</p>\n<p>Descriere</p>", $writes[0][1][1]);
        self::assertSame(100, $writes[0][1][2]);
        self::assertSame(['ro', 'en'], $writes[0][1][3]);
    }

    public function testAProductAlreadyUpToDateIsNotCounted(): void
    {
        DbStub::$getHashSingleArray = static fn (string $q, array $fields, ...$p): array => [5 => 100];
        DbStub::$query = static fn (string $q, ...$p): int => 0; // the WHERE matched no row: same text
        $factory = new CircuitProductFactory(new CircuitRepository());

        self::assertSame(0, $factory->refreshDescriptions([['circuit_id' => 5, 'summary' => 'a', 'description' => 'b']], ['ro']));
        self::assertSame(0, $factory->refreshDescriptions([], ['ro']));
        self::assertSame(0, $factory->refreshDescriptions([['circuit_id' => 5, 'summary' => 'a', 'description' => 'b']], []));
    }

    public function testTheSyncRefreshesDescriptionsAfterTheUpsert(): void
    {
        $sync = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/CircuitSyncService.php');

        $upsert = strpos($sync, '$this->upsertBatch($batch)');
        $refresh = strpos($sync, '->refreshDescriptions($allCircuits,');
        self::assertNotFalse($upsert);
        self::assertNotFalse($refresh);
        self::assertGreaterThan($upsert, $refresh, 'the circuits are saved, then their products follow');
    }
}
