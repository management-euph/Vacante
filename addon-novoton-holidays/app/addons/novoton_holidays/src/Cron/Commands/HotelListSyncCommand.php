<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector;
use Tygh\Addons\NovotonHolidays\Api\Refusal;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\NovotonAvailabilityRecorder;

class HotelListSyncCommand extends AbstractCronCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['hotel_list'];
    }

    public static function getDescription(): string
    {
        return 'Sync hotel list from API (upsert into novoton_hotels)';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $dbHelper = Container::getInstance()->databaseHelper();
        $detector = new PropertyTypeDetector();
        $this->output('Syncing hotels from API (hotel_list)...');
        $this->output('');

        $countries = ConfigProvider::getSelectedCountries();
        $this->output('Countries: ' . implode(', ', $countries));
        $this->output('');

        $total = 0;
        $added = 0;
        $updated = 0;
        $recorder = new NovotonAvailabilityRecorder();
        // Countries that did not answer. Their hotels are left as they are:
        // an outage is never a hotel gone (availability records, plan §4.2).
        // The other countries still sync; one failure used to abort them all.
        $failed = [];

        foreach ($countries as $country) {
            $this->output("Fetching {$country}... ", false);

            try {
                $hotels = $this->api->hotels()->getHotelList($country);
            } catch (\Throwable $e) {
                $failed[] = $country;
                $this->output('no answer: ' . $e->getMessage());
                continue;
            }
            $refusal = Refusal::reason($hotels);
            if ($refusal !== null) {
                $failed[] = $country;
                $this->output("refused: {$refusal}");
                continue;
            }

            if (empty($hotels)) {
                $this->output('0 hotels (or error)');
                continue;
            }

            $count = count($hotels);
            $total += $count;
            $this->output("{$count} hotels");

            $hotelBatch = [];
            foreach ($hotels as $hotel) {
                $hotel_id = (string)($hotel->IdHotel ?? '');
                if (empty($hotel_id)) {
                    continue;
                }

                $hotelName = (string)($hotel->Hotel ?? '');
                $hotelType = (string)($hotel->HotelType ?? '');

                $hotelBatch[] = [
                    'hotel_id' => $hotel_id,
                    'hotel_name' => $hotelName,
                    'city' => (string)($hotel->City ?? ''),
                    'region' => (string)($hotel->Region ?? ''),
                    'country' => (string)($hotel->Country ?? $country),
                    'hotel_type' => $hotelType,
                    'star_rating' => self::parseStarRating($hotelType),
                    'property_type' => $detector->detect($hotelName),
                    // The Eurosite spec names the elements Latitude/Longitude;
                    // older feeds used Lat/Lng — accept both so coordinates
                    // are captured whichever spelling the live feed sends.
                    'latitude' => (string)($hotel->Lat ?? $hotel->Latitude ?? ''),
                    'longitude' => (string)($hotel->Lng ?? $hotel->Longitude ?? ''),
                    'hotel_list_synced_at' => date('Y-m-d H:i:s'),
                ];
            }

            $result = $dbHelper->upsertHotels($hotelBatch);
            $added += $result['inserted'];
            $updated += $result['updated'];

            $this->output("  -> Inserted: {$result['inserted']}, Updated: {$result['updated']}");

            $recorded = $recorder->hotelList($country, $hotelBatch, date('Y-m-d H:i:s'));
            $this->output($recorded['complete']
                ? "  -> Availability records: {$recorded['seen']} listed, {$recorded['missing']} missing from this list"
                : "  -> Availability records: {$recorded['seen']} listed; not taken as a complete list, so none counted missing");
        }

        $this->output('');
        $this->output("Total hotels: {$total}");
        $this->output('Synced: ' . ($added + $updated) . " (new: {$added})");

        // Ensure Novoton aliases exist in travel_api_alias (idempotent)
        if (function_exists('fn_novoton_holidays_seed_travel_aliases')) {
            fn_novoton_holidays_seed_travel_aliases();
        }

        $stats = ['total' => $total, 'added' => $added, 'updated' => $updated];
        $this->logComplete('hotel_list', $stats);
        if ($failed !== []) {
            return ['success' => false, 'error' => 'No hotel list for: ' . implode(', ', $failed), 'stats' => $stats];
        }

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * Parse star rating from hotel_type string.
     * Common formats: "4*", "3* Sup", "5*", "Apart"
     *
     * @return int|null Star rating 1-5 or null if not parseable
     */
    private static function parseStarRating(string $hotelType): ?int
    {
        if ($hotelType === '') {
            return null;
        }
        if (preg_match('/^(\d)/', $hotelType, $matches) === 1) {
            $stars = (int) $matches[1];
            return ($stars >= 1 && $stars <= 5) ? $stars : null;
        }
        return null;
    }
}
