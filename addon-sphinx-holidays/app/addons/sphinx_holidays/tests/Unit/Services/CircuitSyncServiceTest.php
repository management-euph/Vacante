<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
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
     * A circuit's page (board 16.3) shows what the price includes and does
     * not, the things to know, the group's size and the age asked: the
     * sync used to drop all of it.
     */
    public function testThePagesDetailsAreKept(): void
    {
        $json = CircuitSyncService::details([
            'included' => [['title' => 'Zbor dus-întors', 'description' => '', 'category' => 'transport'], ['title' => '', 'description' => '']],
            'not_included' => [['title' => 'Taxele locale', 'description' => 'Se achită la hotel.', 'category' => 'other']],
            'additional_info' => [['title' => 'Documente', 'description' => 'Carte de identitate valabilă.', 'category' => 'other']],
            'age_requirments' => ['min' => 18, 'max' => 60, 'description' => null, 'is_required' => true],
            'group_size' => ['min' => 18, 'max' => 40, 'description' => null],
            'activities' => [['title' => 'Defileul Samaria', 'price' => ['amount' => 90, 'currency' => 'EUR', 'is_unknown' => false]]],
            'tags' => [['id' => 1, 'name' => 'tag']],
        ]);

        self::assertNotNull($json);
        self::assertSame([
            'included' => [['title' => 'Zbor dus-întors', 'description' => '']],
            'not_included' => [['title' => 'Taxele locale', 'description' => 'Se achită la hotel.']],
            'additional_info' => [['title' => 'Documente', 'description' => 'Carte de identitate valabilă.']],
            'group_size' => ['min' => 18, 'max' => 40],
            'age' => ['min' => 18, 'max' => 60],
        ], json_decode($json, true), 'only what the page shows; an empty item is left out');
    }

    public function testAnAgeTheCircuitDoesNotAskIsNotKept(): void
    {
        self::assertNull(CircuitSyncService::details(['age_requirments' => ['min' => 18, 'max' => 60, 'is_required' => false]]));
        self::assertNull(CircuitSyncService::details(['group_size' => ['min' => 0, 'max' => null], 'included' => []]), 'nothing to keep');
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
}
