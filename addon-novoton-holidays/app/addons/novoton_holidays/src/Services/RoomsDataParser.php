<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\ValueObjects\RoomType;

/**
 * Parses and processes multi-room booking form data.
 *
 * Extracted from BookingService (SRP) — pure data transformation, no I/O.
 * Handles parsing room JSON, extracting room info for DB columns,
 * calculating totals, and parsing children ages.
 *
 * @package NovotonHolidays
 * @since   3.4.0
 */
class RoomsDataParser
{
    /**
     * Parse rooms data from booking form input.
     *
     * Handles both JSON string and already-decoded array formats.
     * Falls back to a single room from flat booking fields if empty.
     *
     * @param array<string, mixed> $bookingData Form data
     * @return list<array<string, mixed>> Parsed rooms data
     */
    public function parseRoomsData(array $bookingData): array
    {
        $roomsData = [];

        if (!empty($bookingData['rooms_data'])) {
            $roomsData = is_string($bookingData['rooms_data'])
                ? json_decode($bookingData['rooms_data'], true)
                : $bookingData['rooms_data'];
        }

        // Create default room if empty
        if (empty($roomsData) || !is_array($roomsData)) {
            $roomsData = [[
                'room_id' => $bookingData['room_id'] ?? '',
                'room_name' => RoomType::formatRoomLabel(TypeCoerce::toString($bookingData['room_id'] ?? '')),
                'board_id' => $bookingData['board_id'] ?? 'BB',
                'ext_board_id' => $bookingData['ext_board_id'] ?? '',
                'adults' => TypeCoerce::toInt($bookingData['adults'] ?? 2),
                'children' => TypeCoerce::toInt($bookingData['children'] ?? 0),
                'childrenAges' => $this->parseChildrenAges($bookingData),
                'price' => TypeCoerce::toFloat($bookingData['total_price'] ?? 0),
            ]];
        }

        return TypeCoerce::toRowList($roomsData);
    }

    /**
     * Extract room IDs and types for database columns.
     *
     * Concatenates room_id and room display names from all rooms.
     *
     * @param array<string, mixed> $roomsData Parsed rooms data
     * @param array<string, mixed> $bookingData Booking data fallback
     * @return array{room_id: string, room_type: string}
     */
    public function extractRoomInfo(array $roomsData, array $bookingData): array
    {
        $roomIds = [];
        $roomTypes = [];

        foreach (TypeCoerce::toRowList($roomsData) as $room) {
            if (!empty($room['room_id'])) {
                $roomIds[] = TypeCoerce::toString($room['room_id']);
            }
            if (!empty($room['room_name'])) {
                $roomTypes[] = TypeCoerce::toString($room['room_name']);
            } elseif (!empty($room['room_type_display'])) {
                $roomTypes[] = TypeCoerce::toString($room['room_type_display']);
            } elseif (!empty($room['room_id'])) {
                $roomTypes[] = RoomType::formatRoomLabel(TypeCoerce::toString($room['room_id']));
            }
        }

        // Fallback to bookingData
        if (empty($roomIds) && !empty($bookingData['room_id'])) {
            $roomIds[] = TypeCoerce::toString($bookingData['room_id']);
            $roomTypes[] = RoomType::formatRoomLabel(TypeCoerce::toString($bookingData['room_id']));
        }

        return [
            'room_id' => implode(', ', $roomIds),
            'room_type' => implode(', ', $roomTypes),
        ];
    }

    /**
     * Calculate aggregate totals from rooms data.
     *
     * @param array<string, mixed> $roomsData Parsed rooms data
     * @return array{adults: int, children: int, ages: int[], price: float}
     */
    public function calculateTotals(array $roomsData): array
    {
        $totals = [
            'adults' => 0,
            'children' => 0,
            'ages' => [],
            'price' => 0,
        ];

        foreach (TypeCoerce::toRowList($roomsData) as $room) {
            $totals['adults'] += TypeCoerce::toInt($room['adults'] ?? 0);
            $totals['children'] += TypeCoerce::toInt($room['children'] ?? 0);
            $totals['price'] += TypeCoerce::toFloat($room['price'] ?? 0);

            if (!empty($room['childrenAges'])) {
                foreach (TypeCoerce::toList($room['childrenAges']) as $age) {
                    if ($age !== null && $age !== 'age_needed') {
                        $totals['ages'][] = TypeCoerce::toInt($age);
                    }
                }
            }
        }

        return $totals;
    }

    /**
     * Parse children ages from booking form data.
     *
     * Handles both comma-separated string and array formats.
     *
     * @param array<string, mixed> $bookingData Booking data
     * @return int[] Ages
     */
    public function parseChildrenAges(array $bookingData): array
    {
        if (empty($bookingData['children_ages'])) {
            return [];
        }

        if (is_string($bookingData['children_ages'])) {
            return array_map('intval', array_filter(
                explode(',', $bookingData['children_ages']),
                fn (string $v): bool => $v !== '',
            ));
        }

        return TypeCoerce::toIntList($bookingData['children_ages']);
    }
}
