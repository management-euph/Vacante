<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * The destination picker (components/destination_picker.tpl +
 * destination-picker.js): what we sell from each supplier, per country.
 *
 * Each add-on builds the page data — its countries, their items (resorts,
 * cities) and, for Sphinx, the groups between them (regions) — and this class
 * does the parts every add-on shares: what a mode sells, a country's figures
 * and badge, and reading the posted form back.
 *
 * A mode says what it sells ("sells"):
 *   none      nothing
 *   all       every item, including ones the supplier adds later
 *   flag:KEY  every item flagged KEY (Eurosite "Own cities": flag:own)
 *   ticked    the ticked items and the whole of every ticked group
 *
 * Pure: no CS-Cart calls, so it is tested without a store.
 */
final class DestinationPicker
{
    public const string SELLS_NONE = 'none';
    public const string SELLS_ALL = 'all';
    public const string SELLS_TICKED = 'ticked';

    /** The figures summed over the sold items. */
    private const array FIGURES = ['hotels', 'priced', 'instant', 'live'];

    /**
     * Whether an item is sold under a rule. Gone items (no longer in the
     * supplier's feed) are never sold.
     *
     * @param array<string, mixed> $item
     */
    public static function sold(string $rule, array $item, bool $groupWhole = false): bool
    {
        if (($item['gone'] ?? false) === true) {
            return false;
        }
        if ($rule === self::SELLS_ALL) {
            return true;
        }
        if (str_starts_with($rule, 'flag:')) {
            $flags = is_array($item['flags'] ?? null) ? $item['flags'] : [];

            return !empty($flags[substr($rule, 5)]);
        }
        if ($rule === self::SELLS_TICKED) {
            return $groupWhole || ($item['selected'] ?? false) === true;
        }

        return false;
    }

    /**
     * A country's figures under one rule: items sold, the hotels, priced,
     * instant and live products in them, and the groups with a sold item.
     *
     * @param list<array<mixed>> $groups
     * @return array{sold: int, hotels: int, priced: int, instant: int, live: int, groups_sold: int}
     */
    public static function stats(array $groups, string $rule): array
    {
        $s = ['sold' => 0, 'hotels' => 0, 'priced' => 0, 'instant' => 0, 'live' => 0, 'groups_sold' => 0];
        foreach ($groups as $g) {
            $whole = ($g['whole'] ?? false) === true;
            $any = false;
            foreach (self::items($g) as $item) {
                if (!self::sold($rule, $item, $whole)) {
                    continue;
                }
                $any = true;
                $s['sold']++;
                foreach (self::FIGURES as $f) {
                    $s[$f] += self::int($item[$f] ?? 0);
                }
            }
            if ($any && ($g['key'] ?? '') !== '') {
                $s['groups_sold']++;
            }
        }

        return $s;
    }

