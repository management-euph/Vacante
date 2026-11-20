<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\CacheRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\SecurityService;

/**
 * The limits on searches and bookings were kept per session: a client that
 * sent no cookie got a new session, and so a fresh allowance, on every
 * request. They now hold per visitor address too.
 */
final class VisitorLimitTest extends TestCase
{
    /** @var array<string, string>|null */
    private ?array $server = null;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = (array) $this->server;
    }

    private static function security(): SecurityService
    {
        $store = new class () implements CacheRepositoryInterface {
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

            public function deleteByKey(string $key): void
            {
                unset($this->rows[$key]);
            }

            public function deleteAll(?string $prefix = null): int
            {
                return 0;
            }

            public function deleteExpired(): int
            {
                return 0;
            }

            public function countAll(): int
            {
                return count($this->rows);
            }

            public function countExpired(): int
            {
                return 0;
            }
        };

        return new SecurityService($store);
    }

    public function testAnAddressIsLimitedWhateverItsCookie(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $security = self::security();

        self::assertTrue($security->checkVisitorLimit('search', 2, 60));
        self::assertTrue($security->checkVisitorLimit('search', 2, 60));
        self::assertFalse($security->checkVisitorLimit('search', 2, 60), 'the third within the minute');

        $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
        self::assertTrue($security->checkVisitorLimit('search', 2, 60), 'another address has its own allowance');
    }

    public function testAForwardedForHeaderIsNotTheAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';

        self::assertSame('203.0.113.7', SecurityService::visitorIp());
    }

    public function testWithoutAnAddressNothingIsLimited(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $security = self::security();

        self::assertSame('', SecurityService::visitorIp());
        self::assertTrue($security->checkVisitorLimit('search', 1, 60));
        self::assertTrue($security->checkVisitorLimit('search', 1, 60));
    }

    public function testALoggedInVisitorIsLimitedByAccount(): void
    {
        $backup = $_SESSION ?? null;
        $_SESSION = ['auth' => ['user_id' => 42]];
        try {
            self::assertSame('42', SecurityService::sessionIdentity());
            $_SESSION = ['auth' => ['user_id' => 0]];
            self::assertSame((string) session_id(), SecurityService::sessionIdentity(), 'a guest: its session');
        } finally {
            $_SESSION = $backup;
        }
    }
}
