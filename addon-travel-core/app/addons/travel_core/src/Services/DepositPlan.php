<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * "Pay a deposit now, the balance later" — worked out from the supplier's own
 * payment schedule, one set of rules for every provider.
 *
 * Input rows are the provider-normalised installments TermsTimelineFactory
 * already reads: {due: ?ISO (null = on booking), percent: ?float, amount: ?float}.
 * What is due on booking (or on a date already passed) is the deposit; the
 * rest is the balance, due by the LAST later date.
 *
 * Kept as a RATIO of the price rather than an amount: the schedule's own
 * total rarely equals the commissioned price the guest pays (sphinx's rules
 * are net amounts), and a checkout price correction must rescale the deposit
 * instead of dropping it. amounts() applies the ratio to whatever the full
 * price is at that moment, rounded to cents, the balance taking the rest.
 *
 * No plan (null) when there is nothing to choose: one payment, everything due
 * now, nothing due now, or the balance falls due within $minDays — too close
 * to chase a second payment.
 *
 * Pure: today is injected.
 */
final class DepositPlan
{
    public const MIN_DAYS = 7;

    private function __construct(
        public readonly float $ratio,
        public readonly string $balanceDue,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function fromInstallments(array $rows, string $today, float $scheduleTotal = 0.0, int $minDays = self::MIN_DAYS): ?self
    {
        $now = 0.0;
        $later = 0.0;
        $firstLater = null;
        $lastLater = null;
        foreach ($rows as $r) {
            $share = self::share($r, $scheduleTotal);
            if ($share <= 0) {
                continue;
            }
            $due = self::iso($r['due'] ?? null);
            if ($due === null || $due <= $today) {
                $now += $share;
                continue;
            }
            $later += $share;
            $firstLater = $firstLater === null || $due < $firstLater ? $due : $firstLater;
            $lastLater = $lastLater === null || $due > $lastLater ? $due : $lastLater;
        }
        if ($now <= 0 || $later <= 0 || $firstLater === null || $lastLater === null) {
            return null;
        }
        $cutoff = date('Y-m-d', (int) strtotime($today . ' +' . $minDays . ' days'));
        if ($firstLater < $cutoff) {
            return null;
        }

        return new self(round($now / ($now + $later), 6), $lastLater);
    }

    /**
     * Rebuild a plan saved on a cart line / order item (see toArray()).
     */
    public static function fromSaved(mixed $saved): ?self
    {
        if (!is_array($saved)) {
            return null;
        }
        $ratio = TypeCoerce::toFloat($saved['ratio'] ?? 0);
        $due = self::iso($saved['balance_due'] ?? null);
        if ($ratio <= 0 || $ratio >= 1 || $due === null) {
            return null;
        }

        return new self($ratio, $due);
    }

    /** @return array{deposit: float, balance: float} */
    public function amounts(float $full): array
    {
        $deposit = round($full * $this->ratio, 2);

        return ['deposit' => $deposit, 'balance' => round($full - $deposit, 2)];
    }

    /**
     * What a cart line carries (extra.travel_deposit): the ratio and due date
     * that rebuild the plan, plus the amounts at the line's current price, in
     * the store's primary currency (the cart-line scale).
     *
     * @return array{ratio: float, balance_due: string, full: float, deposit: float, balance: float}
     */
    public function toArray(float $full): array
    {
        $a = $this->amounts($full);

        return [
            'ratio' => $this->ratio,
            'balance_due' => $this->balanceDue,
            'full' => round($full, 2),
            'deposit' => $a['deposit'],
            'balance' => $a['balance'],
        ];
    }

    /**
     * One row's share of the schedule: its percent, or its amount as a
     * percent of the schedule total.
     *
     * @param array<string, mixed> $r
     */
    private static function share(array $r, float $scheduleTotal): float
    {
        $percent = $r['percent'] ?? null;
        if (is_numeric($percent)) {
            return (float) $percent;
        }
        $amount = $r['amount'] ?? null;
        if (is_numeric($amount) && $scheduleTotal > 0) {
            return (float) $amount / $scheduleTotal * 100;
        }

        return 0.0;
    }

    private static function iso(mixed $value): ?string
    {
        $s = trim(TypeCoerce::toString($value));

        return $s === '' ? null : DateHelper::parseDate(substr($s, 0, 10));
    }
}
