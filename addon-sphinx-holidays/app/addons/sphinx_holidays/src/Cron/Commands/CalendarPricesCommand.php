<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Providers\SphinxIndicativePrice;
use Tygh\Addons\SphinxHolidays\Services\CalendarPriceBuilder;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: build per-date calendar from-prices for sphinx hotels.
 *
 * One synchronous cache/hotels call per destination per check-in date covers
 * every hotel in the destination, so the horizon costs
 * destinations × days_ahead requests. Prices are stored raw on
 * sphinx_hotels.calendar_prices_raw; the storefront applies commission when
 * rendering the booking-engine calendar.
 *
 * Usage:
 *   mode=calendar_prices
 *   mode=calendar_prices&days_ahead=90&nights=7
 */
class CalendarPricesCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['calendar_prices'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Build per-date calendar from-prices for the booking engine';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $daysAhead = max(1, TypeCoerce::toInt($params['days_ahead'] ?? CalendarPriceBuilder::DEFAULT_DAYS_AHEAD));
        $nights = max(1, TypeCoerce::toInt($params['nights'] ?? CalendarPriceBuilder::DEFAULT_NIGHTS));
        $currency = ConfigProvider::getDefaultCurrency();

        $destinationIds = TypeCoerce::toList(db_get_fields(
            "SELECT DISTINCT destination_id FROM ?:sphinx_hotels
             WHERE sync_status = 'active' AND destination_id > 0 AND product_id > 0",
        ));

        $this->output(sprintf(
            'Calendar prices: %d destination(s), horizon %d day(s) x %d night(s)',
            count($destinationIds),
            $daysAhead,
            $nights,
        ));

        $builder = new CalendarPriceBuilder(Container::getApi());
        $hotelRepo = Container::getHotelRepository();

        $hotelsUpdated = 0;
        $destinationsProbed = 0;
        $errors = 0;

        foreach ($destinationIds as $destId) {
            $destinationId = TypeCoerce::toInt($destId);
            if ($destinationId <= 0) {
                continue;
            }

            try {
                $byHotel = $builder->probeDestination($destinationId, $currency, $daysAhead, $nights);
            } catch (\Throwable $e) {
                $errors++;
                $this->output("  [WARN] destination {$destinationId}: " . $e->getMessage());
                continue;
            }

            $destinationsProbed++;
            foreach ($byHotel as $hotelId => $datePrices) {
                $hotelRepo->saveCalendarPrices((string) $hotelId, $datePrices);
                $hotelsUpdated++;
            }

            $this->output(sprintf(
                '  destination %d: %d hotel(s) priced',
                $destinationId,
                count($byHotel),
            ));
        }

        // The prices are stay totals: remember how many nights they cover, so
        // the hotel page's per-night "from" price divides by the right number
        // (SphinxIndicativePrice).
        if ($hotelsUpdated > 0 && function_exists('fn_set_storage_data')) {
            fn_set_storage_data(SphinxIndicativePrice::NIGHTS_STORAGE_KEY, (string) $nights);
        }

        $this->output('');
        $this->output(sprintf(
            'Done: %d destination(s) probed, %d hotel calendar(s) updated, %d error(s)',
            $destinationsProbed,
            $hotelsUpdated,
            $errors,
        ));

        return [
            'success' => $errors === 0,
            'stats' => [
                'destinations' => $destinationsProbed,
                'hotels_updated' => $hotelsUpdated,
                'errors' => $errors,
            ],
        ];
    }
}
