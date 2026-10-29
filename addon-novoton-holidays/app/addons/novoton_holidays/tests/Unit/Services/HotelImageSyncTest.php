<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\HotelImageSync;
use Tygh\Addons\NovotonHolidays\Tests\Support\FakeProductImages;
use Tygh\Addons\NovotonHolidays\Tests\Support\PhotoTable;

/**
 * Novoton suggested refreshing hotel photos weekly (28 Oct 2026). The
 * refresh must never add a photo the product already has, and never leave
 * the product with less: nothing new, nothing added or removed; Novoton's
 * photos are replaced only by new ones that arrived. They follow Novoton's
 * order, its first photo the main one. Photos added by hand are kept where
 * they are while "Keep photos added by hand" is on (the default).
 */
#[RequiresPhpExtension('gd')]
final class HotelImageSyncTest extends TestCase
{
    private const string NOW = '2026-11-01 02:15:00';

    private const int PRODUCT = 900;

    /** @var array<string, string> URL => bytes Novoton serves; a missing one does not come */
    private array $server = [];

    /** @var list<string> */
    private array $downloads = [];

    private PhotoTable $table;

    private FakeProductImages $product;

    protected function setUp(): void
    {
        $this->table = new PhotoTable();
        $this->product = new FakeProductImages();
    }

    /** A photo-like picture, different for each $seed; saved $width wide at $quality. */
    private static function photo(int $seed, int $quality = 90, int $width = 320): string
    {
        $image = imagecreatetruecolor(320, 240);
        self::assertNotFalse($image);
        for ($x = 0; $x < 320; $x++) {
            $shade = intdiv(255 * $x, 320);
            imageline($image, $x, 0, $x, 239, (int) imagecolorallocate($image, $shade, 120, 255 - $shade));
        }
        mt_srand($seed);
        for ($i = 0; $i < 14; $i++) {
            $colour = (int) imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledellipse($image, mt_rand(0, 320), mt_rand(0, 240), mt_rand(40, 160), mt_rand(30, 120), $colour);
        }
        mt_srand();
        if ($width !== 320) {
            $scaled = imagecreatetruecolor($width, intdiv($width * 3, 4));
            self::assertNotFalse($scaled);
            imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, intdiv($width * 3, 4), 320, 240);
            $image = $scaled;
        }
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /** Novoton serves these photos: path => bytes. */
    private function serve(string ...$pathsAndSeeds): void
    {
        foreach ($pathsAndSeeds as $entry) {
            [$path, $seed] = explode('=', $entry);
            $this->server[(string) HotelImageSync::url($path)] = self::photo((int) $seed);
        }
    }

    /**
     * @param list<string>|null $listed
     *
     * @return array{outcome: string, listed: int, known: int, added: int, same: int, failed: int, removed: int, moved: int}
     */
    private function refresh(?array $listed, bool $keepByHand = true): array
    {
        $this->downloads = [];
        $sync = new HotelImageSync($this->table, $this->product, function (string $url): ?string {
            $this->downloads[] = $url;

            return $this->server[$url] ?? null;
        }, $keepByHand);

        return $sync->sync('476', self::PRODUCT, $listed, self::NOW);
    }

    /** @return array<string, string> path => origin of what the table knows */
    private function origins(): array
    {
        $out = [];
        foreach ($this->table->forHotel('476') as $row) {
            $out[$row['path']] = $row['origin'];
        }
        ksort($out);

        return $out;
    }

    public function testNoAnswerOrAnEmptyListChangesNothing(): void
    {
        $this->product->has(self::photo(1));

        self::assertSame('no_answer', $this->refresh(null)['outcome']);
        self::assertSame('empty', $this->refresh([])['outcome']);
        self::assertSame([], $this->downloads);
        self::assertCount(1, $this->product->pairs, 'the product keeps its image');
        self::assertSame([], $this->table->forHotel('476'));
    }

    /** A new product: all of Novoton's photos, however many, in its order, the first the main photo. */
    public function testANewProductGetsAllOfNovotonsPhotosInItsOrder(): void
    {
        $paths = [];
        foreach (range(1, 14) as $n) {
            $this->serve("/h/{$n}.jpg={$n}");
            $paths[] = "/h/{$n}.jpg";
        }

        $result = $this->refresh($paths);

        self::assertSame(['outcome' => 'updated', 'listed' => 14, 'known' => 0, 'added' => 14, 'same' => 0, 'failed' => 0, 'removed' => 0, 'moved' => 0], $result);
        self::assertSame(array_map(self::photo(...), range(1, 14)), $this->product->gallery(), 'no limit of 10');
        self::assertSame($this->product->pairOf(self::photo(1)), $this->product->main());
        self::assertSame(['novoton'], array_values(array_unique($this->origins())));
    }

