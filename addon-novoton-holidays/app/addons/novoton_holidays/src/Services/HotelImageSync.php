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
 * (Novoton suggested refreshing it weekly, 28 Oct 2026), in Novoton's order,
 * without ever adding a photo twice or leaving a product with less than it
 * had:
 *
 *  - no answer, or an empty list: nothing changes;
 *  - a photo seen before (its path) is not downloaded again, and one an
 *    admin removed from the product stays removed;
 *  - a photo not seen before is downloaded and compared with the product's
 *    images (ImageFingerprint): the same file, or the same picture saved
 *    again at another size or quality, counts as already there. Anything
 *    else is added, however many photos Novoton lists;
 *  - only when photos were added are the photos Novoton no longer lists
 *    removed;
 *  - at every refresh that gets a list, the photos are put in Novoton's
 *    order and Novoton's first photo becomes the main one.
 *
 * A product image is Novoton's when a row of ?:novoton_hotel_images points
 * at it: one this sync attached or, on a product's first refresh, one its
 * creation imported that is the same picture as a listed photo. Every other
 * image counts as added by hand. With "Keep photos added by hand" on (the
 * default) those are never removed, moved or replaced as the main photo;
 * off, they are treated like photos Novoton no longer lists.
 *
 * Creating a product goes through here too, so its photos are known from
 * the start.
 *
 * @phpstan-import-type PhotoRow from HotelImageRepositoryInterface
 *
 * @phpstan-type Image array{pair_id: int, main: bool, position: int, file: string}
 */
final class HotelImageSync
{
    /** A Novoton photo: this sync may move, replace or remove it. */
    public const string NOVOTON = 'novoton';

    /** Novoton lists the same picture as an image added by hand: that image stays the admin's. */
    public const string BY_HAND = 'manual';

    public const string NO_ANSWER = 'no_answer';

    public const string EMPTY = 'empty';

    public const string UNCHANGED = 'unchanged';

    public const string UPDATED = 'updated';

    /** An image of the product could not be read, so a new photo could be a copy of it: none added. */
    public const string UNREADABLE = 'unreadable';

    /** @var \Closure(string): ?string */
    private readonly \Closure $download;

    private readonly bool $keepByHand;

    /**
     * @param (\Closure(string): ?string)|null $download the file at a URL, null when it does not come
     * @param bool|null $keepByHand "Keep photos added by hand"; null reads the setting
     */
    public function __construct(
        private readonly HotelImageRepositoryInterface $photos = new HotelImageRepository(),
        private readonly ProductImageStoreInterface $store = new ProductImageStore('novoton'),
        ?\Closure $download = null,
        ?bool $keepByHand = null,
    ) {
        $this->download = $download ?? static fn (string $url): ?string => (new ImageDownloader())->get($url);
        $this->keepByHand = $keepByHand ?? ConfigProvider::keepManualImages();
    }

    /**
     * The photo paths of a hotel_images answer, in Novoton's order, without
     * blanks and repeats. Null when it is no answer (a refusal or nothing).
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
            if ($path !== '' && !in_array($path, $paths, true)) {
                $paths[] = $path;
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
     * @return array{outcome: string, listed: int, known: int, added: int, same: int, failed: int, removed: int, moved: int}
     */
    public function sync(string $hotelId, int $productId, ?array $listed, string $now): array
    {
        $result = ['outcome' => self::UNCHANGED, 'listed' => 0, 'known' => 0, 'added' => 0, 'same' => 0, 'failed' => 0, 'removed' => 0, 'moved' => 0];
        if ($listed === null) {
            return ['outcome' => self::NO_ANSWER] + $result;
        }
        if ($listed === [] || $productId <= 0) {
            return ['outcome' => self::EMPTY] + $result;
        }

        $keys = [];
        foreach ($listed as $path) {
            $keys[sha1($path)] ??= $path;
        }
        $rows = array_filter($this->photos->forHotel($hotelId), static fn (array $row): bool => $row['product_id'] === $productId);
        $new = array_diff_key($keys, $rows);
        $result['listed'] = count($keys);
        $result['known'] = count($keys) - count($new);

        /** @var array<int, Image> $images */
        $images = [];
        foreach ($this->store->images($productId) as $image) {
            $images[$image['pair_id']] = $image;
        }

        if ($new !== []) {
            $added = $this->addNew($hotelId, $productId, $new, $rows, $images, $result, $now);
            if ($added === null) {
                $this->photos->markListed($hotelId, array_keys($keys), $now);

                return ['outcome' => self::UNREADABLE] + $result;
            }
            $rows += $added;
        }
        if ($result['added'] > 0) {
            $result['removed'] = $this->replace($productId, $hotelId, $keys, $rows, $images);
        }
        $result['moved'] = $this->follow($productId, $keys, $rows, $images);
        $this->photos->markListed($hotelId, array_keys($keys), $now);
        if ($result['added'] + $result['removed'] + $result['moved'] > 0) {
            $result['outcome'] = self::UPDATED;
        }

        return $result;
    }