    /**
     * Fill in what the template and the script need for one country whose
     * items the add-on listed: the item and group counts, the figures under
     * every mode (the script shows them before a lazily loaded body is in),
     * the badge and the "new" count.
     *
     * A country whose body loads on open ('lazy' => true) comes with its own
     * 'stats' (the add-on counts them in SQL) and no groups.
     *
     * @param array<mixed> $country
     * @param list<array{value: string, label: string, hint: string, sells: string, badge: string}> $modes
     * @return array<mixed>
     */
    public static function finish(array $country, array $modes): array
    {
        $country += ['key' => '', 'label' => '', 'code' => '', 'search' => '', 'meta' => '', 'facet' => '', 'flags' => [], 'badges' => [],
            'open' => false, 'lazy' => false, 'empty_text' => ''];
        $country['search'] = self::str($country['search']) !== '' ? self::str($country['search']) : mb_strtolower(trim(self::str($country['label']) . ' ' . self::str($country['code'])));
        $groups = [];
        foreach (is_array($country['groups'] ?? null) ? $country['groups'] : [] as $i => $g) {
            if (!is_array($g)) {
                continue;
            }
            $g += ['key' => '', 'label' => '', 'meta' => '', 'open' => false, 'whole' => false];
            $g['search'] = mb_strtolower(trim(self::str($g['label'])));
            $g['dom'] = substr(md5(self::str($country['key']) . '|' . self::str($g['key']) . '|' . $i), 0, 12);
            $items = [];
            foreach (self::items($g) as $item) {
                $item += ['value' => '', 'label' => '', 'code' => '', 'selected' => false, 'new' => false, 'gone' => false, 'flags' => [],
                    'badges' => [], 'meta' => '', 'meta_muted' => false, 'hotels' => 0, 'priced' => 0, 'instant' => 0, 'live' => 0];
                if (!isset($item['search'])) {
                    $item['search'] = mb_strtolower(trim(self::str($item['label']) . ' ' . self::str($item['code'])));
                }
                $items[] = $item;
            }
            $g['items'] = $items;
            $groups[] = $g;
        }
        $mode = is_string($country['mode'] ?? null) ? $country['mode'] : '';
        $rule = self::rule($modes, $mode);

        if (($country['lazy'] ?? false) !== true) {
            $total = 0;
            $listed = 0;
            $named = 0;
            $new = 0;
            foreach ($groups as $g) {
                if (($g['key'] ?? '') !== '') {
                    $named++;
                }
                foreach (self::items($g) as $item) {
                    $listed++;
                    $total += ($item['gone'] ?? false) === true ? 0 : 1;
                    $new += ($item['new'] ?? false) === true ? 1 : 0;
                }
            }
            $stats = ['total' => $total, 'groups' => $named, 'saved' => self::stats($groups, $rule)];
            foreach ($modes as $m) {
                if ($m['sells'] === self::SELLS_ALL) {
                    $stats['all'] = self::stats($groups, self::SELLS_ALL);
                } elseif (str_starts_with($m['sells'], 'flag:')) {
                    $stats[substr($m['sells'], 5)] = self::stats($groups, $m['sells']);
                }
            }
            $country['stats'] = $stats;
            $country['new'] = $new;
            $country['empty'] = $listed === 0;
            $country['groups'] = $groups;
        }

        $stats = is_array($country['stats'] ?? null) ? $country['stats'] : [];
        $saved = is_array($stats['saved'] ?? null) ? $stats['saved'] : [];
        $country['rule'] = $rule;
        $country['dom'] = substr(md5(is_string($country['key'] ?? null) ? $country['key'] : ''), 0, 10);
        $country['attrs'] = self::statAttributes($stats);
        $country['badge_class'] = $rule === self::SELLS_NONE ? 'off' : ($rule === self::SELLS_TICKED ? 'specific' : 'all');
        $country['badge'] = self::fill(self::badge($modes, $mode), [
            'sold' => self::int($saved['sold'] ?? 0),
            'total' => self::int($stats['total'] ?? 0),
            'groups_sold' => self::int($saved['groups_sold'] ?? 0),
            'groups' => self::int($stats['groups'] ?? 0),
        ]);

        return $country;
    }

    /**
     * The data-* attributes a country section carries for the script:
     * data-total, data-groups and data-{saved|all|FLAG}-{sold|hotels|…}.
     *
     * @param array<mixed> $stats
     * @return array<string, int>
     */
    public static function statAttributes(array $stats): array
    {
        $out = ['total' => self::int($stats['total'] ?? 0), 'groups' => self::int($stats['groups'] ?? 0)];
        foreach ($stats as $key => $set) {
            if (!is_array($set)) {
                continue;
            }
            foreach (['sold', 'hotels', 'priced', 'instant', 'live'] as $f) {
                $out[$key . '-' . $f] = self::int($set[$f] ?? 0);
            }
            $out[$key . '-groups-sold'] = self::int($set['groups_sold'] ?? 0);
        }

        return $out;
    }

