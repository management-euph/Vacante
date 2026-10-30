<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\HotelAvailabilitySourceInterface;
use Tygh\Addons\TravelCore\Cron\CronHealth;
use Tygh\Addons\TravelCore\Cron\CronRunLog;
use Tygh\Addons\TravelCore\Dto\Availability\DestinationPeers;
use Tygh\Addons\TravelCore\Dto\Availability\HotelAvailabilityRecord;
use Tygh\Addons\TravelCore\Enums\AvailabilityMode;
use Tygh\Addons\TravelCore\Enums\HotelAvailabilityState;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\ValueObjects\AvailabilityThresholds;

/**
 * The weekly hotel availability review (docs/novoton-availability-gaps-plan.md
 * §4.5, §5): every supplier hotel's records go through AvailabilityPolicy, the
 * state changes are logged, and a summary is kept for the Hotel availability
 * page and the weekly email.
 *
 * It runs inside Travel Core's existing daily cron, so it has no crontab line
 * of its own, and once 7 days have passed since its last complete run. It
 * reads stored records only and calls no supplier. It waits, until the next
 * day, while a supplier job that writes records is running, so it never
 * judges a destination half way through its update.
 *
 * Then each supplier's products go through AvailabilityGate: in Automatic
 * mode it hides and shows them, in Report only it counts what it would hide.
 * On the other days the daily run looks only at the hotels the gate hid, so
 * one whose offers returned is shown again the next morning, not a week
 * later (plan §7.3).
 */
final class AvailabilityReview
{
    /** Its run-log entry under 'travel_core', apart from the exchange rates. */
    public const string JOB = 'hotel_availability';

    public const int EVERY_DAYS = 7;

    /** storage_data key of the last review's summary (JSON). */
    public const string SUMMARY_KEY = 'travel_core_availability_review';

    /** Notable changes kept per supplier for the page and the email. */
    public const int CHANGES_KEPT = 50;

    /** Hotel ids per IN (...) list. */
    private const int CHUNK = 500;

    /** The Hotel availability page, where the weekly email points. */
    public const string PAGE = 'travel_hotel_availability.manage';

    public function __construct(private readonly AvailabilityGate $gate = new AvailabilityGate())
    {
    }

    /**
     * From Travel Core's daily cron: the review when it is due and no
     * recording job runs, else why not, as one line for the cron output.
     * Never throws: the exchange rates' run, which this rides on, must not
     * fail because of it.
     */
    public static function runDaily(?\DateTimeImmutable $now = null): string
    {
        $outcome = self::run(false, $now);

        return match ($outcome['status']) {
            'none' => 'Hotel availability review: no supplier records availability yet.',
            'not_due' => 'Hotel availability review: not due yet (weekly).'
                . ($outcome['shown'] > 0 ? " {$outcome['shown']} hidden hotels shown again: their offers are back." : ''),
            'busy' => 'Hotel availability review waits for ' . implode(', ', $outcome['busy']) . '; it tries again on the next run.',
            'failed' => 'Hotel availability review failed: ' . $outcome['error'],
            default => "Hotel availability review: {$outcome['hotels']} hotels classified, {$outcome['changed']} state changes.",
        };
    }

