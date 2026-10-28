<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\ImageDownloader;
use Tygh\Addons\NovotonHolidays\Api\Refusal;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Repository\HotelImageRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelImageRepositoryInterface;
use Tygh\Addons\TravelCore\Contracts\ProductImageStoreInterface;
use Tygh\Addons\TravelCore\Helpers\ImageFingerprint;
use Tygh\Addons\TravelCore\Services\ProductImageStore;

/**
 * Keeps a hotel product's photos in line with Novoton's hotel_images list
 * (Novoton suggested refreshing it weekly, 28 Oct 2026) without ever adding
 * a photo twice or leaving a product with less than it had:
 *
 *  - no answer, or an empty list: nothing changes;
 *  - a photo seen before (its path) is not downloaded again, and one an
 *    admin removed from the product stays removed;
 *  - a photo not seen before is downloaded and compared with the product's
 *    images (ImageFingerprint): the same file, or the same picture saved
 *    again at another size or quality, counts as already there. Anything
 *    else is added;
 *  - only when photos were added are the photos Novoton no longer lists
 *    removed, and only those this sync added itself. Images that were on
 *    the product before (from before this sync existed, or uploaded by
 *    hand) are never removed. A removed main image passes its place to the
 *    first listed photo.
 *
 * The first MAX_PHOTOS of Novoton's list count, as when products were
 * created. Creating a product goes through here too, so its photos are
 * known from the start.
 *
 * @phpstan-import-type PhotoRow from HotelImageRepositoryInterface
 * @phpstan-import-type PhotoSave from HotelImageRepositoryInterface
 */
final class HotelImageSync
{
    public const int MAX_PHOTOS = ConfigProvider::MAX_IMAGES_PER_HOTEL;

    /** This sync attached the image: it may be replaced. */
    public const string ADDED = 'added';

    /** The image was on the product already: never removed. */
    public const string KEPT = 'kept';

    public const string NO_ANSWER = 'no_answer';

    public const string EMPTY = 'empty';

    public const string UNCHANGED = 'unchanged';

    public const string UPDATED = 'updated';

    /** An image of the product could not be read, so a new photo could be a copy of it: none added. */
    public const string UNREADABLE = 'unreadable';

    /** @var \Closure(string): ?string */
    private readonly \Closure $download;

    /**
     * @param (\Closure(string): ?string)|null $download the file at a URL, null when it does not come
     */
    public function __construct(
        private readonly HotelImageRepositoryInterface $photos = new HotelImageRepository(),
        private readonly ProductImageStoreInterface $store = new ProductImageStore('novoton'),
        ?\Closure $download = null,
    ) {
        $this->download = $download ?? static fn (string $url): ?string => (new ImageDownloader())->get($url);
    }

    /**
     * The photo paths of a hotel_images answer, in Novoton's order: the first
     * MAX_PHOTOS, without blanks and repeats. Null when it is no answer (a
     * refusal or nothing at all).
     *
     * @return list<string>|null
     */
    public static function paths(mixed $reply): ?array
    {
        if (!$reply instanceof \SimpleXMLElement || Refusal::reason($reply) !== null) {
            return null;
        }
        $paths = [];
        foreach ($reply->url as $url) {
            $path = trim((string) $url);
            if ($path === '' || in_array($path, $paths, true)) {
                continue;
            }
            $paths[] = $path;
            if (count($paths) >= self::MAX_PHOTOS) {
                break;
            }
        }

        return $paths;
    }

    /**
     * Where a listed path is downloaded from; null for a path that would
     * leave Novoton's image server.
     */
    public static function url(string $path): ?string
    {
        if (str_contains($path, '://') || str_contains($path, '@') || str_contains($path, '\\')) {
            return null;
        }

        return Constants::IMAGE_BASE_URL . '/' . ltrim(str_replace(' ', '%20', $path), '/');
    }

