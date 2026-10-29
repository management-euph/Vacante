<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * Tells whether two image files hold the same picture, so a supplier's
 * photo refresh never adds a photo the product already has.
 *
 * Two checks, cheapest first:
 *  - the same file: the SHA-1 of the bytes;
 *  - the same picture saved again (another size, quality or format): a
 *    64-bit difference hash. The picture is shrunk to 9×8 grey pixels, and
 *    each bit says whether a pixel is brighter than its right-hand
 *    neighbour. Saving a photo again moves a few bits at most; a different
 *    photo differs in about half of them. At most SAME_PICTURE bits apart
 *    counts as the same picture.
 *
 * The picture check needs GD; without it, or for a file GD cannot read,
 * only the bytes are compared.
 */
final class ImageFingerprint
{
    /** Bits two difference hashes may differ by and still be the same picture. */
    public const int SAME_PICTURE = 6;

    /** Larger pictures are compared by their bytes only, to keep memory low. */
    private const int MAX_PIXELS = 40_000_000;

    /**
     * @return array{sha1: string, dhash: string} dhash '' when GD cannot read the picture
     */
    public static function of(string $bytes): array
    {
        return ['sha1' => sha1($bytes), 'dhash' => self::dhash($bytes) ?? ''];
    }

    /** The difference hash as 16 hex digits; null when GD cannot read the picture. */
    public static function dhash(string $bytes): ?string
    {
        if ($bytes === '' || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        $small = imagecreatetruecolor(9, 8);
        if ($image === false || $small === false
            || !imagecopyresampled($small, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image))
        ) {
            return null;
        }
        unset($image);

        $hash = '';
        for ($y = 0; $y < 8; $y++) {
            $byte = 0;
            $left = self::grey($small, 0, $y);
            for ($x = 1; $x < 9; $x++) {
                $right = self::grey($small, $x, $y);
                $byte = ($byte << 1) | ($left > $right ? 1 : 0);
                $left = $right;
            }
            $hash .= chr($byte);
        }

        return bin2hex($hash);
    }

    /** Bits two difference hashes differ by; 64 when either is missing or malformed. */
    public static function distance(string $a, string $b): int
    {
        if (strlen($a) !== 16 || strlen($b) !== 16 || !ctype_xdigit($a . $b)) {
            return 64;
        }
        $bits = 0;
        foreach (str_split((string) hex2bin($a) ^ (string) hex2bin($b)) as $byte) {
            $bits += substr_count(decbin(ord($byte)), '1');
        }

        return $bits;
    }

    /**
     * The same picture: the same bytes, or difference hashes at most
     * SAME_PICTURE bits apart.
     *
     * @param array{sha1: string, dhash: string} $a
     * @param array{sha1: string, dhash: string} $b
     */
    public static function same(array $a, array $b): bool
    {
        if ($a['sha1'] !== '' && $a['sha1'] === $b['sha1']) {
            return true;
        }

        return $a['dhash'] !== '' && $b['dhash'] !== ''
            && self::distance($a['dhash'], $b['dhash']) <= self::SAME_PICTURE;
    }

    /** Brightness of one pixel, 0 to 255,000. */
    private static function grey(\GdImage $image, int $x, int $y): int
    {
        $rgb = imagecolorat($image, $x, $y);
        if ($rgb === false) {
            return 0;
        }

        return (($rgb >> 16) & 0xFF) * 299 + (($rgb >> 8) & 0xFF) * 587 + ($rgb & 0xFF) * 114;
    }
}
