<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Providers;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Contracts\CircuitProductProviderInterface;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDay;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDetails;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;
use Tygh\Addons\TravelCore\Helpers\PlainText;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\CircuitDepartureRepository;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\TravelCore\ViewModels\CircuitDetailsViewFactory;

/**
 * Claims the products that are Sphinx circuits: a row of ?:sphinx_circuits
 * linked to the product (the add_circuit_products cron's SPXC… products),
 * still sold. Its departures come from the circuits sync: the ids (JSON)
 * and the names (", "-joined), in the same order; its hotels' stars from
 * the itinerary the sync keeps (each day's accommodation category). Its
 * page's program, inclusions and things to know come from the itinerary
 * and the details the sync keeps (itinerary_json, details_json); the facts
 * under its title from its transport and its destinations.
 */
final class SphinxCircuitProductProvider implements CircuitProductProviderInterface
{
    /**
     * This add-on's name in Travel Core's provider registry: the supplier of
     * its circuits' pages, and of their rows in the shared departures table
     * (?:travel_circuit_departures).
     */
    public const string PROVIDER = 'sphinx';

    /** @var \Closure(string): float circuit id => its "from" price for 2 adults (store price, primary currency; 0 when unknown) */
    private readonly \Closure $fromPrice;

    /**
     * Tests inject the price; the default reads the departures the circuits
     * sync saved, with the store's commission, in its primary currency.
     *
     * @param (\Closure(string): float)|null $fromPrice
     */
    public function __construct(?\Closure $fromPrice = null)
    {
        $this->fromPrice = $fromPrice ?? static function (string $circuitId): float {
            $lowest = (new CircuitDepartureRepository())->lowestPrice(self::PROVIDER, $circuitId, date('Y-m-d'));
            if ($lowest === null) {
                return 0.0;
            }
            $primary = defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR';

            return (new CurrencyService($lowest['currency'] !== '' ? $lowest['currency'] : ConfigProvider::getDefaultCurrency()))
                ->convertFromApiCurrency(Container::getCartService()->applyCommission($lowest['price']), $primary);
        };
    }

    #[\Override]
    public function resolveCircuit(int $productId, string $productCode): ?CircuitPage
    {
        if ($productId <= 0) {
            return null;
        }
        $row = TypeCoerce::toStringMap(db_get_row(
            "SELECT circuit_id, name, departure_ids, departure_names, duration_days, duration_nights, itinerary_json,
                    transport_type, destination_names
             FROM ?:sphinx_circuits WHERE product_id = ?i AND sync_status = 'active' LIMIT 1",
            $productId,
        ));
        $circuitId = TypeCoerce::toInt($row['circuit_id'] ?? 0);
        if ($circuitId <= 0) {
            return null;
        }

        [$starsMin, $starsMax] = self::hotelStars($row['itinerary_json'] ?? null);

        return new CircuitPage(
            self::PROVIDER,
            (string) $circuitId,
            TypeCoerce::toString($row['name'] ?? ''),
            self::departures($row['departure_ids'] ?? null, TypeCoerce::toString($row['departure_names'] ?? '')),
            TypeCoerce::toInt($row['duration_days'] ?? 0),
            TypeCoerce::toInt($row['duration_nights'] ?? 0),
            $starsMin,
            $starsMax,
            self::transport(TypeCoerce::toString($row['transport_type'] ?? '')),
            self::route(TypeCoerce::toString($row['destination_names'] ?? '')),
            max(0.0, ($this->fromPrice)((string) $circuitId)),
        );
    }

    /** Sphinx's transport_type ("flight", "bus") as CircuitPage's; '' for anything else. */
    private static function transport(string $type): string
    {
        return match (strtolower(trim($type))) {
            'flight', 'plane' => CircuitPage::FLIGHT,
            'bus', 'coach' => CircuitPage::BUS,
            default => '',
        };
    }

    /**
     * The places on the route, from destination_names (", "-joined by the
     * circuits sync), each once, in order.
     *
     * @return list<string>
     */
    public static function route(string $names): array
    {
        $route = [];
        foreach (explode(', ', $names) as $name) {
            $name = trim($name);
            if ($name !== '' && !in_array($name, $route, true)) {
                $route[] = $name;
            }
        }

        return $route;
    }

    #[\Override]
    public function circuitDetails(string $circuitId): ?CircuitDetails
    {
        $id = TypeCoerce::toInt($circuitId);
        if ($id <= 0) {
            return null;
        }
        // Every column, not the two by name: on an install SchemaMigrator has
        // not reached yet details_json does not exist, and the page must not break.
        $row = TypeCoerce::toStringMap(db_get_row(
            "SELECT * FROM ?:sphinx_circuits WHERE circuit_id = ?i AND sync_status = 'active' LIMIT 1",
            $id,
        ));

        return $row === [] ? null : self::details($row['itinerary_json'] ?? null, $row['details_json'] ?? null);
    }

