<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Helpers\ImageFingerprint;

/**
 * A supplier's photo refresh must never add a photo the product already
 * has: the same file, or the same picture saved again at another size or
 * quality. A different photo must still count as new.
 */
#[RequiresPhpExtension('gd')]
final class ImageFingerprintTest extends TestCase
{
    /** A photo-like picture: a gradient and shapes placed by $seed. */
    private static function picture(int $seed, int $width = 320, int $height = 240): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        for ($x = 0; $x < $width; $x++) {
            $shade = (int) (255 * $x / $width);
            imageline($image, $x, 0, $x, $height - 1, (int) imagecolorallocate($image, $shade, 120, 255 - $shade));
        }
        mt_srand($seed);
        for ($i = 0; $i < 14; $i++) {
            $colour = (int) imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            $cx = mt_rand(0, $width);
            $cy = mt_rand(0, $height);
            imagefilledellipse($image, $cx, $cy, mt_rand($width / 8, $width / 2), mt_rand($height / 8, $height / 2), $colour);
        }
        mt_srand();

        return $image;
    }

    private static function jpeg(\GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    private static function png(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function resized(\GdImage $image, int $width, int $height): \GdImage
    {
        $small = imagecreatetruecolor($width, $height);
        self::assertNotFalse($small);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $small;
    }

    public function testTheSameFileIsTheSameImage(): void
    {
        $bytes = self::jpeg(self::picture(1), 90);

        self::assertTrue(ImageFingerprint::same(ImageFingerprint::of($bytes), ImageFingerprint::of($bytes)));
        self::assertSame(sha1($bytes), ImageFingerprint::of($bytes)['sha1']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', ImageFingerprint::of($bytes)['dhash']);
    }

    /** Saved again at another quality, size or format: another file, the same picture. */
    public function testTheSamePictureSavedAgainIsTheSameImage(): void
    {
        $photo = self::picture(1);
        $original = ImageFingerprint::of(self::jpeg($photo, 90));

        foreach ([
            'lower quality' => self::jpeg($photo, 40),
            'half the size' => self::jpeg(self::resized($photo, 160, 120), 85),
            'as a PNG' => self::png($photo),
        ] as $case => $bytes) {
            $copy = ImageFingerprint::of($bytes);
            self::assertNotSame($original['sha1'], $copy['sha1'], $case);
            self::assertLessThanOrEqual(ImageFingerprint::SAME_PICTURE, ImageFingerprint::distance($original['dhash'], $copy['dhash']), $case);
            self::assertTrue(ImageFingerprint::same($original, $copy), $case);
        }
    }

    public function testADifferentPhotoIsANewImage(): void
    {
        $first = ImageFingerprint::of(self::jpeg(self::picture(1), 90));

        foreach ([2, 3, 4] as $seed) {
            $other = ImageFingerprint::of(self::jpeg(self::picture($seed), 90));
            self::assertGreaterThan(ImageFingerprint::SAME_PICTURE, ImageFingerprint::distance($first['dhash'], $other['dhash']), "seed {$seed}");
            self::assertFalse(ImageFingerprint::same($first, $other), "seed {$seed}");
        }
    }

    /** A file GD cannot read is compared by its bytes only. */
    public function testAFileThatIsNoPictureIsComparedByItsBytes(): void
    {
        $junk = ImageFingerprint::of('<html>Not found</html>');

        self::assertSame('', $junk['dhash']);
        self::assertNull(ImageFingerprint::dhash(''));
        self::assertTrue(ImageFingerprint::same($junk, ImageFingerprint::of('<html>Not found</html>')));
        self::assertFalse(ImageFingerprint::same($junk, ImageFingerprint::of('<html>Gone</html>')));
        self::assertFalse(ImageFingerprint::same(['sha1' => '', 'dhash' => ''], ['sha1' => '', 'dhash' => '']), 'nothing known is never the same');
        self::assertSame(64, ImageFingerprint::distance('', 'ffffffffffffffff'));
        self::assertSame(64, ImageFingerprint::distance('zzzzzzzzzzzzzzzz', 'ffffffffffffffff'));
        self::assertSame(0, ImageFingerprint::distance('00ff00ff00ff00ff', '00ff00ff00ff00ff'));
        self::assertSame(8, ImageFingerprint::distance('00ff00ff00ff00ff', '00ff00ff00ff0000'));
    }
}
