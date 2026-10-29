<?php

declare(strict_types=1);

/**
 * Novoton Search Service
 *
 * Handles hotel availability search, result processing, and flexible date searches.
 * Extracted from novoton_booking.php for better maintainability.
 *
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\ValueObjects\BoardType;
use Tygh\Addons\TravelCore\ValueObjects\RoomType;

class SearchService implements SearchServiceInterface
{
    /** Narrow sub-client for applyCommission() used by parseRoomPriceResponse(). */
    private readonly PricingApiClientInterface $pricing;

    /** Pure request -> normalized search-params transformation. */
    private readonly SearchParamsNormalizer $paramsNormalizer;

    /**
     * Constructor.
     *
     * The pricing sub-client is injectable for testing; in production the
     * NovotonApi facade provides the concrete implementation via
     * `fn_novoton_holidays_get_api()`.
     *
     * @throws \RuntimeException if the Novoton API is not available
     */
    public function __construct(
        ?PricingApiClientInterface $pricing = null,
    ) {
        if ($pricing === null) {
            $api = fn_novoton_holidays_get_api();
            if ($api === null) {
                throw new \RuntimeException('Novoton API is not available. Check addon settings and API credentials.');
            }
            $pricing = $api->pricing();
        }
        $this->pricing = $pricing;
        $this->paramsNormalizer = new SearchParamsNormalizer();
    }

    /**
     * Parse search parameters from request
     *
     * @param array<string, mixed> $request Request parameters
     * @return array<string, mixed> Normalized search parameters
     */
    public function parseSearchParams(array $request): array
    {
        return $this->paramsNormalizer->parseSearchParams($request);
    }

    /**
     * Calculate totals from rooms data
     *
     * @param list<array<string, mixed>> $rooms_data Rooms configuration
     * @return array{adults: int, children: int, ages: list<int>}
     */
    public function calculateRoomTotals(array $rooms_data): array
    {
        return $this->paramsNormalizer->calculateRoomTotals($rooms_data);
    }

    /**
     * Get board name from ID
     *
     * Delegates to BoardType value object (single source of truth).
     *
     * @param string $board_id Board ID (e.g. "AI", "FB+", "ALL INCL")
     * @return string Board display name
     */
    public function getBoardName(string $board_id): string
    {
        return \Tygh\Addons\TravelCore\ValueObjects\BoardType::toDisplayName($board_id);
    }

    // =========================================================================
    // BOARD / MEAL PLAN FILTERING (single source of truth)
    // =========================================================================

    /**
     * Check if a board ID matches the requested meal plan.
     *
     * Uses Constants::BOARD_MAPPING to resolve user-facing codes (AI, UAI, FB…)
     * to the API board strings they map to.
     *
     * @param string $boardId Board identifier from the API response
     * @param string $mealPlan User-selected meal plan code (e.g. 'AI', 'HB')
     */
    public static function matchesMealPlan(string $boardId, string $mealPlan): bool
    {
        if (empty($mealPlan)) {
            return true; // "All boards" — everything matches
        }

        $mapping = Constants::BOARD_MAPPING;
        $candidates = $mapping[$mealPlan] ?? [$mealPlan];

        foreach ($candidates as $candidate) {
            if (str_contains(strtolower($boardId), strtolower($candidate))) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // QUOTA INTERPRETATION
    // =========================================================================

    /**
     * Interpret a raw quota value from the hotel_quota API.
     *
     * @param string|null $quotaValue Raw quota (e.g. "5", "RQ", "REQUEST", "")
     * @return array{availability: int|null, is_on_request: bool}
     */
    public static function parseQuotaValue(?string $quotaValue): array
    {
        if ($quotaValue === null) {
            return ['availability' => null, 'is_on_request' => false];
        }

        $quotaValue = trim($quotaValue);
        $upper = strtoupper($quotaValue);

        if ($upper === 'RQ' || $upper === 'REQUEST' || $quotaValue === '') {
            return ['availability' => 0, 'is_on_request' => true];
        }

        $intVal = (int) $quotaValue;
        if ($intVal === 0) {
            return ['availability' => 0, 'is_on_request' => true];
        }

        return ['availability' => $intVal, 'is_on_request' => false];
    }

    // =========================================================================
    // ROOM PRICE RESPONSE PARSING
    // =========================================================================

    /**
     * Parse a room_price API XML response into a structured result array.
     *
     * Handles both single-result and multi-result responses, applies meal plan
     * filtering, commission, quota lookup, and builds the standard result items.
     *
     * @param string $rawXml Raw XML string from room_price API
     * @param int $nights Number of nights
     * @param string $checkIn Check-in date (Y-m-d)
     * @param string $checkOut Check-out date (Y-m-d)
     * @param string $mealPlan Requested meal plan code (empty = all)
     * @param array<string, mixed> $quotaMap Room ID => quota value map (from hotel_quota)
     * @param array<string, mixed> $roomTypeMap Room ID => Type map (from hotelinfo)
     * @param int|null $forRoom Room number (multi-room), null for single
     * @param string|null $occupancyStr Occupancy string for display
     * @return list<array<string, mixed>> List of result items
     */
    public function parseRoomPriceResponse(
        string $rawXml,
        int $nights,
        string $checkIn,
        string $checkOut,
        string $mealPlan = '',
        array $quotaMap = [],
        array $roomTypeMap = [],
        ?int $forRoom = null,
        ?string $occupancyStr = null,
    ): array {
        $results = [];

        $prevLibxml = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prevLibxml);

        if ($xml === false) {
            return [];
        }

        $priceElements = $xml->xpath('//Price');
        $numPrices = count($priceElements ?: []);

        if ($numPrices === 0) {
            return [];
        }

        $searchAllBoards = empty($mealPlan);

        if ($numPrices > 1 || $xml->getName() !== 'room_price') {
            // Multi-result: parallel xpath arrays
            $idRooms = $xml->xpath('//IdRoom');
            $boards = $xml->xpath('//Board');
            $prices = $xml->xpath('//Price');
            $packageNames = $xml->xpath('//PackageName');
            $remarks = $xml->xpath('//remark');
            $earlyBookings = $xml->xpath('//early_booking');
            // EBToDate: the last day the Early Booking can be booked; sent
            // with every offer (empty without one), like early_booking.
            $earlyBookingUntil = $xml->xpath('//EBToDate') ?: [];
            $extras = $xml->xpath('//extras');
            $moreInfos = $xml->xpath('//MoreInfo');
            $importants = $xml->xpath('//Important');
            $termsPayment = $xml->xpath('//TermsOfPayment');
            $termsCancellation = $xml->xpath('//TermsOfCancellation');

            $pricesCount = count($prices ?: []);
            for ($i = 0; $i < $pricesCount; $i++) {
                $roomId = isset($idRooms[$i]) ? (string)$idRooms[$i] : '';
                $boardId = isset($boards[$i]) ? (string)$boards[$i] : '';
                $price = isset($prices[$i]) ? (float) (string) $prices[$i] : 0;

                if (empty($roomId) || $price <= 0) {
                    continue;
                }

                if (!$searchAllBoards && !self::matchesMealPlan($boardId, $mealPlan)) {
                    continue;
                }

                $finalPrice = $this->pricing->applyCommission($price);
                $quota = self::parseQuotaValue(
                    isset($quotaMap[$roomId]) ? PriceInfoFormatter::toScalar($quotaMap[$roomId]) : null,
                );

                $item = [
                    'room' => null,
                    'room_id' => $roomId,
                    'room_name' => str_replace(['%2b', '%2B'], '+', $roomId),
                    'room_type_display' => RoomType::formatRoomLabel($roomId, PriceInfoFormatter::toScalar($roomTypeMap[$roomId] ?? '')),
                    'board_id' => $boardId,
                    'board_name' => BoardType::toDisplayName($boardId),
                    'package_name' => rawurldecode(self::xpathValue($packageNames, $i)),
                    'price_data' => null,
                    'nights' => $nights,
                    'total_price' => $finalPrice,
                    'price_per_night' => round($finalPrice / max($nights, 1), 2),
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'rooms_available' => $quota['availability'],
                    'is_on_request' => $quota['is_on_request'],
                    'remark' => fn_novoton_holidays_format_remark(self::xpathValue($remarks, $i)),
                    'important' => self::xpathValue($importants, $i),
                    'more_info' => fn_novoton_holidays_more_info_label(self::xpathValue($moreInfos, $i)),
                    'early_booking_discount' => (float) self::xpathValue($earlyBookings, $i, '0'),
                    'early_booking_until' => self::xpathValue($earlyBookingUntil, $i),
                    'extras' => self::xpathValue($extras, $i),
                    'terms_of_payment' => isset($termsPayment[$i]) ? (string) $termsPayment[$i]->asXML() : (isset($termsPayment[0]) ? (string) $termsPayment[0]->asXML() : ''),
                    'terms_of_cancellation' => isset($termsCancellation[$i]) ? (string) $termsCancellation[$i]->asXML() : (isset($termsCancellation[0]) ? (string) $termsCancellation[0]->asXML() : ''),
                    'free_cancellation_date' => isset($termsCancellation[$i])
                        ? fn_novoton_holidays_get_free_cancellation_date((string) $termsCancellation[$i]->asXML())
                        : (isset($termsCancellation[0]) ? fn_novoton_holidays_get_free_cancellation_date((string) $termsCancellation[0]->asXML()) : null),
                ];

                if ($forRoom !== null) {
                    $item['for_room'] = $forRoom;
                }
                if ($occupancyStr !== null) {
                    $item['occupancy'] = $occupancyStr;
                }

                $results[] = $item;
            }
        } else {
            // Single result (root is room_price itself)
            $roomId = (string)$xml->IdRoom;
            $boardId = (string)$xml->Board;
            $price = (float) (string) $xml->Price;
            $packageName = (string)$xml->PackageName;
            $remark = isset($xml->remark) ? (string)$xml->remark : '';

            if (empty($roomId) || $price <= 0) {
                return [];
            }

            if (!$searchAllBoards && !self::matchesMealPlan($boardId, $mealPlan)) {
                return [];
            }

            $finalPrice = $this->pricing->applyCommission($price);
            $quota = self::parseQuotaValue(
                isset($quotaMap[$roomId]) ? PriceInfoFormatter::toScalar($quotaMap[$roomId]) : null,
            );

            $item = [
                'room' => null,
                'room_id' => $roomId,
                'room_name' => str_replace(['%2b', '%2B'], '+', $roomId),
                'room_type_display' => RoomType::formatRoomLabel($roomId, PriceInfoFormatter::toScalar($roomTypeMap[$roomId] ?? '')),
                'board_id' => $boardId,
                'board_name' => BoardType::toDisplayName($boardId),
                'package_name' => rawurldecode($packageName),
                'price_data' => null, // SimpleXMLElement not serializable for cache
                'nights' => $nights,
                'total_price' => $finalPrice,
                'price_per_night' => round($finalPrice / max($nights, 1), 2),
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'rooms_available' => $quota['availability'],
                'is_on_request' => $quota['is_on_request'],
                'remark' => fn_novoton_holidays_format_remark($remark),
                'important' => isset($xml->Important) ? (string)$xml->Important : '',
                'more_info' => fn_novoton_holidays_more_info_label(isset($xml->MoreInfo) ? (string)$xml->MoreInfo : ''),
                'early_booking_discount' => isset($xml->early_booking) ? (float) (string) $xml->early_booking : 0,
                'early_booking_until' => isset($xml->EBToDate) ? trim((string) $xml->EBToDate) : '',
                'extras' => isset($xml->extras) ? (string)$xml->extras : '',
                'terms_of_payment' => isset($xml->TermsOfPayment) ? (string) $xml->TermsOfPayment->asXML() : '',
                'terms_of_cancellation' => isset($xml->TermsOfCancellation) ? (string) $xml->TermsOfCancellation->asXML() : '',
                'free_cancellation_date' => isset($xml->TermsOfCancellation)
                    ? fn_novoton_holidays_get_free_cancellation_date((string) $xml->TermsOfCancellation->asXML())
                    : null,
            ];

            if ($forRoom !== null) {
                $item['for_room'] = $forRoom;
            }
            if ($occupancyStr !== null) {
                $item['occupancy'] = $occupancyStr;
            }

            $results[] = $item;
        }

        return $results;
    }

    // =========================================================================
    // EARLY BOOKING DISCOUNTS
    // =========================================================================

    /**
     * Extract active early booking discounts from the priceinfo_data JSON
     * stored in novoton_hotel_packages.
     *
     * @param string $hotelId Hotel ID
     * @param string $checkIn Guest check-in date (Y-m-d)
     * @param string $checkOut Guest check-out date (Y-m-d)
     * @return list<array<string, mixed>> List of applicable discount records
     */
    public static function getEarlyBookingDiscounts(string $hotelId, string $checkIn, string $checkOut): array
    {
        $discounts = [];

        $packageRepo = Container::getInstance()->hotelPackageRepository();
        $eb_package = $packageRepo->findEarlyBookingPackage($hotelId);

        if (empty($eb_package['priceinfo_data'])) {
            return [];
        }

        $priceinfo = TypeCoerce::toStringMap(
            json_decode(PriceInfoFormatter::toScalar($eb_package['priceinfo_data']), true),
        );
        if (empty($priceinfo['early_booking'])) {
            return [];
        }

        // Normalize single entry to a list of entries
        $ebRaw = $priceinfo['early_booking'];
        $eb_data = is_array($ebRaw) && isset($ebRaw['Reduction'])
            ? [$ebRaw]
            : TypeCoerce::toList($ebRaw);

        $today = date('Y-m-d');
        foreach ($eb_data as $ebRow) {
            $eb = TypeCoerce::toStringMap($ebRow);
            $bookTo = PriceInfoFormatter::toScalar($eb['BookTo'] ?? '');
            $stayFrom = PriceInfoFormatter::toScalar($eb['StayFrom'] ?? '');
            $stayTo = PriceInfoFormatter::toScalar($eb['StayTo'] ?? '');

            if (!empty($bookTo) && $bookTo < $today) {
                continue;
            }
            if (!empty($stayFrom) && $stayFrom > $checkOut) {
                continue;
            }
            if (!empty($stayTo) && $stayTo < $checkIn) {
                continue;
            }

            $discounts[] = [
                'discount' => PriceInfoFormatter::toFloat($eb['Reduction'] ?? 0),
                'room_types' => $eb['RoomTypes'] ?? 'all',
                'package' => $eb['PackageId'] ?? '',
                'min_stay' => PriceInfoFormatter::toInt($eb['MinStay'] ?? 0),
                'booking_to' => $bookTo,
            ];
        }

        // Sort by discount DESC, limit to 10
        usort($discounts, fn ($a, $b): int => $b['discount'] <=> $a['discount']);
        return array_slice($discounts, 0, 10);
    }

    /**
     * Calculate discount range from a list of early booking discounts.
     *
     * @param list<array<string, mixed>> $discounts From getEarlyBookingDiscounts()
     * @return array<string, mixed> {min, max, all} or empty
     */
    public static function getDiscountRange(array $discounts): array
    {
        if (empty($discounts)) {
            return [];
        }

        $values = [];
        foreach ($discounts as $discount) {
            $values[] = PriceInfoFormatter::toFloat($discount['discount'] ?? 0);
        }
        $range = [
            'min' => min($values),
            'max' => max($values),
            'all' => array_unique($values),
        ];
        sort($range['all']);
        return $range;
    }

    // =========================================================================
    // RESULT DEDUPLICATION
    // =========================================================================

    /**
     * Deduplicate results, keeping the lowest price for each room/board/package.
     *
     * When two entries share the same key but differ in extras (one has an
     * extras promotion like "7 = 6", the other doesn't), they are merged into
     * a single row: the standard (non-extras) entry is kept as the base, and
     * the promotional price / label are attached as extras_price / extras_label.
     *
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>> Deduplicated results (re-indexed)
     */
    public static function deduplicateResults(array $results): array
    {
        $unique = [];
        foreach ($results as $result) {
            $key = PriceInfoFormatter::toScalar($result['room_id'] ?? '')
                . '|' . PriceInfoFormatter::toScalar($result['board_id'] ?? '')
                . '|' . PriceInfoFormatter::toScalar($result['package_name'] ?? '');

            if (!isset($unique[$key])) {
                $unique[$key] = $result;
                continue;
            }

            $existing = $unique[$key];
            $existingHasExtras = !empty(trim(PriceInfoFormatter::toScalar($existing['extras'] ?? '')));
            $currentHasExtras = !empty(trim(PriceInfoFormatter::toScalar($result['extras'] ?? '')));

            if ($existingHasExtras !== $currentHasExtras) {
                // One has extras promotion, one doesn't — combine into one row
                $standard = $existingHasExtras ? $result : $existing;
                $promotional = $existingHasExtras ? $existing : $result;

                $standard['extras_price'] = $promotional['total_price'];
                $standard['extras_label'] = $promotional['extras'];
                $unique[$key] = $standard;
            } else {
                // Same type (both with or both without extras) — keep lowest price
                if (
                    $result['total_price'] > 0
                    && $result['total_price'] < $existing['total_price']
                ) {
                    $unique[$key] = $result;
                }
            }
        }
        return array_values($unique);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Safe xpath value accessor.
     * @param array<\SimpleXMLElement>|null $elements
     */
    private static function xpathValue(?array $elements, int $index, string $default = ''): string
    {
        if ($elements === null || !isset($elements[$index])) {
            return $default;
        }
        return (string)$elements[$index];
    }
}