    /**
     * Sphinx's additional_info notes have no type ("category": "other"):
     * these words in a note's title say where the page shows it. Any other
     * note is a thing to know.
     *
     * @var array<string, string> section => title pattern
     */
    public const array NOTE_SECTIONS = [
        'highlights' => '/(incant|încânt|highlight|de ce sa|de ce să|puncte de atractie|puncte de atracție)/iu',
        'excursions' => '/excursi/iu',
        'prices' => '/(reduceri|supliment|discount)/iu',
    ];

    /**
     * The page's program and details from what the circuits sync keeps:
     * each itinerary day (its title, its description and additional info,
     * its hotel and stars, its meals, its photos, whether it is a high
     * point) and the details (CircuitStaticContent::details(): included,
     * not included, the notes by where they show, the group's size, the age
     * asked, the difficulty, the hotel, the optional excursions, the
     * documents). The texts come as plain text: Sphinx may send HTML.
     *
     * @param mixed $itinerary the itinerary_json column (a JSON list of days)
     * @param mixed $details the details_json column (CircuitStaticContent::details())
     */
    public static function details(mixed $itinerary, mixed $details): CircuitDetails
    {
        $plain = static fn (mixed $value): string => PlainText::fromHtml(TypeCoerce::toString($value));

        $days = [];
        foreach (self::jsonRows($itinerary) as $day) {
            $accommodation = TypeCoerce::toStringMap($day['accommodation'] ?? null);
            $text = trim($plain($day['description'] ?? '') . "\n\n" . $plain($day['additional_info'] ?? ''));
            $circuitDay = new CircuitDay(
                $plain($day['title'] ?? ''),
                $text,
                $plain($accommodation['hotel'] ?? ''),
                self::stars(TypeCoerce::toString($accommodation['category'] ?? '')),
                $plain(TypeCoerce::toStringMap($day['meals'] ?? null)['description'] ?? ''),
                self::photos($day['images'] ?? null),
                ($day['is_highlighted'] ?? false) === true,
            );
            if ($circuitDay->title !== '' || $circuitDay->text !== '' || $circuitDay->hotel !== '' || $circuitDay->meals !== '') {
                $days[] = $circuitDay;
            }
        }

        $map = TypeCoerce::toStringMap(is_string($details) ? json_decode($details, true) : $details);
        // An item is its title, else its description.
        $titles = static function (mixed $items) use ($plain): array {
            $out = [];
            foreach (TypeCoerce::toRowList($items) as $item) {
                $title = $plain($item['title'] ?? '');
                $title = $title !== '' ? $title : $plain($item['description'] ?? '');
                if ($title !== '') {
                    $out[] = $title;
                }
            }

            return $out;
        };
        $sections = ['notes' => [], 'highlights' => [], 'excursions' => [], 'prices' => []];
        foreach (TypeCoerce::toRowList($map['additional_info'] ?? []) as $item) {
            $note = ['title' => $plain($item['title'] ?? ''), 'text' => $plain($item['description'] ?? '')];
            if ($note['title'] !== '' || $note['text'] !== '') {
                $sections[self::noteSection($note['title'])][] = $note;
            }
        }
        $group = TypeCoerce::toStringMap($map['group_size'] ?? null);
        $age = TypeCoerce::toStringMap($map['age'] ?? null);
        $hotel = TypeCoerce::toStringMap($map['accommodation'] ?? null);

        return new CircuitDetails(
            $days,
            $titles($map['included'] ?? []),
            $titles($map['not_included'] ?? []),
            $sections['notes'],
            max(0, TypeCoerce::toInt($group['min'] ?? 0)),
            max(0, TypeCoerce::toInt($group['max'] ?? 0)),
            max(0, TypeCoerce::toInt($age['min'] ?? 0)),
            max(0, TypeCoerce::toInt($age['max'] ?? 0)),
            self::highlights($sections['highlights']),
            self::difficulty($map['difficulty'] ?? null),
            [
                'hotel' => $plain($hotel['hotel'] ?? ''),
                'stars' => self::stars(TypeCoerce::toString($hotel['category'] ?? '')),
                'text' => self::keepLines(TypeCoerce::toString($hotel['description'] ?? '')),
            ],
            self::excursions($map['activities'] ?? null),
            $sections['excursions'],
            $sections['prices'],
            self::documents($map['documents'] ?? null, $plain),
        );
    }

    /** Where a note with this title shows: highlights, excursions, prices, or notes. */
    public static function noteSection(string $title): string
    {
        foreach (self::NOTE_SECTIONS as $section => $pattern) {
            if (preg_match($pattern, $title) === 1) {
                return $section;
            }
        }

        return 'notes';
    }

    /**
     * The trip's high points: the first highlights note's title and its
     * lines (each a point), the others' lines after them.
     *
     * @param list<array{title: string, text: string}> $notes
     *
     * @return array{title: string, items: list<string>}
     */
    private static function highlights(array $notes): array
    {
        $items = [];
        foreach ($notes as $note) {
            array_push($items, ...CircuitDetailsViewFactory::lines($note['text']));
        }

        return ['title' => $notes[0]['title'] ?? '', 'items' => $items];
    }

    /** The difficulty, 1 to 5; 0 when the details say none. */
    private static function difficulty(mixed $raw): int
    {
        $level = TypeCoerce::toInt(TypeCoerce::toStringMap($raw)['level'] ?? 0);

        return $level >= 1 && $level <= 5 ? $level : 0;
    }

