<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\GuestDataServiceInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Guest Data Service
 *
 * Handles guest data parsing, formatting, and validation.
 * Supports both single and multi-room bookings.
 * Provider-agnostic — used by all travel providers.
 */
class GuestDataService implements GuestDataServiceInterface
{
    private readonly GuestDataNormalizer $normalizer;

    public function __construct(?GuestDataNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new GuestDataNormalizer();
    }

    /**
     * Parse guests data from booking form.
     *
     * Accepts any supported format (keyed, indexed-array, or JSON string)
     * and always returns canonical keyed format via GuestDataNormalizer.
     *
     * @param array<string, mixed> $bookingData Booking form data
     * @return array<string, mixed> Parsed guests data in canonical keyed format
     */
    #[\Override]
    public function parseGuestsData(array $bookingData): array
    {
        // Primary source: guests_data field
        if (!empty($bookingData['guests_data'])) {
            $normalized = $this->normalizer->normalize(self::normalizableInput($bookingData['guests_data']));
            if (!empty($normalized)) {
                return $normalized;
            }
        }

        // Fallback: guests array (legacy format)
        if (!empty($bookingData['guests'])) {
            return $this->normalizer->normalize(self::normalizableInput($bookingData['guests']));
        }

        return [];
    }

    /**
     * Format name for API (FirstName LastName)
     *
     * @param array<string, mixed> $guest Guest data
     * @return string Formatted API name
     */
    #[\Override]
    public function formatApiName(array $guest): string
    {
        // If already has api_name, use it
        if (!empty($guest['api_name'])) {
            return TypeCoerce::toString($guest['api_name']);
        }

        // Build from first/last name
        $first = trim(TypeCoerce::toString($guest['first_name'] ?? ''));
        $last = trim(TypeCoerce::toString($guest['last_name'] ?? ''));

        if ($first !== '' && $last !== '') {
            return $first . ' ' . $last;
        }

        // Fall back to name field
        return trim(TypeCoerce::toString($guest['name'] ?? 'Guest'));
    }

