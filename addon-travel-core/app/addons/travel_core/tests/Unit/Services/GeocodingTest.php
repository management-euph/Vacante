<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Exceptions\GeocodeTransportException;
use Tygh\Addons\TravelCore\Services\Geocoding\GeocodeBacklogRunner;
use Tygh\Addons\TravelCore\Services\Geocoding\NominatimClient;
use Tygh\Addons\TravelCore\Services\Geocoding\ReverseGeocodeResult;

/**
 * The shared reverse-geocoding stack.
 *
 * It lives in travel_core rather than in each provider addon because the OSM
 * Nominatim usage policy limits an APPLICATION to one request per second —
 * two addons pacing themselves independently would double the real rate and
 * risk the shop's IP being blocked. GeocodeBacklogRunner therefore owns the
 * loop and the sleep for every caller.
 */
#[CoversClass(NominatimClient::class)]
#[CoversClass(ReverseGeocodeResult::class)]
#[CoversClass(GeocodeBacklogRunner::class)]
final class GeocodingTest extends TestCase
{
    /**
     * Verbatim response from the live API for Adalya Port Hotel
     * (36.885640, 30.702412) — the hotel that motivated the whole ladder.
     */
    private const string ADALYA_BODY = '{"address":{"office":"Consulatul onorific al Franței",'
        . '"house_number":"72","road":"İskele Caddesi","suburb":"Selçuk Mahallesi",'
        . '"city":"Antalya","town":"Muratpaşa","province":"Antalya",'
        . '"region":"Akdeniz Bölgesi","postcode":"07100","country":"Turcia","country_code":"tr"}}';

    private function client(int $code = 200, string $body = self::ADALYA_BODY): NominatimClient
    {
        return new NominatimClient(
            static fn (string $url, string $ua): array => [$code, $body],
            'https://nominatim.example.test',
            'ops@example.test',
            'TestApp/1.0',
        );
    }

    public function testAdalyaCoordinatesYieldTheCityAndProvinceNotTheMacroRegion(): void
    {
        $out = $this->client()->reverseDetailed(36.885640, 30.702412);

        self::assertSame('Antalya', $out->city);
        // `province` must win over `region` ("Akdeniz Bölgesi" is the
        // Mediterranean macro-region and matches no Sphinx region node).
        self::assertSame('Antalya', $out->region);
        self::assertSame('İskele Caddesi 72', $out->street);
        self::assertSame('07100', $out->postcode);
        self::assertSame('TR', $out->countryCode);
        self::assertFalse($out->isEmpty());
    }

    public function testReverseKeepsTheStreetOnlyContractForExistingCallers(): void
    {
        self::assertSame('İskele Caddesi 72', $this->client()->reverse(36.885640, 30.702412));
        // Nothing street-like → null, as novoton's geocode cron expects.
        self::assertNull($this->client(200, '{"address":{"city":"Antalya"}}')->reverse(1.0, 2.0));
    }

    public function testCityFallsBackThroughTheSettlementKeys(): void
    {
        $out = ReverseGeocodeResult::fromNominatimAddress(['village' => 'Öludeniz', 'county' => 'Fethiye']);

        self::assertSame('Öludeniz', $out->city);
        self::assertSame('Fethiye', $out->region);
    }

    public function testUserAgentCarriesTheAppNameAndContact(): void
    {
        self::assertSame('TestApp/1.0 (ops@example.test)', $this->client()->getUserAgent());
    }

    public function testUserAgentWithoutContactHasNoEmptyParens(): void
    {
        $client = new NominatimClient(
            static fn (string $u, string $a): array => [200, '{}'],
            'https://nominatim.example.test',
            '',
            'NovotonHolidays/1.0',
        );

        self::assertSame('NovotonHolidays/1.0', $client->getUserAgent());
    }

    // ── Street extraction (moved here with the client; these rules feed
    //    novoton's PDP location line and sphinx's street_address column) ──

    /**
     * @param array<string, mixed> $address
     */
    private function street(array $address): ?string
    {
        return $this->client(200, (string) json_encode(['address' => $address]))->reverse(41.2818, 19.4416);
    }

    public function testRoadWithHouseNumberUsesRomanianPostalOrder(): void
    {
        self::assertSame(
            'Strada Mihai Eminescu 12',
            $this->street(['road' => 'Strada Mihai Eminescu', 'house_number' => '12', 'city' => 'Golem']),
        );
    }

    public function testRoadOnly(): void
    {
        self::assertSame('Rruga e Plazhit', $this->street(['road' => 'Rruga e Plazhit']));
    }

