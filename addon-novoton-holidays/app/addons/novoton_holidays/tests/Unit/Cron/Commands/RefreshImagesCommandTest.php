<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\HotelApiClientInterface;
use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Cron\Commands\RefreshImagesCommand;
use Tygh\Addons\NovotonHolidays\Services\HotelImageSync;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Addons\NovotonHolidays\Tests\Support\FakeProductImages;
use Tygh\Addons\NovotonHolidays\Tests\Support\PhotoTable;

/**
 * refresh_images: Novoton suggested refreshing hotel photos weekly
 * (28 Oct 2026). Each night a seventh of the hotels with a product, least
 * recently checked first, done before 03:00, Novoton's busiest hour.
 */
#[RequiresPhpExtension('gd')]
final class RefreshImagesCommandTest extends TestCase
{
    private PhotoTable $table;

    private FakeProductImages $product;

    /** @var list<string> */
    private array $lines = [];

    /** @var list<string> hotels asked */
    private array $asked = [];

    private float $clock = 0.0;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->table = new PhotoTable();
        $this->product = new FakeProductImages();
        foreach (range(1, 15) as $n) {
            $this->table->hotels[] = ['hotel_id' => (string) (100 + $n), 'hotel_name' => 'Hotel ' . $n, 'product_id' => 900 + $n];
        }
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    private static function jpeg(int $red): string
    {
        $image = imagecreatetruecolor(200, 150);
        self::assertNotFalse($image);
        imagefilledrectangle($image, 0, 0, 199, 149, (int) imagecolorallocate($image, $red, 40, 200));
        imagefilledellipse($image, 60 + $red % 80, 70, 90, 60, (int) imagecolorallocate($image, 250, 250 - $red, 10));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * @param \Closure(string): \SimpleXMLElement $answer hotel id => Novoton's reply, or it throws
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function runJob(\Closure $answer, array $params = [], float $secondsPerHotel = 1.0): array
    {
        $hotels = $this->createStub(HotelApiClientInterface::class);
        $hotels->method('getHotelImages')->willReturnCallback(function (string $hotelId) use ($answer, $secondsPerHotel): \SimpleXMLElement {
            $this->asked[] = $hotelId;
            $this->clock += $secondsPerHotel;

            return $answer($hotelId);
        });
        $api = $this->createStub(NovotonApiKitInterface::class);
        $api->method('hotels')->willReturn($hotels);

        $command = new RefreshImagesCommand($api, null, $params);
        $command->setOutputCallback(function (string $line): void {
            $this->lines[] = $line;
        });
        $photos = ['/p/1.jpg' => self::jpeg(10), '/p/2.jpg' => self::jpeg(200)];
        $command->useServices(
            new HotelImageSync($this->table, $this->product, static function (string $url) use ($photos): ?string {
                foreach ($photos as $path => $bytes) {
                    if (str_ends_with($url, $path)) {
                        return $bytes;
                    }
                }

                return null;
            }),
            $this->table,
            fn (): float => $this->clock,
        );

        return $command->execute();
    }

    public function testEachNightTakesASeventhOfTheHotels(): void
    {
        $result = $this->runJob(static fn (): \SimpleXMLElement => new \SimpleXMLElement('<hotel_images/>'));

        self::assertSame(['101', '102', '103'], $this->asked, '15 hotels: 3 a night, least recently checked first');
        self::assertTrue($result['success']);
        self::assertSame([3, 3], [$result['stats']['hotels'], $result['stats']['empty']]);
        self::assertSame(['101', '102', '103'], array_map('strval', array_keys($this->table->checked)), 'answered: to the back of the queue');
        self::assertContains('  NVT-101 Hotel 1: Novoton lists no photo; photos kept as they are.', $this->lines);
    }

    public function testARunCanTakeMoreOrOneHotel(): void
    {
        $this->runJob(static fn (): \SimpleXMLElement => new \SimpleXMLElement('<hotel_images/>'), ['limit' => '5']);
        self::assertCount(5, $this->asked);

        $this->asked = [];
        $this->runJob(static fn (): \SimpleXMLElement => new \SimpleXMLElement('<hotel_images/>'), ['hotel_id' => '112']);
        self::assertSame(['112'], $this->asked);
    }

    public function testPhotosGoThroughTheSync(): void
    {
        $result = $this->runJob(static fn (): \SimpleXMLElement => new \SimpleXMLElement('<hotel_images><url>/p/1.jpg</url><url>/p/2.jpg</url><url>/p/missing.jpg</url></hotel_images>'), ['hotel_id' => '101']);

        self::assertSame([1, 2, 1], [$result['stats']['updated'], $result['stats']['added'], $result['stats']['failed']]);
        self::assertCount(2, $this->product->pairs);
        self::assertContains('  NVT-101 Hotel 1: 3 listed: 2 added, 0 replaced, 0 already there; 1 did not download, tried again next time.', $this->lines);

        $this->lines = [];
        $this->runJob(static fn (): \SimpleXMLElement => new \SimpleXMLElement('<hotel_images><url>/p/1.jpg</url><url>/p/2.jpg</url></hotel_images>'), ['hotel_id' => '101']);
        self::assertContains('  NVT-101 Hotel 1: 2 listed, nothing new.', $this->lines);
    }

    /** A refusal is an answer: the hotel's photos stay, and it goes to the back of the queue. */
    public function testARefusalKeepsThePhotos(): void
    {
        $this->product->has(self::jpeg(10), main: true);

        $result = $this->runJob(static fn (): \SimpleXMLElement => new \SimpleXMLElement('<error><err>Hotel not found</err></error>'), ['hotel_id' => '101']);

        self::assertSame(1, $result['stats']['refused']);
        self::assertCount(1, $this->product->pairs);
        self::assertArrayHasKey('101', $this->table->checked);
        self::assertContains('  NVT-101 Hotel 1: Novoton: Hotel not found; photos kept as they are.', $this->lines);
    }

    /** No answer: first again next night; three hotels in a row end the run. */
    public function testNovotonNotAnsweringEndsTheRun(): void
    {
        $result = $this->runJob(static function (): never {
            throw new \RuntimeException('Operation timed out');
        }, ['limit' => '6']);

        self::assertCount(3, $this->asked);
        self::assertFalse($result['success']);
        self::assertSame(['silent', 3, 3], [$result['stats']['stopped'], $result['stats']['no_answer'], $result['stats']['left']]);
        self::assertSame([], $this->table->checked);
        self::assertContains('Stopped: Novoton did not answer for 3 hotels in a row: 3 hotels go first next night.', $this->lines);
    }

    /** Started at 02:15, no hotel is started after 35 minutes. */
    public function testTheRunEndsBeforeNovotonsBusiestHour(): void
    {
        $result = $this->runJob(static fn (): \SimpleXMLElement => new \SimpleXMLElement('<hotel_images/>'), ['limit' => '15'], 600.0);

        self::assertCount(4, $this->asked, 'four hotels of ten minutes each: the fifth would start at 40 minutes');
        self::assertSame(['time', 11], [$result['stats']['stopped'], $result['stats']['left']]);
        self::assertContains('Stopped at the time budget (35 minutes): 11 hotels go first next night.', $this->lines);
    }

    public function testEachOutcomeReadsAsWords(): void
    {
        $base = ['listed' => 10, 'known' => 8, 'added' => 0, 'same' => 0, 'failed' => 0, 'removed' => 0];

        self::assertSame('10 listed, nothing new.', RefreshImagesCommand::describe(['outcome' => 'unchanged'] + $base));
        self::assertSame('10 listed: 2 added, 1 replaced, 9 already there.', RefreshImagesCommand::describe(['outcome' => 'updated', 'added' => 2, 'removed' => 1, 'same' => 1] + $base));
        self::assertSame('an image of the product cannot be read here, so no photo was added.', RefreshImagesCommand::describe(['outcome' => 'unreadable'] + $base));
    }
}
