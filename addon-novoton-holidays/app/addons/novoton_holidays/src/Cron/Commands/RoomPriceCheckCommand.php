<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Helpers\PriceCheckFlags;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\NovotonHolidays\Services\NovotonAvailabilityRecorder;
use Tygh\Addons\NovotonHolidays\Services\ResortCheckRunner;
use Tygh\Addons\NovotonHolidays\Services\ResortCheckSettings;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The weekly availability check, on room_price's existing nightly line
 * (docs/novoton-availability-gaps-plan.md §4.3): each night a share of the
 * sold resorts, asked with one resort-level room_price call per date, so
 * every resort is checked once a week. It sets has_room_price, which
 * add_hotels_as_products reads, and writes the availability records the
 * weekly review classifies. ResortCheckRunner does the work.
 *
 * It used to ask every sold hotel on its own about one date 30 days ahead:
 * up to 500 calls a night, and out of season a seaside hotel read "no
 * prices" for months.
 */
class RoomPriceCheckCommand extends AbstractCronCommand
{
    /** Parameters of the per-hotel check this replaced; a run that passes them is told they are ignored. */
    private const array RETIRED_PARAMS = ['check_in', 'limit', 'concurrency'];

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['room_price'];
    }

    public static function getDescription(): string
    {
        return 'Weekly availability check: which hotels have offers on the check dates (sets has_room_price)';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $settings = ResortCheckSettings::from(ConfigProvider::all(), $this->params);
        $country = strtoupper(trim(TypeCoerce::toString($this->getParam('country', ''))));
        foreach (self::RETIRED_PARAMS as $param) {
            if (array_key_exists($param, $this->params)) {
                $this->output("NOTE: &{$param} is no longer used: the check asks every resort on the dates in Settings -> Advanced.");
            }
        }
        $this->output('Options: &country=XX  &resorts=N|all (this run)  &max_calls=N  &max_time=MINUTES  &nights=N');
        if ($country !== '') {
            $this->output("Country: {$country}");
        }

        $runner = new ResortCheckRunner(
            $this->api,
            Container::getInstance()->databaseHelper(),
            new NovotonAvailabilityRecorder(),
            function (string $line): void {
                $this->output($line);
            },
        );
        $result = $runner->run($settings, new \DateTimeImmutable(), DestinationScope::current()->syncWhere(), $country);
        foreach (self::formatCountryGroups($result['priced']) as $line) {
            $this->output($line);
        }

        $stats = $result['stats'];
        $this->logComplete('room_price', $stats);
        $asked = $stats['checked'] + $stats['partial'] + $stats['unanswered'];
        if ($asked > 0 && $stats['checked'] + $stats['partial'] === 0) {
            return ['success' => false, 'error' => 'Novoton did not answer the availability check', 'stats' => $stats];
        }
        if ($stats['units'] === 0 && $stats['skipped_countries'] !== []) {
            return ['success' => false, 'error' => 'Novoton listed no resorts for ' . implode(', ', $stats['skipped_countries']), 'stats' => $stats];
        }

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * Whether a room_price response carries prices.
     *
     * TRUE when it has at least one <Price>, FALSE for a valid response with
     * none or an empty document ($unparsedBody, PriceCheckFlags), NULL when
     * there is no usable response (API or XML error), which leaves the
     * hotel's flag as it was. A presence check only — the cron sets
     * has_room_price Y/N; it stores no amount.
     */
    public static function hasPrices(mixed $response, string $unparsedBody = ''): ?bool
    {
        return PriceCheckFlags::hasPrices($response, $unparsedBody);
    }

    /**
     * Build the "grouped by country" output lines for hotels that have prices.
     *
     * Pure (no I/O) so it is unit-testable without driving the live API loop.
     * Countries are listed alphabetically; each carries its hotel count.
     *
     * @param array<string, list<string>> $pricedByCountry country => ["NVT-{id} | {name}", …]
     * @return list<string> Output lines (empty when there are no priced hotels).
     */
    public static function formatCountryGroups(array $pricedByCountry): array
    {
        if ($pricedByCountry === []) {
            return [];
        }

        ksort($pricedByCountry);

        $lines = ['', '=== Hotels WITH prices — grouped by country ==='];
        foreach ($pricedByCountry as $country => $hotels) {
            $lines[] = "{$country} (" . count($hotels) . '):';
            foreach ($hotels as $hotel) {
                $lines[] = "  {$hotel}";
            }
        }

        return $lines;
    }
}
