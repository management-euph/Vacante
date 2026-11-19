<?php

declare(strict_types=1);

/**
 * Searches nearby dates for availability when the primary search returns nothing.
 *
 * Iterates ±N days around the requested check-in, calling the room_price API
 * for each room × board combination until availability is found.
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class AlternativeDateSearcher implements AlternativeDateSearcherInterface
{
    /**
     * Maximum total API calls across all dates/rooms/boards. Every anonymous
     * search with no availability makes them; 50 let a few visitors take
     * the store's whole Novoton allowance (5 a second).
     */
    private const int MAX_API_CALLS = 20;

    /**  */
    private bool $debug;

    /** @var string[] */
    private array $debugLog = [];

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    /**
     * Search alternative dates for a hotel.
     *
     * Uses batch API requests (curl_multi) per date to parallelize room×board
     * combinations. Enforces a hard cap of MAX_API_CALLS to prevent runaway loops.
     *
     * @param string $hotelId Hotel identifier
     * @param string $checkIn Original check-in date
     * @param int $nights Stay duration
     * @param int $adults Total adults
     * @param list<int> $children Children ages
     * @param int $flexDays Days to search before/after (0 = use default 10)
     * @param array<string, mixed> $rooms Room XML nodes from HotelAvailabilitySearcher::getRooms()
     * @param array<string, mixed> $boardTypes Board type IDs from HotelAvailabilitySearcher::getBoardTypes()
     * @return array{
     *   results: list<array<string, mixed>>,
     *   check_in: string,
     *   check_out: string
     * }
     */
    #[\Override]
    public function search(
        string $hotelId,
        string $checkIn,
        int $nights,
        int $adults,
        array $children,
        int $flexDays,
        array $rooms,
        array $boardTypes,
    ): array {
        $searchRange = ($flexDays > 0) ? $flexDays : 10;
        $nights = max($nights, 1);
        $baseDate = strtotime($checkIn);

        if ($baseDate === false) {
            return ['results' => [], 'check_in' => '', 'check_out' => ''];
        }

        // Build date list: after first, then before
        $altDates = [];
        for ($i = 1; $i <= $searchRange; $i++) {
            $tryDate = date('Y-m-d', (int) strtotime("+{$i} days", $baseDate));
            if (strtotime($tryDate) >= strtotime('today')) {
                $altDates[] = $tryDate;
            }
        }
        for ($i = 1; $i <= $searchRange; $i++) {
            $tryDate = date('Y-m-d', (int) strtotime("-{$i} days", $baseDate));
            if (strtotime($tryDate) >= strtotime('today')) {
                array_unshift($altDates, $tryDate);
            }
        }

        $this->log("No results for {$checkIn}. Searching alternative dates (±{$searchRange} days)...");
        $this->log('Alternative dates to try: ' . implode(', ', array_slice($altDates, 0, 5)) . '...');

        $api = fn_novoton_holidays_get_api();
        if ($api === null) {
            return ['results' => [], 'check_in' => '', 'check_out' => ''];
        }
        // Bind the pricing sub-client once so every call inside this method
        // goes through the narrow PricingApiClientInterface rather than the
        // deprecated NovotonApi facade methods.
        $pricing = $api->pricing();

        // Pre-extract room IDs/names for the batch
        $roomData = [];
        foreach ($rooms as $room) {
            if (!is_object($room) && !is_array($room)) {
                continue;
            }
            /** @var object{IdRoom: string, Room: string}|array<string, mixed> $room */
            $roomId = is_object($room) ? (string) $room->IdRoom : TypeCoerce::toString($room['IdRoom'] ?? '');
            $roomName = is_object($room) ? (string) $room->Room : TypeCoerce::toString($room['Room'] ?? '');
            if (!empty($roomId)) {
                $roomData[] = ['id' => $roomId, 'name' => $roomName, 'original' => $room];
            }
        }

        $altResults = [];
        $altCheckIn = '';
        $altCheckOut = '';
        $apiCallCount = 0;

        foreach ($altDates as $tryCheckIn) {
            $tryCheckOut = date('Y-m-d', (int) strtotime($tryCheckIn . ' +' . $nights . ' days'));

            // Build all room×board requests for this date as a batch
            $batchRequests = [];
            $requestMeta = []; // Maps batch key → room/board metadata
            foreach ($roomData as $ri => $rd) {
                foreach ($boardTypes as $bi => $tryBoard) {
                    $batchKey = "r{$ri}_b{$bi}";
                    $batchRequests[$batchKey] = [
                        'hotel_id' => $hotelId,
                        'room_id' => $rd['id'],
                        'board_id' => $tryBoard,
                        'star_rating' => '',
                        'check_in' => $tryCheckIn,
                        'check_out' => $tryCheckOut,
                        'adults' => $adults,
                        'children' => $children,
                    ];
                    $requestMeta[$batchKey] = [
                        'roomIdx' => $ri,
                        'boardId' => $tryBoard,
                    ];
                }
            }

            // Enforce API call cap
            $remaining = self::MAX_API_CALLS - $apiCallCount;
            if ($remaining <= 0) {
                $this->log("API call cap reached ({$apiCallCount}/" . self::MAX_API_CALLS . '). Stopping.');
                break;
            }
            if (count($batchRequests) > $remaining) {
                $batchRequests = array_slice($batchRequests, 0, $remaining, true);
                $this->log("Trimmed batch to {$remaining} requests (cap).");
            }

            $apiCallCount += count($batchRequests);

            // Send all room×board requests for this date in parallel
            $batchResponses = $pricing->getRoomPriceBatch($batchRequests);

            // Process responses: find first valid board per room
            $foundRoomIds = [];
            foreach ($batchResponses as $batchKey => $response) {
                if (!isset($requestMeta[$batchKey])) {
                    continue;
                }
                $meta = $requestMeta[$batchKey];
                $ri = $meta['roomIdx'];

                // Skip if we already found a result for this room
                if (isset($foundRoomIds[$ri])) {
                    continue;
                }

                $priceData = $response['data'];
                if ((bool) $priceData && isset($priceData->Price)) {
                    $rawPrice = (float) ((string) $priceData->Price);
                    if ($rawPrice > 0) {
                        $rd = $roomData[$ri];
                        $foundRoomIds[$ri] = true;
                        $altCheckIn = $tryCheckIn;
                        $altCheckOut = $tryCheckOut;
                        $altPrice = $pricing->applyCommission($rawPrice);
                        $altResults[] = [
                            'room' => $rd['original'],
                            'room_id' => $rd['id'],
                            'room_name' => $rd['name'] ?: str_replace(['%2b', '%2B'], '+', $rd['id']),
                            'board_id' => $meta['boardId'],
                            'board_name' => \Tygh\Addons\TravelCore\ValueObjects\BoardType::toDisplayName(TypeCoerce::toString($meta['boardId'])),
                            'price_data' => $priceData,
                            'nights' => $nights,
                            'total_price' => $altPrice,
                            'price_per_night' => round($altPrice / $nights, 2),
                            'check_in' => $tryCheckIn,
                            'check_out' => $tryCheckOut,
                        ];
                    }
                }
            }

            if (!empty($altResults)) {
                $this->log('Found ' . count($altResults) . " alternative(s) for {$tryCheckIn}.");
                break; // Found results, stop searching dates
            }
        }

        if (empty($altResults)) {
            $this->log("No alternatives found after {$apiCallCount} API calls.");
        }

        return [
            'results' => $altResults,
            'check_in' => $altCheckIn,
            'check_out' => $altCheckOut,
        ];
    }

    /**
     * @return string[]
     */
    #[\Override]
    public function getDebugLog(): array
    {
        return $this->debugLog;
    }

    private function log(string $message): void
    {
        if ($this->debug) {
            $this->debugLog[] = $message;
        }
    }
}
