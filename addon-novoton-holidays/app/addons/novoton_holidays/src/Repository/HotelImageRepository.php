<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * @phpstan-import-type PhotoRow from HotelImageRepositoryInterface
 */
final class HotelImageRepository implements HotelImageRepositoryInterface
{
    #[\Override]
    public function forHotel(string $hotelId): array
    {
        $rows = [];
        foreach (TypeCoerce::toRowList(db_get_array(
            'SELECT path_key, path, product_id, pair_id, origin, sha1, dhash, listed FROM ?:novoton_hotel_images WHERE hotel_id = ?s',
            $hotelId,
        )) as $row) {
            $key = TypeCoerce::toString($row['path_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $rows[$key] = [
                'path_key' => $key,
                'path' => TypeCoerce::toString($row['path'] ?? ''),
                'product_id' => TypeCoerce::toInt($row['product_id'] ?? 0),
                'pair_id' => TypeCoerce::toInt($row['pair_id'] ?? 0),
                'origin' => TypeCoerce::toString($row['origin'] ?? ''),
                'sha1' => TypeCoerce::toString($row['sha1'] ?? ''),
                'dhash' => TypeCoerce::toString($row['dhash'] ?? ''),
                'listed' => TypeCoerce::toString($row['listed'] ?? '') === 'Y',
            ];
        }

        return $rows;
    }

    #[\Override]
    public function save(string $hotelId, array $rows, string $now): void
    {
        $values = [];
        foreach ($rows as $row) {
            $values[] = db_quote(
                '(?s, ?s, ?s, ?i, ?i, ?s, ?s, ?s, ?s, ?s, ?s)',
                $hotelId,
                $row['path_key'],
                $row['path'],
                $row['product_id'],
                $row['pair_id'],
                $row['origin'],
                $row['sha1'],
                $row['dhash'],
                'Y',
                $now,
                $now,
            );
        }
        if ($values === []) {
            return;
        }
        db_query(
            'INSERT INTO ?:novoton_hotel_images
                (hotel_id, path_key, path, product_id, pair_id, origin, sha1, dhash, listed, first_seen_at, last_seen_at)
             VALUES ?p
             ON DUPLICATE KEY UPDATE
                path = VALUES(path), product_id = VALUES(product_id), pair_id = VALUES(pair_id), origin = VALUES(origin),
                sha1 = VALUES(sha1), dhash = VALUES(dhash), listed = VALUES(listed), last_seen_at = VALUES(last_seen_at)',
            implode(', ', $values),
        );
    }

    #[\Override]
    public function markListed(string $hotelId, array $pathKeys, string $now): void
    {
        if ($pathKeys === []) {
            return;
        }
        db_query(
            "UPDATE ?:novoton_hotel_images SET listed = 'Y', last_seen_at = ?s WHERE hotel_id = ?s AND path_key IN (?a)",
            $now,
            $hotelId,
            $pathKeys,
        );
        db_query(
            "UPDATE ?:novoton_hotel_images SET listed = 'N' WHERE hotel_id = ?s AND path_key NOT IN (?a) AND listed = 'Y'",
            $hotelId,
            $pathKeys,
        );
    }

    #[\Override]
    public function forget(string $hotelId, array $pathKeys): void
    {
        if ($pathKeys !== []) {
            db_query('DELETE FROM ?:novoton_hotel_images WHERE hotel_id = ?s AND path_key IN (?a)', $hotelId, $pathKeys);
        }
    }

    #[\Override]
    public function due(int $limit, string $hotelId = ''): array
    {
        $rows = TypeCoerce::toRowList(db_get_array(
            'SELECT h.hotel_id, h.hotel_name, h.product_id FROM ?:novoton_hotels h
             INNER JOIN ?:products p ON p.product_id = h.product_id
             WHERE h.product_id > 0 ?p
             ORDER BY h.images_checked_at IS NULL DESC, h.images_checked_at, h.hotel_id
             LIMIT ?i',
            $hotelId !== '' ? db_quote('AND h.hotel_id = ?s', $hotelId) : '',
            max(1, $limit),
        ));

        return array_map(static fn (array $row): array => [
            'hotel_id' => TypeCoerce::toString($row['hotel_id'] ?? ''),
            'hotel_name' => TypeCoerce::toString($row['hotel_name'] ?? ''),
            'product_id' => TypeCoerce::toInt($row['product_id'] ?? 0),
        ], $rows);
    }

    #[\Override]
    public function linkedCount(): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels h INNER JOIN ?:products p ON p.product_id = h.product_id WHERE h.product_id > 0',
        ));
    }

    #[\Override]
    public function stampChecked(string $hotelId, string $now): void
    {
        db_query('UPDATE ?:novoton_hotels SET images_checked_at = ?s WHERE hotel_id = ?s', $now, $hotelId);
    }
}
