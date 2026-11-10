<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

/**
 * The offer id a package quote hands the hotel page ("Rezervă" opens the
 * booking form on it): what the booking form needs to find the same offer
 * at Eurosite again, signed by the store.
 *
 * Eurosite has no offer id of its own, and the page asks several quotes in a
 * row (the chosen date, then the dates beside it), so a session store would
 * be overwritten while the shopper reads; the token carries its offer
 * instead. The booking form trusts nothing in it beyond what it signs, and
 * asks Eurosite again before anything is booked.
 *
 * A token is "<base64url JSON>.<HMAC-SHA256, 32 hex>", the key the store's
 * own (config.crypt_key, as Travel Core's balance links).
 */
final class PackageOfferToken
{
    /**
     * The fields a token carries, all strings; room_codes is each room's
     * code in the order the rooms were asked ("274,274").
     */
    public const array FIELDS = [
        'product_code', 'tourop_code', 'country_code', 'city_code',
        'dep_country_code', 'dep_city_code', 'transport', 'departure_date', 'nights',
        'package_id', 'variant_id', 'room_codes', 'check_in', 'check_out', 'price', 'currency',
    ];

    public function __construct(private readonly string $secret)
    {
    }

    /**
     * @param array<string, string> $offer FIELDS (others are left out, a missing one is '')
     */
    public function encode(array $offer): string
    {
        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = $offer[$field] ?? '';
        }
        $body = rtrim(strtr(base64_encode((string) json_encode($fields, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');

        return $body . '.' . $this->sign($body);
    }

    /**
     * The offer a token carries, or null when it is not one this store
     * signed (altered, cut, or from another store).
     *
     * @return array<string, string>|null
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || !hash_equals($this->sign($parts[0]), $parts[1])) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $decoded = $json === false ? null : json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $offer = [];
        foreach (self::FIELDS as $field) {
            $value = $decoded[$field] ?? '';
            $offer[$field] = is_scalar($value) ? (string) $value : '';
        }

        return $offer;
    }

    private function sign(string $body): string
    {
        return substr(hash_hmac('sha256', 'eurosite_package|' . $body, $this->secret), 0, 32);
    }
}
