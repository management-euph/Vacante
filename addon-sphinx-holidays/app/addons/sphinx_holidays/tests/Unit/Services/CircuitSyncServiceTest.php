<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\CircuitProductFactory;
use Tygh\Addons\SphinxHolidays\Services\CircuitSyncService;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * The circuits sync runs incrementally by default: sync(false) must read the
 * last-synced watermark (MAX(last_synced_at)) so only circuits changed since
 * then are re-fetched; sync(true) must NOT consult the watermark (full re-fetch).
 */
final class CircuitSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testIncrementalSyncReadsTheWatermark(): void
    {
        $api = $this->createMock(SphinxApi::class);
        $api->method('getCircuits')->willReturn(null);

        $queries = [];
        DbStub::$getField = static function (string $q, ...$p) use (&$queries): string {
            $queries[] = $q;
            return '2026-07-01 00:00:00';
        };

        (new CircuitSyncService($api))->sync(false);

        self::assertStringContainsString(
            'MAX(last_synced_at) FROM ?:sphinx_circuits',
            implode("\n", $queries),
            'incremental sync must read the last-synced watermark',
        );
    }

    /**
     * The static list sends a departure as {date, time, destination_id}: the
     * city is a static destination. The sync used to look only for {id,
     * name}, so it learned no city from the list.
     */
    public function testTheDepartureCitiesAreReadFromTheStaticDepartures(): void
    {
        $asked = [];
        DbStub::$getHashSingleArray = static function (string $q, array $fields, ...$p) use (&$asked): array {
            $asked[] = $p[0];

            return [202405 => 'Bucuresti', 197128 => 'Timisoara'];
        };

        $row = $this->normalize(['id' => 1, 'title' => 'Malta', 'departures' => [
            ['date' => '2020-05-07', 'time' => null, 'destination_id' => 555],
            ['date' => '2999-05-04', 'time' => null, 'destination_id' => 202405],
            ['date' => '2999-05-11', 'time' => null, 'destination_id' => 202405],
            ['date' => '2999-06-01', 'time' => null, 'destination_id' => 197128],
            ['date' => '2999-06-08', 'time' => null, 'destination_id' => 900],
        ]]);

        self::assertSame([[202405, 197128, 900]], $asked, 'named from sphinx_destinations; a departure already past names no city');
        self::assertSame('[202405,197128]', $row['departure_ids'], 'each city once; 900 is not a stored destination, so it is left to the rates');
        self::assertSame('Bucuresti, Timisoara', $row['departure_names'], 'as many names as ids: the bar pairs them by position');
    }

    public function testDepartureObjectsAreStillRead(): void
    {
        $row = $this->normalize(['id' => 2, 'title' => 'Grecia', 'departures' => [['id' => 111, 'name' => 'Cluj-Napoca'], ['id' => 222, 'name' => 'Iasi']]]);

        self::assertSame('[111,222]', $row['departure_ids']);
        self::assertSame('Cluj-Napoca, Iasi', $row['departure_names']);
    }

    /** Without a city in the list the two columns are not written: they keep what the rates gave. */
    public function testACircuitWhoseListNamesNoCityLeavesTheDepartureColumnsAlone(): void
    {
        $row = $this->normalize(['id' => 3, 'title' => 'Fara', 'departures' => [['date' => '2020-05-07', 'destination_id' => 202405]]]);

        self::assertArrayNotHasKey('departure_ids', $row);
        self::assertArrayNotHasKey('departure_names', $row);
    }

    /** The rates have the last word on the cities; the static list's stand in when the rates name none. */
    public function testTheStaticCitiesStandInWhenTheRatesNameNone(): void
    {
        $facts = CircuitSyncService::withStaticCities(
            [
                1 => ['months' => ['2027-05'], 'departures' => []],
                2 => ['months' => ['2027-06'], 'departures' => [111 => 'Cluj-Napoca']],
                3 => ['months' => ['2027-07'], 'departures' => []],
            ],
            [1 => [202405 => 'Bucuresti'], 2 => [202405 => 'Bucuresti'], 3 => []],
        );

        self::assertSame([202405 => 'Bucuresti'], $facts[1]['departures']);
        self::assertSame([111 => 'Cluj-Napoca'], $facts[2]['departures'], 'the rates name it: theirs');
        self::assertSame([], $facts[3]['departures'], 'neither says: none, as before');
        self::assertSame(['2027-05'], $facts[1]['months']);
    }

    public function testTheListsFirstPhotoIsTheCircuitsImage(): void
    {
        $row = $this->normalize(['id' => 4, 'title' => 'Malta', 'images' => [['url' => 'https://cdn.example/a.jpg'], ['url' => 'https://cdn.example/b.jpg']]]);

        self::assertSame('https://cdn.example/a.jpg', $row['image_url'], 'the API names no single image: the first photo');
    }

    /**
     * The departures go to Travel Core's shared table once the rates were
     * read in full, after the circuits' own facts; a failure there is
     * reported and never stops the sync.
     */
    public function testTheDeparturesAreSavedFromACompleteReadingOfTheRates(): void
    {
        $sync = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/CircuitSyncService.php');

        $guard = (int) strpos($sync, "if (\$bookable !== null) {\n            (new CircuitRepository())->saveDepartureFacts(self::withStaticCities(");
        self::assertGreaterThan(0, $guard, 'only when the rates answered in full');
        $save = (int) strpos($sync, '$this->saveDepartures($rates->departures(), $bookable);');
        self::assertGreaterThan($guard, $save);
        self::assertLessThan((int) strpos($sync, '$features = new CircuitFeatures();'), $save, 'before the features');
        self::assertStringContainsString('(new CircuitDepartureRepository())->replace(SphinxCircuitProductProvider::PROVIDER, $rows, date(\'Y-m-d H:i:s\'));', $sync);
        self::assertStringContainsString("} catch (\\Throwable \$e) {\n            \$this->output('WARNING: the departures could not be saved: '", $sync);
    }

    public function testFullSyncDoesNotReadTheWatermark(): void
    {
        $api = $this->createMock(SphinxApi::class);
        $api->method('getCircuits')->willReturn(null);

        $queries = [];
        DbStub::$getField = static function (string $q, ...$p) use (&$queries): string {
            $queries[] = $q;
            return '';
        };

        (new CircuitSyncService($api))->sync(true);

        self::assertStringNotContainsString(
            'MAX(last_synced_at) FROM ?:sphinx_circuits',
            implode("\n", $queries),
            'full sync must not consult the incremental watermark',
        );
    }
    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $sync = new CircuitSyncService($this->createMock(SphinxApi::class));
        $normalize = new \ReflectionMethod($sync, 'normalizeCircuit');

        /** @var array<string, mixed> $row */
        $row = $normalize->invoke($sync, $raw);

        return $row;
    }

    public function testDestinationIdsAreReadAndNamedFromTheStaticDestinations(): void
    {
        // The live static/circuits API lists destinations as plain ids.
        DbStub::$getHashSingleArray = static fn (string $q, array $fields, ...$p): array => [2440 => 'Malta', 122417 => 'Bugibba'];

        $row = $this->normalize(['id' => 1, 'title' => 'Malta', 'destinations' => [122417, 122428, 2440]]);

        self::assertSame('[122417,122428,2440]', $row['destination_ids']);
        self::assertSame('Bugibba, Malta', $row['destination_names'], 'named from sphinx_destinations, in id order; 122428 is not stored, so it has no name');
    }

    public function testDestinationObjectsAreStillRead(): void
    {
        DbStub::$getHashSingleArray = static fn (string $q, array $fields, ...$p): array => [];

        $row = $this->normalize(['id' => 2, 'title' => 'Grecia', 'destinations' => [['id' => 7, 'name' => 'Atena'], ['id' => 8, 'name' => 'Roma']]]);

        self::assertSame('[7,8]', $row['destination_ids']);
        self::assertSame('Atena, Roma', $row['destination_names']);
    }

    public function testACircuitWithoutDestinationsKeepsNullColumns(): void
    {
        $row = $this->normalize(['id' => 3, 'title' => 'Fara', 'destinations' => []]);

        self::assertNull($row['destination_ids']);
        self::assertNull($row['destination_names']);
    }

    public function testCircuitsAreNotFilteredByTheDestinationWhitelist(): void
    {
        $sync = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/CircuitSyncService.php');

        self::assertStringNotContainsString('getAllowedDestinationIds', $sync, 'the whitelist is for hotels; every active circuit syncs');
    }

    public function testTheFullDescriptionIsTheSummaryThenTheDescription(): void
    {
        $html = CircuitProductFactory::fullDescription("Pregateste-te.\nO aventura \"de 7 zile\".", 'Iti spunem <b>Bine ai venit</b>.');

        self::assertSame(
            "<p>Pregateste-te.<br>\nO aventura &quot;de 7 zile&quot;.</p>\n<p>Iti spunem &lt;b&gt;Bine ai venit&lt;/b&gt;.</p>",
            $html,
        );
        self::assertSame('<p>Doar descrierea</p>', CircuitProductFactory::fullDescription('  ', 'Doar descrierea'), 'an empty part is left out');
        self::assertSame('', CircuitProductFactory::fullDescription('', ''));
    }
}
