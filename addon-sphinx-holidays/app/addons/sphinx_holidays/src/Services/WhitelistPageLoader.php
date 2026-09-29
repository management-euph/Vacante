<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\DestinationWhitelistRepository;
use Tygh\Addons\SphinxHolidays\Repository\WhitelistPickerRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Reads what the Destination whitelist page shows and turns its post back
 * into rows — the database side of Services\DestinationsPicker, kept out of
 * the controller (GodFileRatchetTest caps it).
 */
final class WhitelistPageLoader
{
    public function __construct(
        private readonly WhitelistPickerRepository $picker = new WhitelistPickerRepository(),
        private readonly DestinationWhitelistRepository $whitelist = new DestinationWhitelistRepository(),
    ) {
    }

    /**
     * The page (every country; the whitelisted ones with their tree), or one
     * country with its tree for its body.
     *
     * @return array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function built(string $only = ''): array
    {
        $rows = $this->whitelist->findAllWithPlace();
        $countries = $this->picker->countries();
        $treeFor = [];
        if ($only !== '') {
            $countries = array_values(array_filter($countries, static fn (array $c): bool => $c['country_code'] === $only));
            $treeFor = [$only];
        } else {
            foreach ($rows as $row) {
                if ($row['country_code'] !== '') {
                    $treeFor[$row['country_code']] = $row['country_code'];
                }
            }
            $treeFor = array_values($treeFor);
        }

        return DestinationsPicker::build(
            $countries,
            $only === '' ? $this->picker->structure() : [],
            $only === '' ? $this->picker->hotelTotals() : [],
            $this->picker->tree($treeFor),
            $this->picker->hotelsByDestination($treeFor),
            $this->picker->circuits(),
            $rows,
            $this->whitelist->lastSavedAt(),
        );
    }

    /**
     * Live products outside the saved whitelist, and the circuits in scope.
     *
     * @return array{outside: list<array{product_id: int, label: string}>, circuits_in_scope: int}
     */
    public function outside(): array
    {
        return DestinationsPicker::outside($this->picker->liveHotels(), $this->picker->circuits(), ConfigProvider::getAllowedDestinationIds());
    }

    /** Package routes into the sold countries (they follow the arrival country). */
    public function routes(): int
    {
        return $this->picker->routesInto(ConfigProvider::getSelectedCountryCodes());
    }

    /**
     * The posted picker as whitelist rows: each loaded country is checked
     * against its own tree.
     *
     * @param array<string, array{mode: string, items: list<string>, groups: list<string>, loaded: bool}> $picked
     * @return list<array{destination_id: int, selection_type: string}>
     */
    public function rows(array $picked): array
    {
        $countryIds = [];
        foreach ($this->picker->countries() as $c) {
            $countryIds[$c['country_code']] = $c['destination_id'];
        }
        $trees = [];
        foreach ($picked as $cc => $entry) {
            $cc = strtoupper(trim((string) $cc));
            if ($entry['loaded'] && $entry['mode'] === DestinationsPicker::MODE_SPECIFIC && isset($countryIds[$cc])) {
                foreach ($this->built($cc)['countries'] as $country) {
                    if (TypeCoerce::toString($country['key'] ?? '') === $cc) {
                        $trees[$cc] = DestinationsPicker::treeOf($country);
                    }
                }
            }
        }

        return DestinationsPicker::rows($picked, $countryIds, $trees, DestinationsPicker::scope($this->whitelist->findAllWithPlace()));
    }

    /** @param list<int> $productIds */
    public function disableProducts(array $productIds): int
    {
        return $this->picker->disableProducts($productIds);
    }
}
