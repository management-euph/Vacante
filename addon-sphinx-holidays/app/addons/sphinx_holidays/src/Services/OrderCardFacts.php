<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * What only the sphinx booking row knows about an order line, for travel
 * core's order booking card (TravelProviderRegistry::setOrderCardResolver):
 *
 *  - the supplier reference: the booking confirmation number (api_booking_ref);
 *  - why a booking failed: the book / retry call keeps the API's answer as
 *    {"error": …} in api_response;
 *  - what kind of trip it is: a circuit (its transport, departure city and
 *    meal plan) or a package (flight / bus + hotel), from the line itself.
 */
final class OrderCardFacts
{
    public function __construct(private readonly ?SphinxBookingRepository $repo = null)
    {
    }

    /**
     * @param array<string, mixed> $extra an order line's extra
     * @return array<string, mixed> empty when the line is not sphinx's
     */
    public function facts(array $extra): array
    {
        if (empty($extra['sphinx_booking']) && TypeCoerce::toString($extra['travel_provider'] ?? '') !== 'sphinx') {
            return [];
        }
        $id = TypeCoerce::toInt($extra['travel_booking_id'] ?? 0);
        $row = $id > 0 ? ($this->repo ?? Container::getBookingRepository())->findById($id) : null;

        return self::fromRow($extra, $id, $row);
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed>|null $row ?:sphinx_bookings row
     * @return array<string, mixed>
     */
    public static function fromRow(array $extra, int $bookingId, ?array $row): array
    {
        $kind = strtolower(TypeCoerce::toString($extra['booking_type'] ?? ''));
        $facts = [
            'provider_booking_id' => $bookingId > 0 ? (string) $bookingId : '',
            'kind' => in_array($kind, ['circuit', 'package'], true) ? $kind : 'hotel',
            // Circuits store their transport as the board ("bus", "flight").
            'transport' => TypeCoerce::toString($extra['transport_type'] ?? ($kind === 'circuit' ? $extra['board_id'] ?? '' : '')),
            'meals' => TypeCoerce::toString($extra['meal_name'] ?? ''),
            'departure' => TypeCoerce::toString($extra['departure_name'] ?? ''),
        ];
        if ($row === null) {
            return $facts;
        }
        $status = strtolower(TypeCoerce::toString($row['status'] ?? ''));
        $facts['reference'] = trim(TypeCoerce::toString($row['api_booking_ref'] ?? ''));
        $facts['status'] = $status;
        if ($status === TravelConstants::STATUS_FAILED) {
            $answer = json_decode(TypeCoerce::toString($row['api_response'] ?? ''), true);
            $error = is_array($answer) ? trim(TypeCoerce::toString($answer['error'] ?? '')) : '';
            $facts['error'] = mb_strlen($error, 'UTF-8') > 300 ? mb_substr($error, 0, 299, 'UTF-8') . '…' : $error;
        }

        return $facts;
    }
}