    /**
     * Brings one product's photos in line with what Novoton lists for its hotel.
     *
     * @param list<string>|null $listed paths(): the hotel's photos, null when Novoton did not answer
     *
     * @return array{outcome: string, listed: int, known: int, added: int, same: int, failed: int, removed: int}
     */
    public function sync(string $hotelId, int $productId, ?array $listed, string $now): array
    {
        $result = ['outcome' => self::UNCHANGED, 'listed' => 0, 'known' => 0, 'added' => 0, 'same' => 0, 'failed' => 0, 'removed' => 0];
        if ($listed === null) {
            return ['outcome' => self::NO_ANSWER] + $result;
        }
        if ($listed === [] || $productId <= 0) {
            return ['outcome' => self::EMPTY] + $result;
        }

        $keys = [];
        foreach (array_slice($listed, 0, self::MAX_PHOTOS) as $path) {
            $keys[sha1($path)] = $path;
        }
        $rows = array_filter($this->photos->forHotel($hotelId), static fn (array $row): bool => $row['product_id'] === $productId);
        $new = array_diff_key($keys, $rows);
        $result['listed'] = count($keys);
        $result['known'] = count($keys) - count($new);
        if ($new === []) {
            $this->photos->markListed($hotelId, array_keys($keys), $now);

            return $result;
        }

        // What the product holds: images this sync knows by their rows, any
        // other one read from its file.
        $known = [];
        foreach ($rows as $row) {
            if ($row['pair_id'] > 0 && $row['sha1'] !== '') {
                $known[$row['pair_id']] = ['sha1' => $row['sha1'], 'dhash' => $row['dhash']];
            }
        }
        $current = [];
        $main = 0;
        foreach ($this->store->images($productId) as $image) {
            $print = $known[$image['pair_id']] ?? null;
            if ($print === null) {
                $bytes = $this->store->read($image['file']);
                if ($bytes === null) {
                    $this->photos->markListed($hotelId, array_keys($keys), $now);

                    return ['outcome' => self::UNREADABLE] + $result;
                }
                $print = ImageFingerprint::of($bytes);
            }
            $current[$image['pair_id']] = $print;
            if ($image['main'] && $main === 0) {
                $main = $image['pair_id'];
            }
        }
        $added = [];
        foreach ($rows as $row) {
            if ($row['origin'] === self::ADDED && $row['pair_id'] > 0) {
                $added[$row['pair_id']] = true;
            }
        }

        $saved = [];
        foreach ($new as $key => $path) {
            $url = self::url($path);
            $bytes = $url === null ? null : ($this->download)($url);
            if ($bytes === null || @getimagesizefromstring($bytes) === false) {
                $result['failed']++; // not remembered: asked for again next time

                continue;
            }
            $print = ImageFingerprint::of($bytes);
            $pairId = 0;
            foreach ($current as $id => $there) {
                if (ImageFingerprint::same($print, $there)) {
                    $pairId = $id;

                    break;
                }
            }
            if ($pairId > 0) {
                $result['same']++;
            } else {
                $pairId = $this->store->attach($productId, $bytes, $main === 0);
                if ($pairId <= 0) {
                    $result['failed']++;

                    continue;
                }
                $result['added']++;
                $current[$pairId] = $print;
                $added[$pairId] = true;
                $main = $main === 0 ? $pairId : $main;
            }
            $saved[$key] = [
                'path_key' => $key,
                'path' => $path,
                'product_id' => $productId,
                'pair_id' => $pairId,
                'origin' => isset($added[$pairId]) ? self::ADDED : self::KEPT,
                'sha1' => $print['sha1'],
                'dhash' => $print['dhash'],
            ];
        }
        $this->photos->save($hotelId, array_values($saved), $now);

        if ($result['added'] > 0) {
            $result['removed'] = $this->replace($productId, $hotelId, $keys, $rows, $saved, $current, $main);
        }
        $this->photos->markListed($hotelId, array_keys($keys), $now);
        $result['outcome'] = $result['added'] + $result['removed'] > 0 ? self::UPDATED : self::UNCHANGED;

        return $result;
    }

    /**
     * Removes the photos this sync added that Novoton no longer lists (none
     * still listed under another path), then makes sure a main image is left.
     *
     * @param array<string, string> $keys path_key => path, as listed now
     * @param array<string, PhotoRow> $rows the rows before this sync
     * @param array<string, PhotoSave> $saved the rows this sync wrote
     * @param array<int, array{sha1: string, dhash: string}> $current pair id => print, the product's images
     *
     * @return int images removed
     */
    private function replace(int $productId, string $hotelId, array $keys, array $rows, array $saved, array $current, int $main): int
    {
        $stillListed = [];
        foreach ([...array_intersect_key($rows, $keys), ...$saved] as $row) {
            $stillListed[$row['pair_id']] = true;
        }
        $removed = 0;
        $forget = [];
        foreach (array_diff_key($rows, $keys) as $key => $row) {
            $pairId = $row['pair_id'];
            if ($row['origin'] !== self::ADDED || $pairId <= 0 || isset($stillListed[$pairId]) || !isset($current[$pairId])) {
                continue; // kept, still listed elsewhere, or no longer on the product (an admin removed it)
            }
            if ($this->store->remove($productId, $pairId)) {
                $removed++;
                $forget[] = (string) $key;
                unset($current[$pairId]);
            }
        }
        $this->photos->forget($hotelId, $forget);

        if ($removed > 0 && !isset($current[$main])) {
            $heir = 0;
            foreach (array_keys($keys) as $key) {
                $row = $saved[$key] ?? $rows[$key] ?? null;
                $pairId = $row === null ? 0 : $row['pair_id'];
                if ($pairId > 0 && isset($current[$pairId])) {
                    $heir = $pairId;

                    break;
                }
            }
            $heir = $heir > 0 ? $heir : (int) array_key_first($current);
            if ($heir > 0) {
                $this->store->makeMain($productId, $heir);
            }
        }

        return $removed;
    }
}
