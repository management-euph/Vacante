<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Cron\Commands\GeocodeAddressesCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\Geocoding\NominatimClient;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Registry;

/**
 * Behavioural coverage for the geocode_addresses cron: the opt-in guards
 * (disabled setting / missing contact email do NOT touch the DB or the
 * network), the eligible-rows selection (real coordinates; never tried, or
 * no street and tried more than 30 days ago — a hotel with a street is not
 * asked again), the stamp-even-without-street rule that makes re-runs
 * naturally resumable, the 1 req/s pacing between (not before) requests,
 * force=1 redoing every hotel over the following runs, the read-only status
 * view, and the abort-on-transport-failure behaviour that leaves remaining
 * hotels unstamped for the next run.
 */
#[CoversClass(GeocodeAddressesCommand::class)]
final class GeocodeAddressesCommandTest extends TestCase
{
    /** @var list<string> */
    private array $out = [];

    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    private int $sleeps = 0;

    /** The force=1 "redo before" time as the command stored it ('' = none). */
    private string $redoBefore = '';

    protected function setUp(): void
    {
        DbStub::reset();
        $this->out = [];
        $this->queries = [];
        $this->sleeps = 0;
        $this->redoBefore = '';

        // Geocoding is configured in Travel Core only — one switch, one
        // contact, one endpoint for every provider addon (the Nominatim
        // policy caps requests per APPLICATION).
        Registry::set('addons.travel_core.geocoding_enabled', 'Y');
        Registry::set('addons.travel_core.geocoding_contact_email', 'ops@example.com');
        Registry::set('addons.novoton_holidays', []);
        ConfigProvider::reset();

        DbStub::$query = function (string $query, ...$params): int {
            $this->queries[] = [$query, $params];

            return 1;
        };
        DbStub::$getField = static fn (string $query, ...$params): int => 0;
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        Registry::set('addons.novoton_holidays', []);
        Registry::set('addons.travel_core.geocoding_enabled', 'N');
        Registry::set('addons.travel_core.geocoding_contact_email', '');
        ConfigProvider::reset();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function command(array $params = []): GeocodeAddressesCommand
    {
        $cmd = (new \ReflectionClass(GeocodeAddressesCommand::class))->newInstanceWithoutConstructor();

        $paramsProp = new \ReflectionProperty(\Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand::class, 'params');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($cmd, $params);

        $startProp = new \ReflectionProperty(\Tygh\Addons\TravelCore\Cron\AbstractCronCommand::class, 'startTime');
        $startProp->setAccessible(true);
        $startProp->setValue($cmd, microtime(true));

        $cmd->setOutputCallback(function (string $msg, bool $addNewline = true): void {
            $this->out[] = $msg;
        });
        $cmd->setSleeper(function (int $seconds): void {
            $this->sleeps += $seconds;
        });
        $cmd->setRedoStore(
            fn (): string => $this->redoBefore,
            function (string $before): void {
                $this->redoBefore = $before;
            },
        );

        return $cmd;
    }

    /**
     * Real client over a queued fake transport (the class is final).
     *
     * @param list<array{int, string}> $responses
     */
    private function clientWithResponses(array $responses): NominatimClient
    {
        $i = 0;

        return new NominatimClient(
            static function (string $url, string $userAgent) use (&$i, $responses): array {
                $response = $responses[$i] ?? [200, '{}'];
                $i++;

                return $response;
            },
            'https://nominatim.example.org',
            'ops@example.com',
        );
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function hotelUpdates(): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (array $q): bool => str_starts_with($q[0], 'UPDATE ?:novoton_hotels'),
        ));
    }

    public function testModeRegistrationPins(): void
    {
        self::assertSame(['geocode_addresses'], GeocodeAddressesCommand::getModes());
        self::assertStringContainsString('Nominatim', GeocodeAddressesCommand::getDescription());
    }

    public function testDisabledSettingExitsWithoutTouchingAnything(): void
    {
        Registry::set('addons.travel_core.geocoding_enabled', 'N');
        ConfigProvider::reset();

        $result = $this->command()->execute();

        self::assertFalse($result['success']);
        self::assertSame([], $this->queries);
        self::assertStringContainsString('disabled', implode("\n", $this->out));
    }

    public function testMissingContactEmailRefusesToRun(): void
    {
        Registry::set('addons.travel_core.geocoding_enabled', 'Y');
        Registry::set('addons.travel_core.geocoding_contact_email', '');
        ConfigProvider::reset();

        $result = $this->command()->execute();

        self::assertFalse($result['success']);
        self::assertSame([], $this->queries);
        self::assertStringContainsString('contact email', implode("\n", $this->out));
    }

    public function testRunStampsBothOutcomesAndPacesBetweenRequests(): void
    {
        $selects = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$selects): array {
            $selects[] = $query;

            return [
                ['hotel_id' => '4535', 'hotel_name' => 'Monaco Hotel', 'latitude' => '41.2818', 'longitude' => '19.4416'],
                ['hotel_id' => '4536', 'hotel_name' => 'Mare Hotel', 'latitude' => '41.3', 'longitude' => '19.45'],
            ];
        };

        $cmd = $this->command(['limit' => 5]);
        $cmd->setClient($this->clientWithResponses([
            [200, (string) json_encode(['address' => ['road' => 'Rruga e Plazhit', 'house_number' => '3']])],
            [200, (string) json_encode(['address' => ['city' => 'Golem']])],
        ]));

        $result = $cmd->execute();

        // Selection pins: real coordinates; never tried, or tried without a
        // street long enough ago. A hotel with a street is never selected.
        self::assertCount(1, $selects);
        self::assertStringContainsString('latitude IS NOT NULL', $selects[0]);
        self::assertStringContainsString('latitude <> 0', $selects[0]);
        self::assertStringContainsString("geocoded_at IS NULL OR ((street_address IS NULL OR street_address = '') AND geocoded_at < DATE_SUB(NOW(), INTERVAL ?i DAY))", $selects[0]);
        self::assertStringContainsString('ORDER BY geocoded_at, hotel_id LIMIT ?i', $selects[0], 'never-tried first, then the oldest attempts');

        $updates = $this->hotelUpdates();
        self::assertCount(2, $updates);

        // Street found → stored and stamped.
        self::assertStringContainsString('SET street_address = ?s, geocoded_at = NOW()', $updates[0][0]);
        self::assertSame(['Rruga e Plazhit 3', '4535'], $updates[0][1]);

        // No street → still stamped (resumability), street stays NULL.
        self::assertStringContainsString('SET street_address = NULL, geocoded_at = NOW()', $updates[1][0]);
        self::assertSame(['4536'], $updates[1][1]);

        // Paced BETWEEN requests only: 2 requests → 1 second slept.
        self::assertSame(1, $this->sleeps);

        self::assertTrue($result['success']);
        self::assertSame(1, $result['stats']['geocoded'] ?? null);
        self::assertSame(1, $result['stats']['no_street'] ?? null);
    }

    public function testTransportFailureAbortsLeavingRowsUnstamped(): void
    {
        DbStub::$getArray = static fn (string $query, ...$params): array => [
            ['hotel_id' => '4535', 'hotel_name' => 'Monaco Hotel', 'latitude' => '41.2818', 'longitude' => '19.4416'],
            ['hotel_id' => '4536', 'hotel_name' => 'Mare Hotel', 'latitude' => '41.3', 'longitude' => '19.45'],
        ];

        $cmd = $this->command();
        $cmd->setClient($this->clientWithResponses([[429, '']]));

        $result = $cmd->execute();

        self::assertFalse($result['success']);
        self::assertSame([], $this->hotelUpdates());
        self::assertStringContainsString('ABORT', implode("\n", $this->out));
    }

    public function testAHotelTriedWithoutAStreetIsTriedAgainAfter30Days(): void
    {
        $params = [];
        DbStub::$getArray = static function (string $query, ...$p) use (&$params): array {
            $params = $p;

            return [];
        };

        $this->command(['limit' => 50])->execute();

        self::assertSame([GeocodeAddressesCommand::RETRY_AFTER_DAYS, 50], $params);
        self::assertSame(30, GeocodeAddressesCommand::RETRY_AFTER_DAYS);
    }

    /**
     * force=1 used to clear every stamp with one UPDATE, losing which hotels
     * had been tried without a street. It now records "redo every hotel
     * stamped before now" (the database's clock, as the stamps) and each
     * run, the scheduled ones too, takes the next batch, streets included.
     */
    public function testForceRedoesEveryHotelOverTheFollowingRuns(): void
    {
        DbStub::$getField = static fn (string $query, ...$params): mixed => $query === 'SELECT NOW()' ? '2026-10-21 02:00:00' : 1;
        $selects = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$selects): array {
            $selects[] = [$query, $params];

            return [['hotel_id' => '476', 'hotel_name' => 'Admiral', 'latitude' => '43.28', 'longitude' => '28.04']];
        };

        $cmd = $this->command(['force' => '1', 'limit' => 300]);
        $cmd->setClient($this->clientWithResponses([[200, (string) json_encode(['address' => ['road' => 'Zlatni Pyasatsi']])]]));
        $result = $cmd->execute();

        self::assertTrue($result['success']);
        self::assertSame('2026-10-21 02:00:00', $this->redoBefore, 'kept for the next runs (1 hotel still due)');
        self::assertStringContainsString('(geocoded_at IS NULL OR geocoded_at < ?s)', $selects[0][0]);
        self::assertStringNotContainsString('street_address', $selects[0][0], 'hotels with a street are redone too');
        self::assertSame(['2026-10-21 02:00:00', 300], $selects[0][1]);
        self::assertSame([], array_filter($this->hotelUpdates(), static fn (array $q): bool => !str_contains($q[0], 'WHERE hotel_id = ?s')), 'no mass UPDATE of the stamps');

        // A later scheduled run (no force) continues the same redo.
        $next = $this->command();
        $next->setClient($this->clientWithResponses([[200, '{}']]));
        $next->execute();
        self::assertSame(['2026-10-21 02:00:00', 300], $selects[1][1]);
    }

    public function testTheRedoEndsWhenNoHotelIsLeft(): void
    {
        $this->redoBefore = '2026-10-21 02:00:00';
        DbStub::$getArray = static fn (string $query, ...$params): array => [];

        $result = $this->command()->execute();

        self::assertTrue($result['success']);
        self::assertSame('', $this->redoBefore);
        self::assertStringContainsString('The re-geocode of every hotel is complete.', implode("\n", $this->out));
    }

    public function testStatusIsReadOnly(): void
    {
        $result = $this->command(['status' => '1'])->execute();

        self::assertTrue($result['success']);
        self::assertSame([], $this->hotelUpdates());
        self::assertArrayHasKey('pending', $result['stats']);
        self::assertArrayHasKey('attempted', $result['stats']);
        self::assertArrayHasKey('with_street', $result['stats']);
        self::assertArrayHasKey('tried_no_street', $result['stats']);
        self::assertStringContainsString('Geocoding status:', implode("\n", $this->out));
        self::assertStringContainsString('With a street address', implode("\n", $this->out));
    }
}
