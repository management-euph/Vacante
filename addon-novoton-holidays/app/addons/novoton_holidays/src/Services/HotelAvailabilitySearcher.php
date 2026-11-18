<?php

declare(strict_types=1);

/**
 * Orchestrates hotel-specific availability searches against the Novoton API.
 *
 * Fetches cached hotel info, builds room-type and board-type maps, then
 * dispatches either single-room or multi-room API calls and returns the
 * structured result arrays the controller and templates expect.
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class HotelAvailabilitySearcher implements HotelAvailabilitySearcherInterface
{
    /**  */
    private readonly SearchServiceInterface $searchService;

    /**  */
    private bool $debug;

    /** @var string[] Accumulated debug lines (read via getDebugLog) */
    private array $debugLog = [];

    /** Pure hotelinfo-XML parsing (rooms, board types, room-type map). */
    private readonly HotelInfoExtractor $hotelInfoExtractor;

    /** Pure result shaping (max capacity, children-ages cleanup, empty envelope). */
    private readonly AvailabilityResultNormalizer $resultNormalizer;

    /** Multi-room parallel search + aggregation. */
    private readonly MultiRoomSearchBatcher $multiRoomBatcher;

    public function __construct(SearchServiceInterface $searchService, bool $debug = false, ?HotelInfoExtractor $hotelInfoExtractor = null)
    {
        $this->searchService = $searchService;
        $this->debug = $debug;
        $this->hotelInfoExtractor = $hotelInfoExtractor ?? new HotelInfoExtractor();
        $this->resultNormalizer = new AvailabilityResultNormalizer();
        $this->multiRoomBatcher = new MultiRoomSearchBatcher($this->searchService, $this->resultNormalizer);
    }

    // =====================================================================
    // Public API
    // =====================================================================

    /**
     * Search a specific hotel for room availability.
     *
     * @param array<string, mixed> $params Normalized params from SearchParameterNormalizer
     * @return array{
     *   results: list<array<string, mixed>>,
     *   all_room_results: array<int, list<array<string, mixed>>>,
     *   is_multi_room: bool,
     *   multi_room_total_options: int,
     *   no_availability: bool,
     *   max_room_capacity: array<string, int>,
     *   early_booking_discounts: list<array<string, mixed>>,
     *   early_booking_range: array<string, mixed>
     * }
     */
    #[\Override]
    public function search(array $params): array
    {
        $hotelId = PriceInfoFormatter::toScalar($params['hotel_id']);
        $checkIn = PriceInfoFormatter::toScalar($params['check_in']);
        $checkOut = PriceInfoFormatter::toScalar($params['check_out']);
        $nights = PriceInfoFormatter::toInt($params['nights']);
        $adults = PriceInfoFormatter::toInt($params['adults']);
        /** @var list<int> $children */
        $children = is_array($params['children']) ? $params['children'] : [];
        $mealPlan = PriceInfoFormatter::toScalar($params['meal_plan']);
        $numRooms = PriceInfoFormatter::toInt($params['num_rooms']);
        /** @var array<string, mixed> $roomsData */
        $roomsData = is_array($params['rooms_data']) ? $params['rooms_data'] : [];

        $this->log('=== SEARCH DEBUG ===');
        $this->log("Hotel ID: {$hotelId}");
        $this->log("Check-in: {$checkIn}, Check-out: {$checkOut}, Nights: {$nights}");
        $this->log("Adults: {$adults}, Children: " . json_encode($children));
        $this->log('Meal Plan: ' . ($mealPlan ?: 'ALL'));

        $this->logCircuitStatus();

        // ── Cached hotel info ────────────────────────────────────────
        $hotelInfo = _nvt_get_cached_hotel_info($hotelId);

        $this->logHotelInfo($hotelInfo);

        if ($hotelInfo === null || !isset($hotelInfo->rooms)) {
            return $this->resultNormalizer->emptyResult();
        }

        // ── Rooms / boards / packages from XML ──────────────────────
        $rooms = $this->hotelInfoExtractor->extractRooms($hotelInfo);
        $roomTypeMap = $this->hotelInfoExtractor->buildRoomTypeMap($rooms);
        if ($this->debug && !empty($roomTypeMap)) {
            $this->log('=== ROOM TYPE MAP ===');
            foreach ($roomTypeMap as $rtId => $rtType) {
                $this->log("  {$rtId}: " . PriceInfoFormatter::toScalar($rtType));
            }
        }

        // ── API client ──────────────────────────────────────────────
        // Typed as the narrow kit interface so this method can only
        // reach the five domain sub-clients (+ debugInfo) and never
        // falls back to the deprecated NovotonApi facade methods.
        $api = fn_novoton_holidays_get_api();
        if ($api === null) {
            fn_set_notification(
                'W',
                __('warning'),
                __(
                    'novoton_holidays.api_unavailable',
                    ['[default]' => 'API is temporarily unavailable. Please try again later.'],
                ),
            );
            return $this->resultNormalizer->emptyResult();
        }

        // ── Single vs multi-room dispatch ────────────────────────────
        if ($numRooms > 1 && count($roomsData) > 1) {
            return $this->multiRoomBatcher->search(
                $api,
                $hotelId,
                $checkIn,
                $checkOut,
                $nights,
                $mealPlan,
                $roomsData,
                $roomTypeMap,
                function (string $message): void {
                    $this->log($message);
                },
            );
        }

        return $this->searchSingleRoom(
            $api,
            $hotelId,
            $checkIn,
            $checkOut,
            $nights,
            $adults,
            $children,
            $mealPlan,
            $roomsData,
            $roomTypeMap,
        );
    }

    /**
     * @return string[]
     */
    #[\Override]
    public function getDebugLog(): array
    {
        return $this->debugLog;
    }

    /**
     * @return \SimpleXMLElement[] The rooms XML nodes (for alternative search)
     */
    #[\Override]
    public function getRooms(string $hotelId): array
    {
        $hotelInfo = _nvt_get_cached_hotel_info($hotelId);
        if ($hotelInfo === null || !isset($hotelInfo->rooms)) {
            return [];
        }
        return $this->hotelInfoExtractor->extractRooms($hotelInfo);
    }

    /**
     * @return string[] Board type identifiers
     */
    #[\Override]
    public function getBoardTypes(string $hotelId, string $mealPlan = ''): array
    {
        $hotelInfo = _nvt_get_cached_hotel_info($hotelId);
        if ($hotelInfo === null) {
            return [];
        }
        return $this->hotelInfoExtractor->extractBoardTypes($hotelInfo, $mealPlan);
    }

    // =====================================================================
    // Single-room search
    // =====================================================================

    /**
     * @param list<int> $children
     * @param array<string, mixed> $roomsData
     * @param array<string, mixed> $roomTypeMap
     * @return array{results: list<array<string, mixed>>, all_room_results: array<int, list<array<string, mixed>>>, is_multi_room: bool, multi_room_total_options: int, no_availability: bool, max_room_capacity: array<string, int>, early_booking_discounts: list<array<string, mixed>>, early_booking_range: array<string, mixed>}
     */
    private function searchSingleRoom(
        NovotonApiKitInterface $api,
        string $hotelId,
        string $checkIn,
        string $checkOut,
        int $nights,
        int $adults,
        array $children,
        string $mealPlan,
        array $roomsData,
        array $roomTypeMap,
    ): array {
        $singleRoomChildren = $children;
        $firstRoom = reset($roomsData);
        if (empty($singleRoomChildren) && is_array($firstRoom) && !empty($firstRoom['childrenAges'])) {
            /** @var list<mixed> $rawAges0 */
            $rawAges0 = is_array($firstRoom['childrenAges']) ? $firstRoom['childrenAges'] : [];
            $singleRoomChildren = $this->resultNormalizer->cleanChildrenAges($rawAges0);
        }

        $this->log('=== SINGLE ROOM SEARCH MODE ===');
        $this->log("Adults: {$adults}, Children count: " . count($singleRoomChildren));

        $priceParams = [
            'hotel_id' => $hotelId,
            'room_id' => '',
            'board_id' => '',
            'star_rating' => '',
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => $adults,
            'children' => $singleRoomChildren,
        ];

        $pricing = $api->pricing();
        $priceData = $pricing->getRoomPrice($priceParams);

        $this->logSingleRoomDebug($api, $hotelId, $priceParams);

        $results = [];
        if ($priceData !== false) {
            $rawXml = $api->debugInfo()->lastResponse;
            $this->log('=== PARSING ROOM_PRICE RESPONSE ===');

            // Fetch room quota for all rooms
            $quotaMap = [];
            try {
                $quotaMap = $api->availability()->getHotelQuotaAll($hotelId, $checkIn, $checkOut);
                $this->log('=== ROOM QUOTA (hotel_quota API) ===');
                foreach ($quotaMap as $qRoom => $qValue) {
                    $this->log("  {$qRoom}: {$qValue}");
                }
            } catch (\Exception $e) {
                $this->log('=== QUOTA FETCH ERROR: ' . $e->getMessage() . ' ===');
            }

            $results = $this->searchService->parseRoomPriceResponse(
                $rawXml,
                $nights,
                $checkIn,
                $checkOut,
                $mealPlan,
                $quotaMap,
                $roomTypeMap,
            );

            foreach ($results as $r) {
                $status = TypeCoerce::toBool($r['is_on_request'])
                    ? 'ON REQUEST'
                    : ($r['rooms_available'] !== null ? PriceInfoFormatter::toScalar($r['rooms_available']) . ' rooms' : 'available');
                $rRoomId = PriceInfoFormatter::toScalar($r['room_id']);
                $rBoardId = PriceInfoFormatter::toScalar($r['board_id']);
                $rPrice = PriceInfoFormatter::toScalar($r['total_price']);
                $this->log("  -> ADDED: Room={$rRoomId}, Board={$rBoardId}, Price={$rPrice}€, {$status}");
            }
        } else {
            $this->logApiError($api, '  ');
        }

        $results = SearchService::deduplicateResults($results);

        $this->log('=== RESULTS SUMMARY ===');
        $this->log('Total results found: ' . count($results));

        $earlyBookingDiscounts = SearchService::getEarlyBookingDiscounts($hotelId, $checkIn, $checkOut);

        return [
            'results' => $results,
            'all_room_results' => [],
            'is_multi_room' => false,
            'multi_room_total_options' => 0,
            'no_availability' => empty($results),
            'max_room_capacity' => $this->resultNormalizer->calculateMaxCapacity($results),
            'early_booking_discounts' => $earlyBookingDiscounts,
            'early_booking_range' => SearchService::getDiscountRange($earlyBookingDiscounts),
        ];
    }

    // =====================================================================
    // Debug logging
    // =====================================================================

    private function log(string $message): void
    {
        if ($this->debug) {
            $this->debugLog[] = $message;
        }
    }

    private function logCircuitStatus(): void
    {
        if (!$this->debug) {
            return;
        }
        $api = fn_novoton_holidays_get_api();
        if ($api !== null) {
            $cs = $api->getCircuitStatus();
            $this->log('=== API CIRCUIT BREAKER STATUS ===');
            $this->log('Circuit Open: ' . (TypeCoerce::toBool($cs['is_open']) ? 'YES (BLOCKING REQUESTS!)' : 'NO'));
            $this->log('Failure Count: ' . PriceInfoFormatter::toScalar($cs['failure_count']) . '/' . PriceInfoFormatter::toScalar($cs['threshold']));
            if (TypeCoerce::toString($cs['last_failure']) !== '') {
                $this->log('Last Failure: ' . PriceInfoFormatter::toScalar($cs['last_failure']));
            }
            if (TypeCoerce::toBool($cs['is_open'])) {
                $this->log('Seconds Until Retry: ' . PriceInfoFormatter::toScalar($cs['seconds_until_retry']));
            }
        }
    }

    private function logHotelInfo(?\SimpleXMLElement $hotelInfo): void
    {
        if (!$this->debug) {
            return;
        }
        $this->log('=== HOTEL INFO RESPONSE (hotelinfo) ===');
        if ($hotelInfo !== null) {
            $this->log('Has rooms: ' . (isset($hotelInfo->rooms) ? 'YES' : 'NO'));
            $this->log('Has board: ' . (isset($hotelInfo->board) ? 'YES' : 'NO'));
            $this->log('Has packages: ' . (isset($hotelInfo->packages) ? 'YES' : 'NO'));
            $rawXml = (string) $hotelInfo->asXML();
            $this->log('=== RAW HOTELINFO XML (truncated) ===');
            $this->log(substr(htmlspecialchars($rawXml), 0, 2000));
        } else {
            $this->log('ERROR: No hotel info returned from API');
        }
    }

    private function logApiError(NovotonApiKitInterface $api, string $prefix = ''): void
    {
        if (!$this->debug) {
            return;
        }
        $this->log($prefix . 'API Response: EMPTY or FALSE');
        $lastError = $api->debugInfo()->lastError;
        if ($lastError !== '') {
            $this->log($prefix . 'API Error: ' . $lastError);
        }
        // Circuit-breaker state isn't on the narrow kit interface — re-query
        // the concrete facade singleton for diagnostic output only.
        $concrete = fn_novoton_holidays_get_api();
        if ($concrete !== null) {
            $cs = $concrete->getCircuitStatus();
            if (TypeCoerce::toBool($cs['is_open'])) {
                $this->log($prefix . 'CIRCUIT BREAKER IS OPEN!');
            }
        }
    }

    /**
     * @param array<string, mixed> $priceParams
     */
    private function logSingleRoomDebug(NovotonApiKitInterface $api, string $hotelId, array $priceParams): void
    {
        if (!$this->debug) {
            return;
        }

        $debugInfo = $api->debugInfo();
        $lastReq = $debugInfo->lastRequestFormatted;
        $this->log("  -> API Request Params: hotel_id={$hotelId}, check_in="
            . PriceInfoFormatter::toScalar($lastReq['check_in'] ?? '') . ', check_out=' . PriceInfoFormatter::toScalar($lastReq['check_out'] ?? '')
            . ', adults=' . PriceInfoFormatter::toScalar($priceParams['adults'] ?? 2));
        $this->log('  -> Children ages: ' . json_encode($priceParams['children'] ?? []));

        $fullRequest = $debugInfo->lastRequest;
        if ($fullRequest !== '') {
            $masked = (string) preg_replace(['/<usr>[^<]*<\/usr>/', '/<psw>[^<]*<\/psw>/'], ['<usr>***</usr>', '<psw>***</psw>'], $fullRequest);
            $this->log('  -> Full XML Request: ' . substr(htmlspecialchars($masked), 0, 1500));
        }

        $rawResponse = $debugInfo->lastResponse;
        if ($rawResponse !== '') {
            $this->log('  -> Raw Response (first 2000 chars): ' . substr(htmlspecialchars($rawResponse), 0, 2000));
        } else {
            $this->logApiError($api, '  -> ');
        }
    }
}
