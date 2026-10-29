<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\Commands\BackfillImagesCommand;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Behavioural coverage for the backfill_images recovery cron: the candidate
 * selection (linked products with zero images_links rows), the photo paths
 * handed to HotelImageSync as AddProductsCommand hands them (all of them, in
 * Novoton's order; the URLs are HotelImageSyncTest's), the attach invocation, the
 * pacing between products, force=1 widening to every linked product, the
 * single-hotel path, the read-only status view, and the empty-backlog no-op.
 *
 * The live API (getHotelImages) and the attach (HotelImageSync) are injected
 * as seams so the run is hermetic — the same approach
 * GeocodeAddressesCommand takes with its Nominatim client.
 */
#[CoversClass(BackfillImagesCommand::class)]
final class BackfillImagesCommandTest extends TestCase
{
    /** @var list<string> */
    private array $out = [];

    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    /** @var list<array{string, int, list<string>}> hotel id, product id, photo paths */
    private array $attachCalls = [];

    private int $sleeps = 0;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->out = [];
        $this->queries = [];
        $this->attachCalls = [];
        $this->sleeps = 0;

        DbStub::$query = function (string $query, ...$params): int {
            $this->queries[] = [$query, $params];

            return 1;
        };
        DbStub::$getField = static fn (string $query, ...$params): int => 0;
        DbStub::$getArray = static fn (string $query, ...$params): array => [];
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function command(array $params = []): BackfillImagesCommand
    {
        $cmd = (new \ReflectionClass(BackfillImagesCommand::class))->newInstanceWithoutConstructor();

        $paramsProp = new \ReflectionProperty(\Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand::class, 'params');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($cmd, $params);

        $startProp = new \ReflectionProperty(\Tygh\Addons\TravelCore\Cron\AbstractCronCommand::class, 'startTime');
        $startProp->setAccessible(true);
        $startProp->setValue($cmd, microtime(true));

        $cmd->setOutputCallback(function (string $msg, bool $addNewline = true): void {
            $this->out[] = $msg;
        });
        $cmd->setSleeper(function (int $us): void {
            $this->sleeps += $us;
        });
        $cmd->setAttacher(function (string $hid, int $pid, array $paths): int {
            $this->attachCalls[] = [$hid, $pid, $paths];

            return count($paths);
        });

        return $cmd;
    }

    /**
     * A fetcher that returns a SimpleXMLElement whose <url> children come from
     * the per-hotel body strings (mirrors the provider's hotel_images XML).
     *
     * @param array<string, string> $bodyByHotel hotel_id => inner <url> nodes
     */
    private function fetcherReturning(array $bodyByHotel): callable
    {
        return static function (string $hotelId) use ($bodyByHotel): \SimpleXMLElement {
            $body = $bodyByHotel[$hotelId] ?? '';

            return new \SimpleXMLElement('<images>' . $body . '</images>');
        };
    }

    private function outText(): string
    {
        return implode("\n", $this->out);
    }

    public function testModeAndDescriptionPins(): void
    {
        self::assertSame(['backfill_images'], BackfillImagesCommand::getModes());
        self::assertStringContainsString('image', strtolower(BackfillImagesCommand::getDescription()));
    }

    public function testSelectsImagelessProductsAndAttachesNovotonsPhotos(): void
    {
        $selects = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$selects): array {
            $selects[] = $query;

            return [
                ['hotel_id' => '4402', 'hotel_name' => 'Edart', 'product_id' => '6'],
            ];
        };

        $cmd = $this->command(['limit' => 50]);
        $cmd->setImageFetcher($this->fetcherReturning([
            '4402' => '<url>/hotels/a b.jpg</url><url>/hotels/c.jpg</url>',
        ]));

        $result = $cmd->execute();

        // Candidate selection: only linked products with no images_links rows.
        self::assertNotEmpty($selects);
        self::assertStringContainsString('NOT EXISTS', $selects[0]);
        self::assertStringContainsString('images_links', $selects[0]);
        self::assertStringContainsString('product_id > 0', $selects[0]);
        self::assertStringContainsString('LIMIT ?i', $selects[0]);

        // Attach called once, with the hotel, its product and Novoton's paths in order.
        self::assertSame([['4402', 6, ['/hotels/a b.jpg', '/hotels/c.jpg']]], $this->attachCalls);

