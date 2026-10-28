<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The hotels an incremental hotelinfo run asks about: the ones never
 * fetched, and the ones offers_update named with a package their stored
 * hotelinfo does not list (hotelinfo_due_at, set by OffersUpdateCommand).
 *
 * No API call. This used to call offers_update itself, for every selected
 * country, on every five-minute run that found nothing to do. Novoton asked
 * (28 Oct 2026) for offers_update every 12 hours, so it runs as its own job
 * and leaves its findings on the hotel and package rows.
 *
 * A hotel Novoton did not answer for (hotelinfo_tried_at after the last
 * answer) is asked again once a day, or at once when offers_update names it
 * again, never on every run.
 */
class ChangedHotelDetector
{
    /** Seconds before a hotel Novoton did not answer for is asked again. */
    public const int RETRY_AFTER = 86400;

    public function __construct(
        private readonly SyncLoggerInterface $logger,
    ) {
    }

    /**
     * @param string[] $countries
     * @return list<string> hotel IDs, never fetched or named by offers_update since their last answer
     */
    public function detect(array $countries, int $now): array
    {
        if ($countries === []) {
            return [];
        }

        $ids = TypeCoerce::toStringList(db_get_fields(
            'SELECT hotel_id FROM ?:novoton_hotels
             WHERE country IN (?a)
               AND (hotelinfo_synced_at IS NULL OR hotelinfo_due_at > hotelinfo_synced_at)
               AND (hotelinfo_tried_at IS NULL OR hotelinfo_tried_at < hotelinfo_due_at OR hotelinfo_tried_at < ?s)
             ORDER BY hotel_name',
            $countries,
            date('Y-m-d H:i:s', $now - self::RETRY_AFTER),
        ));

        if ($ids !== []) {
            $this->logger->output(count($ids) . ' hotels never fetched or named by offers_update since their last details.');
        }

        return $ids;
    }
}