    /**
     * The review, if it should run now: due (or $force, the page's "Review
     * now") and no recording job running. When it is not due, the daily
     * step: the hotels the gate hid whose offers are back are shown again.
     * Never throws.
     *
     * @return array{status: 'none'|'not_due'|'busy'|'failed'|'done', busy: list<string>, hotels: int, changed: int, shown: int, error: string}
     */
    public static function run(bool $force = false, ?\DateTimeImmutable $now = null): array
    {
        $outcome = ['status' => 'done', 'busy' => [], 'hotels' => 0, 'changed' => 0, 'shown' => 0, 'error' => ''];
        try {
            $now ??= new \DateTimeImmutable();
            $sources = TravelProviderRegistry::getAvailabilitySources();
            if ($sources === []) {
                return ['status' => 'none'] + $outcome;
            }
            $due = $force || self::isDue(CronRunLog::get('travel_core', self::JOB), $now->getTimestamp());
            $busy = self::busyJobs($sources, TravelProviderRegistry::getCronProviders(), $now->getTimestamp());
            if (!$due) {
                $automatic = self::automatic($sources);

                return ['status' => 'not_due', 'shown' => $busy === [] && $automatic !== []
                    ? (new self())->showReturned($automatic, TravelCoreConfig::getAvailabilityThresholds(), $now)
                    : 0] + $outcome;
            }
            if ($busy !== []) {
                return ['status' => 'busy', 'busy' => $busy] + $outcome;
            }

            $totals = ['hotels' => 0, 'changed' => 0];
            // A failure inside throws through record(), which logs it, to the catch below.
            CronRunLog::record('travel_core', self::JOB, static function () use ($sources, $now, &$totals): array {
                $modes = [];
                foreach (array_keys($sources) as $name) {
                    $modes[$name] = TravelCoreConfig::getAvailabilityMode($name);
                }
                $summary = (new self())->review($sources, $modes, TravelCoreConfig::getAvailabilityThresholds(), $now);
                self::storeSummary($summary);
                (new AvailabilityReportMail())->send(
                    $summary,
                    self::labels(array_keys($sources)),
                    TypeCoerce::toString(fn_url(self::PAGE, 'A', 'https')),
                    TypeCoerce::toString(db_get_field("SELECT value FROM ?:settings_objects WHERE name = 'company_orders_department'")),
                );

                foreach ($summary['providers'] as $provider) {
                    $totals['hotels'] += array_sum($provider['counts']);
                    $totals['changed'] += $provider['changed'];
                }

                return ['success' => true, 'message' => "{$totals['hotels']} hotels classified, {$totals['changed']} state changes"];
            });

            return ['hotels' => $totals['hotels'], 'changed' => $totals['changed']] + $outcome;
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()] + $outcome;
        }
    }

    /**
     * The suppliers whose products the gate changes: Automatic mode, and
     * hotels Travel Core's gate acts for (a supplier with a gate of its own
     * stays Report only whatever its setting says).
     *
     * @param array<string, HotelAvailabilitySourceInterface> $sources
     *
     * @return list<string>
     */
    public static function automatic(array $sources): array
    {
        $names = [];
        foreach ($sources as $name => $source) {
            if (TravelCoreConfig::getAvailabilityMode($name) === AvailabilityMode::Automatic && $source->capabilities()->sharedGate) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The daily step for suppliers in Automatic mode: the hotels the gate hid
     * are classified again from today's records, and those that no longer
     * hide are shown (and a product an admin changed by hand released).
     * Nothing is hidden here: that waits for the weekly review and its brake.
     *
     * @param list<string> $providers
     *
     * @return int products shown again
     */
    public function showReturned(array $providers, AvailabilityThresholds $limits, \DateTimeImmutable $now): int
    {
        $shown = 0;
        foreach ($providers as $name) {
            $this->reviewProvider($name, $limits, $now, true);
            $shown += $this->gate->apply($name, $now->format('Y-m-d H:i:s'), true, hide: false)['shown'];
        }

        return $shown;
    }

    /**
     * Each supplier's label, as it registered with Travel Core.
     *
     * @param list<string> $providers
     *
     * @return array<string, string>
     */
    public static function labels(array $providers): array
    {
        $labels = [];
        foreach ($providers as $name) {
            $labels[$name] = TypeCoerce::toString(TravelProviderRegistry::get($name)['label'] ?? $name);
        }

        return $labels;
    }

    /**
     * The last review's summary, for the page; null before the first one.
     *
     * @return array{at: string, providers: array<string, mixed>}|null
     */
    public static function lastSummary(): ?array
    {
        $raw = function_exists('fn_get_storage_data') ? TypeCoerce::toString(fn_get_storage_data(self::SUMMARY_KEY)) : '';
        $summary = json_decode($raw, true);
        if (!is_array($summary) || !is_string($summary['at'] ?? null) || !is_array($summary['providers'] ?? null)) {
            return null;
        }

        return ['at' => $summary['at'], 'providers' => TypeCoerce::toStringMap($summary['providers'])];
    }

    /**
     * The last review in two numbers, for the Tools page; null before the first.
     *
     * @return array{at: string, hotels: int, changed: int}|null
     */
    public static function lastTotals(): ?array
    {
        $summary = self::lastSummary();
        if ($summary === null) {
            return null;
        }
        $hotels = 0;
        $changed = 0;
        foreach ($summary['providers'] as $provider) {
            if (!is_array($provider)) {
                continue;
            }
            foreach (is_array($provider['counts'] ?? null) ? $provider['counts'] : [] as $count) {
                $hotels += TypeCoerce::toInt($count);
            }
            $changed += TypeCoerce::toInt($provider['changed'] ?? 0);
        }

        return ['at' => $summary['at'], 'hotels' => $hotels, 'changed' => $changed];
    }

    /** @param array<string, mixed> $summary */
    private static function storeSummary(array $summary): void
    {
        if (function_exists('fn_set_storage_data')) {
            fn_set_storage_data(self::SUMMARY_KEY, (string) json_encode($summary, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Due once EVERY_DAYS have passed since the last complete review; a
     * review that failed or never finished is due again at once.
     *
     * @param array{started?: int, finished?: int, ok?: bool} $last CronRunLog::get('travel_core', JOB)
     */
    public static function isDue(array $last, int $now): bool
    {
        $finished = $last['finished'] ?? 0;

        return !($finished > 0 && ($last['ok'] ?? false) && $now - $finished < self::EVERY_DAYS * 86400);
    }

    /**
     * The recording jobs running now, as "Novoton: hotel_list": the review
     * waits for them. A run open longer than CronHealth::STALL_AFTER is dead,
     * not running, and does not hold the review back.
     *
     * @param array<string, HotelAvailabilitySourceInterface> $sources
     * @param array<string, array{addon: string, label: string}> $cron TravelProviderRegistry::getCronProviders()
     *
     * @return list<string>
     */
    public static function busyJobs(array $sources, array $cron, int $now): array
    {
        $busy = [];
        foreach ($sources as $name => $source) {
            $addon = $cron[$name]['addon'] ?? '';
            if ($addon === '') {
                continue;
            }
            $records = [];
            foreach ($source->recordingModes() as $mode) {
                $records[$mode] = CronRunLog::get($addon, $mode);
            }
            foreach (CronHealth::assess($records, 0, $now)['running'] as $mode) {
                $busy[] = ($cron[$name]['label'] ?? $name) . ': ' . $mode;
            }
        }

        return $busy;
    }

    /**
     * How each destination fared over its last two weekly checks.
     *
     * @param list<array<string, mixed>> $rows one supplier's records
     *
     * @return array<string, DestinationPeers> destination_key => peers
     */
    public static function peers(array $rows, AvailabilityThresholds $limits, \DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $twoChecks = $today->modify('-' . max(0, $limits->notOfferedDays) . ' days');
        $fresh = $today->modify('-' . max(0, $limits->freshDays) . ' days');

        $tally = [];
        foreach ($rows as $row) {
            $record = HotelAvailabilityRecord::fromRow($row);
            if ($record->destinationKey === '') {
                continue;
            }
            $t = $tally[$record->destinationKey] ?? ['checked' => 0, 'offers' => 0, 'answered' => false];
            $checked = $record->offerCheckedAt !== null && $record->offerCheckedAt >= $twoChecks;
            $offer = $limits->onRequestCounts ? $record->offerSeenAt : $record->immediateSeenAt;
            if ($checked) {
                $t['checked']++;
                if ($offer !== null && $offer >= $twoChecks) {
                    $t['offers']++;
                }
            }
            if ($record->offerCheckedAt !== null && $record->offerCheckedAt >= $fresh) {
                $t['answered'] = true;
            }
            $tally[$record->destinationKey] = $t;
        }

        return array_map(
            static fn (array $t): DestinationPeers => new DestinationPeers($t['checked'], $t['offers'], $t['answered']),
            $tally,
        );
    }

    /**
     * Reviews one supplier: its last booked stays, then every record (or,
     * $hiddenOnly, only the hotels the gate hid; the destinations' peers
     * still come from every record).
     *
     * @return array{counts: array<string, int>, changed: int, first: int, changes: list<array{hotel_id: string, hotel_name: string, product_id: int, from: string, to: string, reason: string}>}
     */
    public function reviewProvider(string $provider, AvailabilityThresholds $limits, \DateTimeImmutable $now, bool $hiddenOnly = false): array
    {
        HotelAvailabilityRepository::ensureTables();
        $stamp = $now->format('Y-m-d H:i:s');
        $this->carryLastStays($provider);

        $rows = TypeCoerce::toRowList(db_get_array('SELECT * FROM ?:travel_hotel_availability WHERE provider = ?s', $provider));
        $peers = self::peers($rows, $limits, $now);

        $counts = array_fill_keys(array_map(static fn (HotelAvailabilityState $s): string => $s->value, HotelAvailabilityState::cases()), 0);
        $groups = [];
        $log = [];
        $changes = [];
        $changed = 0;
        $first = 0;
        foreach ($rows as $row) {
            if ($hiddenOnly && TypeCoerce::toString($row['gate_hidden'] ?? '') !== 'Y') {
                continue;
            }
            $record = HotelAvailabilityRecord::fromRow($row);
            $verdict = AvailabilityPolicy::classify($record, $peers[$record->destinationKey] ?? new DestinationPeers(), $limits, $now);
            $state = $verdict->state->value;
            $season = $verdict->seasonPublished ? 'Y' : 'N';
            $counts[$state]++;

            $oldState = TypeCoerce::toString($row['state'] ?? '');
            if ($oldState === $state
                && TypeCoerce::toString($row['state_reason'] ?? '') === $verdict->reason
                && TypeCoerce::toString($row['season_published'] ?? '') === $season
            ) {
                continue;
            }
            $groups[$state . '|' . $verdict->reason . '|' . $season][] = $record->hotelId;
            if ($oldState === $state) {
                continue;
            }

            $log[] = '(' . implode(', ', [
                db_quote('?s', $provider),
                db_quote('?s', $record->hotelId),
                db_quote('?i', $record->productId),
                db_quote('?s', $oldState),
                db_quote('?s', $state),
                db_quote('?s', $verdict->reason),
                db_quote('?s', $stamp),
            ]) . ')';
            // The first classification of a hotel is history, not news.
            if ($record->stateSince === null) {
                $first++;
                continue;
            }
            $changed++;
            if (count($changes) < self::CHANGES_KEPT) {
                $changes[] = [
                    'hotel_id' => $record->hotelId,
                    'hotel_name' => TypeCoerce::toString($row['hotel_name'] ?? ''),
                    'product_id' => $record->productId,
                    'from' => $oldState,
                    'to' => $state,
                    'reason' => $verdict->reason,
                ];
            }
        }

        foreach ($groups as $key => $hotelIds) {
            [$state, $reason, $season] = explode('|', $key);
            foreach (array_chunk($hotelIds, self::CHUNK) as $chunk) {
                // state_since first, while `state` still holds the old value.
                db_query(
                    'UPDATE ?:travel_hotel_availability
                     SET state_since = IF(state = ?s, COALESCE(state_since, ?s), ?s),
                         state = ?s, state_reason = ?s, season_published = ?s
                     WHERE provider = ?s AND hotel_id IN (?a)',
                    $state,
                    $stamp,
                    $stamp,
                    $state,
                    $reason,
                    $season,
                    $provider,
                    $chunk,
                );
            }
        }
        foreach (array_chunk($log, self::CHUNK) as $chunk) {
            db_query(
                'INSERT INTO ?:travel_hotel_state_log (provider, hotel_id, product_id, old_state, new_state, reason, created_at) VALUES ?p',
                implode(', ', $chunk),
            );
        }

        return ['counts' => $counts, 'changed' => $changed, 'first' => $first, 'changes' => $changes];
    }

    /**
     * Every supplier whose mode is not Off, then the gate: in Automatic mode
     * (for a supplier the shared gate acts for) it hides and shows products,
     * otherwise it counts what it would hide.
     *
     * @param array<string, HotelAvailabilitySourceInterface> $sources
     * @param array<string, AvailabilityMode> $modes
     *
     * @return array{at: string, providers: array<string, array{mode: string, counts: array<string, int>, changed: int, first: int, changes: list<array{hotel_id: string, hotel_name: string, product_id: int, from: string, to: string, reason: string}>, gate: array{hidden: int, shown: int, released: int, would_hide: int, braked: int}}>}
     */
    public function review(array $sources, array $modes, AvailabilityThresholds $limits, \DateTimeImmutable $now): array
    {
        $summary = ['at' => $now->format('Y-m-d H:i:s'), 'providers' => []];
        foreach ($sources as $name => $source) {
            $mode = $modes[$name] ?? AvailabilityMode::Report;
            if ($mode === AvailabilityMode::Off) {
                continue;
            }
            if ($mode === AvailabilityMode::Automatic && !$source->capabilities()->sharedGate) {
                $mode = AvailabilityMode::Report;
            }
            $summary['providers'][$name] = ['mode' => $mode->value]
                + $this->reviewProvider($name, $limits, $now)
                + ['gate' => $this->gate->apply($name, $summary['at'], $mode === AvailabilityMode::Automatic)];
        }

        return $summary;
    }

    /**
     * The latest confirmed stay per hotel, from the bookings every supplier
     * mirrors into ?:travel_bookings. Only moves forward, and only on hotels
     * already recorded.
     */
    private function carryLastStays(string $provider): void
    {
        $stays = db_get_hash_single_array(
            'SELECT hotel_id, MAX(check_in) AS last_stay FROM ?:travel_bookings
             WHERE provider = ?s AND status IN (?a) GROUP BY hotel_id',
            ['hotel_id', 'last_stay'],
            $provider,
            [TravelConstants::STATUS_CONFIRMED, TravelConstants::STATUS_COMPLETED],
        );
        // Numeric hotel ids arrive as int keys: read them as strings.
        foreach (is_array($stays) ? $stays : [] as $hotelId => $stay) {
            $stay = TypeCoerce::toString($stay);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $stay) !== 1) {
                continue;
            }
            db_query(
                'UPDATE ?:travel_hotel_availability SET last_booking_check_in = ?s
                 WHERE provider = ?s AND hotel_id = ?s AND (last_booking_check_in IS NULL OR last_booking_check_in < ?s)',
                $stay,
                $provider,
                (string) $hotelId,
                $stay,
            );
        }
    }
}