    /**
     * The posted picker, per country: the mode, the ticked items and groups,
     * and whether its body was on the page (a country never opened on a page
     * that loads bodies on open posts no ticks — keep what it had).
     *
     * Reads the one JSON field the script sends (dest_json) or, without the
     * script, the form fields dest[COUNTRY][mode|items][]|groups][]|loaded].
     * Values are trimmed, repeats and empties dropped, each at most 191 chars.
     *
     * @param array<mixed> $post $_POST
     * @return array<string, array{mode: string, items: list<string>, groups: list<string>, loaded: bool}>
     */
    public static function readPost(array $post): array
    {
        $raw = null;
        if (is_string($post['dest_json'] ?? null) && trim($post['dest_json']) !== '') {
            $decoded = json_decode($post['dest_json'], true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $jsonForm = $raw !== null;
        if ($raw === null) {
            $raw = is_array($post['dest'] ?? null) ? $post['dest'] : [];
        }

        $out = [];
        foreach ($raw as $country => $entry) {
            $key = trim((string) $country);
            if ($key === '' || !is_array($entry) || mb_strlen($key) > 191) {
                continue;
            }
            $loaded = $jsonForm ? ($entry['loaded'] ?? false) === true : isset($entry['loaded']);
            $out[$key] = [
                'mode' => is_string($entry['mode'] ?? null) ? trim($entry['mode']) : '',
                'items' => self::values($entry['items'] ?? []),
                'groups' => self::values($entry['groups'] ?? []),
                'loaded' => $loaded,
            ];
        }

        return $out;
    }

    /**
     * Product ids posted by "Disable products outside the whitelist": a
     * comma-separated field (one field, whatever the count) or a list.
     *
     * @return list<int>
     */
    public static function productIds(mixed $posted): array
    {
        $parts = is_array($posted) ? $posted : (is_string($posted) ? explode(',', $posted) : []);
        $ids = [];
        foreach ($parts as $p) {
            $id = is_numeric($p) ? (int) $p : 0;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Products outside the saved whitelist, grouped by where they are, for the
     * confirmation list: "Mamaia, Romania — 3 products".
     *
     * @param list<array{product_id: int, label: string}> $products label = the place
     * @return array{n: int, ids: string, groups: list<array{label: string, n: int}>}
     */
    public static function outside(array $products): array
    {
        $groups = [];
        $ids = [];
        foreach ($products as $p) {
            $ids[] = $p['product_id'];
            $groups[$p['label']] = ($groups[$p['label']] ?? 0) + 1;
        }
        $list = [];
        foreach ($groups as $label => $n) {
            $list[] = ['label' => (string) $label, 'n' => $n];
        }

        return ['n' => count($ids), 'ids' => implode(',', $ids), 'groups' => $list];
    }

    /**
     * The add-on's modes, typed: [{value, label, hint, sells, badge}].
     *
     * @return list<array{value: string, label: string, hint: string, sells: string, badge: string}>
     */
    public static function modes(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $out[] = [
                'value' => self::str($m['value'] ?? ''),
                'label' => self::str($m['label'] ?? ''),
                'hint' => self::str($m['hint'] ?? ''),
                'sells' => self::str($m['sells'] ?? self::SELLS_NONE),
                'badge' => self::str($m['badge'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * What a mode sells; an unknown mode sells nothing.
     *
     * @param list<array{value: string, sells: string}> $modes
     */
    public static function rule(array $modes, string $mode): string
    {
        foreach ($modes as $m) {
            if ($m['value'] === $mode) {
                return $m['sells'];
            }
        }

        return self::SELLS_NONE;
    }

    /** @param array<string, int|string> $values */
    public static function fill(string $text, array $values): string
    {
        foreach ($values as $k => $v) {
            $text = str_replace('[' . $k . ']', (string) $v, $text);
        }

        return $text;
    }

    /**
     * @param list<array{value: string, badge: string}> $modes
     */
    private static function badge(array $modes, string $mode): string
    {
        foreach ($modes as $m) {
            if ($m['value'] === $mode) {
                return $m['badge'];
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $group
     * @return list<array<string, mixed>>
     */
    private static function items(array $group): array
    {
        $items = is_array($group['items'] ?? null) ? $group['items'] : [];
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function values(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $v) {
            if (!is_scalar($v)) {
                continue;
            }
            $v = trim((string) $v);
            if ($v === '' || mb_strlen($v) > 191 || in_array($v, $out, true)) {
                continue;
            }
            $out[] = $v;
        }

        return $out;
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? $v : (is_int($v) || is_float($v) ? (string) $v : '');
    }

    private static function int(mixed $v): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }
}
