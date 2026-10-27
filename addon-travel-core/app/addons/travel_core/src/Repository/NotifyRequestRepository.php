<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The hotel page's "notify me" requests (docs/novoton-availability-gaps-plan.md
 * §8): a guest leaves an email address, with consent, to get ONE email when
 * booking opens for a hotel.
 *
 * They live in travel_alternative_requests with request_type 'notify', so
 * the Availability Requests grid lists them with the other requests. The
 * address is removed once the email went out, or after KEEP_MONTHS at the
 * latest, as the consent text promises; the row stays without it, for the
 * demand counts.
 */
final class NotifyRequestRepository
{
    public const string TYPE = 'notify';

    /**
     * What the guest waits for: next season's prices (between seasons), an
     * offer (not offered), the hotel's return (no offers for a year).
     */
    public const array WATCH_FOR = ['prices', 'booking', 'return'];

    /** How long a request waits, with its address. */
    public const int KEEP_MONTHS = 12;

    /**
     * Stores a request, or finds the one this address already has waiting
     * for this hotel: a second click sends no second email.
     *
     * @param array{provider: string, hotel_id: string, hotel_name: string, product_id: int, user_id: int, email: string, lang_code: string, watch_for: string, consent_version: string} $request
     */
    public function create(array $request, string $now): int
    {
        AlternativeRequestRepository::ensureTable();
        $waiting = TypeCoerce::toInt(db_get_field(
            "SELECT request_id FROM ?:travel_alternative_requests
             WHERE request_type = 'notify' AND status = 'pending' AND provider = ?s AND hotel_id = ?s AND contact_email = ?s",
            $request['provider'],
            $request['hotel_id'],
            $request['email'],
        ));
        if ($waiting > 0) {
            return $waiting;
        }

        $row = [
            'provider' => $request['provider'],
            'hotel_id' => $request['hotel_id'],
            'hotel_name' => mb_substr($request['hotel_name'], 0, 255),
            'contact_email' => $request['email'],
            'request_type' => self::TYPE,
            'watch_for' => $request['watch_for'],
            'lang_code' => $request['lang_code'],
            'consent_at' => $now,
            'consent_version' => $request['consent_version'],
            'status' => 'pending',
            'created_at' => $now,
        ];
        if ($request['product_id'] > 0) {
            $row['product_id'] = $request['product_id'];
        }
        if ($request['user_id'] > 0) {
            $row['user_id'] = $request['user_id'];
        }

        return TypeCoerce::toInt(db_query('INSERT INTO ?:travel_alternative_requests ?e', $row));
    }

    /**
     * Requests still waiting with an address to write to, the oldest first.
     *
     * @return list<array{request_id: int, provider: string, hotel_id: string, hotel_name: string, product_id: int, email: string, lang_code: string, watch_for: string, created_at: string}>
     */
    public function waiting(int $limit): array
    {
        AlternativeRequestRepository::ensureTable();
        $out = [];
        foreach (TypeCoerce::toRowList(db_get_array(
            "SELECT request_id, provider, hotel_id, hotel_name, product_id, contact_email, lang_code, watch_for, created_at
             FROM ?:travel_alternative_requests
             WHERE request_type = 'notify' AND status = 'pending' AND contact_email <> ''
             ORDER BY request_id LIMIT ?i",
            max(1, $limit),
        )) as $row) {
            $out[] = [
                'request_id' => TypeCoerce::toInt($row['request_id'] ?? 0),
                'provider' => TypeCoerce::toString($row['provider'] ?? ''),
                'hotel_id' => TypeCoerce::toString($row['hotel_id'] ?? ''),
                'hotel_name' => TypeCoerce::toString($row['hotel_name'] ?? ''),
                'product_id' => max(0, TypeCoerce::toInt($row['product_id'] ?? 0)),
                'email' => TypeCoerce::toString($row['contact_email'] ?? ''),
                'lang_code' => TypeCoerce::toString($row['lang_code'] ?? ''),
                'watch_for' => TypeCoerce::toString($row['watch_for'] ?? ''),
                'created_at' => TypeCoerce::toString($row['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The email went out: the request is done and its address removed.
     *
     * @param list<int> $requestIds
     */
    public function markNotified(array $requestIds): void
    {
        if ($requestIds === []) {
            return;
        }
        db_query(
            "UPDATE ?:travel_alternative_requests SET status = 'notified', contact_email = NULL
             WHERE request_type = 'notify' AND request_id IN (?n)",
            $requestIds,
        );
    }

    /** Requests that waited KEEP_MONTHS without news: dropped, with their address. */
    public function expireBefore(string $before): int
    {
        AlternativeRequestRepository::ensureTable();

        return TypeCoerce::toInt(db_query(
            "UPDATE ?:travel_alternative_requests SET status = 'expired', contact_email = NULL
             WHERE request_type = 'notify' AND status = 'pending' AND created_at < ?s",
            $before,
        ));
    }

    /** An admin cancelled one: it is done, and its address removed. */
    public function cancel(int $requestId): bool
    {
        return (bool) db_query(
            "UPDATE ?:travel_alternative_requests SET status = 'cancelled', contact_email = NULL
             WHERE request_type = 'notify' AND request_id = ?i",
            $requestId,
        );
    }
}
