<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Api;

use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Eurosite API data normalizer.
 *
 * Translates Eurosite's Romanian/English free-text values and codes into the
 * canonical codes the shared travel_core feature-mapping system uses, so
 * Eurosite hotels resolve into the same star/board/room/facility vocabulary as
 * novoton and sphinx. MVP maps the common values from the spec examples;
 * unknown inputs return null (the mapping system falls back gracefully).
 */
final class EurositeNormalizer implements ProviderNormalizerInterface
{
    /** Eurosite meal names (RO/EN) → canonical board codes. Specific first. */
    private const array BOARD_MAP = [
        'ultra all inclusive' => 'UAI',
        'all inclusive light' => 'AIL',
        'all inclusive plus' => 'AI',
        'all inclusive' => 'AI',
        'pensiune completa' => 'FB',
        'pensiune completă' => 'FB',
        'full board' => 'FB',
        'demipensiune' => 'HB',
        'half board' => 'HB',
        'mic dejun' => 'BB',
        'bed and breakfast' => 'BB',
        'b&b' => 'BB',
        'fara masa' => 'RO',
        'fără masă' => 'RO',
        'room only' => 'RO',
        'self catering' => 'SC',
    ];

    /** Eurosite room GCode / names → canonical room-type codes. */
    private const array ROOM_MAP = [
        'sgl' => 'SGL',
        'single' => 'SGL',
        'db' => 'DBL',
        'dbl' => 'DBL',
        'double' => 'DBL',
        'dubla' => 'DBL',
        'twin' => 'TWIN',
        'tw' => 'TWIN',
        'tpl' => 'TRP',
        'tripla' => 'TRP',
        'triple' => 'TRP',
        'quad' => 'QUAD',
        'apt' => 'APT',
        'apartament' => 'APT',
        'apartment' => 'APT',
        'studio' => 'STUDIO',
        'suite' => 'SUITE',
    ];

    #[\Override]
    public function getProviderName(): string
    {
        return 'eurosite';
    }

    #[\Override]
    public function normalizeStarRating(mixed $rawValue): ?string
    {
        // Eurosite <ProductCategory> is already 1-5 (see getHotelPriceResponse).
        $digits = (string) preg_replace('/\D+/', '', TypeCoerce::toString($rawValue));
        if ($digits === '') {
            return null;
        }
        $n = (int) $digits[0];

        return ($n >= 1 && $n <= 5) ? (string) $n : null;
    }

    #[\Override]
    public function normalizeBoardCode(mixed $rawValue): ?string
    {
        $key = strtolower(trim(TypeCoerce::toString($rawValue)));
        if ($key === '') {
            return null;
        }
        foreach (self::BOARD_MAP as $needle => $code) {
            if (str_contains($key, $needle)) {
                return $code;
            }
        }

        return null;
    }

    #[\Override]
    public function normalizeRoomTypeCode(mixed $rawValue): ?string
    {
        $key = strtolower(trim(TypeCoerce::toString($rawValue)));
        if ($key === '') {
            return null;
        }
        if (isset(self::ROOM_MAP[$key])) {
            return self::ROOM_MAP[$key];
        }
        foreach (self::ROOM_MAP as $needle => $code) {
            if (str_contains($key, $needle)) {
                return $code;
            }
        }

        return null;
    }

    #[\Override]
    public function normalizePropertyType(mixed $rawValue): ?string
    {
        // Eurosite <Class> carries "Hotel", "Vila", "Apartament", etc.
        $key = strtolower(trim(TypeCoerce::toString($rawValue)));
        return match (true) {
            $key === '' => null,
            str_contains($key, 'vila'), str_contains($key, 'villa') => 'villa',
            str_contains($key, 'apartament'), str_contains($key, 'apartment') => 'apartment',
            str_contains($key, 'pensiune'), str_contains($key, 'guest') => 'guest_house',
            str_contains($key, 'hostel') => 'hostel',
            str_contains($key, 'resort') => 'resort',
            str_contains($key, 'hotel') => 'hotel',
            default => 'hotel',
        };
    }

    #[\Override]
    public function normalizeFacilityCode(mixed $rawValue): ?string
    {
        // Eurosite facilities are numeric ids in the spec; passed through as
        // strings for the mapping table to resolve. Non-numeric → null.
        $raw = trim(TypeCoerce::toString($rawValue));
        return ($raw !== '' && ctype_digit($raw)) ? $raw : null;
    }

    #[\Override]
    public function normalizeResort(mixed $rawValue): ?string
    {
        $name = trim(TypeCoerce::toString($rawValue));
        return $name !== '' ? $name : null;
    }
}
