<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

/**
 * Which product image each Novoton hotel photo became
 * (?:novoton_hotel_images), and which hotels' photos are due a refresh.
 *
 * @phpstan-type PhotoRow array{path_key: string, path: string, product_id: int, pair_id: int, origin: string, sha1: string, dhash: string, listed: bool}
 * @phpstan-type PhotoSave array{path_key: string, path: string, product_id: int, pair_id: int, origin: string, sha1: string, dhash: string}
 */
interface HotelImageRepositoryInterface
{
    /**
     * The hotel's known photos.
     *
     * @return array<string, PhotoRow> path_key => row
     */
    public function forHotel(string $hotelId): array;

    /**
     * Records photos (path_key is unique per hotel): first seen now when new.
     *
     * @param list<PhotoSave> $rows
     */
    public function save(string $hotelId, array $rows, string $now): void;

    /**
     * The photos the latest answer listed; the hotel's others are marked unlisted.
     *
     * @param list<string> $pathKeys
     */
    public function markListed(string $hotelId, array $pathKeys, string $now): void;

    /**
     * Forgets photos, so the same path listed again counts as new.
     *
     * @param list<string> $pathKeys
     */
    public function forget(string $hotelId, array $pathKeys): void;

    /**
     * Hotels with a product, their photos checked longest ago first (never checked first).
     *
     * @return list<array{hotel_id: string, hotel_name: string, product_id: int}>
     */
    public function due(int $limit, string $hotelId = ''): array;

    /** How many hotels have a product. */
    public function linkedCount(): int;

    /** The hotel's photos were checked now. */
    public function stampChecked(string $hotelId, string $now): void;
}
