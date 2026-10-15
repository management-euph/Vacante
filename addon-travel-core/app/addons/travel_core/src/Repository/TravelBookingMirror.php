<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * The provider → travel_bookings dual-write, in ONE place.
 *
 * Both provider addons mirror their booking tables into the shared
 * travel_bookings registry (UNIQUE KEY uq_provider_booking on
 * provider + provider_booking_id). The upsert/partial-update/delete logic
 * and the 16-entry field map were previously duplicated verbatim in
 * novoton's BookingSyncRepository and sphinx's SphinxBookingRepository —
 * and had already drifted (the guests_json string-coercion guard existed
 * only on sphinx's insert path). Provider repositories now delegate here;
 * any travel_bookings schema change is a one-file edit.
 */
final class TravelBookingMirror
{
    /**
     * provider-table column => travel_bookings column. Only these fields
     * are stored in the shared registry.
     *
     * @var array<string, string>
     */
    public const array UPDATE_FIELD_MAP = [
        'order_id' => 'order_id',
        'user_id' => 'user_id',
        'hotel_id' => 'hotel_id',
        'hotel_name' => 'hotel_name',
        'room_type' => 'room_name',
        'board_id' => 'board_code',
        'check_in' => 'check_in',
        'check_out' => 'check_out',
        'nights' => 'nights',
        'adults' => 'adults',
        'children' => 'children',
        'children_ages' => 'children_ages',
        'total_price' => 'total_price',
        'currency' => 'currency',
        'status' => 'status',
        'guests_data' => 'guests_json',
    ];

    /** Whether travel_bookings has linked_at yet; null until this mirror first asks. */
    private ?bool $hasLinkedAt = null;

    public function __construct(private readonly string $provider)
    {
    }

    /**
     * travel_core adds linked_at from an admin page load (ensure_schema); a
     * customer may order after a deploy before any admin page opened, and a
     * write naming a missing column would lose the booking. One cheap check
     * per mirror (a provider repository keeps one for its lifetime).
     */
    private function hasLinkedAt(): bool
    {
        return $this->hasLinkedAt ??= TypeCoerce::toString(
            db_get_field("SHOW COLUMNS FROM ?:travel_bookings LIKE 'linked_at'"),
        ) !== '';
    }

    /**
     * Mirror a created (or replaced) booking — atomic
     * INSERT ... ON DUPLICATE KEY UPDATE keyed on uq_provider_booking.
     *
     * @param array<string, mixed> $data Provider-table column data
     */
    public function upsert(int $providerBookingId, array $data): void
    {
        $travel_record = [
            'provider' => $this->provider,
            'provider_booking_id' => (string) $providerBookingId,
            'order_id' => TypeCoerce::toInt($data['order_id'] ?? 0),
            'user_id' => TypeCoerce::toInt($data['user_id'] ?? 0),
            'hotel_id' => $data['hotel_id'] ?? '',
            'hotel_name' => $data['hotel_name'] ?? '',
            'room_name' => $data['room_type'] ?? '',
            'board_code' => $data['board_id'] ?? '',
            'check_in' => $data['check_in'] ?? '',
            'check_out' => $data['check_out'] ?? '',
            'nights' => TypeCoerce::toInt($data['nights'] ?? 0),
            'adults' => TypeCoerce::toInt($data['adults'] ?? 2),
            'children' => TypeCoerce::toInt($data['children'] ?? 0),
            'children_ages' => $data['children_ages'] ?? '',
            'total_price' => TypeCoerce::toFloat($data['total_price'] ?? 0),
            'currency' => $data['currency'] ?? 'EUR',
            'status' => $data['status'] ?? TravelConstants::STATUS_PENDING,
            'guests_json' => self::coerceGuestsJson($data['guests_data'] ?? '{}'),
        ];

        // On re-upsert of an existing row, a positive order_id must never be
        // reset to 0: bookings are born unlinked (order_id=0 at add-to-cart)
        // and linked later, so a caller re-upserting without order_id in $data
        // would silently un-link the mirror row while the provider table keeps
        // the real order ("Order ID -" in the admin grid). order_id only moves
        // forward here; it is cleared/changed exclusively via applyUpdate().
        $update_record = $travel_record;
        unset($update_record['order_id']);

        // linked_at: when the booking got its order (the admin order card's
        // "Linked to order"), from the database clock like created_at. A row
        // born with an order is stamped on insert; an existing one when this
        // write gives it an order, or a different one (<=>: order_id may be
        // NULL). Assignments run left to right, so linked_at still compares
        // against the stored order_id.
        $linkedAt = '';
        if ($this->hasLinkedAt()) {
            $now = $travel_record['order_id'] > 0 ? TypeCoerce::toString(db_get_field('SELECT NOW()')) : '';
            if ($now !== '') {
                $travel_record['linked_at'] = $now;
            }
            $linkedAt = ' linked_at = IF(VALUES(order_id) > 0 AND NOT (VALUES(order_id) <=> order_id), NOW(), linked_at),';
        }

        db_query(
            'INSERT INTO ?:travel_bookings ?e'
                . ' ON DUPLICATE KEY UPDATE ?u,'
                . $linkedAt
                . ' order_id = IF(VALUES(order_id) > 0, VALUES(order_id), order_id)',
            $travel_record,
            $update_record,
        );
    }

    /**
     * Mirror a partial update. Only mapped fields forward; a no-op when
     * nothing mirrored changed.
     *
     * @param array<string, mixed> $data Changed provider-table columns
     */
    public function applyUpdate(int $providerBookingId, array $data): void
    {
        $travelUpdate = [];
        foreach (self::UPDATE_FIELD_MAP as $providerField => $travelField) {
            if (array_key_exists($providerField, $data)) {
                $travelUpdate[$travelField] = $data[$providerField];
            }
        }

        if ($travelUpdate === []) {
            return;
        }

        if (array_key_exists('guests_json', $travelUpdate)) {
            $travelUpdate['guests_json'] = self::coerceGuestsJson($travelUpdate['guests_json']);
        }

        if (array_key_exists('order_id', $travelUpdate) && $this->hasLinkedAt()) {
            // linked_at as in upsert(): stamped when the order changes,
            // cleared when the booking loses it. Placed before ?u, so it
            // reads the stored order_id.
            $orderId = TypeCoerce::toInt($travelUpdate['order_id']);
            db_query(
                'UPDATE ?:travel_bookings SET linked_at = IF(?i > 0, IF(order_id <=> ?i, linked_at, NOW()), NULL), ?u'
                    . ' WHERE provider = ?s AND provider_booking_id = ?s',
                $orderId,
                $orderId,
                $travelUpdate,
                $this->provider,
                (string) $providerBookingId,
            );

            return;
        }

        db_query(
            'UPDATE ?:travel_bookings SET ?u WHERE provider = ?s AND provider_booking_id = ?s',
            $travelUpdate,
            $this->provider,
            (string) $providerBookingId,
        );
    }

    /** Remove the mirror row for one provider booking. */
    public function deleteByProviderBookingId(int $providerBookingId): void
    {
        db_query(
            'DELETE FROM ?:travel_bookings WHERE provider = ?s AND provider_booking_id = ?s',
            $this->provider,
            (string) $providerBookingId,
        );
    }

    /**
     * guests_data commonly arrives pre-encoded, but some call sites pass
     * the raw array — store JSON either way (this guard previously existed
     * only on sphinx's insert path).
     */
    private static function coerceGuestsJson(mixed $guests): string
    {
        if (is_string($guests)) {
            return $guests;
        }

        return json_encode($guests) ?: '{}';
    }
}