    /**
     * A text that may be HTML, as plain text with its lines kept (the
     * hotel's "# Facilitati camere" titles start lines).
     */
    private static function keepLines(string $text): string
    {
        return trim(PlainText::fromHtml(str_replace(["\r\n", "\r"], "\n", $text)));
    }

    /**
     * @return list<array{title: string, text: string, duration: string, price: float, currency: string, highlighted: bool}>
     */
    private static function excursions(mixed $list): array
    {
        $out = [];
        foreach (TypeCoerce::toRowList($list) as $activity) {
            $title = PlainText::fromHtml(TypeCoerce::toString($activity['title'] ?? ''));
            $text = PlainText::fromHtml(TypeCoerce::toString($activity['description'] ?? ''));
            if ($title === '' && $text === '') {
                continue;
            }
            $out[] = [
                'title' => $title !== '' ? $title : $text,
                'text' => $title !== '' && $text !== $title ? $text : '',
                'duration' => TypeCoerce::toString($activity['duration'] ?? ''),
                'price' => max(0.0, TypeCoerce::toFloat($activity['price'] ?? 0)),
                'currency' => TypeCoerce::toString($activity['currency'] ?? ''),
                'highlighted' => ($activity['highlighted'] ?? false) === true,
            ];
        }

        return $out;
    }

    /**
     * @param \Closure(mixed): string $plain
     *
     * @return list<array{title: string, url: string}>
     */
    private static function documents(mixed $list, \Closure $plain): array
    {
        $out = [];
        foreach (TypeCoerce::toRowList($list) as $document) {
            $url = TypeCoerce::toString($document['url'] ?? '');
            if (preg_match('#^https?://\S+$#i', $url) === 1) {
                $out[] = ['title' => $plain($document['title'] ?? ''), 'url' => $url];
            }
        }

        return $out;
    }

    /**
     * A day's photos: their web links, each once.
     *
     * @return list<string>
     */
    private static function photos(mixed $images): array
    {
        $urls = [];
        foreach (TypeCoerce::toList($images) as $image) {
            $url = trim(TypeCoerce::toString(is_array($image) ? ($image['url'] ?? '') : $image));
            if (preg_match('#^https?://\S+$#i', $url) === 1) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * The fewest and the most stars of the hotels on the route: each
     * itinerary day's accommodation category ("4"), 1 to 5; [0, 0] when no
     * day says.
     *
     * @param mixed $itinerary the itinerary_json column (a JSON list of days)
     *
     * @return array{0: int, 1: int}
     */
    public static function hotelStars(mixed $itinerary): array
    {
        $stars = self::routeStars($itinerary);

        return $stars === [] ? [0, 0] : [min($stars), max($stars)];
    }

    /**
     * The stars of the hotels on the route, each once, lowest first: each
     * itinerary day's accommodation category ("4"), 1 to 5.
     *
     * @param mixed $itinerary the itinerary_json column (a JSON list of days)
     *
     * @return list<int>
     */
    public static function routeStars(mixed $itinerary): array
    {
        $stars = [];
        foreach (self::jsonRows($itinerary) as $day) {
            $count = self::stars(TypeCoerce::toString(TypeCoerce::toStringMap($day['accommodation'] ?? null)['category'] ?? ''));
            if ($count > 0) {
                $stars[] = $count;
            }
        }
        $stars = array_values(array_unique($stars));
        sort($stars);

        return $stars;
    }

    /** A hotel category ("4", "4*") as stars, 1 to 5; 0 for anything else ("10", "4.5", "lux"). */
    public static function stars(string $category): int
    {
        return preg_match('/^\s*([1-5])(?![\d.,])/', $category, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * @param mixed $json a JSON list column (or its decoded value)
     *
     * @return list<array<string, mixed>>
     */
    private static function jsonRows(mixed $json): array
    {
        return TypeCoerce::toRowList(is_string($json) ? json_decode($json, true) : $json);
    }

    /**
     * The departures, each id with its name. The names are paired by
     * position when there are as many as the ids; otherwise (a name with
     * ", " in it) a lone id keeps the whole text and several get none, the
     * bar then naming them by number.
     *
     * @param mixed $ids the departure_ids column (a JSON list)
     * @param string $names the departure_names column (", "-joined)
     *
     * @return list<array{id: string, name: string}>
     */
    public static function departures(mixed $ids, string $names): array
    {
        $idList = [];
        foreach (TypeCoerce::toList(is_string($ids) ? json_decode($ids, true) : $ids) as $id) {
            $id = TypeCoerce::toString($id);
            if ($id !== '' && $id !== '0') {
                $idList[] = $id;
            }
        }
        $nameList = $names !== '' ? explode(', ', $names) : [];
        if (count($nameList) !== count($idList)) {
            $nameList = count($idList) === 1 ? [$names] : [];
        }

        $departures = [];
        foreach ($idList as $i => $id) {
            $departures[] = ['id' => $id, 'name' => trim($nameList[$i] ?? '')];
        }

        return $departures;
    }
}
