<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Repository\HotelLinkingRepository;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: back-fill images_json for hotels that have no images stored.
 *
 * The list API (/api/v1/static/hotels) often omits the images array.
 * This command finds those hotels and calls the detail endpoint
 * (/api/v1/static/hotels/{id}) which always includes images, then writes
 * the result back to the DB so sync_images can queue them without API calls.
 *
 * Run this before sync_images until the backlog is empty:
 *   cron_mode=enrich_hotel_data&batch_size=100&country=HR
 *
 * Params:
 *   &batch_size=N   — how many hotels to process per run (default 100)
 *   &country=XX     — limit to a specific country_code (optional)
 */
class EnrichHotelDataCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['enrich_hotel_data'];
    }

    private const int DEFAULT_BATCH = 100;

    #[\Override]
    public static function getDescription(): string
    {
        return 'Back-fill images_json from detail API for hotels with empty images';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $startMs = (int)(microtime(true) * 1000);
        $batchSize = TypeCoerce::toInt($params['batch_size'] ?? self::DEFAULT_BATCH);
        $countryCode = TypeCoerce::toString($params['country'] ?? '');

        if ($batchSize <= 0) {
            $batchSize = self::DEFAULT_BATCH;
        }

        $scope = $countryCode !== '' ? "country={$countryCode}" : 'all countries';
        $this->output("Enriching hotel images — scope: {$scope}, batch_size: {$batchSize}");

        $hotels = Container::getHotelRepository()->findMissingImages($countryCode, $batchSize);

        if (empty($hotels)) {
            $this->output('No hotels with missing images found. Nothing to do.');
            return ['success' => true, 'stats' => ['scanned' => 0, 'enriched' => 0, 'none' => 0, 'failed' => 0]];
        }

        $this->output('Found ' . count($hotels) . ' hotel(s) with empty images_json.');

        $enriched = 0;
        $none = 0;
        $failed = 0;
        $api = Container::getApi();

        foreach ($hotels as $hotel) {
            $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            $hotelName = TypeCoerce::toString($hotel['name'] ?? '');

            if ($hotelId === '') {
                continue;
            }

            $fresh = $api->getHotel($hotelId);

            if ($fresh === null) {
                $httpCode = $api->getHttpClient()->getLastHttpCode();
                $apiError = $api->getHttpClient()->getLastError();
                $this->output("[{$hotelId}] {$hotelName} — API failed (HTTP {$httpCode}): {$apiError}");
                $failed++;
                continue;
            }

            // Unwrap {"data": {...}} envelope if present
            /** @var mixed $dataEnvelope */
            $dataEnvelope = $fresh['data'] ?? null;
            $hotelData = is_array($dataEnvelope) ? $dataEnvelope : $fresh;

            /** @var mixed $rawImages */
            $rawImages = $hotelData['images'] ?? null;

            // No images at Sphinx: not a failure. The hotel is asked again
            // after RECHECK_IMAGES_DAYS, so the next run moves on to others.
            if (empty($rawImages) || !is_array($rawImages)) {
                Container::getHotelRepository()->markImagesChecked($hotelId);
                $this->output("[{$hotelId}] {$hotelName} — no images at Sphinx; asked again in " . HotelLinkingRepository::RECHECK_IMAGES_DAYS . ' days');
                $none++;
                continue;
            }

            $firstUrl = '';
            foreach ($rawImages as $img) {
                if (is_array($img)) {
                    $u = TypeCoerce::toString($img['url'] ?? '');
                } elseif (is_string($img)) {
                    $u = $img;
                } else {
                    $u = '';
                }
                if ($u !== '') {
                    $firstUrl = $u;
                    break;
                }
            }

            Container::getHotelRepository()->updateImages(
                $hotelId,
                $firstUrl,
                (string) json_encode($rawImages),
            );

            $this->output("[{$hotelId}] {$hotelName} — enriched with " . count($rawImages) . ' image(s)');
            $enriched++;
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;
        $scanned = count($hotels);

        $this->output("Done: {$scanned} scanned, {$enriched} enriched, {$none} without images at Sphinx, {$failed} failed (" . round($durationMs / 1000, 1) . 's)');

        return [
            'success' => true,
            'stats' => [
                'scanned' => $scanned,
                'enriched' => $enriched,
                'none' => $none,
                'failed' => $failed,
                'duration_ms' => $durationMs,
            ],
        ];
    }
}
