<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Support;

use Tygh\Addons\NovotonHolidays\Repository\HotelImageRepositoryInterface;

/**
 * ?:novoton_hotel_images in memory.
 *
 * @phpstan-import-type PhotoRow from HotelImageRepositoryInterface
 */
final class PhotoTable implements HotelImageRepositoryInterface
{
    /** @var array<string, array<string, PhotoRow>> */
    private array $rows = [];

    /** @var list<array{hotel_id: string, hotel_name: string, product_id: int}> hotels with a product, least recently checked first */
    public array $hotels = [];

    /** @var array<string, string> hotel_id => when its photos were checked */
    public array $checked = [];

    public function forHotel(string $hotelId): array
    {
        return $this->rows[$hotelId] ?? [];
    }

    public function save(string $hotelId, array $rows, string $now): void
    {
        foreach ($rows as $row) {
            $this->rows[$hotelId][$row['path_key']] = $row + ['listed' => true];
        }
    }

    public function markListed(string $hotelId, array $pathKeys, string $now): void
    {
        foreach ($this->rows[$hotelId] ?? [] as $key => $row) {
            $this->rows[$hotelId][$key]['listed'] = in_array($key, $pathKeys, true);
        }
    }

    public function forget(string $hotelId, array $pathKeys): void
    {
        foreach ($pathKeys as $key) {
            unset($this->rows[$hotelId][$key]);
        }
    }

    public function due(int $limit, string $hotelId = ''): array
    {
        $due = array_values(array_filter($this->hotels, static fn (array $h): bool => $hotelId === '' || $h['hotel_id'] === $hotelId));

        return array_slice($due, 0, $limit);
    }

    public function linkedCount(): int
    {
        return count($this->hotels);
    }

    public function stampChecked(string $hotelId, string $now): void
    {
        $this->checked[$hotelId] = $now;
    }
}