    /** The weekly case: the same list, one API call and not one download. */
    public function testPhotosSeenBeforeAreNotDownloadedAgain(): void
    {
        $this->serve('/h/1.jpg=1', '/h/2.jpg=2');
        $this->refresh(['/h/1.jpg', '/h/2.jpg']);

        $result = $this->refresh(['/h/1.jpg', '/h/2.jpg']);

        self::assertSame(['unchanged', 2, 0, 0], [$result['outcome'], $result['known'], $result['added'], $result['moved']]);
        self::assertSame([], $this->downloads);
        self::assertCount(2, $this->product->pairs);
    }

    /** The hotel re-sorts its photos and puts a new cover first: the product follows. */
    public function testThePhotosFollowNovotonsOrderAndItsFirstIsTheMainPhoto(): void
    {
        $this->serve('/h/1.jpg=1', '/h/2.jpg=2', '/h/3.jpg=3', '/h/new-cover.jpg=8');
        $this->refresh(['/h/1.jpg', '/h/2.jpg', '/h/3.jpg']);

        $result = $this->refresh(['/h/3.jpg', '/h/1.jpg', '/h/2.jpg']);
        self::assertSame(['updated', 0, 0], [$result['outcome'], $result['added'], $result['removed']]);
        self::assertSame([], $this->downloads, 'a new order is no reason to download');
        self::assertSame([self::photo(3), self::photo(1), self::photo(2)], $this->product->gallery());
        self::assertSame($this->product->pairOf(self::photo(3)), $this->product->main());

        $this->refresh(['/h/new-cover.jpg', '/h/3.jpg', '/h/1.jpg', '/h/2.jpg']);
        self::assertSame([self::photo(8), self::photo(3), self::photo(1), self::photo(2)], $this->product->gallery());
        self::assertSame($this->product->pairOf(self::photo(8)), $this->product->main());
    }

    /**
     * A product made before the refresh existed: its images are compared by
     * content. The same file, or the same picture saved again, is not added
     * and counts as Novoton's; a new photo is added.
     */
    public function testAPhotoTheProductAlreadyHasIsNeverAddedTwice(): void
    {
        $this->product->has(self::photo(1), main: true);
        $this->product->has(self::photo(2));
        $this->server[(string) HotelImageSync::url('/h/1.jpg')] = self::photo(1);
        $this->server[(string) HotelImageSync::url('/h/2-new-name.jpg')] = self::photo(2, quality: 55, width: 240);
        $this->serve('/h/3.jpg=3');

        $result = $this->refresh(['/h/1.jpg', '/h/2-new-name.jpg', '/h/3.jpg']);

        self::assertSame([1, 2, 0], [$result['added'], $result['same'], $result['failed']]);
        self::assertCount(3, $this->product->pairs);
        self::assertSame(['/h/1.jpg' => 'novoton', '/h/2-new-name.jpg' => 'novoton', '/h/3.jpg' => 'novoton'], $this->origins());
    }

    public function testTheSamePictureListedTwiceIsAddedOnce(): void
    {
        $this->serve('/h/1.jpg=1');
        $this->server[(string) HotelImageSync::url('/h/1-small.jpg')] = self::photo(1, quality: 60, width: 160);

        $result = $this->refresh(['/h/1.jpg', '/h/1-small.jpg']);

        self::assertSame([1, 1], [$result['added'], $result['same']]);
        self::assertCount(1, $this->product->pairs);
    }

    /** Novoton drops a photo but sends nothing new: the product keeps it, after the listed ones. */
    public function testWithoutNewPhotosNothingIsRemoved(): void
    {
        $this->serve('/h/1.jpg=1', '/h/2.jpg=2', '/h/3.jpg=3');
        $this->refresh(['/h/1.jpg', '/h/2.jpg', '/h/3.jpg']);

        $result = $this->refresh(['/h/1.jpg', '/h/3.jpg']);

        self::assertSame([0, 0], [$result['added'], $result['removed']]);
        self::assertSame([self::photo(1), self::photo(3), self::photo(2)], $this->product->gallery());
        self::assertFalse($this->table->forHotel('476')[sha1('/h/2.jpg')]['listed']);
    }

