<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Facility Repository
 *
 * Centralized database access for facilities data.
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;
use Tygh\Addons\TravelCore\Services\FacilityLabelResolver;

class FacilityRepository implements FacilityRepositoryInterface
{
    use RowNarrowingTrait;

    /** Provider key in ?:travel_api_alias — seeded by NovotonAliasSeedData. */
    private const string API_SOURCE = 'novoton';

    /** @var array<string, string> Allowed facility name columns by language */
    private const array NAME_COLUMNS = [
        'ro' => 'facility_name_ro',
        'en' => 'facility_name_en',
    ];

    private static function nameField(string $lang): string
    {
        return self::NAME_COLUMNS[$lang] ?? self::NAME_COLUMNS['en'];
    }

    /**
     * Find facility by ID
     * @return array<string, mixed>|null
     */
    public function findById(int $facility_id): ?array
    {
        $facility = self::asRow(db_get_row('SELECT * FROM ?:novoton_facilities WHERE facility_id = ?i', $facility_id));
        return $facility === [] ? null : $facility;
    }

    /**
     * Find all facilities
     * @return list<array<string, mixed>>
     */
    public function findAll(string $lang = 'en'): array
    {
        $col = self::nameField($lang);
        return self::asRowList(db_get_array("SELECT facility_id, {$col} as facility_name FROM ?:novoton_facilities ORDER BY {$col}"));
    }

    /**
     * Find all facilities with all columns (for admin listing).
     * @return list<array<string, mixed>>
     */
    public function findAllFull(): array
    {
        return self::asRowList(db_get_array('SELECT * FROM ?:novoton_facilities ORDER BY facility_name_en'));
    }

    /**
     * Check if facility exists
     */
    public function exists(int $facility_id): bool
    {
        return TypeCoerce::toInt(db_get_field('SELECT 1 FROM ?:novoton_facilities WHERE facility_id = ?i', $facility_id)) > 0;
    }

    /**
     * Save facility (insert or update)
     */
    public function save(int $facility_id, string $name_en, string $name_ro = ''): bool
    {
        if (empty($name_ro)) {
            $name_ro = $name_en;
        }

        if ($this->exists($facility_id)) {
            return (bool) db_query(
                'UPDATE ?:novoton_facilities SET facility_name_en = ?s, facility_name_ro = ?s WHERE facility_id = ?i',
                $name_en,
                $name_ro,
                $facility_id,
            );
        }

        return (bool) db_query(
            'INSERT INTO ?:novoton_facilities (facility_id, facility_name_en, facility_name_ro) VALUES (?i, ?s, ?s)',
            $facility_id,
            $name_en,
            $name_ro,
        );
    }

    /**
     * Delete facility
     */
    public function delete(int $facility_id): bool
    {
        // Delete hotel links first
        db_query('DELETE FROM ?:novoton_hotel_facilities WHERE facility_id = ?i', $facility_id);
        return (bool) db_query('DELETE FROM ?:novoton_facilities WHERE facility_id = ?i', $facility_id);
    }

