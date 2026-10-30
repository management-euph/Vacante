<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * A booking's exchange with Novoton, as its admin page lists it under the
 * technical details: the booking's notes (why it was not sent, the API's
 * error), the rooms of its hotel, the reservation request, Novoton's answer
 * and the booking data the request is built from.
 *
 * The request itself is not stored: ?:novoton_bookings.api_request holds
 * the data createReservation() is given, so the request is built again
 * from it as it is sent (ReservationApiClient::generateReservationXml),
 * its login masked. The answer is the summary submitAndRecordBooking()
 * keeps: IdNum, Price, Currency, Quota, Status.
 */
final class ReservationExchange
{
    /** @var \Closure(array<string, mixed>): string booking data => hotel_res_RQ, login masked */
    private readonly \Closure $request;

    private readonly SupplierRoomLabels $rooms;

    /**
     * @param (\Closure(array<string, mixed>): string)|null $request default: the API's reservations client
     */
    public function __construct(?\Closure $request = null, ?SupplierRoomLabels $rooms = null)
    {
        $this->request = $request ?? self::built(...);
        $this->rooms = $rooms ?? new SupplierRoomLabels();
    }

    /**
     * Short rows first, then the request, the answer and the data, each
     * preformatted; a row without a value shows as empty.
     *
     * @param array<string, mixed> $booking ?:novoton_bookings row
     * @return list<array{label: string, value: string, is_pre: bool}>
     */
    public function rows(array $booking): array
    {
        $data = self::decoded($booking['api_request'] ?? null);

        return [
            self::row('novoton_holidays.bv_notes', trim(TypeCoerce::toString($booking['notes'] ?? ''))),
            self::row('novoton_holidays.bv_hotel_rooms', implode(' · ', $this->rooms->hotelRooms(TypeCoerce::toString($booking['hotel_id'] ?? '')))),
            self::row('novoton_holidays.bv_request', $data !== [] ? $this->request($data) : '', true),
            self::row('novoton_holidays.bv_answer', self::pretty($booking['api_response'] ?? null), true),
            self::row('novoton_holidays.bv_request_data', $data !== [] ? self::json($data) : '', true),
        ];
    }

    /**
     * The request, or why it could not be built (the API not set up): the
     * page shows it either way.
     *
     * @param array<string, mixed> $data
     */
    private function request(array $data): string
    {
        try {
            return ($this->request)($data);
        } catch (\Throwable $e) {
            return TypeCoerce::toString(__('novoton_holidays.bv_request_unavailable', ['[error]' => $e->getMessage()]));
        }
    }

    /**
     * The request as the store's own Novoton API client builds it.
     *
     * @param array<string, mixed> $data
     */
    private static function built(array $data): string
    {
        return Container::getInstance()->novotonApi()->reservations()->generateReservationXml($data);
    }

    /**
     * A JSON column's value as an array; [] when empty or not JSON.
     *
     * @return array<string, mixed>
     */
    private static function decoded(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return TypeCoerce::toStringMap($value);
    }

    /** A JSON column, pretty; a value that is not JSON as it is. */
    private static function pretty(mixed $value): string
    {
        $decoded = self::decoded($value);
        if ($decoded !== []) {
            return self::json($decoded);
        }
        $text = is_string($value) ? trim($value) : '';

        return in_array($text, ['null', '[]', '{}'], true) ? '' : $text;
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{label: string, value: string, is_pre: bool} */
    private static function row(string $key, string $value, bool $isPre = false): array
    {
        return ['label' => TypeCoerce::toString(__($key)), 'value' => $value, 'is_pre' => $isPre && $value !== ''];
    }
}
