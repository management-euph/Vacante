<?php

declare(strict_types=1);

/**
 * Travel Core Date Helper
 *
 * Date formatting and calculation utilities with Romanian locale support.
 * Shared across all travel provider addons.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Services;

class DateHelper
{
    /** @var array<int, string> Romanian month names */
    private static array $roMonths = [
        1 => 'ianuarie', 2 => 'februarie', 3 => 'martie', 4 => 'aprilie',
        5 => 'mai', 6 => 'iunie', 7 => 'iulie', 8 => 'august',
        9 => 'septembrie', 10 => 'octombrie', 11 => 'noiembrie', 12 => 'decembrie',
    ];

    /** @var array<int, string> Romanian short month names */
    private static array $roMonthsShort = [
        1 => 'ian', 2 => 'feb', 3 => 'mar', 4 => 'apr',
        5 => 'mai', 6 => 'iun', 7 => 'iul', 8 => 'aug',
        9 => 'sep', 10 => 'oct', 11 => 'noi', 12 => 'dec',
    ];

    /** @var array<int, string> Romanian day names */
    private static array $roDays = [
        0 => 'duminică', 1 => 'luni', 2 => 'marți', 3 => 'miercuri',
        4 => 'joi', 5 => 'vineri', 6 => 'sâmbătă',
    ];

    /**
     * Format date in Romanian.
     *
     * @param string $date Date (YYYY-MM-DD)
     * @param string $format Format: 'full', 'medium', 'short', 'day_month', 'day_month_short'
     * @return string Formatted date
     */
    public static function formatRomanian(string $date, string $format = 'medium'): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        $day = (int)date('j', $timestamp);
        $month = (int)date('n', $timestamp);
        $year = date('Y', $timestamp);
        $dayOfWeek = (int)date('w', $timestamp);

        return match ($format) {
            'full' => self::$roDays[$dayOfWeek] . ', ' . $day . ' ' . self::$roMonths[$month] . ' ' . $year,
            'medium' => $day . ' ' . self::$roMonths[$month] . ' ' . $year,
            'short' => $day . ' ' . self::$roMonthsShort[$month] . ' ' . $year,
            'day_month' => $day . ' ' . self::$roMonths[$month],
            'day_month_short' => $day . ' ' . self::$roMonthsShort[$month],
            default => date('d.m.Y', $timestamp),
        };
    }

    /**
     * Format date range in Romanian.
     */
    public static function formatRangeRomanian(string $checkIn, string $checkOut): string
    {
        $inTimestamp = strtotime($checkIn);
        $outTimestamp = strtotime($checkOut);

        if ($inTimestamp === false || $outTimestamp === false) {
            return $checkIn . ' - ' . $checkOut;
        }

        $inMonth = (int)date('n', $inTimestamp);
        $outMonth = (int)date('n', $outTimestamp);
        $inYear = date('Y', $inTimestamp);
        $outYear = date('Y', $outTimestamp);
        $inDay = (int)date('j', $inTimestamp);
        $outDay = (int)date('j', $outTimestamp);

        if ($inMonth === $outMonth && $inYear === $outYear) {
            return $inDay . ' - ' . $outDay . ' ' . self::$roMonths[$outMonth] . ' ' . $outYear;
        }

        if ($inYear === $outYear) {
            return $inDay . ' ' . self::$roMonths[$inMonth] . ' - ' .
                   $outDay . ' ' . self::$roMonths[$outMonth] . ' ' . $outYear;
        }

        return self::formatRomanian($checkIn, 'medium') . ' - ' . self::formatRomanian($checkOut, 'medium');
    }

    /**
     * Get nights text in Romanian (with proper plural).
     */
    public static function nightsTextRomanian(int $nights): string
    {
        if ($nights === 1) {
            return '1 noapte';
        }
        if ($nights >= 2 && $nights <= 19) {
            return $nights . ' nopți';
        }
        return $nights . ' de nopți';
    }

    /**
     * Calculate nights between dates.
     */
    public static function calculateNights(string $checkIn, string $checkOut): int
    {
        $in = strtotime($checkIn);
        $out = strtotime($checkOut);
        if ($in === false || $out === false) {
            return 0;
        }
        return max(0, (int)floor(($out - $in) / 86400));
    }

    /**
     * Get check-out date from check-in and nights.
     */
    public static function getCheckOutDate(string $checkIn, int $nights): string
    {
        return date('Y-m-d', (int) strtotime($checkIn . ' + ' . $nights . ' days'));
    }

    /**
     * Check if date is in the past.
     */
    public static function isPast(string $date): bool
    {
        return strtotime($date) < strtotime('today');
    }

    /**
     * Check if date is today.
     */
    public static function isToday(string $date): bool
    {
        return date('Y-m-d', (int) strtotime($date)) === date('Y-m-d');
    }

    /**
     * Get minimum check-in date (today or tomorrow based on cutoff).
     */
    public static function getMinCheckIn(int $hoursCutoff = 0): string
    {
        $now = time();
        $today = strtotime('today');
        $hoursRemaining = ($today + 86400 - $now) / 3600;

        if ($hoursRemaining < $hoursCutoff) {
            return date('Y-m-d', strtotime('tomorrow'));
        }

        return date('Y-m-d');
    }

    /**
     * Parse date from various formats.
     *
     * @return string|null Date in YYYY-MM-DD or null
     */
    public static function parseDate(string $date): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        if (preg_match('/^(\d{2})[\/.](\d{2})[\/.](\d{4})$/', $date, $m) === 1) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    // ── Store-configured display dates ──────────────────────────────────────
    //
    // Every customer-facing date in the travel addons goes through here.
    // Before this existed there were three behaviours on one page: the booking
    // sidebar hardcoded '%d.%m.%Y', novoton's cancellation lines followed the
    // store setting, and sphinx's hardcoded dd.mm.yyyy — so a store set to
    // %m/%d/%Y showed "Check-in 07.09.2026" next to "Anulare gratuită până la
    // 08/28/2026". One date, two formats, on the same card.

    /**
     * Format a date exactly as Settings ▸ Appearance ▸ date format asks.
     *
     * Uses CS-Cart's own fn_date_format() when the framework is loaded, so
     * month and weekday names come out translated; falls back to a strftime →
     * PHP conversion elsewhere (unit tests, CLI tooling).
     */
    public static function formatStoreDate(string|int|null $date): string
    {
        return self::formatWith($date, TravelCoreConfig::getDateFormat());
    }

    /**
     * Weekday name for the same date ("Monday" / "luni").
     *
     * Deliberately NOT part of the store date format: the booking sidebar
     * renders the weekday as its own line under the date.
     */
    public static function formatStoreWeekday(string|int|null $date): string
    {
        return self::formatWith($date, '%A');
    }

    /**
     * Whether a CS-Cart date format already prints the weekday (%a / %A).
     * The booking sidebar shows the weekday on its own line under each
     * date; with a format like "%a, %d %b %Y" that line would repeat it.
     */
    public static function formatHasWeekday(string $csFormat): bool
    {
        return preg_match('/%[aAu]/', $csFormat) === 1;
    }

    /** Format with an explicit CS-Cart strftime-style pattern. */
    public static function formatWith(string|int|null $date, string $csFormat): string
    {
        $timestamp = self::toTimestamp($date);
        if ($timestamp === null) {
            return is_scalar($date) ? (string) $date : '';
        }

        if (function_exists('fn_date_format')) {
            /** @var mixed $formatted */
            $formatted = fn_date_format($timestamp, $csFormat);

            return is_scalar($formatted) ? (string) $formatted : '';
        }

        return date(self::strftimeToPhp($csFormat), $timestamp);
    }

    /**
     * Timestamp for a date string or numeric timestamp; null when the input
     * carries no usable date (the caller then keeps whatever it was given
     * rather than printing an epoch date).
     */
    public static function toTimestamp(string|int|null $date): ?int
    {
        if ($date === null || $date === '' || $date === 0 || $date === '0') {
            return null;
        }

        if (is_int($date)) {
            return $date > 0 ? $date : null;
        }

        if (is_numeric($date)) {
            $timestamp = (int) $date;

            return $timestamp > 0 ? $timestamp : null;
        }

        $timestamp = strtotime($date);

        return is_int($timestamp) && $timestamp !== 0 ? $timestamp : null;
    }

    /** CS-Cart stores strftime-style patterns; PHP's date() wants its own. */
    public static function strftimeToPhp(string $csFormat): string
    {
        return str_replace(
            ['%d', '%m', '%Y', '%y', '%B', '%b', '%A', '%a', '%e', '%H', '%M'],
            ['d', 'm', 'Y', 'y', 'F', 'M', 'l', 'D', 'j', 'H', 'i'],
            $csFormat,
        );
    }
}
