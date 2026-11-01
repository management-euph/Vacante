<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;

/**
 * Runs the multi-room availability search: builds one price request per
 * distinct occupancy (rooms with the same adults and children's ages share
 * one answer: twelve rooms of three kinds are three requests, keyed by the
 * first such room), fires them in parallel (curl_multi batch), reads the
 * hotel's quota once (rooms left per type, 0 = on request, as the one-room
 * search does), parses each room's options from its occupancy's answer, and
 * aggregates them into the standard availability envelope (first room's options
 * as `results`, every room's options in `all_room_results`, total option count,
 * max capacity, early-booking discounts).
 *
 * Extracted verbatim from HotelAvailabilitySearcher::searchMultiRoom. The
 * searcher delegates and passes its own debug sink as the $log callback, so the
 * batch's debug lines still surface through HotelAvailabilitySearcher::getDebugLog().
 */
class MultiRoomSearchBatcher
{
    private readonly SearchServiceInterface $searchService;
    private readonly AvailabilityResultNormalizer $normalizer;

    public function __construct(SearchServiceInterface $searchService, AvailabilityResultNormalizer $normalizer)
    {
        $this->searchService = $searchService;
        $this->normalizer = $normalizer;
    }

    /**
     * @param array<string, mixed> $roomsData
     * @param array<string, mixed> $roomTypeMap
     * @param callable(string): void $log
     * @return array{results: list<array<string, mixed>>, all_room_results: array<int, list<array<string, mixed>>>, is_multi_room: bool, multi_room_total_options: int, no_availability: bool, max_room_capacity: array<string, int>, early_booking_discounts: list<array<string, mixed>>, early_booking_range: array<string, mixed>}
     */
    public function search(
        NovotonApiKitInterface $api,
        string $hotelId,
        string $checkIn,
        string $checkOut,
        int $nights,
        string $mealPlan,
        array $roomsData,
        array $roomTypeMap,
        callable $log,
    ): array {
        $log('=== MULTI-ROOM SEARCH MODE ===');

        // Build one request per distinct occupancy upfront for batch execution
        $batchRequests = [];
        $roomMeta = []; // roomKey => occupancy metadata and the request it reads
        $requestFor = []; // occupancy => the request key of its first room

        foreach ($roomsData as $roomIdx => $roomOccupancy) {
            $roomNum = PriceInfoFormatter::toInt($roomIdx) + 1;
            /** @var array<string, mixed> $roomOccupancy */
            $roomOccupancy = is_array($roomOccupancy) ? $roomOccupancy : [];
            $roomAdults = PriceInfoFormatter::toInt($roomOccupancy['adults'] ?? 2);
            $roomChildrenCount = PriceInfoFormatter::toInt($roomOccupancy['children'] ?? 0);
            /** @var list<mixed> $rawChildrenAges */
            $rawChildrenAges = is_array($roomOccupancy['childrenAges'] ?? null) ? $roomOccupancy['childrenAges'] : [];
            $roomChildrenAges = $this->normalizer->cleanChildrenAges($rawChildrenAges);

            $log("--- Room #{$roomNum}: {$roomAdults} adults, {$roomChildrenCount} children ---");
            if (!empty($roomChildrenAges)) {
                $log('Children ages: ' . implode(', ', $roomChildrenAges));
            }

            $roomKey = "room_{$roomNum}";
            $ages = $roomChildrenAges;
            sort($ages);
            $occupancy = $roomAdults . '|' . implode(',', $ages);
            if (!isset($requestFor[$occupancy])) {
                $requestFor[$occupancy] = $roomKey;
                $batchRequests[$roomKey] = [
                    'hotel_id' => $hotelId,
                    'room_id' => '',
                    'board_id' => '',
                    'star_rating' => '',
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'adults' => $roomAdults,
                    'children' => $roomChildrenAges,
                ];
            }
            $roomMeta[$roomKey] = [
                'roomNum' => $roomNum,
                'request' => $requestFor[$occupancy],
                'occupancy' => "{$roomAdults} adults"
                    . ($roomChildrenCount > 0 ? ", {$roomChildrenCount} children" : ''),
            ];
        }

        // Execute one request per occupancy in parallel via curl_multi
        $log('Sending ' . count($batchRequests) . ' price requests for ' . count($roomMeta) . ' rooms in parallel via curl_multi');
        $batchResponses = $api->pricing()->getRoomPriceBatch($batchRequests, count($batchRequests));

        // Rooms left per type (0 = on request), as the one-room search reads them
        $quotaMap = [];
        try {
            $quotaMap = $api->availability()->getHotelQuotaAll($hotelId, $checkIn, $checkOut);
        } catch (\Throwable $e) {
            $log('=== QUOTA FETCH ERROR: ' . $e->getMessage() . ' ===');
        }

        // Process each room from its occupancy's answer
        $allRoomResults = [];
        foreach ($roomMeta as $meta) {
            $response = $batchResponses[$meta['request']] ?? ['data' => false, 'rawXml' => ''];
            $roomNum = $meta['roomNum'];
            $occupancyStr = $meta['occupancy'];
            $roomResults = [];

            $priceData = $response['data'];
            $rawXml = $response['rawXml'];

            if ($priceData !== false && !empty($rawXml)) {
                $log("  Room #{$roomNum}: API response received (parsing...)");
                $roomResults = $this->searchService->parseRoomPriceResponse(
                    $rawXml,
                    $nights,
                    $checkIn,
                    $checkOut,
                    $mealPlan,
                    $quotaMap,
                    $roomTypeMap,
                    $roomNum,
                    $occupancyStr,
                );
            } else {
                $log("  Room #{$roomNum}: No response or empty data");
            }

            $allRoomResults[$roomNum] = $roomResults;
            $log('  Found ' . count($roomResults) . " options for Room #{$roomNum}");
        }

        // Ensure all room numbers are present (in order)
        ksort($allRoomResults);

        // ── Aggregate results ────────────────────────────────────────
        $totalOptions = 0;
        $firstResults = [];
        foreach ($allRoomResults as $rr) {
            $totalOptions += count($rr);
            if (empty($firstResults) && !empty($rr)) {
                $firstResults = $rr;
            }
        }

        // Early booking discounts
        $earlyBookingDiscounts = SearchService::getEarlyBookingDiscounts($hotelId, $checkIn, $checkOut);
        $discountRange = SearchService::getDiscountRange($earlyBookingDiscounts);

        return [
            'results' => $firstResults,
            'all_room_results' => $allRoomResults,
            'is_multi_room' => true,
            'multi_room_total_options' => $totalOptions,
            'no_availability' => ($totalOptions === 0),
            'max_room_capacity' => $this->normalizer->calculateMaxCapacity($firstResults),
            'early_booking_discounts' => $earlyBookingDiscounts,
            'early_booking_range' => $discountRange,
        ];
    }
}
