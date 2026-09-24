<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Notices that the shared cron key changed, and tracks re-copying the cron
 * commands on every page that shows them.
 *
 * WHY BY FINGERPRINT. The key can change four ways: the Generate button on
 * Tools, the same button on the Eurosite dashboard, the settings self-heal
 * (which mints or rotates it), and a hand edit on the Settings page — and the
 * last two do not pass through any code a hook could catch. Comparing a
 * fingerprint of the current key with the one seen last time catches all
 * four. Only a truncated SHA-256 is stored, never the key.
 *
 * WHAT A CHANGE OPENS. A checklist with one entry per page whose commands
 * carry the key (Travel Core plus each provider that declared its cron). An
 * entry is done when the operator ticks it OR — better — when that add-on
 * has a successful SCHEDULED run after the change, which proves its crontab
 * already carries the new key. The checklist closes when every entry is done
 * or the operator dismisses it.
 *
 * The first time this ever sees a key it only records it: there is no way to
 * know whether that key is new, and a checklist nobody needs is noise.
 */
final class CronKeyChangeTracker
{
    private const string KEY = 'travel_cron_key_change';

    /** @var null|\Closure(string): (string|null) */
    private static ?\Closure $reader = null;

    /** @var null|\Closure(string, string): void */
    private static ?\Closure $writer = null;

    /**
     * Compare the current key with the last one seen; start a new checklist
     * when it differs. Returns the stored state after that.
     *
     * @param list<string> $pages add-on ids whose commands carry the key
     *
     * @return array{fp: string, at: int, open: bool, pages: array<string, int>}
     */
    public static function observe(string $currentKey, array $pages, int $now): array
    {
        $fp = self::fingerprint($currentKey);
        $state = self::load();

        if ($state === null) {
            $state = ['fp' => $fp, 'at' => 0, 'open' => false, 'pages' => []];
            self::save($state);

            return $state;
        }

        if ($state['fp'] !== $fp) {
            $state = [
                'fp' => $fp,
                'at' => $now,
                // A key REMOVED stops everything too, but there is nothing to
                // re-copy until a new one exists.
                'open' => $fp !== '',
                'pages' => array_fill_keys($pages, 0),
            ];
            self::save($state);
        }

        return $state;
    }

    /** Tick one page as updated by hand. */
    public static function markDone(string $page, int $now): void
    {
        $state = self::load();
        if ($state === null || !array_key_exists($page, $state['pages'])) {
            return;
        }

        $state['pages'][$page] = $now;
        self::save($state);
    }

    /** Close the checklist without ticking the rest. */
    public static function dismiss(): void
    {
        $state = self::load();
        if ($state === null) {
            return;
        }

        $state['open'] = false;
        self::save($state);
    }

    /**
     * The checklist as the page shows it: each entry done by hand, or proved
     * done by a successful scheduled run after the change.
     *
     * @param array{fp: string, at: int, open: bool, pages: array<string, int>} $state
     * @param array<string, int> $lastScheduledOk add-on id => CronHealth 'last_scheduled_ok'
     *
     * @return list<array{page: string, done: bool, proved: bool}>
     */
    public static function checklist(array $state, array $lastScheduledOk): array
    {
        $out = [];
        foreach ($state['pages'] as $page => $tickedAt) {
            $proved = ($lastScheduledOk[$page] ?? 0) > $state['at'];
            $out[] = ['page' => (string) $page, 'done' => $tickedAt > 0 || $proved, 'proved' => $proved];
        }

        return $out;
    }

    /**
     * TRUE while the checklist should be shown.
     *
     * @param array{fp: string, at: int, open: bool, pages: array<string, int>} $state
     * @param list<array{page: string, done: bool, proved: bool}> $checklist
     */
    public static function isOpen(array $state, array $checklist): bool
    {
        if (!$state['open'] || $checklist === []) {
            return false;
        }
        foreach ($checklist as $entry) {
            if (!$entry['done']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace storage — tests only.
     *
     * @param null|\Closure(string): (string|null) $reader
     * @param null|\Closure(string, string): void $writer
     */
    public static function useStorage(?\Closure $reader, ?\Closure $writer): void
    {
        self::$reader = $reader;
        self::$writer = $writer;
    }

    private static function fingerprint(string $key): string
    {
        return $key === '' ? '' : substr(hash('sha256', $key), 0, 16);
    }

    /** @return array{fp: string, at: int, open: bool, pages: array<string, int>}|null */
    private static function load(): ?array
    {
        try {
            $raw = self::$reader !== null
                ? (self::$reader)(self::KEY)
                : (function_exists('fn_get_storage_data') ? fn_get_storage_data(self::KEY) : null);
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return null;
        }

        $pages = [];
        foreach (is_array($d['pages'] ?? null) ? $d['pages'] : [] as $page => $at) {
            $pages[(string) $page] = TypeCoerce::toInt($at);
        }

        return [
            'fp' => TypeCoerce::toString($d['fp'] ?? ''),
            'at' => TypeCoerce::toInt($d['at'] ?? 0),
            'open' => TypeCoerce::toBool($d['open'] ?? false),
            'pages' => $pages,
        ];
    }

    /** @param array{fp: string, at: int, open: bool, pages: array<string, int>} $state */
    private static function save(array $state): void
    {
        try {
            $json = (string) json_encode($state);
            if (self::$writer !== null) {
                (self::$writer)(self::KEY, $json);
            } elseif (function_exists('fn_set_storage_data')) {
                fn_set_storage_data(self::KEY, $json);
            }
        } catch (\Throwable) {
            // A checklist that cannot be saved must not break the page.
        }
    }
}
