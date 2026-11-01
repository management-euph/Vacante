<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\Services\DepositPlan;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * The "Cancellation & payment" timeline of the shared booking sidebar —
 * one set of rules for every provider, each feeding its OWN API terms.
 *
 * Providers normalise what their API gives into two plain lists and hand them
 * here; nothing below knows a provider:
 *
 *   cancel windows   {from: ?ISO, to: ?ISO, percent: ?float, amount: ?float,
 *                     nights: ?int, no_show: ?bool}
 *                    sphinx: rules[{since, value}] (cumulative → one window per
 *                    rule), novoton: Penalty tillDate Percent / Over Nights,
 *                    eurosite: getItemFees FromDate/ToDate Value(+Procent)
 *   payment rows     {due: ?ISO (null = on booking), percent: ?float, amount: ?float}
 *
 * Amounts are in the store's PRIMARY currency (the cart-line scale); the
 * MoneyFormatter shows them in the shopper's currency. A window that only
 * carries a percent (or a number of nights) gets its amount from the total.
 *
 * What this fixes — the old card said "Free cancellation until 09/25" and
 * "If you cancel, you'll pay 673 $" at the same time, because sphinx and
 * novoton printed the first PENALTY day as "free until" and nobody compared
 * it with today. Here:
 *   - windows that ended before today are dropped;
 *   - the window containing today is `is_current` (styled amber, "Today");
 *   - a free step exists only while today is before the first penalty;
 *   - `free_until` is the LAST free day, and only while it is still ahead.
 *
 * What a provider knows beyond its windows comes as `$meta` (Novoton, 1 Nov
 * 2026): the hour a deadline day ends (`cutoff`, "23:59": a step says
 * "until 4 Nov, 23:59", today's or tomorrow's deadline is named so,
 * `to_rel`), the arrival (`check_in`: the last period goes on through
 * arrival and no-show, `is_last` / `from_arrival`) and each night's share
 * of the total (`night_shares`, per-stay fees left out: "N nights" is the
 * first N nights, exact, in any currency, with or without a commission;
 * without them N average nights, `approx`).
 *
 * Pure: today, the date format and the formatter are injected.
 */