    /**
     * Downloads the photos not seen before and adds those the product does
     * not have yet. On a product's first refresh (no rows yet) its images are
     * the ones its creation imported, so a photo matching one of them is
     * Novoton's; later, an image without a row was added by hand.
     *
     * @param array<string, string> $new path_key => path
     * @param array<string, PhotoRow> $rows the product's rows
     * @param array<int, Image> $images the product's images by pair id; added ones are put in
     * @param array{outcome: string, listed: int, known: int, added: int, same: int, failed: int, removed: int, moved: int} $result counts
     *
     * @return array<string, PhotoRow>|null the rows written; null when an image of the product cannot be read
     */
    private function addNew(string $hotelId, int $productId, array $new, array $rows, array &$images, array &$result, string $now): ?array
    {
        $prints = $this->prints($rows, $images);
        if ($prints === null) {
            return null;
        }
        $novoton = self::novotonPairs($rows);
        $firstRefresh = $rows === [];
        $hasMain = array_filter($images, static fn (array $image): bool => $image['main']) !== [];

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
            foreach ($prints as $id => $there) {
                if (ImageFingerprint::same($print, $there)) {
                    $pairId = $id;

                    break;
                }
            }
            if ($pairId > 0) {
                $result['same']++;
                $origin = isset($novoton[$pairId]) || $firstRefresh ? self::NOVOTON : self::BY_HAND;
            } else {
                $position = count($images);
                $pairId = $this->store->attach($productId, $bytes, !$hasMain);
                if ($pairId <= 0) {
                    $result['failed']++;

                    continue;
                }
                $result['added']++;
                $images[$pairId] = ['pair_id' => $pairId, 'main' => !$hasMain, 'position' => $position, 'file' => ''];
                $hasMain = true;
                $prints[$pairId] = $print;
                $origin = self::NOVOTON;
            }
            if ($origin === self::NOVOTON) {
                $novoton[$pairId] = true;
            }
            $saved[$key] = [
                'path_key' => $key,
                'path' => $path,
                'product_id' => $productId,
                'pair_id' => $pairId,
                'origin' => $origin,
                'sha1' => $print['sha1'],
                'dhash' => $print['dhash'],
                'listed' => true,
            ];
        }
        $this->photos->save($hotelId, array_values($saved), $now);

