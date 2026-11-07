<?php

declare(strict_types=1);

/**
 * Sphinx Security Service
 *
 * Implements travel_core's SecurityServiceInterface with Sphinx-specific
 * validation rules for booking data, search parameters, and guest data.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\SphinxCacheRepository;
use Tygh\Addons\TravelCore\Contracts\SecurityServiceInterface;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;
use Tygh\Addons\TravelCore\TravelConstants;

class SecurityService implements SecurityServiceInterface
{
    /** Rate limit window in seconds */
    private const int RATE_LIMIT_WINDOW = 3600;

    /** Max bookings per hour */
    private const int MAX_BOOKINGS_PER_HOUR = 20;

    /**
     * {@inheritdoc}
     * @param array<string, mixed> $data
     * @return array{valid: bool, errors: array<int, string>}
     */
    #[\Override]
    public function validateBookingData(array $data): array
    {
        $errors = [];

        $required = ['hotel_id', 'offer_id', 'check_in', 'check_out'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        if (!empty($data['check_in']) && !$this->isValidDate(ValidationHelpers::toString($data['check_in']))) {
            $errors[] = 'Invalid check-in date format';
        }

        if (!empty($data['check_out']) && !$this->isValidDate(ValidationHelpers::toString($data['check_out']))) {
            $errors[] = 'Invalid check-out date format';
        }

        if (
            !empty($data['check_in']) && !empty($data['check_out'])
            && strtotime(ValidationHelpers::toString($data['check_out'])) <= strtotime(ValidationHelpers::toString($data['check_in']))
        ) {
            $errors[] = 'Check-out must be after check-in';
        }

        if (isset($data['adults'])) {
            $adults = ValidationHelpers::toInt($data['adults']);
            if ($adults < 1 || $adults > TravelConstants::MAX_ADULTS) {
                $errors[] = 'Adults must be between 1 and ' . TravelConstants::MAX_ADULTS;
            }
        }

        if (isset($data['children'])) {
            $children = ValidationHelpers::toInt($data['children']);
            if ($children < 0 || $children > TravelConstants::MAX_CHILDREN) {
                $errors[] = 'Children must be between 0 and ' . TravelConstants::MAX_CHILDREN;
            }
        }

        if (isset($data['total_price'])) {
            $price = ValidationHelpers::toFloat($data['total_price']);
            if ($price < 0 || $price > 100000) {
                $errors[] = 'Invalid price value';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * {@inheritdoc}
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function validateSearchParams(array $params): array
    {
        $sanitized = [];

        if (!empty($params['check_in']) && $this->isValidDate(ValidationHelpers::toString($params['check_in']))) {
            $sanitized['check_in'] = $params['check_in'];
        }

        if (!empty($params['check_out']) && $this->isValidDate(ValidationHelpers::toString($params['check_out']))) {
            $sanitized['check_out'] = $params['check_out'];
        }

        $sanitized['nights'] = max(1, min(TravelConstants::MAX_NIGHTS, ValidationHelpers::toInt($params['nights'] ?? TravelConstants::DEFAULT_NIGHTS)));
        $sanitized['adults'] = max(1, min(TravelConstants::MAX_ADULTS, ValidationHelpers::toInt($params['adults'] ?? TravelConstants::DEFAULT_ADULTS)));
        $sanitized['children'] = max(0, min(TravelConstants::MAX_CHILDREN, ValidationHelpers::toInt($params['children'] ?? TravelConstants::DEFAULT_CHILDREN)));

        if (!empty($params['destination'])) {
            $sanitized['destination'] = ValidationHelpers::sanitizeString(ValidationHelpers::toString($params['destination']), 100);
        }

        if (!empty($params['hotel_id'])) {
            $sanitized['hotel_id'] = preg_replace('/[^a-zA-Z0-9_-]/', '', substr(ValidationHelpers::toString($params['hotel_id']), 0, 50));
        }

        if (!empty($params['children_ages']) && is_string($params['children_ages'])) {
            $sanitized['children_ages'] = preg_replace('/[^0-9.,]/', '', $params['children_ages']);
        }

        return $sanitized;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function checkBookingRateLimit(string $identifier): bool
    {
        $cacheKey = 'sphinx_rate_booking_' . md5($identifier);
        $now = time();

        $cacheRepo = new SphinxCacheRepository();
        $row = $cacheRepo->findByKey($cacheKey);

        $cacheData = '';
        if (is_array($row) && ValidationHelpers::toInt($row['expires_at'] ?? 0) > $now) {
            $cacheData = ValidationHelpers::toString($row['cache_data'] ?? '');
        }

        $decoded = $cacheData !== '' ? json_decode($cacheData, true) : null;
        $record = is_array($decoded) ? $decoded : [];

        $count = ValidationHelpers::toInt($record['count'] ?? 0);
        $reset = ValidationHelpers::toInt($record['reset'] ?? 0);

        if ($reset <= $now) {
            $count = 0;
            $reset = $now + self::RATE_LIMIT_WINDOW;
        }

        if ($count >= self::MAX_BOOKINGS_PER_HOUR) {
            return false;
        }

        $count++;

        $cacheRepo->upsert(
            $cacheKey,
            json_encode(['count' => $count, 'reset' => $reset], JSON_UNESCAPED_UNICODE) ?: '',
            $reset + 60,
        );

        return true;
    }

    /**
     * {@inheritdoc}
     * @param array<string, mixed> $guests
     * @return array<string, mixed>
     */
    #[\Override]
    public function sanitizeGuestData(array $guests): array
    {
        $sanitized = [];

        foreach ($guests as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            $sanitized[$key] = [
                'first_name' => $this->sanitizeName(ValidationHelpers::toString($guest['first_name'] ?? '')),
                'last_name' => $this->sanitizeName(ValidationHelpers::toString($guest['last_name'] ?? '')),
                'name' => $this->sanitizeName(ValidationHelpers::toString($guest['name'] ?? '')),
                'type' => in_array($guest['type'] ?? '', ['adult', 'child'], true) ? $guest['type'] : 'adult',
                'age' => isset($guest['age']) ? max(0, min(99, ValidationHelpers::toInt($guest['age']))) : null,
                'room' => max(1, min(5, ValidationHelpers::toInt($guest['room'] ?? 1))),
                'is_holder' => !empty($guest['is_holder']),
            ];

            if (!empty($guest['birthday']) && $this->isValidDate(ValidationHelpers::toString($guest['birthday']))) {
                $sanitized[$key]['birthday'] = $guest['birthday'];
            }
            // The date of birth the guest cards post (DD/MM/YYYY, or
            // YYYY-MM-DD): it was dropped here, so no Sphinx booking kept a
            // birth date, and the child age guard never saw one.
            // GuestDataService::parseDob() checks the date itself.
            $dob = trim(ValidationHelpers::toString($guest['dob'] ?? ''));
            if (preg_match('#^(\d{2}/\d{2}/\d{4}|\d{4}-\d{2}-\d{2})$#', $dob) === 1) {
                $sanitized[$key]['dob'] = $dob;
            }
            // Sphinx's book calls want each guest's gender: m or f.
            $gender = strtolower(trim(ValidationHelpers::toString($guest['gender'] ?? '')));
            if (in_array($gender, ['m', 'f'], true)) {
                $sanitized[$key]['gender'] = $gender;
            }
        }

        return $sanitized;
    }

    private function isValidDate(string $date): bool
    {
        return ValidationHelpers::isValidDate($date);
    }

    private function sanitizeName(string $name): string
    {
        return ValidationHelpers::sanitizeName($name);
    }
}