        self::assertTrue($result['success']);
        self::assertSame(1, $result['stats']['processed'] ?? null);
        self::assertSame(2, $result['stats']['attached'] ?? null);
    }

    /** No limit: every photo Novoton lists is handed over. */
    public function testHandsOverEveryPhoto(): void
    {
        $manyUrls = '';
        for ($i = 0; $i < 15; $i++) {
            $manyUrls .= "<url>/img/{$i}.jpg</url>";
        }
        DbStub::$getArray = static fn (string $query, ...$params): array => [
            ['hotel_id' => '10', 'hotel_name' => 'Big', 'product_id' => '99'],
        ];

        $cmd = $this->command();
        $cmd->setImageFetcher($this->fetcherReturning(['10' => $manyUrls]));
        $cmd->execute();

        self::assertCount(1, $this->attachCalls);
        self::assertCount(15, $this->attachCalls[0][2]);
    }

    public function testNoImageUrlsIsCountedAndSkipsAttach(): void
    {
        DbStub::$getArray = static fn (string $query, ...$params): array => [
            ['hotel_id' => '11', 'hotel_name' => 'Empty', 'product_id' => '5'],
        ];

        $cmd = $this->command();
        $cmd->setImageFetcher($this->fetcherReturning(['11' => ''])); // <images/> — no <url> nodes
        $result = $cmd->execute();

        self::assertSame([], $this->attachCalls);
        self::assertSame(1, $result['stats']['no_urls'] ?? null);
        self::assertStringContainsString('no image URLs', $this->outText());
    }

    public function testForceSelectsAllLinkedProducts(): void
    {
        $selects = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$selects): array {
            $selects[] = $query;

            return [];
        };

        $result = $this->command(['force' => '1'])->execute();

        self::assertNotEmpty($selects);
        // force = every linked product, so NO image-less filter.
        self::assertStringNotContainsString('NOT EXISTS', $selects[0]);
        self::assertStringContainsString('product_id > 0', $selects[0]);
        self::assertTrue($result['success']);
        self::assertStringContainsString('No linked products', $this->outText());
    }

    public function testHotelIdTargetsSingleProduct(): void
    {
        $selects = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$selects): array {
            $selects[] = [$query, $params];

            return [['hotel_id' => '4402', 'hotel_name' => 'Edart', 'product_id' => '6']];
        };

        $cmd = $this->command(['hotel_id' => '4402']);
        $cmd->setImageFetcher($this->fetcherReturning(['4402' => '<url>/x.jpg</url>']));
        $cmd->execute();

        self::assertNotEmpty($selects);
        self::assertStringContainsString('hotel_id = ?s', $selects[0][0]);
        self::assertSame(['4402'], $selects[0][1]);
        self::assertCount(1, $this->attachCalls);
    }

    public function testStatusIsReadOnly(): void
    {
        DbStub::$getField = static fn (string $query, ...$params): int => 3;

        $result = $this->command(['status' => '1'])->execute();

        self::assertSame([], $this->attachCalls);
        self::assertSame([], $this->queries); // no writes at all
        self::assertTrue($result['success']);
        self::assertArrayHasKey('linked', $result['stats']);
        self::assertArrayHasKey('missing', $result['stats']);
        self::assertStringContainsString('Image backfill status', $this->outText());
    }

    public function testEmptyBacklogIsANoOp(): void
    {
        DbStub::$getArray = static fn (string $query, ...$params): array => [];

        $result = $this->command()->execute();

        self::assertSame([], $this->attachCalls);
        self::assertTrue($result['success']);
        self::assertSame(0, $result['stats']['processed'] ?? null);
        self::assertStringContainsString('Nothing to backfill', $this->outText());
    }

    public function testPacesBetweenMultipleProducts(): void
    {
        DbStub::$getArray = static fn (string $query, ...$params): array => [
            ['hotel_id' => '1', 'hotel_name' => 'A', 'product_id' => '1'],
            ['hotel_id' => '2', 'hotel_name' => 'B', 'product_id' => '2'],
            ['hotel_id' => '3', 'hotel_name' => 'C', 'product_id' => '3'],
        ];

        $cmd = $this->command();
        $cmd->setImageFetcher($this->fetcherReturning([
            '1' => '<url>/1.jpg</url>',
            '2' => '<url>/2.jpg</url>',
            '3' => '<url>/3.jpg</url>',
        ]));
        $cmd->execute();

        // Paced BETWEEN products only: 3 products → 2 delays.
        self::assertSame(2 * Constants::API_DELAY_MODERATE, $this->sleeps);
        self::assertCount(3, $this->attachCalls);
    }
}
