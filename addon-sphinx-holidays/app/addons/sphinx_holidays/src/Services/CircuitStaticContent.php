<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * What the circuits sync keeps of one circuit of static/circuits besides its
 * own columns (docs/circuit-page-plan.md §4): the details its page shows
 * (details_json), its first photo, and the cities its dated departures name.
 *
 * Pure reading of Sphinx's answer: nothing is fetched or stored here. Every
 * part the API leaves empty is left out, so details_json holds only what
 * there is to show.
 */
final class CircuitStaticContent
{
    /** A difficulty is 1 (the easiest) to this. */
    private const int MAX_DIFFICULTY = 5;

    /** Photos kept, at most (their links only: the photos become the product's images). */
    private const int MAX_IMAGES = 40;

    /** A link longer than this is not one we show. */
    private const int MAX_URL_LENGTH = 1000;

    /** The image_url column's size. */
    private const int IMAGE_URL_LENGTH = 500;

    /**
     * The details_json of a circuit; null when the API says none of it:
     *  - included, not_included, additional_info: the items, each its title
     *    and description (and its category, when Sphinx gives one other than
     *    "other");
     *  - group_size, age: the limits (age only when the circuit requires
     *    it) and Sphinx's note on them;
     *  - difficulty: the level, 1 to 5, and its note;
     *  - accommodation: the hotel, its category as sent ("4") and its text;
     *  - activities: the optional excursions, each with its price when
     *    Sphinx knows it;
     *  - documents: the files to download (title, link);
     *  - images: the photos' links.
     *
     * @param array<string, mixed> $raw one circuit of static/circuits
     */
    public static function details(array $raw): ?string
    {
        // The API spells it "age_requirments".
        $age = TypeCoerce::toStringMap($raw['age_requirments'] ?? $raw['age_requirements'] ?? null);
        $group = TypeCoerce::toStringMap($raw['group_size'] ?? null);

        $details = array_filter([
            'included' => self::items($raw['included'] ?? null),
            'not_included' => self::items($raw['not_included'] ?? null),
            'additional_info' => self::items($raw['additional_info'] ?? null),
            'group_size' => self::range($group) + self::note($group),
            'age' => (($age['is_required'] ?? false) === true ? self::range($age) : []) + self::note($age),
            'difficulty' => self::difficulty($raw['difficulty'] ?? null),
            'accommodation' => self::accommodation($raw['accommodation'] ?? null),
            'activities' => self::activities($raw['activities'] ?? null),
            'documents' => self::documents($raw['documents'] ?? null),
            'images' => self::images($raw['images'] ?? null),
        ]);

        return $details === [] ? null : (string) json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The circuit's photo for the admin list: the one the API names, else
     * the first of its photos; '' when it has none (or a link too long for
     * the column).
     *
     * @param array<string, mixed> $raw one circuit of static/circuits
     */
    public static function imageUrl(array $raw): string
    {
        $url = self::url($raw['image'] ?? $raw['image_url'] ?? null);
        if ($url === '') {
            $url = self::images($raw['images'] ?? null)[0] ?? '';
        }

        return strlen($url) <= self::IMAGE_URL_LENGTH ? $url : '';
    }

    /**
     * The city each dated departure from $today on leaves from, for a price
     * (circuits/rates) that names no city of its own.
     *
     * @param array<string, mixed> $raw one circuit of static/circuits
     * @param string $today Y-m-d
     *
     * @return array<string, int> Y-m-d => destination id (the first the list has for that day)
     */
    public static function departureCityByDate(array $raw, string $today): array
    {
        $byDate = [];
        foreach (TypeCoerce::toRowList($raw['departures'] ?? null) as $departure) {
            $id = TypeCoerce::toInt($departure['destination_id'] ?? 0);
            $date = substr(TypeCoerce::toString($departure['date'] ?? ''), 0, 10);
            if ($id > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date >= $today && !isset($byDate[$date])) {
                $byDate[$date] = $id;
            }
        }

        return $byDate;
    }

    /**
     * @return list<array{title: string, description: string, category?: string}>
     */
    private static function items(mixed $list): array
    {
        $out = [];
        foreach (TypeCoerce::toRowList($list) as $item) {
            $title = trim(TypeCoerce::toString($item['title'] ?? ''));
            $description = trim(TypeCoerce::toString($item['description'] ?? ''));
            if ($title !== '' || $description !== '') {
                $out[] = ['title' => $title, 'description' => $description] + self::category($item);
            }
        }

        return $out;
    }

    /**
     * Sphinx's category of an item, when it says more than "other".
     *
     * @param array<string, mixed> $item
     *
     * @return array{category?: string}
     */
    private static function category(array $item): array
    {
        $category = strtolower(trim(TypeCoerce::toString($item['category'] ?? '')));

        return $category !== '' && $category !== 'other' ? ['category' => $category] : [];
    }

    /**
     * @param array<string, mixed> $limits {min, max}
     *
     * @return array{min?: int, max?: int} the limits above 0
     */
    private static function range(array $limits): array
    {
        return array_filter(
            ['min' => max(0, TypeCoerce::toInt($limits['min'] ?? 0)), 'max' => max(0, TypeCoerce::toInt($limits['max'] ?? 0))],
            static fn (int $value): bool => $value > 0,
        );
    }

    /**
     * @param array<string, mixed> $map a block with a "description"
     *
     * @return array{description?: string}
     */
    private static function note(array $map): array
    {
        $description = trim(TypeCoerce::toString($map['description'] ?? ''));

        return $description !== '' ? ['description' => $description] : [];
    }

    /**
     * @return array{level?: int, description?: string}
     */
    private static function difficulty(mixed $raw): array
    {
        $map = TypeCoerce::toStringMap($raw);
        $level = TypeCoerce::toInt($map['level'] ?? 0);

        return ($level >= 1 && $level <= self::MAX_DIFFICULTY ? ['level' => $level] : []) + self::note($map);
    }

    /**
     * @return array{hotel?: string, category?: string, description?: string}
     */
    private static function accommodation(mixed $raw): array
    {
        $map = TypeCoerce::toStringMap($raw);
        $hotel = trim(TypeCoerce::toString($map['hotel'] ?? ''));
        $category = trim(TypeCoerce::toString($map['category'] ?? ''));

        return ($hotel !== '' ? ['hotel' => $hotel] : [])
            + ($category !== '' && $category !== '0' ? ['category' => $category] : [])
            + self::note($map);
    }

    /**
     * The optional excursions. A price is kept only when Sphinx knows it
     * (is_unknown false, an amount above 0); a duration of "0" says nothing.
     *
     * @return list<array{title: string, description: string, duration?: string, price?: float, currency?: string, category?: string, highlighted?: true}>
     */
    private static function activities(mixed $list): array
    {
        $out = [];
        foreach (TypeCoerce::toRowList($list) as $activity) {
            $title = trim(TypeCoerce::toString($activity['title'] ?? ''));
            $description = trim(TypeCoerce::toString($activity['description'] ?? ''));
            if ($title === '' && $description === '') {
                continue;
            }
            $row = ['title' => $title, 'description' => $description];

            $duration = trim(TypeCoerce::toString($activity['duration'] ?? ''));
            if ($duration !== '' && $duration !== '0') {
                $row['duration'] = $duration;
            }
            $price = TypeCoerce::toStringMap($activity['price'] ?? null);
            $amount = TypeCoerce::toFloat($price['amount'] ?? 0);
            if (($price['is_unknown'] ?? false) !== true && $amount > 0) {
                $row['price'] = $amount;
                $row['currency'] = TypeCoerce::toString($price['currency'] ?? '');
            }
            $row += self::category($activity);
            if (($activity['is_highlighted'] ?? false) === true) {
                $row['highlighted'] = true;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<array{title: string, description: string, url: string}> the files with a web link
     */
    private static function documents(mixed $list): array
    {
        $out = [];
        foreach (TypeCoerce::toRowList($list) as $document) {
            $url = self::url($document['url'] ?? null);
            if ($url !== '') {
                $out[] = [
                    'title' => trim(TypeCoerce::toString($document['title'] ?? '')),
                    'description' => trim(TypeCoerce::toString($document['description'] ?? '')),
                    'url' => $url,
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<string> the photos' links, each once, MAX_IMAGES at most
     */
    private static function images(mixed $list): array
    {
        $urls = [];
        foreach (TypeCoerce::toList($list) as $image) {
            $url = self::url(is_array($image) ? ($image['url'] ?? null) : $image);
            if ($url !== '') {
                $urls[$url] = true;
            }
        }

        return array_slice(array_keys($urls), 0, self::MAX_IMAGES);
    }

    /** A web link (http or https) as sent; '' for anything else. */
    private static function url(mixed $value): string
    {
        $url = trim(TypeCoerce::toString($value));

        return preg_match('#^https?://\S+$#i', $url) === 1 && strlen($url) <= self::MAX_URL_LENGTH ? $url : '';
    }
}