final class TermsTimelineFactory
{
    public const KIND_FREE = 'free';
    public const KIND_PARTIAL = 'partial';
    public const KIND_FULL = 'full';

    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly string $today,
        private readonly string $dateFormat = '%d.%m.%Y',
    ) {
    }

    /**
     * @param list<array<string, mixed>> $windows
     * @param array{cutoff?: string, check_in?: string, night_shares?: list<float>} $meta what the provider knows beyond its windows (above)
     * @return array{steps: list<array<string, mixed>>, free_until: string, full_charge_now: bool, cutoff: string}
     */
    public function cancellation(array $windows, float $total, int $nights = 0, array $meta = []): array
    {
        $cutoff = trim($meta['cutoff'] ?? '');
        $checkIn = self::iso($meta['check_in'] ?? null) ?? '';
        $nightShares = self::shares($meta['night_shares'] ?? [], $nights);
        $rows = [];
        foreach ($windows as $w) {
            $from = self::iso($w['from'] ?? null);
            $to = self::iso($w['to'] ?? null);
            $percent = self::optFloat($w['percent'] ?? null);
            $amount = self::optFloat($w['amount'] ?? null);
            $n = self::optInt($w['nights'] ?? null);
            if ($percent === null && $amount === null && $n === null) {
                continue;
            }
            $rows[] = [
                'from' => $from, 'to' => $to, 'percent' => $percent, 'amount' => $amount, 'nights' => $n,
                'no_show' => !empty($w['no_show']),
            ];
        }
        // Chronological; an open start sorts first, "no show" last.
        usort($rows, static function (array $a, array $b): int {
            if ($a['no_show'] !== $b['no_show']) {
                return $a['no_show'] ? 1 : -1;
            }

            return strcmp((string) ($a['from'] ?? $a['to'] ?? ''), (string) ($b['from'] ?? $b['to'] ?? ''));
        });

        // Close the gaps each provider leaves: novoton only sends the end of
        // a window, sphinx only its start.
        $count = count($rows);
        for ($i = 0; $i < $count; $i++) {
            if ($rows[$i]['no_show']) {
                continue;
            }
            if ($rows[$i]['from'] === null && $i > 0 && $rows[$i - 1]['to'] !== null && !$rows[$i - 1]['no_show']) {
                $rows[$i]['from'] = self::addDays($rows[$i - 1]['to'], 1);
            }
            if ($rows[$i]['to'] === null && isset($rows[$i + 1]) && !$rows[$i + 1]['no_show'] && $rows[$i + 1]['from'] !== null) {
                $rows[$i]['to'] = self::addDays($rows[$i + 1]['from'], -1);
            }
        }

        $rows = self::mergeEqualNeighbours($rows, $total, $nights, $nightShares);

        $steps = [];
        $firstPenaltyFrom = null;
        foreach ($rows as $r) {
            if (!$r['no_show'] && $r['to'] !== null && $r['to'] < $this->today) {
                continue; // already over
            }
            $kind = $this->kind($r, $total);
            if ($kind !== self::KIND_FREE && $firstPenaltyFrom === null && !$r['no_show']) {
                $firstPenaltyFrom = $r['from'] ?? $this->today;
            }
            $isCurrent = !$r['no_show']
                && ($r['from'] === null || $r['from'] <= $this->today)
                && ($r['to'] === null || $r['to'] >= $this->today);
            $steps[] = $this->step($r, $kind, $isCurrent, $total, $nights, $nightShares, $cutoff);
        }

        // Nothing charges before the first penalty → that stretch is free,
        // even when the API only lists the penalties (sphinx, eurosite).
        $hasCurrent = in_array(true, array_column($steps, 'is_current'), true);
        if ($steps !== [] && !$hasCurrent && $firstPenaltyFrom !== null && $firstPenaltyFrom > $this->today) {
            array_unshift($steps, $this->step(
                ['from' => null, 'to' => self::addDays($firstPenaltyFrom, -1), 'percent' => 0.0, 'amount' => null, 'nights' => null, 'no_show' => false],
                self::KIND_FREE,
                true,
                $total,
                $nights,
                $nightShares,
                $cutoff,
            ));
        }

        // The arrival known: the last period goes on through arrival and no-show.
        if ($checkIn !== '' && $steps !== []) {
            $last = array_key_last($steps);
            if ($steps[$last]['is_no_show'] !== true) {
                $steps[$last]['is_last'] = true;
                $steps[$last]['from_arrival'] = $steps[$last]['from_iso'] === $checkIn;
            }
        }

        $freeUntil = '';
        $fullNow = false;
        foreach ($steps as $s) {
            if ($s['is_current'] !== true) {
                continue;
            }
            if ($s['kind'] === self::KIND_FREE && $s['to_iso'] !== '' && is_string($s['to_label'])) {
                $freeUntil = $s['to_label'];
            }
            $fullNow = $s['kind'] === self::KIND_FULL;
        }

        return ['steps' => $steps, 'free_until' => $freeUntil, 'full_charge_now' => $fullNow, 'cutoff' => $cutoff];
    }

    /**
     * Two windows in a row that charge the same are one window: novoton sends
     * "100% until 30 Oct" and "100% until 2 Nov", which read as two prices
     * for the same thing.
     *
     * @param list<array{from: ?string, to: ?string, percent: ?float, amount: ?float, nights: ?int, no_show: bool}> $rows
     * @param list<float> $nightShares
     * @return list<array{from: ?string, to: ?string, percent: ?float, amount: ?float, nights: ?int, no_show: bool}>
     */
    private static function mergeEqualNeighbours(array $rows, float $total, int $nights, array $nightShares = []): array
    {
        $out = [];
        foreach ($rows as $r) {
            $last = count($out) - 1;
            if ($last >= 0 && !$r['no_show'] && !$out[$last]['no_show']
                && self::charge($r, $total, $nights, $nightShares) === self::charge($out[$last], $total, $nights, $nightShares)
                && $out[$last]['to'] !== null && $r['from'] === self::addDays($out[$last]['to'], 1)) {
                $out[$last]['to'] = $r['to'];
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * What a window charges, in cents of the total when it is known (a
     * percent, an amount and a number of nights can then be compared),
     * else as given.
     *
     * @param array{percent: ?float, amount: ?float, nights: ?int} $r
     * @param list<float> $nightShares
     */
    private static function charge(array $r, float $total, int $nights, array $nightShares = []): string
    {
        $amount = $r['amount'];
        if ($total > 0) {
            if ($r['percent'] !== null) {
                $amount = $total * min(100.0, max(0.0, $r['percent'])) / 100;
            } elseif ($amount === null && $r['nights'] !== null && $nights > 0) {
                $amount = self::nightsAmount($r['nights'], $total, $nights, $nightShares);
            }
            if ($amount !== null) {
                return (string) (int) round(min($total, max(0.0, $amount)) * 100);
            }
        }

        return json_encode([$r['percent'], $amount, $r['nights']], JSON_PRESERVE_ZERO_FRACTION) ?: '';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function payment(array $rows, float $total): array
    {
        $out = [];
        foreach ($rows as $r) {
            $percent = self::optFloat($r['percent'] ?? null);
            $amount = self::optFloat($r['amount'] ?? null);
            if (($percent === null || $percent <= 0) && ($amount === null || $amount <= 0)) {
                continue;
            }
            $due = self::iso($r['due'] ?? null);
            if ($amount === null && $total > 0) {
                // $percent > 0 here: a row with neither was skipped above
                $amount = $total * $percent / 100;
            }
            if ($percent === null && $amount !== null && $total > 0) {
                $percent = $amount / $total * 100;
            }
            $out[] = [
                'due_iso' => $due ?? '',
                'due_label' => $due !== null ? $this->date($due) : '',
                // No date, or a date already passed: it is due now.
                'is_now' => $due === null || $due <= $this->today,
                'percent_label' => $percent !== null ? self::percent($percent) : '',
                'amount_label' => $amount !== null ? $this->money->format($amount) : '',
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['is_now'] ? 0 : 1, $a['due_iso']] <=> [$b['is_now'] ? 0 : 1, $b['due_iso']]);

        return $out;
    }

    /**
     * Deposit / balance summary for the price card, from the same payment
     * rows as payment() (DepositPlan: what the supplier wants on booking and
     * what is left, by the last due date). [] when the terms don't split (one
     * payment, or everything due now) or when the balance falls due within
     * $minDays.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{full: string, deposit: string, balance: string, balance_due: string}|array{}
     */
    public function split(array $rows, float $total, int $minDays = DepositPlan::MIN_DAYS): array
    {
        $plan = $total > 0 ? DepositPlan::fromInstallments($rows, $this->today, $total, $minDays) : null;
        if ($plan === null) {
            return [];
        }
        $a = $plan->amounts($total);

        return [
            'full' => $this->money->format($total),
            'deposit' => $this->money->format($a['deposit']),
            'balance' => $this->money->format($a['balance']),
            'balance_due' => $this->date($plan->balanceDue),
        ];
    }

    /** @param array<string, mixed> $r */
    private function kind(array $r, float $total): string
    {
        $percent = self::optFloat($r['percent'] ?? null);
        $amount = self::optFloat($r['amount'] ?? null);
        $nights = self::optInt($r['nights'] ?? null);
        if ($percent !== null) {
            return $percent <= 0 ? self::KIND_FREE : ($percent >= 100 ? self::KIND_FULL : self::KIND_PARTIAL);
        }
        if ($amount !== null) {
            return $amount <= 0 ? self::KIND_FREE : ($total > 0 && $amount >= $total - 0.01 ? self::KIND_FULL : self::KIND_PARTIAL);
        }

        return ($nights ?? 0) <= 0 ? self::KIND_FREE : self::KIND_PARTIAL;
    }

    /**
     * Each night's share of the total as the provider gave it: one per
     * night, none negative, together at most the whole; else none (the
     * average night).
     *
     * @return list<float>
     */
    private static function shares(mixed $value, int $nights): array
    {
        if ($nights <= 0 || !is_array($value) || count($value) !== $nights) {
            return [];
        }
        $shares = [];
        foreach ($value as $share) {
            if (!is_int($share) && !is_float($share)) {
                return [];
            }
            $shares[] = (float) $share;
        }
        if (min($shares) < 0 || array_sum($shares) > 1.000001) {
            return [];
        }

        return $shares;
    }

    /**
     * What N nights cost: the first N nights at their own share of the total
     * when the provider gave them, else N average nights.
     *
     * @param list<float> $nightShares
     */
    private static function nightsAmount(int $n, float $total, int $nights, array $nightShares): float
    {
        $n = min(max(0, $n), $nights);
        if ($nightShares !== []) {
            return $total * array_sum(array_slice($nightShares, 0, $n));
        }

        return $total / $nights * $n;
    }

    /**
     * @param array<string, mixed> $r
     * @param list<float> $nightShares
     * @return array<string, mixed>
     */
    private function step(array $r, string $kind, bool $isCurrent, float $total, int $nights, array $nightShares = [], string $cutoff = ''): array
    {
        // A penalty is at most the whole price: a supplier figure above 100%
        // (an amount sent as a percent) is the full price, not 499% of it.
        $percent = self::optFloat($r['percent'] ?? null);
        $percent = $percent !== null ? min(100.0, $percent) : null;
        $amount = self::optFloat($r['amount'] ?? null);
        $amount = $amount !== null && $total > 0 ? min($total, $amount) : $amount;
        $n = self::optInt($r['nights'] ?? null);
        $approx = false;
        if ($amount === null && $kind !== self::KIND_FREE && $total > 0) {
            if ($percent !== null) {
                $amount = $total * $percent / 100;
            } elseif ($n !== null && $nights > 0) {
                $amount = min($total, self::nightsAmount($n, $total, $nights, $nightShares));
                $approx = $nightShares === [];
            }
        }
        if ($percent === null && $n === null && $amount !== null && $total > 0) {
            $percent = min(100.0, $amount / $total * 100);
        }
        $from = is_string($r['from'] ?? null) ? $r['from'] : null;
        $to = is_string($r['to'] ?? null) ? $r['to'] : null;

        return [
            'kind' => $kind,
            'is_current' => $isCurrent,
            'is_no_show' => (bool) ($r['no_show'] ?? false),
            // A current window reads "until …": its start is today or past.
            'from_iso' => $from !== null && !$isCurrent ? $from : '',
            'from_label' => $from !== null && !$isCurrent ? $this->date($from) : '',
            'to_iso' => $to ?? '',
            'to_label' => $to !== null ? $this->date($to) : '',
            // The hour the deadline day ends (the provider's), and a deadline of today or tomorrow named so
            'to_time' => $to !== null ? $cutoff : '',
            'to_rel' => $to === null ? '' : ($to === $this->today ? 'today' : ($to === self::addDays($this->today, 1) ? 'tomorrow' : '')),
            // Set by cancellation() once the arrival is known: the last period, from the arrival day
            'is_last' => false,
            'from_arrival' => false,
            'percent_label' => $n === null && $percent !== null && $kind !== self::KIND_FREE ? self::percent($percent) : '',
            'nights' => $n !== null && $kind !== self::KIND_FREE ? $n : 0,
            'amount_label' => $amount !== null && $kind !== self::KIND_FREE ? $this->money->format($amount) : '',
            // N average nights: the provider did not give each night's price
            'approx' => $approx,
        ];
    }

    private function date(string $iso): string
    {
        return DateHelper::formatWith((int) strtotime($iso), $this->dateFormat);
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . '%';
    }

    private static function iso(mixed $value): ?string
    {
        $s = trim(TypeCoerce::toString($value));
        if ($s === '') {
            return null;
        }

        // "2026-10-05T00:00:00" and "05.10.2026" both reduce to a date.
        return DateHelper::parseDate(substr($s, 0, 10));
    }

    private static function addDays(string $iso, int $days): string
    {
        return (new \DateTimeImmutable($iso))->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');
    }

    private static function optFloat(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function optInt(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