        return $saved;
    }

    /**
     * What each of the product's images holds: from its row when this sync
     * knows the image, else read from its file. Null when one cannot be read.
     *
     * @param array<string, PhotoRow> $rows
     * @param array<int, Image> $images
     *
     * @return array<int, array{sha1: string, dhash: string}>|null
     */
    private function prints(array $rows, array $images): ?array
    {
        $known = [];
        foreach ($rows as $row) {
            if ($row['pair_id'] > 0 && $row['sha1'] !== '') {
                $known[$row['pair_id']] = ['sha1' => $row['sha1'], 'dhash' => $row['dhash']];
            }
        }
        $prints = [];
        foreach ($images as $pairId => $image) {
            if (isset($known[$pairId])) {
                $prints[$pairId] = $known[$pairId];

                continue;
            }
            $bytes = $this->store->read($image['file']);
            if ($bytes === null) {
                return null;
            }
            $prints[$pairId] = ImageFingerprint::of($bytes);
        }

        return $prints;
    }

    /**
     * Removes the images not in Novoton's list now: Novoton's photos, and,
     * with "Keep photos added by hand" off, the others too. An image a
     * listed path points at stays.
     *
     * @param array<string, string> $keys path_key => path, as listed now
     * @param array<string, PhotoRow> $rows the product's rows, this refresh's included
     * @param array<int, Image> $images the product's images by pair id; removed ones are taken out
     *
     * @return int images removed
     */
    private function replace(int $productId, string $hotelId, array $keys, array $rows, array &$images): int
    {
        $listed = [];
        foreach (array_intersect_key($rows, $keys) as $row) {
            $listed[$row['pair_id']] = true;
        }
        $novoton = self::novotonPairs($rows);
        $gone = [];
        foreach (array_keys($images) as $pairId) {
            if (isset($listed[$pairId]) || (!isset($novoton[$pairId]) && $this->keepByHand)) {
                continue;
            }
            if ($this->store->remove($productId, $pairId)) {
                $gone[$pairId] = true;
                unset($images[$pairId]);
            }
        }

        // A photo removed now and listed again later counts as new; one an
        // admin removed stays remembered, so it is not brought back.
        $forget = [];
        foreach ($rows as $key => $row) {
            if (isset($gone[$row['pair_id']])) {
                $forget[] = (string) $key;
            }
        }
        $this->photos->forget($hotelId, $forget);

        return count($gone);
    }

    /**
     * Puts the photos in Novoton's order and makes Novoton's first photo the
     * main one. Photos Novoton no longer lists follow in their order. Images
     * added by hand stay where they are while they are kept, and so does a
     * main image added by hand; otherwise they go after Novoton's.
     *
     * @param array<string, string> $keys path_key => path, as listed now
     * @param array<string, PhotoRow> $rows the product's rows, this refresh's included
     * @param array<int, Image> $images the product's images by pair id, main first, then in gallery order
     *
     * @return int moves made (a place or the main image)
     */
    private function follow(int $productId, array $keys, array $rows, array $images): int
    {
        $novoton = self::novotonPairs($rows);
        $movable = fn (int $pairId): bool => isset($novoton[$pairId]) || !$this->keepByHand;

        $order = [];
        foreach (array_keys($keys) as $key) {
            $pairId = isset($rows[$key]) ? $rows[$key]['pair_id'] : 0;
            if (isset($images[$pairId]) && !isset($order[$pairId]) && $movable($pairId)) {
                $order[$pairId] = count($order);
            }
        }
        $cover = array_key_first($order) ?? 0;
        foreach (array_keys($images) as $pairId) {
            if (!isset($order[$pairId]) && $movable($pairId)) {
                $order[$pairId] = count($order);
            }
        }

        $moves = 0;
        foreach ($order as $pairId => $position) {
            if ($images[$pairId]['position'] !== $position) {
                $this->store->setPosition($productId, $pairId, $position);
                $moves++;
            }
        }
        $main = 0;
        foreach ($images as $pairId => $image) {
            if ($image['main']) {
                $main = $pairId;

                break;
            }
        }
        if ($cover > 0 && $cover !== $main && ($main === 0 || $movable($main))) {
            $this->store->makeMain($productId, $cover);
            $moves++;
        }

        return $moves;
    }

    /**
     * The images that are Novoton's photos.
     *
     * @param array<string, PhotoRow> $rows
     *
     * @return array<int, true>
     */
    private static function novotonPairs(array $rows): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            if ($row['origin'] === self::NOVOTON && $row['pair_id'] > 0) {
                $pairs[$row['pair_id']] = true;
            }
        }

        return $pairs;
    }
}
