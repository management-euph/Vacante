<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\ChangedHotelDetector;
use Tygh\Addons\NovotonHolidays\Helpers\SyncLoggerInterface;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Which hotels an incremental hotelinfo run asks about. It used to call
 * offers_update itself, for every country, on every five-minute run that
 * found nothing; now the offers_update job (every 12 hours, as Novoton asked
 * on 28 Oct 2026) leaves its findings on the rows and this only reads them.
 */
#[CoversClass(ChangedHotelDetector::class)]
final class ChangedHotelDetectorTest extends TestCase
{
    private const int NOW = 1_793_000_000;

    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        DbStub::$getFields = function (string $query, mixed ...$params): array {
            $this->queries[] = [$query, $params];

            return ['H5', 'H9'];
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    private function detector(): ChangedHotelDetector
    {
        return new ChangedHotelDetector($this->createStub(SyncLoggerInterface::class));
    }

    public function testItReadsTheMarksWithoutCallingNovoton(): void
    {
        self::assertSame(['H5', 'H9'], $this->detector()->detect(['GREECE'], self::NOW));

        self::assertCount(1, $this->queries);
        [$sql, $params] = $this->queries[0];
        self::assertStringContainsString('hotelinfo_synced_at IS NULL OR hotelinfo_due_at > hotelinfo_synced_at', $sql, 'never fetched, or named by offers_update since');
        self::assertSame(['GREECE'], $params[0]);
    }

    /** A hotel Novoton did not answer for waits a day, unless offers_update names it again. */
    public function testAHotelWithoutAnAnswerIsAskedAgainADayLater(): void
    {
        $this->detector()->detect(['GREECE'], self::NOW);

        [$sql, $params] = $this->queries[0];
        self::assertStringContainsString('hotelinfo_tried_at IS NULL OR hotelinfo_tried_at < hotelinfo_due_at OR hotelinfo_tried_at < ?s', $sql);
        self::assertSame(date('Y-m-d H:i:s', self::NOW - ChangedHotelDetector::RETRY_AFTER), $params[1]);
    }

    public function testNoCountriesNoQuery(): void
    {
        self::assertSame([], $this->detector()->detect([], self::NOW));
        self::assertSame([], $this->queries);
    }
}
