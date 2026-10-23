<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\NovotonAvailabilityRecorder;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoService;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: Recompute calendar_prices_raw for hotels
 *
 * Runs as a lightweight cron (no API calls, pure DB + PHP computation).
 * Typically completes in 10-30 seconds for ~1000 hotels.
 *
 * Modes:
 *   - recompute_calendar_prices: Recompute all hotels with priceinfo_data
 *
 * Parameters:
 *   - stale_only=1: Only recompute hotels where calendar_prices_raw is NULL
 *   - hotel_id=X:   Recompute a single hotel
 *
 * Usage:
 *   cron.php?mode=recompute_calendar_prices                  # All hotels
 *   cron.php?mode=recompute_calendar_prices&stale_only=1     # Only missing
 *   cron.php?mode=recompute_calendar_prices&hotel_id=12345   # Single hotel
 */
class CalendarPricesCommand extends AbstractCronCommand
{
    /** The availability backfill's share of a run (seconds) and its hotels per batch. */
    private const int BACKFILL_SECONDS = 300;

    private const int BACKFILL_BATCH = 200;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['recompute_calendar_prices'];
    }

    public static function getDescription(): string
    {
        return 'Recompute calendar_prices_raw for hotels (no API calls, fast)';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $this->output('Recompute Calendar Prices');
        $this->output('========================');
        $this->output('');

        // Single hotel mode
        $singleHotel = $this->getParam('hotel_id', '');
        if (!empty($singleHotel)) {
            return $this->recomputeSingle(TypeCoerce::toString($singleHotel));
        }

        $this->backfillAvailability();

        $staleOnly = !empty($this->params['stale_only']);

        // Find hotels that need recomputation
        if ($staleOnly) {
            $this->output('Mode: stale_only (NULL calendar_prices_raw)');
            $hotel_ids = db_get_fields(
                "SELECT DISTINCT h.hotel_id FROM ?:novoton_hotels h
                 INNER JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
                 WHERE p.priceinfo_data IS NOT NULL AND p.priceinfo_data != ''
                   AND (h.calendar_prices_raw IS NULL OR h.calendar_prices_raw = '')",
            );
        } else {
            $this->output('Mode: full (all hotels with priceinfo)');
            $hotel_ids = db_get_fields(
                "SELECT DISTINCT h.hotel_id FROM ?:novoton_hotels h
                 INNER JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
                 WHERE p.priceinfo_data IS NOT NULL AND p.priceinfo_data != ''",
            );
        }

        $total = count(TypeCoerce::toList($hotel_ids));
        $this->output("Hotels to process: {$total}");
        $this->output('');

        if ($total === 0) {
            $this->output('Nothing to do.');
            return ['success' => true, 'stats' => ['total' => 0, 'processed' => 0, 'errors' => 0]];
        }

        $processed = 0;
        $errors = 0;

        foreach (TypeCoerce::toList($hotel_ids) as $hid) {
            $hidStr = TypeCoerce::toString($hid);
            try {
                PriceInfoService::precomputeCalendarPrices($hidStr);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $this->output("  ERROR [{$hidStr}]: " . $e->getMessage());
            }

            // Progress every 100 hotels
            if (($processed + $errors) % 100 === 0) {
                $done = $processed + $errors;
                $pct = round($done / $total * 100, 1);
                $this->output("  Progress: {$done}/{$total} ({$pct}%)");
            }
        }

        $duration = round(microtime(true) - $this->startTime, 1);
        $this->output('');
        $this->output("Done in {$duration}s: {$processed} computed, {$errors} errors");

        $this->logToSyncTable('recompute_calendar_prices', $processed);

        return [
            'success' => true,
            'stats' => [
                'total' => $total,
                'processed' => $processed,
                'errors' => $errors,
                'duration_sec' => $duration,
            ],
        ];
    }

    /**
     * The first runs after this ships also fill in past seasons for the hotel
     * availability records (docs/novoton-availability-gaps-plan.md §4.4):
     * stored data only, at most BACKFILL_SECONDS a run, resumed the next
     * night, then never again.
     */
    private function backfillAvailability(): void
    {
        $recorder = new NovotonAvailabilityRecorder();
        $deadline = microtime(true) + self::BACKFILL_SECONDS;
        $hotels = 0;
        do {
            $step = $recorder->backfill(self::BACKFILL_BATCH, date('Y-m-d H:i:s'));
            $hotels += $step['hotels'];
        } while (!$step['done'] && $step['hotels'] > 0 && microtime(true) < $deadline);

        if ($hotels > 0) {
            $this->output("Availability records: past seasons filled in for {$hotels} hotels" . ($step['done'] ? ' (all done)' : ' (continues next run)'));
            $this->output('');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function recomputeSingle(string $hotelId): array
    {
        $this->output("Recomputing hotel: {$hotelId}");

        try {
            PriceInfoService::precomputeCalendarPrices($hotelId);
            $this->output('Good');
            return ['success' => true, 'stats' => ['hotel_id' => $hotelId]];
        } catch (\Throwable $e) {
            $this->output('ERROR: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