    /**
     * Build comma-separated guest list
     *
     * @param array<string, mixed> $guests_data Guests data (keyed array)
     * @return string Guest list
     */
    #[\Override]
    public function buildGuestList(array $guests_data): string
    {
        $names = [];

        foreach ($guests_data as $guest) {
            if (is_array($guest)) {
                $name = TypeCoerce::toString($guest['api_name'] ?? $guest['name'] ?? '');
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return implode(', ', $names);
    }

    /**
     * Get holder name from guests data
     *
     * @param array<string, mixed> $guests_data Guests data
     * @param array<string, mixed> $bookingData Fallback booking data
     * @return string Holder name
     */
    #[\Override]
    public function getHolderName(array $guests_data, array $bookingData = []): string
    {
        // Look for holder flag
        foreach ($guests_data as $guest) {
            if (is_array($guest) && !empty($guest['is_holder'])) {
                $name = trim(TypeCoerce::toString($guest['api_name'] ?? $guest['name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        // Look for room1_adult_1 (usually the holder)
        if (isset($guests_data['room1_adult_1']) && is_array($guests_data['room1_adult_1'])) {
            $guest = $guests_data['room1_adult_1'];
            $name = trim(TypeCoerce::toString($guest['api_name'] ?? $guest['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        // First guest
        $first = reset($guests_data);
        if (is_array($first)) {
            $name = trim(TypeCoerce::toString($first['api_name'] ?? $first['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        // Fallback to booking data
        return TypeCoerce::toString($bookingData['holder_name'] ?? 'Guest');
    }

    /**
     * Get guests grouped by room
     *
     * @param array<string, mixed> $guests_data Guests data
     * @return array<int, array<int, array<int|string, mixed>>> Guests by room [room_num => [guests]]
     */
    #[\Override]
    public function getGuestsByRoom(array $guests_data): array
    {
        $by_room = [];

        foreach ($guests_data as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            // Try to get room from data
            $room = TypeCoerce::toInt($guest['room'] ?? 1);

            // Or parse from key (room1_adult_1)
            if (preg_match('/^room(\d+)_/', (string) $key, $matches) === 1) {
                $room = (int) $matches[1];
            }

            if (!isset($by_room[$room])) {
                $by_room[$room] = [];
            }

            $by_room[$room][] = $guest;
        }

        ksort($by_room);
        return $by_room;
    }

    /**
     * Get guest counts per room
     *
     * @param array<string, mixed> $guests_data Guests data
     * @return array<int, array{adults: int, children: int}> Room counts [room_num => [adults, children]]
     */
    #[\Override]
    public function getRoomCounts(array $guests_data): array
    {
        $by_room = $this->getGuestsByRoom($guests_data);
        $counts = [];

        foreach ($by_room as $room_num => $guests) {
            $adults = 0;
            $children = 0;

            foreach ($guests as $guest) {
                if (($guest['type'] ?? 'adult') === 'child') {
                    $children++;
                } else {
                    $adults++;
                }
            }

            $counts[$room_num] = [
                'adults' => $adults,
                'children' => $children,
            ];
        }

        return $counts;
    }

    /**
     * Format guests for display
     *
     * @param array<string, mixed> $guests_data Guests data
     * @return array<int, array<string, mixed>> Display-formatted guests
     */
    #[\Override]
    public function formatForDisplay(array $guests_data): array
    {
        $display = [];

        foreach ($guests_data as $guest) {
            if (!is_array($guest)) {
                continue;
            }

            $name = $guest['api_name'] ?? $guest['name'] ?? 'Guest';
            $type = ($guest['type'] ?? 'adult') === 'child' ? 'Child' : 'Adult';
            $room = $guest['room'] ?? 1;

            $display[] = [
                'name' => $name,
                'type' => $type,
                'type_lower' => strtolower($type),
                'age' => $guest['age'] ?? null,
                'room' => $room,
                'is_holder' => !empty($guest['is_holder']),
            ];
        }

        return $display;
    }

    /**
     * Validate guests data
     *
     * @param array<string, mixed> $guests_data Guests data
     * @param int $expected_adults Expected adult count
     * @param int $expected_children Expected children count
     * @return array{valid: bool, errors: array<string>, adults: int, children: int} Validation result
     */
    #[\Override]
    public function validate(array $guests_data, int $expected_adults = 0, int $expected_children = 0): array
    {
        $errors = [];
        $adults = 0;
        $children = 0;

        foreach ($guests_data as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            // Check name
            $name = TypeCoerce::toString($guest['api_name'] ?? $guest['name'] ?? '');
            if ($name === '' || strlen($name) < 2) {
                $errors[] = "Guest '{$key}' has invalid name";
            }

            // Count by type
            if (($guest['type'] ?? 'adult') === 'child') {
                $children++;
                // Children should have age
                if (!isset($guest['age']) || $guest['age'] < 0 || $guest['age'] > 17) {
                    $errors[] = "Child '{$key}' has invalid age";
                }
            } else {
                $adults++;
            }
        }

        // Check counts
        if ($expected_adults > 0 && $adults !== $expected_adults) {
            $errors[] = "Expected {$expected_adults} adults, found {$adults}";
        }

        if ($expected_children > 0 && $children !== $expected_children) {
            $errors[] = "Expected {$expected_children} children, found {$children}";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'adults' => $adults,
            'children' => $children,
        ];
    }

    /**
     * Format guests data for order display (email/admin/frontend).
     *
     * Converts api_name (First Last) to display format (Last, First)
     * and marks the holder guest. Shared by travel_core and provider hooks.
     *
     * @param mixed $guests_data Raw guests data (array or JSON string)
     * @param string $holder_name Holder name for matching
     * @return array<string, mixed> Formatted guests array, or empty array if input is empty
     */
    public static function formatGuestsForOrderDisplay($guests_data, string $holder_name = ''): array
    {
        if (empty($guests_data)) {
            return [];
        }

        $guests_data = (new GuestDataNormalizer())->normalize(self::normalizableInput($guests_data));
        if (empty($guests_data)) {
            return [];
        }

        $formatted = [];
        $is_first = true;

        foreach ($guests_data as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            $display_name = TypeCoerce::toString($guest['display_name'] ?? $guest['name'] ?? '');
            $api_name = TypeCoerce::toString($guest['api_name'] ?? '');

            if ($display_name === '' && $api_name !== '') {
                $parts = explode(' ', trim($api_name), 2);
                $display_name = count($parts) === 2
                    ? $parts[1] . ', ' . $parts[0]
                    : $api_name;
            }

            $guest_type = $guest['type'] ?? 'adult';
            $is_holder = false;

            if ($is_first && $guest_type === 'adult') {
                $is_holder = true;
                $is_first = false;
            } elseif (!empty($holder_name) && str_contains(strtolower($display_name), strtolower($holder_name))) {
                $is_holder = true;
            }

            $formatted[$key] = [
                'display_name' => $display_name,
                'name' => $guest['name'] ?? $display_name,
                'type' => $guest_type,
                'age' => TypeCoerce::toInt($guest['age'] ?? 0),
                'is_holder' => $is_holder,
                'birthday' => $guest['birthday'] ?? '',
                'room' => $guest['room'] ?? 1,
            ];
        }

        return $formatted;
    }

    /**
     * Merge guest data from multiple sources
     *
     * @param array<string, mixed> $sources Array of guest data sources
     * @return array<string, mixed> Merged guests data
     */
    #[\Override]
    public function merge(array ...$sources): array
    {
        $merged = [];

        foreach ($sources as $source) {
            foreach ($source as $key => $guest) {
                $existing = $merged[$key] ?? null;
                if (!is_array($existing) || empty($existing['name'])) {
                    $merged[$key] = $guest;
                    continue;
                }
                // Merge non-empty values into the existing guest
                if (is_array($guest)) {
                    foreach ($guest as $field => $value) {
                        if (!empty($value) && empty($existing[$field])) {
                            $existing[$field] = $value;
                        }
                    }
                    $merged[$key] = $existing;
                }
            }
        }

        return $merged;
    }

    /**
     * Parse date of birth from guest form data.
     *
     * Accepts multiple input formats:
     *   - $guest['dob'] as DD/MM/YYYY
     *   - $guest['dob'] as YYYY-MM-DD
     *   - $guest['dob_day'] + $guest['dob_month'] + $guest['dob_year'] (component)
     *   - $guest['birthday'] as YYYY-MM-DD
     *
     * @param array<string, mixed> $guest Guest form data
     * @return string YYYY-MM-DD or '' if invalid/missing
     */
    #[\Override]
    public static function parseDob(array $guest): string
    {
        $currentYear = (int) date('Y');
        $minYear = $currentYear - 120;

        // Format 1: $guest['dob'] field (DD/MM/YYYY or YYYY-MM-DD)
        if (!empty($guest['dob'])) {
            $dob = trim(TypeCoerce::toString($guest['dob']));

            // DD/MM/YYYY
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dob, $m) === 1) {
                $d = (int) $m[1];
                $mo = (int) $m[2];
                $y = (int) $m[3];
                if (
                    $d >= 1 && $d <= 31 && $mo >= 1 && $mo <= 12
                    && $y >= $minYear && $y <= $currentYear
                    && checkdate($mo, $d, $y)
                ) {
                    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
                }
            }

            // YYYY-MM-DD
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) === 1) {
                $parts = explode('-', $dob);
                if (checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                    return $dob;
                }
            }
        }

        // Format 2: Component fields (dob_day, dob_month, dob_year)
        if (!empty($guest['dob_day']) && !empty($guest['dob_month']) && !empty($guest['dob_year'])) {
            $d = TypeCoerce::toInt($guest['dob_day']);
            $mo = TypeCoerce::toInt($guest['dob_month']);
            $y = TypeCoerce::toInt($guest['dob_year']);
            if (
                $d >= 1 && $d <= 31 && $mo >= 1 && $mo <= 12
                && $y >= $minYear && $y <= $currentYear
                && checkdate($mo, $d, $y)
            ) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        // Format 3: $guest['birthday'] as YYYY-MM-DD
        if (!empty($guest['birthday'])) {
            $raw = trim(TypeCoerce::toString($guest['birthday']));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
                $parts = explode('-', $raw);
                if (checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                    return $raw;
                }
            }
        }

        return '';
    }

    /**
     * Parse and validate guest data from a booking form submission.
     *
     * Shared by all travel providers. Handles:
     *   - Name parsing (first/last or single name)
     *   - DOB parsing and validation (no future dates)
     *   - Child age validation at check-in (must be under 18)
     *   - Age calculation from DOB
     *   - Holder name resolution (prefers is_holder flag, falls back to first guest)
     *
     * @param array<string, mixed> $guests Raw guests array from form
     * @param string $checkIn Check-in date (YYYY-MM-DD) for child age validation
     * @param string $provider Provider name for log/notification messages ('novoton'|'sphinx')
     * @return array<string, mixed>|false Parsed result array or false if validation fails
     */
    #[\Override]
    public static function parseAndValidateGuests(
        array $guests,
        string $checkIn = '',
        string $provider = '',
    ): array|false {
        $guestNames = [];
        $guestsData = [];

        foreach ($guests as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            $firstName = trim(TypeCoerce::toString($guest['first_name'] ?? ''));
            $lastName = trim(TypeCoerce::toString($guest['last_name'] ?? ''));
            $name = trim(TypeCoerce::toString($guest['name'] ?? ''));
            // Kept only when the form asks it (Sphinx packages: m/f, each
            // provider's sanitizer decides which values pass).
            $gender = trim(TypeCoerce::toString($guest['gender'] ?? ''));
            $genderField = $gender !== '' ? ['gender' => $gender] : [];

            // The age the form DECLARED for this guest (for children this is the
            // age the offer was searched/priced for — the hidden [age] field).
            // Null when the form posted no age at all: "not declared" must stay
            // distinguishable from a declared age of 0 (infant).
            $declaredAge = isset($guest['age']) && $guest['age'] !== ''
                ? TypeCoerce::toInt($guest['age'])
                : null;
            // Age at CHECK-IN derived from the DOB (children only; null when
            // unknown). Distinct from 'age' below, which is age at today.
            $childAgeAtCheckin = null;

            $birthday = self::parseDob(TypeCoerce::toStringMap($guest));

            // Validate DOB: not in future
            if (!empty($birthday)) {
                $dobTimestamp = strtotime($birthday);
                $todayMidnight = strtotime('today midnight');
                if ($dobTimestamp !== false && $dobTimestamp > $todayMidnight) {
                    $birthday = '';
                    if (!empty($provider)) {
                        fn_log_event('general', 'runtime', [
                            'message' => ucfirst($provider) . ': Rejected future DOB',
                            'guest_key' => $key,
                            'invalid_dob' => $guest['dob'] ?? $guest['birthday'] ?? 'unknown',
                        ]);
                    }
                }

                // Validate child age: must be under 18 at check-in
                $guestType = strtolower(TypeCoerce::toString($guest['type'] ?? ''));
                $isChild = (str_contains($key, 'child') || $guestType === 'child');
                if ($dobTimestamp !== false && $isChild && !empty($checkIn)) {
                    try {
                        $dobDate = new \DateTime($birthday);
                        $checkInDate = new \DateTime($checkIn);
                        $ageAtCheckin = $dobDate->diff($checkInDate)->y;
                        $childAgeAtCheckin = $ageAtCheckin;

                        if ($ageAtCheckin >= 18) {
                            if (!empty($provider)) {
                                fn_log_event('general', 'runtime', [
                                    'message' => ucfirst($provider) . ': Child age >= 18 at check-in (blocked)',
                                    'guest_key' => $key,
                                    'birthday' => $birthday,
                                    'check_in' => $checkIn,
                                    'calculated_age' => $ageAtCheckin,
                                ]);
                            }
                            $langKey = $provider . '_holidays.child_must_be_under_18';
                            fn_set_notification('E', __('error'), __(
                                $langKey,
                                ['[default]' => 'Child must be under 18 years old at check-in date.'],
                            ));
                            return false;
                        }
                    } catch (\Exception) {
                        $birthday = '';
                    }
                }
            }

            // Resolve display name and API name
            if (!empty($lastName) || !empty($firstName)) {
                if (!empty($lastName) && !empty($firstName)) {
                    $displayName = $lastName . ', ' . $firstName;
                    $apiName = $firstName . ' ' . $lastName;
                } elseif (!empty($lastName)) {
                    $displayName = $lastName;
                    $apiName = $lastName;
                } else {
                    $displayName = $firstName;
                    $apiName = $firstName;
                }
                $guestNames[] = $displayName;

                $guestAge = self::calculateAge($birthday, $declaredAge ?? 0);

                $guestsData[$key] = [
                    'name' => $displayName,
                    'api_name' => $apiName,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'type' => $guest['type'] ?? 'adult',
                    'age' => $guestAge,
                    'declared_age' => $declaredAge,
                    'age_at_checkin' => $childAgeAtCheckin,
                    'birthday' => $birthday,
                    'dob' => !empty($birthday) ? date('d/m/Y', (int) strtotime($birthday)) : '',
                    'room' => TypeCoerce::toInt($guest['room'] ?? 1),
                    'is_holder' => !empty($guest['is_holder']) ? 1 : 0,
                ] + $genderField;
            } elseif (!empty($name)) {
                $guestNames[] = $name;

                $guestAge = self::calculateAge($birthday, $declaredAge ?? 0);

                $guestsData[$key] = [
                    'name' => $name,
                    'api_name' => $name,
                    'first_name' => '',
                    'last_name' => '',
                    'type' => $guest['type'] ?? 'adult',
                    'age' => $guestAge,
                    'declared_age' => $declaredAge,
                    'age_at_checkin' => $childAgeAtCheckin,
                    'birthday' => $birthday,
                    'dob' => !empty($birthday) ? date('d/m/Y', (int) strtotime($birthday)) : '',
                    'room' => TypeCoerce::toInt($guest['room'] ?? 1),
                    'is_holder' => !empty($guest['is_holder']) ? 1 : 0,
                ] + $genderField;
            }
        }

        // Resolve holder: prefer guest with is_holder flag, fallback to first guest
        $holderName = $guestNames[0] ?? '';
        foreach ($guestsData as $g) {
            if (!empty($g['is_holder'])) {
                $holderName = $g['name'];
                break;
            }
        }

        return [
            'guests_data' => $guestsData,
            'guest_names' => $guestNames,
            'guest_list' => implode(', ', $guestNames),
            'holder_name' => $holderName,
        ];
    }

    /**
     * Find the first child whose DOB-derived age at CHECK-IN differs from the
     * age the offer was searched/priced for (the declared hidden [age] field).
     *
     * Providers whose offer price is fixed per searched occupancy (sphinx)
     * block the booking on a hit — booking a child on a price calculated for a
     * different age produces a wrong-price booking or an API rejection.
     * Providers that re-price live on DOB change (novoton) don't need this.
     *
     * Only children with BOTH a declared age and a computed age-at-check-in
     * are compared; guests missing either are skipped (nothing to validate).
     *
     * @param array<string, mixed> $guestsData Parsed guests from parseAndValidateGuests()
     * @return array{key: string, name: string, declared_age: int, age_at_checkin: int}|null
     */
    public static function findChildAgeMismatch(array $guestsData): ?array
    {
        foreach ($guestsData as $key => $guest) {
            if (!is_array($guest) || strtolower(TypeCoerce::toString($guest['type'] ?? '')) !== 'child') {
                continue;
            }
            if (!isset($guest['age_at_checkin']) || !is_numeric($guest['age_at_checkin'])) {
                continue;
            }
            if (!isset($guest['declared_age']) || !is_numeric($guest['declared_age'])) {
                continue;
            }

            $declared = TypeCoerce::toInt($guest['declared_age']);
            $atCheckin = TypeCoerce::toInt($guest['age_at_checkin']);

            if ($declared !== $atCheckin) {
                return [
                    'key' => TypeCoerce::toString($key),
                    'name' => TypeCoerce::toString($guest['name'] ?? ''),
                    'declared_age' => $declared,
                    'age_at_checkin' => $atCheckin,
                ];
            }
        }

        return null;
    }

    /**
     * Coerce raw guest data to the string the normalizer can decode.
     *
     * The normalizer accepts array<string,mixed>|string; arrays (including the
     * legacy indexed format) are JSON round-tripped so a mixed value still
     * reaches it as a decodable string without changing the structure it sees
     * (guest data is JSON round-trip safe).
     */
    private static function normalizableInput(mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }
        $json = json_encode($raw);
        return $json === false ? '' : $json;
    }

    /**
     * Calculate guest age from birthday, with fallback to form-supplied age.
     */
    private static function calculateAge(string $birthday, int $fallbackAge): int
    {
        if (!empty($birthday)) {
            try {
                $dobDate = new \DateTime($birthday);
                return $dobDate->diff(new \DateTime())->y;
            } catch (\Exception) {
                // Invalid date — use fallback
            }
        }

        return $fallbackAge;
    }
}
