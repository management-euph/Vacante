<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CircuitImageQueue;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Step 2 of the circuit page plan: a circuit's photos go through the hotels'
 * image queue to its product's CS-Cart images. Circuit products used to get
 * no photo at all.
 */
final class CircuitImageQueueTest extends TestCase
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        DbStub::$query = function (string $query, mixed ...$params): int {
            $this->queries[] = [$query, array_values($params)];

            return 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** @param list<string> $known @param bool $hasMain */
    private function store(array $known, bool $hasMain): void
    {
        DbStub::$getFields = static fn (): array => $known;
        DbStub::$getField = static fn (): int => $hasMain ? 1 : 0;
    }

    /** @return list<array{0: string, 1: list<mixed>}> */
    private function inserts(): array
    {
        return array_values(array_filter($this->queries, static fn (array $q): bool => str_contains($q[0], 'INSERT IGNORE INTO ?:sphinx_image_sync_queue')));
    }

    public function testThePhotosAreTheKeptListElseTheOneImage(): void
    {
        self::assertSame(
            ['https://cdn.example/1.jpg', 'https://cdn.example/2.jpg'],
            CircuitImageQueue::photoUrls('{"images":["https://cdn.example/1.jpg","https://cdn.example/2.jpg","https://cdn.example/1.jpg","javascript:x"]}', 'https://cdn.example/main.jpg'),
        );
        self::assertSame(['https://cdn.example/main.jpg'], CircuitImageQueue::photoUrls('{"included":[]}', 'https://cdn.example/main.jpg'), 'no list kept: the circuit image');
        self::assertSame([], CircuitImageQueue::photoUrls(null, ''));
    }

    public function testNewPhotosAreQueuedTheFirstAsMainWhenTheProductHasNone(): void
    {
        $this->store([], false);

        $new = (new CircuitImageQueue())->queue(1, 1649, ['https://cdn.example/1.jpg', 'https://cdn.example/2.jpg'], false, 100);

        self::assertSame(2, $new);
        [$first, $second] = $this->inserts();
        self::assertSame(['circuit:1', 1649, 'https://cdn.example/1.jpg', 1, 100, 100], $first[1], 'keyed apart from hotel ids');
        self::assertSame(0, $second[1][3]);
        self::assertStringContainsString("DELETE FROM ?:sphinx_image_sync_queue WHERE hotel_id = ?s AND product_id <> ?i", $this->queries[0][0], 'rows of a deleted product go, so the new one gets the photos');
    }

    public function testOnlyPhotosNotQueuedBeforeAreAddedAndNeverAsASecondMain(): void
    {
        $this->store(['https://cdn.example/1.jpg'], true);

        $new = (new CircuitImageQueue())->queue(1, 1649, ['https://cdn.example/1.jpg', 'https://cdn.example/3.jpg'], false, 100);

        self::assertSame(1, $new);
        self::assertCount(1, $this->inserts());
        self::assertSame('https://cdn.example/3.jpg', $this->inserts()[0][1][2]);
        self::assertSame(0, $this->inserts()[0][1][3], 'the product has a main image');
        self::assertSame([], array_filter($this->queries, static fn (array $q): bool => str_contains($q[0], "status = 'failed'")), 'failed ones only with force');
    }

    public function testForceRetriesTheFailedDownloads(): void
    {
        $this->store(['https://cdn.example/1.jpg'], true);

        (new CircuitImageQueue())->queue(1, 1649, ['https://cdn.example/1.jpg'], true, 100);

        $retry = array_values(array_filter($this->queries, static fn (array $q): bool => str_contains($q[0], "WHERE hotel_id = ?s AND status = 'failed'")));
        self::assertCount(1, $retry);
        self::assertSame([100, 'circuit:1'], $retry[0][1]);
    }

    public function testNothingWithoutAProductOrPhotos(): void
    {
        self::assertSame(0, (new CircuitImageQueue())->queue(1, 0, ['https://cdn.example/1.jpg'], false, 100));
        self::assertSame(0, (new CircuitImageQueue())->queue(1, 1649, [], false, 100));
        self::assertSame([], $this->queries);
    }
}
