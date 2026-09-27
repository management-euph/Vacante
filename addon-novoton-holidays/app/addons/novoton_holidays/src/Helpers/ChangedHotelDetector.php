<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Determines which hotels need a hotelinfo refresh for an incremental sync.
 *
 * Queries the offers_update API (per country, since the last completed
 * hotelinfo sync) for hotels whose offers changed, and unions that with hotels
 * that have never had hotelinfo synced yet. An API error for one country is
 * logged and skipped rather than aborting the whole detection.
 *
 * Extracted from BatchedHotelInfoSyncV2; behaviour is preserved verbatim. The
 * API kit is passed in (rather than resolved internally) so the caller keeps
 * control of lazy API construction and the detector stays trivially mockable.
 */
class ChangedHotelDetector
{
    public function __construct(
        private readonly SyncLoggerInterface $logger,
    ) {
    }

    /**
     * @param string[] $countries
     * @return list<string> Changed/never-synced hotel IDs
     */
    public function detect(NovotonApiKitInterface $api, array $countries): array
    {
        // Get last sync date
        $lastSync = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'hotelinfo' AND status = 'completed'",
        );

        if (empty($lastSync)) {
            // No previous sync - should do full sync instead
            return [];
        }

        $datetimeParam = date('Y-m-d\TH:i:s', (int) strtotime(TypeCoerce::toString($lastSync)));
        $this->logger->output("Checking offers_update since: {$datetimeParam}");

        /** @var array<string, true> $changedIds */
        $changedIds = [];

        foreach ($countries as $country) {
            $this->logger->output("Checking {$country}...");

            try {
                $response = $api->destinations()->getOffersUpdate($datetimeParam, $country);

                if (isset($response->Offer)) {
                    // Every <Offer>: wrapping the node set as [$response->Offer]
                    // kept only the first, so other changed hotels were missed.
                    $offers = [];
                    foreach ($response->Offer as $offerNode) {
                        $offers[] = $offerNode;
                    }
                    foreach ($offers as $offer) {
                        $hid = (string) ($offer->IdHotel ?? '');
                        if ($hid !== '') {
                            $changedIds[$hid] = true;
                        }
                    }
                    $this->logger->output('  Found ' . count($offers) . ' changed offers');
                } else {
                    $this->logger->output('  No changes');
                }
            } catch (ApiException $e) {
                $this->logger->output('  Error: ' . $e->getMessage());
            }
        }

        // Also include hotels that never had hotelinfo synced
        $unsynced = TypeCoerce::toStringList(db_get_fields(
            'SELECT hotel_id FROM ?:novoton_hotels
             WHERE country IN (?a) AND hotelinfo_synced_at IS NULL',
            $countries,
        ));

        if ($unsynced !== []) {
            $this->logger->output('Also adding ' . count($unsynced) . ' hotels that never had hotelinfo synced.');
            foreach ($unsynced as $id) {
                $changedIds[$id] = true;
            }
        }

        return array_keys($changedIds);
    }
}
