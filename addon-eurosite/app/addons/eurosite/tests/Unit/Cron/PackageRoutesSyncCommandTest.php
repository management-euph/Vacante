<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\Cron\Commands\PackageRoutesSyncCommand;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;
use Tygh\Addons\Eurosite\EurositeXmlParser;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Tests\Support\DbStub;
use Tygh\Addons\Eurosite\Tests\Support\ScriptedTransport;

/**
 * The `package_routes` job asks for the plane routes and the coach routes,
 * each on its own, and keeps each transport's routes in the table; a
 * transport whose call fails keeps the routes it had.
 */
final class PackageRoutesSyncCommandTest extends TestCase
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $queries = [];

    #[\Override]
    protected function setUp(): void
    {
        Container::reset();
        DbStub::reset();
        $this->queries = [];
        DbStub::$query = function (string $sql, mixed ...$params): int {
            $this->queries[] = ['sql' => $sql, 'params' => array_values($params)];

            return 1;
        };
        DbStub::$getField = static fn (string $sql): string => str_contains($sql, 'LAST_INSERT_ID') ? '41' : '0';
    }

    #[\Override]
    protected function tearDown(): void
    {
        Container::reset();
        DbStub::reset();
    }

    public function testBothTransportsAreAskedAndKept(): void
    {
        $transport = $this->api([
            'plane' => (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/package_routes_response.xml'),
            'bus' => self::routes('<Country><CountryCode>GR</CountryCode><CountryName>Greece</CountryName><Destinations>'
                . '<Destination><CityCode>GRTHS</CityCode><CityName>Thassos</CityName><Departures><Departure>'
                . '<CountryCode>RO</CountryCode><CountryName>Romania</CountryName><CityCode>ROBCH1</CityCode><CityName>Bucharest</CityName>'
                . '<Dates><Date>2026-07-03</Date></Dates></Departure></Departures></Destination></Destinations></Country>'),
        ]);

        $result = (new PackageRoutesSyncCommand())->execute();

        self::assertTrue($result['success']);
        $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
        self::assertSame([6, 6, 0, 5, 1], [$stats['total'], $stats['synced'], $stats['failed'], $stats['plane'], $stats['bus']]);

        // Plane first, then coach: each its own request.
        self::assertCount(2, $transport->requests);
        self::assertStringContainsString('<Transport>plane</Transport>', $transport->requests[0]);
        self::assertStringContainsString('<Transport>bus</Transport>', $transport->requests[1]);

        // Each transport's routes written, then its gone ones dropped.
        $routes = array_values(array_filter($this->queries, static fn (array $q): bool => str_contains($q['sql'], 'eurosite_package_routes')));
        self::assertSame(['INSERT plane', 'DELETE plane', 'INSERT bus', 'DELETE bus'], array_map(
            static fn (array $q): string => strtok(ltrim($q['sql']), ' ') . ' ' . $q['params'][0],
            $routes,
        ));
        self::assertSame(['bus', 'GRTHS', 'ROBCH1'], array_slice($routes[2]['params'], 0, 3));

        // The run is in the sync log, as package_routes, completed.
        self::assertSame(['package_routes', 'full'], $this->queries[0]['params']);
        $complete = $this->lastSyncLogUpdate();
        self::assertSame(['completed', 6, 6, 0, ''], array_slice($complete['params'], 0, 5));
    }

    /** A failed call keeps that transport's routes; the other transport's are still refreshed. */
    public function testATransportThatFailsKeepsItsRoutes(): void
    {
        $this->api([
            'plane' => self::error('-5: Pachetele nu sunt activate'),
            'bus' => self::routes(''),
        ]);

        $result = (new PackageRoutesSyncCommand())->execute();

        // Nothing written, one transport failed: the run failed, and says why.
        self::assertFalse($result['success']);
        $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
        self::assertSame([0, 0, 1], [$stats['total'], $stats['synced'], $stats['failed']]);
        self::assertStringContainsString('plane: getPackageNVRoutesRequest failed: -5: Pachetele nu sunt activate', (string) $stats['error']);

        $routes = array_values(array_filter($this->queries, static fn (array $q): bool => str_contains($q['sql'], 'eurosite_package_routes')));
        self::assertSame([['bus', $routes[0]['params'][1]]], array_map(static fn (array $q): array => $q['params'], $routes), 'only the coach routes are replaced');
    }

    public function testNeitherTransportAnsweringFailsTheRun(): void
    {
        $this->api(['plane' => self::error('-1000: You are not authorised'), 'bus' => self::error('-1000: You are not authorised')]);

        $result = (new PackageRoutesSyncCommand())->execute();

        self::assertFalse($result['success']);
        self::assertStringContainsString('plane: ', (string) $result['error']);
        self::assertStringContainsString('; bus: ', (string) $result['error']);
        self::assertSame([], array_filter($this->queries, static fn (array $q): bool => str_contains($q['sql'], 'eurosite_package_routes')), 'no routes touched');
        self::assertSame('failed', $this->lastSyncLogUpdate()['params'][0]);
    }

    public function testItIsTheModePackageRoutes(): void
    {
        self::assertSame(['package_routes'], PackageRoutesSyncCommand::getModes());
        self::assertSame(['plane', 'bus'], PackageRoutesSyncCommand::TRANSPORTS);
    }

    /**
     * @param array<string, string> $answers transport => the answer to its request
     */
    private function api(array $answers): ScriptedTransport
    {
        $transport = new ScriptedTransport(static function (string $xml) use ($answers): string {
            preg_match('#<Transport>([a-z]+)</Transport>#', $xml, $m);

            return $answers[$m[1] ?? ''] ?? self::routes('');
        });
        (new \ReflectionProperty(Container::class, 'api'))->setValue(
            null,
            new EurositeApiClient($transport, new EurositeXmlBuilder('u', 'p', 'RO'), new EurositeXmlParser(), 'EU'),
        );

        return $transport;
    }

    /** @return array{sql: string, params: list<mixed>} */
    private function lastSyncLogUpdate(): array
    {
        $updates = array_values(array_filter($this->queries, static fn (array $q): bool => str_starts_with(ltrim($q['sql']), 'UPDATE ?:eurosite_sync_log')));
        self::assertNotSame([], $updates, 'the run is completed in the sync log');

        return $updates[count($updates) - 1];
    }

    private static function routes(string $inner): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><Response ResponseType="getPackageNVRoutesResponse">'
            . '<AuditInfo><ResponseId>1</ResponseId><RequestId>1</RequestId><ResponseTime>t</ResponseTime></AuditInfo>'
            . '<ResponseDetails><getPackageNVRoutesResponse>' . $inner . '</getPackageNVRoutesResponse></ResponseDetails></Response>';
    }

    private static function error(string $text): string
    {
        [$id, $message] = explode(': ', $text, 2);

        return '<?xml version="1.0" encoding="utf-8"?><Response ResponseType="Error">'
            . '<AuditInfo><ResponseId>1</ResponseId><RequestId>1</RequestId><ResponseTime>t</ResponseTime></AuditInfo>'
            . '<ResponseDetails><Errors><Error><ErrorId>' . $id . '</ErrorId><ErrorText>' . $message . '</ErrorText></Error></Errors></ResponseDetails></Response>';
    }
}