    /** New photos came: the ones Novoton no longer lists make way. */
    public function testNewPhotosReplaceTheOnesNovotonDropped(): void
    {
        $this->serve('/h/1.jpg=1', '/h/2.jpg=2', '/h/3.jpg=3', '/h/4.jpg=4');
        $this->refresh(['/h/1.jpg', '/h/2.jpg', '/h/3.jpg']);

        $result = $this->refresh(['/h/1.jpg', '/h/3.jpg', '/h/4.jpg']);

        self::assertSame(['updated', 1, 1], [$result['outcome'], $result['added'], $result['removed']]);
        self::assertSame([self::photo(1), self::photo(3), self::photo(4)], $this->product->gallery());
        self::assertSame(['/h/1.jpg' => 'novoton', '/h/3.jpg' => 'novoton', '/h/4.jpg' => 'novoton'], $this->origins(), 'a dropped photo listed again later is new again');
        self::assertSame([(string) HotelImageSync::url('/h/4.jpg')], $this->downloads, 'only the new photo was downloaded');
    }

    /**
     * A product made before the refresh: its photos that are Novoton's are
     * replaced like any Novoton photo; one added by hand stays where it is.
     */
    public function testAPhotoAddedByHandStaysWhereItIs(): void
    {
        $this->product->has(self::photo(1), main: true);
        $this->product->has(self::photo(9)); // uploaded by hand: Novoton never listed it
        $this->serve('/h/1.jpg=1', '/h/5.jpg=5');
        $this->refresh(['/h/1.jpg']);

        $result = $this->refresh(['/h/5.jpg']);

        self::assertSame([1, 1], [$result['added'], $result['removed']]);
        self::assertSame([self::photo(5), self::photo(9)], $this->product->gallery());
        self::assertSame($this->product->pairOf(self::photo(5)), $this->product->main());
    }

    /** "Keep photos added by hand" off: they are treated like photos Novoton no longer lists. */
    public function testWithTheSettingOffPhotosAddedByHandGoWhenNewPhotosCome(): void
    {
        $this->serve('/h/1.jpg=1', '/h/2.jpg=2', '/h/3.jpg=3');
        $this->refresh(['/h/1.jpg', '/h/2.jpg']);
        $this->product->has(self::photo(9), position: 0); // uploaded by hand, put first

        $result = $this->refresh(['/h/1.jpg', '/h/2.jpg'], keepByHand: false);
        self::assertSame([0, 0], [$result['added'], $result['removed']], 'nothing new: nothing removed');
        self::assertSame([self::photo(1), self::photo(2), self::photo(9)], $this->product->gallery(), 'moved after Novoton\'s photos');

        $result = $this->refresh(['/h/1.jpg', '/h/2.jpg', '/h/3.jpg'], keepByHand: false);
        self::assertSame([1, 1], [$result['added'], $result['removed']]);
        self::assertSame([self::photo(1), self::photo(2), self::photo(3)], $this->product->gallery());
    }

    /** A main photo chosen by hand stays the main photo while the setting is on. */
    public function testAMainPhotoAddedByHandStaysMain(): void
    {
        $this->serve('/h/1.jpg=1', '/h/2.jpg=2');
        $this->refresh(['/h/1.jpg', '/h/2.jpg']);
        $mine = $this->product->has(self::photo(9));
        $this->product->makeMain(self::PRODUCT, $mine);

        $this->refresh(['/h/2.jpg', '/h/1.jpg']);
        self::assertSame($mine, $this->product->main());
        self::assertSame([self::photo(9), self::photo(2), self::photo(1)], $this->product->gallery(), 'Novoton\'s photos still follow its order');

        $this->refresh(['/h/2.jpg', '/h/1.jpg'], keepByHand: false);
        self::assertSame($this->product->pairOf(self::photo(2)), $this->product->main());
    }

    /**
     * Novoton lists the same picture as a photo added by hand: not added
     * twice, and the photo stays the admin's when Novoton drops it.
     */
    public function testNovotonsCopyOfAPhotoAddedByHandStaysTheAdmins(): void
    {
        $this->serve('/h/1.jpg=1', '/h/5.jpg=5', '/h/6.jpg=6');
        $this->refresh(['/h/1.jpg']);
        $this->product->has(self::photo(5));

        $result = $this->refresh(['/h/1.jpg', '/h/5.jpg']);
        self::assertSame([0, 1], [$result['added'], $result['same']]);
        self::assertSame('manual', $this->origins()['/h/5.jpg']);

        $result = $this->refresh(['/h/1.jpg', '/h/6.jpg']);
        self::assertSame([1, 0], [$result['added'], $result['removed']]);
        self::assertContains(self::photo(5), $this->product->gallery());
    }