    public function testPedestrianFallbackWhenNoRoad(): void
    {
        self::assertSame('Promenada Mamaia', $this->street(['pedestrian' => 'Promenada Mamaia']));
    }

    public function testNeighbourhoodFallbackWhenNoWayAtAll(): void
    {
        self::assertSame('Sunny Beach Central', $this->street(['neighbourhood' => 'Sunny Beach Central']));
    }

    public function testNothingStreetLikeGivesNull(): void
    {
        self::assertNull($this->street(['city' => 'Golem', 'country' => 'Albania', 'postcode' => '2504']));
    }

    public function testRequestShapeCarriesPolicyRequirements(): void
    {
        $urls = [];
        $client = new NominatimClient(
            static function (string $url, string $ua) use (&$urls): array {
                $urls[] = $url;
                return [200, '{"address":{}}'];
            },
            'https://nominatim.example.org',
            'ops@example.com',
        );
        $client->reverse(41.2818, 19.4416);

        self::assertCount(1, $urls);
        self::assertStringStartsWith('https://nominatim.example.org/reverse?format=jsonv2', $urls[0]);
        self::assertStringContainsString('lat=41.2818000', $urls[0]);
        self::assertStringContainsString('lon=19.4416000', $urls[0]);
        self::assertStringContainsString('zoom=18', $urls[0]);
        // Romanian first, then English: "Kiten", not "Китен".
        self::assertStringContainsString('accept-language=ro,en', $urls[0]);
    }

    public function testCyrillicNamesComeBackInLatinLetters(): void
    {
        $out = ReverseGeocodeResult::fromNominatimAddress([
            'road' => 'Нептун', 'house_number' => '26', 'town' => 'Китен', 'state' => 'Regiunea Burgas',
        ]);

        self::assertSame('Neptun 26', $out->street);
        self::assertSame('Kiten', $out->city);
        self::assertSame('Regiunea Burgas', $out->region);
    }

    public function testRunnerAsksAtStreetLevelWhenTheBuildingHasNoRoad(): void
    {
        $urls = [];
        $answers = [
            '{"address":{"leisure":"Sunny Beach","town":"Kiten","state":"Regiunea Burgas"}}',
            '{"address":{"road":"Нептун","town":"Kiten","state":"Regiunea Burgas"}}',
        ];
        $client = new NominatimClient(
            static function (string $url, string $ua) use (&$urls, $answers): array {
                $urls[] = $url;

                return [200, $answers[count($urls) - 1] ?? '{}'];
            },
            'https://nominatim.example.test',
        );
        $sleeps = [];
        $runner = new GeocodeBacklogRunner($client, static function (int $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });

        $persisted = [];
        $runner->run(
            [['id' => 'A', 'lat' => 42.2316, 'lng' => 27.7796, 'label' => 'A']],
            static function (string $id, ?ReverseGeocodeResult $r) use (&$persisted): void {
                $persisted[$id] = $r;
            },
        );

        self::assertCount(2, $urls);
        self::assertStringContainsString('zoom=18', $urls[0]);
        self::assertStringContainsString('zoom=17', $urls[1]);
        self::assertSame([1], $sleeps, 'the retry keeps the one-request-per-second pace');
        self::assertSame('Neptun', $persisted['A']?->street);
        self::assertSame('Kiten', $persisted['A']?->city);
    }

    public function testRunnerDoesNotRetryWhenTheBuildingNamesAStreet(): void
    {
        $calls = 0;
        $client = new NominatimClient(
            static function (string $url, string $ua) use (&$calls): array {
                $calls++;

                return [200, self::ADALYA_BODY];
            },
            'https://nominatim.example.test',
        );
        (new GeocodeBacklogRunner($client, static function (int $s): void {
        }))->run([['id' => 'A', 'lat' => 1.0, 'lng' => 2.0, 'label' => 'A']], static function (): void {
        });

        self::assertSame(1, $calls);
    }

    public function testNon200IsAStopSignal(): void
    {
        $this->expectException(GeocodeTransportException::class);
        $this->expectExceptionMessage('HTTP 429');
        $this->client(429, '{}')->reverse(41.0, 19.0);
    }