    /**
     * Count facilities
     */
    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:novoton_facilities'));
    }

    // =========================================================================
    // Hotel-Facility Links
    // =========================================================================

    /**
     * Get facilities for a hotel
     * @return list<array<string, mixed>>
     */
    public function getForHotel(string $hotel_id, string $lang = 'en'): array
    {
        $col = self::nameField($lang);

        return self::asRowList(db_get_array(
            // The language column, then English, then the raw name: an RO
            // column left blank by the sync must not leave a nameless chip.
            "SELECT hf.facility_id, COALESCE(NULLIF(f.{$col}, ''), NULLIF(f.facility_name_en, ''), f.facility_name) as facility_name
             FROM ?:novoton_hotel_facilities hf
             LEFT JOIN ?:novoton_facilities f ON hf.facility_id = f.facility_id
             WHERE hf.hotel_id = ?s
             ORDER BY facility_name",
            $hotel_id,
        ));
    }

    /**
     * Localized facility labels for one hotel, for the booking-form summary
     * sidebar.
     *
     * The booking sidebar asks for all of them and shows the first few as
     * chips with "+N more" (Travel Core -> Display setting).
     *
     * @return list<string>
     */
    public function getLabelsForHotel(string $hotel_id, string $lang = 'en', int $limit = 6): array
    {
        if ($hotel_id === '') {
            return [];
        }

        // Names come from the SHARED feature map, not from this addon's own
        // facility_name_ro column: fn_novoton_holidays_sync_facilities_list()
        // fills that column with a verbatim copy of the English name, so a
        // Romanian storefront was listing "Air conditioning/Heating". Novoton's
        // facility ids are already aliased to canonical codes that carry real
        // Romanian (NovotonAliasSeedData + travel_core's seed), and the raw
        // provider name stays as the fallback for anything unmapped.
        $facilities = [];
        foreach ($this->getForHotel($hotel_id, $lang) as $row) {
            $facilities[] = [
                'id' => TypeCoerce::toString($row['facility_id'] ?? ''),
                'name' => TypeCoerce::toString($row['facility_name'] ?? ''),
            ];
        }

        return FacilityLabelResolver::labels(
            self::API_SOURCE,
            $facilities,
            $lang,
            $limit,
            // Never drop a facility: one nobody named reads "Facility #27".
            function_exists('__') ? TypeCoerce::toString(__('travel_core.facility_unnamed')) : 'Facility #[code]',
        );
    }

    /**
     * Get facility IDs for a hotel
     * @return list<int>
     */
    public function getIdsForHotel(string $hotel_id): array
    {
        return self::asIntList(db_get_fields('SELECT facility_id FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s', $hotel_id));
    }

    /**
     * Link facility to hotel
     */
    public function linkToHotel(string $hotel_id, int $facility_id): bool
    {
        return (bool) db_query(
            'INSERT IGNORE INTO ?:novoton_hotel_facilities (hotel_id, facility_id) VALUES (?s, ?i)',
            $hotel_id,
            $facility_id,
        );
    }

    /**
     * Unlink facility from hotel
     */
    public function unlinkFromHotel(string $hotel_id, int $facility_id): bool
    {
        return (bool) db_query(
            'DELETE FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s AND facility_id = ?i',
            $hotel_id,
            $facility_id,
        );
    }

    /**
     * Clear all facilities for a hotel
     */
    public function clearHotelFacilities(string $hotel_id): bool
    {
        return (bool) db_query('DELETE FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s', $hotel_id);
    }

    /**
     * Set facilities for a hotel (replace all)
     * @param list<int> $facility_ids
     */
    public function setHotelFacilities(string $hotel_id, array $facility_ids): bool
    {
        db_query('START TRANSACTION');
        try {
            $this->clearHotelFacilities($hotel_id);

            foreach ($facility_ids as $facility_id) {
                $this->linkToHotel($hotel_id, (int) $facility_id);
            }

            db_query('COMMIT');
            return true;
        } catch (\Exception $e) {
            db_query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Count hotels with a specific facility
     */
    public function countHotelsWithFacility(int $facility_id): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotel_facilities WHERE facility_id = ?i',
            $facility_id,
        ));
    }

    // =========================================================================
    // Type-filtered queries
    // =========================================================================

    /**
     * Get facilities for a hotel filtered by feature type (hotel_facility, room_facility, travel_group, beach_access)
     * @return list<array<string, mixed>>
     */
    public function getForHotelByType(string $hotel_id, string $facility_type, string $lang = 'en'): array
    {
        $col = self::nameField($lang);

        return self::asRowList(db_get_array(
            "SELECT f.facility_id, f.{$col} as facility_name
             FROM ?:novoton_hotel_facilities hf
             JOIN ?:novoton_facilities f ON hf.facility_id = f.facility_id
             WHERE hf.hotel_id = ?s AND f.facility_type = ?s
             ORDER BY f.{$col}",
            $hotel_id,
            $facility_type,
        ));
    }

    /**
     * Get all facilities for a hotel, grouped by feature type.
     * Returns ['hotel_facility' => [['facility_id' => 1, ...], ...], 'travel_group' => [...], ...]
     * @return array<string, list<array<string, mixed>>>
     */
    public function getForHotelGroupedByType(string $hotel_id, string $lang = 'en'): array
    {
        $col = self::nameField($lang);

        $rows = self::asRowList(db_get_array(
            "SELECT f.facility_id, f.facility_type, f.{$col} as facility_name
             FROM ?:novoton_hotel_facilities hf
             JOIN ?:novoton_facilities f ON hf.facility_id = f.facility_id
             WHERE hf.hotel_id = ?s
             ORDER BY f.facility_type, f.{$col}",
            $hotel_id,
        ));

        $grouped = [];
        foreach ($rows as $row) {
            $type = TypeCoerce::toString($row['facility_type'] ?? '');
            if ($type === '') {
                continue;
            }
            $grouped[$type][] = $row;
        }
        return $grouped;
    }

    /**
     * Update facility feature type mapping
     */
    public function updateType(int $facility_id, string $facility_type): bool
    {
        return (bool) db_query(
            'UPDATE ?:novoton_facilities SET facility_type = ?s WHERE facility_id = ?i',
            $facility_type,
            $facility_id,
        );
    }

    /**
     * Update facility type and Romanian translation.
     */
    public function updateTypeAndTranslation(int $facility_id, string $facility_type, ?string $name_ro = null): bool
    {
        if ($name_ro !== null) {
            return (bool) db_query(
                'UPDATE ?:novoton_facilities SET facility_type = ?s, facility_name_ro = ?s WHERE facility_id = ?i',
                $facility_type,
                $name_ro,
                $facility_id,
            );
        }
        return $this->updateType($facility_id, $facility_type);
    }

    /**
     * Get the latest synced_at timestamp across all facilities.
     */
    public function getLastSyncedAt(): ?string
    {
        $val = TypeCoerce::toString(db_get_field('SELECT MAX(synced_at) FROM ?:novoton_facilities'));
        return $val === '' ? null : $val;
    }
}