    /** An image an admin deleted is not brought back while Novoton still lists it. */
    public function testAPhotoAnAdminRemovedStaysRemoved(): void
    {
        $this->serve('/h/1.jpg=1', '/h/2.jpg=2');
        $this->refresh(['/h/1.jpg', '/h/2.jpg']);
        $this->product->adminRemoves($this->product->pairOf(self::photo(2)));

        $result = $this->refresh(['/h/1.jpg', '/h/2.jpg']);

        self::assertSame(['unchanged', 0], [$result['outcome'], $result['added']]);
        self::assertSame([], $this->downloads);
        self::assertSame([self::photo(1)], $this->product->gallery());
    }

    public function testARemovedMainImageIsReplacedByTheFirstListedPhoto(): void
    {
        $this->serve('/h/1.jpg=1', '/h/2.jpg=2', '/h/3.jpg=3');
        $this->refresh(['/h/1.jpg', '/h/2.jpg']);

        $this->refresh(['/h/3.jpg', '/h/2.jpg']);

        self::assertSame([self::photo(3), self::photo(2)], $this->product->gallery());
        self::assertSame($this->product->pairOf(self::photo(3)), $this->product->main());
    }

    /** Renamed by Novoton: the same picture under a new path keeps its image while others are replaced. */
    public function testARenamedPhotoIsNotRemoved(): void
    {
        $this->serve('/h/1.jpg=1', '/h/2.jpg=2', '/h/2-renamed.jpg=2', '/h/7.jpg=7');
        $this->refresh(['/h/1.jpg', '/h/2.jpg']);

        $result = $this->refresh(['/h/2-renamed.jpg', '/h/7.jpg']);

        self::assertSame([1, 1, 1], [$result['added'], $result['same'], $result['removed']]);
        self::assertSame([self::photo(2), self::photo(7)], $this->product->gallery());
    }

    /** A photo that does not come is tried again next time, and replaces nothing meanwhile. */
    public function testADownloadThatFailsReplacesNothingAndIsTriedAgain(): void
    {
        $this->serve('/h/1.jpg=1', '/h/2.jpg=2');
        $this->refresh(['/h/1.jpg', '/h/2.jpg']);
        $this->server[(string) HotelImageSync::url('/h/3.jpg')] = '<html>Service Unavailable</html>';

        $result = $this->refresh(['/h/1.jpg', '/h/3.jpg']);
        self::assertSame([0, 1, 0], [$result['added'], $result['failed'], $result['removed']]);
        self::assertCount(2, $this->product->pairs);

        $this->serve('/h/3.jpg=3');
        $result = $this->refresh(['/h/1.jpg', '/h/3.jpg']);
        self::assertSame([1, 1], [$result['added'], $result['removed']]);
    }

    /** An image of the product that cannot be read could be any new photo: none is added. */
    public function testAnImageThatCannotBeReadStopsAdditions(): void
    {
        $this->product->has(self::photo(1), main: true, readable: false);
        $this->serve('/h/3.jpg=3');

        $result = $this->refresh(['/h/3.jpg']);

        self::assertSame(['unreadable', 0], [$result['outcome'], $result['added']]);
        self::assertSame([], $this->downloads);
        self::assertCount(1, $this->product->pairs);
    }

    public function testTheAnswerIsReadAsAllOfNovotonsPhotosInOrder(): void
    {
        $urls = '';
        foreach (range(1, 12) as $n) {
            $urls .= "<url> /h/{$n}.jpg </url>";
        }
        $paths = HotelImageSync::paths(new \SimpleXMLElement("<hotel_images><url>/h/1.jpg</url><url></url>{$urls}</hotel_images>"));

        self::assertSame(array_map(static fn (int $n): string => "/h/{$n}.jpg", range(1, 12)), $paths, 'blanks and repeats dropped, no limit');
        self::assertSame([], HotelImageSync::paths(new \SimpleXMLElement('<hotel_images/>')));
        self::assertNull(HotelImageSync::paths(new \SimpleXMLElement('<error><err>Hotel not found</err></error>')));
        self::assertNull(HotelImageSync::paths(false));

        self::assertSame('https://booking.allinclusive.bg/hotels/a%20b.jpg', HotelImageSync::url('/hotels/a b.jpg'));
        self::assertSame('https://booking.allinclusive.bg/hotels/c.jpg', HotelImageSync::url('hotels/c.jpg'));
        self::assertNull(HotelImageSync::url('@evil.example/x.jpg'), 'a path never leaves Novoton\'s image server');
        self::assertNull(HotelImageSync::url('https://evil.example/x.jpg'));
    }
}
