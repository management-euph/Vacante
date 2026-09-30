<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\SphinxCacheRepository;
use Tygh\Addons\SphinxHolidays\Services\CacheService;
use Tygh\Addons\TravelCore\Contracts\CacheServiceInterface;

/**
 * The sphinx cache is an instance service implementing the shared
 * travel_core cache contract (previously an all-static API — the last
 * documented hold-out of the contract). Behavior against an in-memory
 * repository double: TTL expiry, array-only storage, delete/cleanup
 * reporting, and the deterministic search key.
 */
final class SphinxCacheServiceTest extends TestCase
{
    /** @var SphinxCacheRepository&object{rows: array<string, array{cache_data: string, expires_at: int}>} */
    private SphinxCacheRepository $repo;
    private CacheService $cache;

    protected function setUp(): void
    {
        $this->repo = new class extends SphinxCacheRepository {
            /** @var array<string, array{cache_data: string, expires_at: int}> */
            public array $rows = [];

            public function findByKey(string $key): ?array
            {
                return $this->rows[$key] ?? null;
            }

            public function upsert(string $key, string $data, int $expiresAt): void
            {
                $this->rows[$key] = ['cache_data' => $data, 'expires_at' => $expiresAt];
            }

            public function deleteByKey(string $key): int
            {
                $existed = isset($this->rows[$key]);
                unset($this->rows[$key]);

                return $existed ? 1 : 0;
            }

            public function deleteExpired(): int
            {
                $before = count($this->rows);
                $this->rows = array_filter($this->rows, static fn (array $r): bool => $r['expires_at'] >= time());

                return $before - count($this->rows);
            }
        };
        $this->cache = new CacheService($this->repo);
    }

    public function testImplementsTheSharedTravelCoreContract(): void
    {
        self::assertInstanceOf(CacheServiceInterface::class, $this->cache);
    }

    public function testSetGetRoundTripsAnArray(): void
    {
        self::assertTrue($this->cache->set('k', ['results' => [1, 2]], 60));
        self::assertSame(['results' => [1, 2]], $this->cache->get('k'));
    }

    public function testExpiredEntriesReadAsNull(): void
    {
        $this->repo->rows['old'] = ['cache_data' => '{"a":1}', 'expires_at' => time() - 5];
        self::assertNull($this->cache->get('old'));
    }

    public function testNonArrayValuesAreRejected(): void
    {
        // This backend stores JSON rows of search-result arrays only.
        self::assertFalse($this->cache->set('k', 'scalar', 60));
        self::assertFalse($this->cache->set('k', null, 60));
        self::assertNull($this->cache->get('k'));
    }

    public function testDeleteReportsWhetherAnEntryWasRemoved(): void
    {
        $this->cache->set('k', ['a' => 1], 60);
        self::assertTrue($this->cache->delete('k'));
        self::assertFalse($this->cache->delete('k'));
    }

    public function testCleanupReturnsTheRemovedCount(): void
    {
        $this->repo->rows = [
            'live' => ['cache_data' => '{}', 'expires_at' => time() + 100],
            'dead1' => ['cache_data' => '{}', 'expires_at' => time() - 1],
            'dead2' => ['cache_data' => '{}', 'expires_at' => time() - 2],
        ];
        self::assertSame(2, $this->cache->cleanup());
        self::assertArrayHasKey('live', $this->repo->rows);
    }

    public function testBuildSearchKeyIsOrderInsensitiveAndVersioned(): void
    {
        $a = CacheService::buildSearchKey(['x' => 1, 'y' => 2]);
        $b = CacheService::buildSearchKey(['y' => 2, 'x' => 1]);
        self::assertSame($a, $b);
        self::assertStringStartsWith('search:v2:', $a);
        self::assertNotSame($a, CacheService::buildSearchKey(['x' => 1, 'y' => 3]));
    }

    public function testCallSitesGoThroughTheContainerInstance(): void
    {
        // No static get/set/delete/cleanup calls remain — only the pure
        // buildSearchKey() helper may be called statically.
        $root = dirname(__DIR__, 3);
        foreach ([
            'controllers/frontend/sphinx_booking/search.php',
            'controllers/frontend/sphinx_booking/search_poll.php',
            'controllers/frontend/sphinx_booking/package_search.php',
        ] as $rel) {
            $src = (string) file_get_contents($root . '/' . $rel);
            foreach (['get', 'set', 'delete', 'cleanup'] as $method) {
                self::assertStringNotContainsString(
                    'CacheService::' . $method . '(',
                    $src,
                    "{$rel} must use Container::getCacheService()->{$method}()",
                );
            }
        }
    }
}