    public function testEachAddonIdentifiesItselfWhileSharingOnePacer(): void
    {
        // The policy wants the APPLICATION identified, so each cron passes its
        // own token — but the 1 req/s ceiling is per application, so BOTH must
        // go through the shared runner. A second hand-rolled loop anywhere
        // silently doubles the real request rate against the public instance.
        $novoton = (string) file_get_contents(
            dirname(__DIR__, 7)
            . '/addon-novoton-holidays/app/addons/novoton_holidays/src/Cron/Commands/GeocodeAddressesCommand.php',
        );
        self::assertStringContainsString("'NovotonHolidays/1.0'", $novoton);
        self::assertStringContainsString('TravelCore\Services\Geocoding\NominatimClient', $novoton);
        self::assertStringContainsString('GeocodeBacklogRunner', $novoton);
        // …and must not regrow its own pacing loop.
        self::assertStringNotContainsString('max 1 request/second', $novoton);
        self::assertStringNotContainsString('$sleeper(1)', $novoton);

        $sphinx = (string) file_get_contents(
            dirname(__DIR__, 7)
            . '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Cron/Commands/GeocodeHotelsCommand.php',
        );
        self::assertStringContainsString("'SphinxHolidays/1.0'", $sphinx);
        self::assertStringContainsString('GeocodeBacklogRunner', $sphinx);
        self::assertStringNotContainsString('$sleeper(1)', $sphinx);
    }

    public function testTransportFailuresRaiseSoARunCanAbort(): void
    {
        $this->expectException(GeocodeTransportException::class);
        $this->client(503, '')->reverseDetailed(1.0, 2.0);
    }

    public function testUnparseableBodyRaises(): void
    {
        $this->expectException(GeocodeTransportException::class);
        $this->client(200, 'not json')->reverseDetailed(1.0, 2.0);
    }

    public function testRunnerPacesAtOneRequestPerSecondBetweenRows(): void
    {
        $sleeps = [];
        $runner = new GeocodeBacklogRunner(
            $this->client(),
            static function (int $s) use (&$sleeps): void {
                $sleeps[] = $s;
            },
        );

        $persisted = [];
        $stats = $runner->run(
            [
                ['id' => 'A', 'lat' => 1.0, 'lng' => 2.0, 'label' => 'Hotel A'],
                ['id' => 'B', 'lat' => 3.0, 'lng' => 4.0, 'label' => 'Hotel B'],
                ['id' => 'C', 'lat' => 5.0, 'lng' => 6.0, 'label' => 'Hotel C'],
            ],
            static function (string $id, ?ReverseGeocodeResult $r) use (&$persisted): void {
                $persisted[$id] = $r?->city;
            },
        );

        self::assertSame([1, 1], $sleeps, 'one second between rows, none before the first');
        self::assertSame(3, $stats['found']);
        self::assertSame(['A' => 'Antalya', 'B' => 'Antalya', 'C' => 'Antalya'], $persisted);
    }

    public function testRunnerStampsRowsWhereNothingWasFound(): void
    {
        $runner = new GeocodeBacklogRunner($this->client(200, '{"address":{}}'), static function (int $s): void {
        });

        $persisted = [];
        $stats = $runner->run(
            [['id' => 'A', 'lat' => 1.0, 'lng' => 2.0, 'label' => 'A']],
            static function (string $id, ?ReverseGeocodeResult $r) use (&$persisted): void {
                $persisted[$id] = $r;
            },
        );

        self::assertSame(1, $stats['empty']);
        // persist() is still called (with null) so the row is not retried forever.
        self::assertArrayHasKey('A', $persisted);
        self::assertNull($persisted['A']);
    }

    public function testRunnerAbortsTheWholeRunOnATransportFailure(): void
    {
        $calls = 0;
        $client = new NominatimClient(
            static function (string $url, string $ua) use (&$calls): array {
                $calls++;
                return $calls === 1 ? [200, self::ADALYA_BODY] : [429, ''];
            },
            'https://nominatim.example.test',
            'ops@example.test',
        );
        $runner = new GeocodeBacklogRunner($client, static function (int $s): void {
        });

        $persisted = [];
        $stats = $runner->run(
            [
                ['id' => 'A', 'lat' => 1.0, 'lng' => 2.0, 'label' => 'A'],
                ['id' => 'B', 'lat' => 3.0, 'lng' => 4.0, 'label' => 'B'],
                ['id' => 'C', 'lat' => 5.0, 'lng' => 6.0, 'label' => 'C'],
            ],
            static function (string $id) use (&$persisted): void {
                $persisted[] = $id;
            },
        );

        self::assertTrue($stats['aborted'], 'a rate-limit block must stop the run, not burn the backlog');
        self::assertSame(['A'], $persisted, 'rows after the failure stay unstamped for the next run');
        self::assertSame(2, $calls, 'no further requests after the failure');
    }
}
