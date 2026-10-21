<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;

/**
 * Gates ALL hotels on immediate availability.
 *
 * For each destination with candidate hotels (unflagged or carrying the
 * gate's no_availability flag — linked to a CS-Cart product or not), runs a
 * live search per probe window (PROBE_WINDOWS_DAYS_AHEAD). A hotel with no
 * immediate-confirmation offer in ANY window is handled by link state:
 *
 *  - UNLINKED: the row stays, flagged no_availability, which keeps it from
 *    becoming a product. It is checked again on every run and the flag is
 *    cleared when an immediate offer appears. Deleting it lost the hotel for
 *    good: incremental hotel syncs never bring a deleted row back, so a
 *    seaside hotel removed in October could not become a product next summer.
 *    The admin Hotels grid hides these rows by default.
 *  - LINKED: the CS-Cart product is set to HIDDEN (reachable by direct link,
 *    absent from listings and searches) and the hotel row is flagged
 *    no_availability. Nothing is deleted: a seasonal hotel keeps its product
 *    page, URL and SEO, and when a later probe finds an immediate offer
 *    again the flag is cleared and the product reactivated. Products the
 *    admin Hid or Disabled by hand are never touched in either direction
 *    (both status flips are guarded on the current status).
 *
 * Hotels skipped for other reasons are never candidates.
 *
 * Flagging/hiding is scoped to destinations that were probed successfully,
 * so an API error never mass-affects hotels — only clearing/reactivating
 * (always safe) still applies.
 *
 * Extracted from HotelSyncService so the availability concern lives behind its
 * own collaborator, paired with HotelSkipRepository. The inter-destination and
 * poll delays are injectable (defaulting to the production constants) so tests
 * can run without real sleeps.
 */
class HotelAvailabilityGate
{
    // Availability probe parameters. A hotel counts as available when ANY
    // probe window yields an immediate-confirmation offer — a single fixed
    // window (the old +30d-only probe) excluded hotels whose inventory sits
    // outside that arbitrary week (e.g. summer-only hotels probed in spring).
    private const int NIGHTS = 7;
    private const int ADULTS = 2;
    /** @var list<int> check-in offsets (days from today), each probed for NIGHTS */
    private const array PROBE_WINDOWS_DAYS_AHEAD = [14, 30, 60];
    private const int POLL_INTERVAL = 3;     // seconds between result polls
    private const int POLL_DEADLINE = 60;    // hard cap per destination+window
    private const int DEST_DELAY_US = 500000; // 500ms between searches

    public function __construct(
        private readonly SphinxApi $api,
        private readonly HotelSkipRepository $skipRepo,
        private readonly int $destDelayUs = self::DEST_DELAY_US,
        private readonly int $pollIntervalSecs = self::POLL_INTERVAL,
    ) {
    }

    /**
     * Run the availability gate for one country's destinations.
     *
     * @param int[] $destinationIds
     * @param array<string, mixed> $stats
     * @param callable(string): void $output Progress sink
     * @return array<string, mixed>
     */
    public function apply(string $countryCode, array $destinationIds, array $stats, callable $output): array
    {
        // Normalise to a unique list of positive int destination IDs.
        $destIds = [];
        foreach ($destinationIds as $d) {
            $di = ValidationHelpers::toInt($d);
            if ($di > 0) {
                $destIds[] = $di;
            }
        }
        $destIds = array_values(array_unique($destIds));
        if ($destIds === []) {
            return $stats;
        }

        $candidates = $this->skipRepo->findAvailabilityGateCandidates($destIds);
        if ($candidates === []) {
            $output("    {$countryCode}: availability gate — no hotels to check");
            return $stats;
        }

        $output(sprintf(
            '    %s: availability gate — %d candidate hotel(s) across %d destination(s)',
            $countryCode,
            count($candidates),
            count($destIds),
        ));

        // Probe each destination across the probe windows; collect the set of
        // hotel IDs with at least one immediate-confirmation offer in ANY
        // window, and which destinations answered at least once.
        $currency = ConfigProvider::getDefaultCurrency();
        $debug = ConfigProvider::isDebugLogging();

        // Per-destination candidate hotel sets, so a destination can stop
        // probing further windows once every candidate is already available.
        /** @var array<int, array<string, true>> $candidatesByDest */
        $candidatesByDest = [];
        foreach ($candidates as $row) {
            $hid = TypeCoerce::toString($row['hotel_id'] ?? '');
            $did = ValidationHelpers::toInt($row['destination_id'] ?? 0);
            if ($hid !== '' && $did > 0) {
                $candidatesByDest[$did][$hid] = true;
            }
        }

        /** @var array<string, true> $availableSet */
        $availableSet = [];
        /** @var array<int, true> $probedDestinations */
        $probedDestinations = [];
        $errors = 0;
        $stopProbing = false;
        $httpClient = $this->api->getHttpClient();

        foreach ($destIds as $destId) {
            foreach (self::PROBE_WINDOWS_DAYS_AHEAD as $daysAhead) {
                if ($httpClient->isCircuitOpen()) {
                    $output('    Circuit breaker open — stopping availability probe early (unprobed hotels left unchanged)');
                    $stopProbing = true;
                    break;
                }

                $searchResponse = $this->api->searchHotels([
                    'destination_id' => $destId,
                    'check_in' => date('Y-m-d', strtotime("+{$daysAhead} days")),
                    'check_out' => date('Y-m-d', strtotime('+' . ($daysAhead + self::NIGHTS) . ' days')),
                    'occupancy' => [['adults' => self::ADULTS, 'children_ages' => []]],
                    'currency' => $currency,
                ]);

                if (!is_array($searchResponse)) {
                    $errors++;
                    if ($errors <= 5) {
                        $output("    [WARN] availability search failed for destination {$destId} (+{$daysAhead}d window)");
                    }
                    usleep($this->destDelayUs);
                    continue;
                }

                // Search was accepted → destination counts as probed (zero offers
                // is a legitimate "no availability" answer, not an error).
                $probedDestinations[$destId] = true;

                // Inline results (synchronous completion) + cursor-polled results.
                $availableSet += OfferAvailability::collectImmediateHotelIds(
                    TypeCoerce::toRowList($searchResponse['results'] ?? []),
                );
                $cursor = TypeCoerce::toString($searchResponse['cursor'] ?? '');
                if ($cursor !== '') {
                    $availableSet += $this->pollImmediateHotelIds($cursor);
                }

                if ($debug) {
                    $output("    [DEBUG] dest={$destId} +{$daysAhead}d: " . count($availableSet) . ' immediate hotel(s) so far');
                }

                usleep($this->destDelayUs);

                // All of this destination's candidates already available —
                // further windows can't change the partition below.
                if (isset($candidatesByDest[$destId])
                    && array_diff_key($candidatesByDest[$destId], $availableSet) === []
                ) {
                    break;
                }
            }

            if ($stopProbing) {
                break;
            }
        }

        // Partition candidates. Unavailable hotels in successfully-probed
        // destinations are flagged: unlinked ones wait for an immediate offer
        // before becoming products, linked ones also get their product
        // HIDDEN. Available hotels get the flag cleared and — when linked —
        // the gate-hidden product reactivated.
        /** @var list<string> $toWait */
        $toWait = [];
        /** @var list<string> $toHideHotels */
        $toHideHotels = [];
        /** @var list<int> $toHideProducts */
        $toHideProducts = [];
        $toClear = [];
        /** @var list<int> $toRestoreProducts */
        $toRestoreProducts = [];
        foreach ($candidates as $row) {
            $hid = TypeCoerce::toString($row['hotel_id'] ?? '');
            if ($hid === '') {
                continue;
            }
            $reason = TypeCoerce::toString($row['product_skip_reason'] ?? '');
            $productId = ValidationHelpers::toInt($row['product_id'] ?? 0);
            if (isset($availableSet[$hid])) {
                if ($reason === HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY) {
                    $toClear[] = $hid;
                    if ($productId > 0) {
                        $toRestoreProducts[] = $productId;
                    }
                }
                continue;
            }
            $destId = ValidationHelpers::toInt($row['destination_id'] ?? 0);
            if (isset($probedDestinations[$destId])) {
                if ($productId > 0) {
                    $toHideHotels[] = $hid;
                    $toHideProducts[] = $productId;
                } else {
                    $toWait[] = $hid;
                }
            }
        }

        $waiting = $this->skipRepo->markSkippedBatch($toWait, HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);
        // Product first, flag second: the flag is what marks the product as
        // gate-hidden (vs. admin-hidden), so it must never exist without the
        // hide attempt having run. Both calls are idempotent re-runs.
        $hidden = $this->skipRepo->hideProductsBatch($toHideProducts);
        $this->skipRepo->markSkippedBatch($toHideHotels, HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);
        $restored = $this->skipRepo->showProductsBatch($toRestoreProducts);
        $cleared = $this->skipRepo->clearSkipReasonBatch($toClear, HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);

        $stats['availability_probed'] = count($probedDestinations);
        $stats['availability_waiting'] = $waiting;
        $stats['availability_products_hidden'] = $hidden;
        $stats['availability_products_restored'] = $restored;
        $stats['availability_cleared'] = $cleared;
        $stats['availability_errors'] = $errors;

        $output(sprintf(
            '    %s: availability gate — %d newly waiting (no product, no immediate-confirmation offer), %d product(s) hidden, %d cleared, %d product(s) reactivated, %d search error(s)',
            $countryCode,
            $waiting,
            $hidden,
            $cleared,
            $restored,
            $errors,
        ));

        return $stats;
    }

    /**
     * Poll a search cursor to completion, collecting hotel IDs with an
     * immediate-confirmation offer. Memory stays flat: each batch is reduced to
     * the hotel-id set instead of being accumulated.
     *
     * The Sphinx spec's only definitive terminal is a cursor:null page; empty
     * pages with a live cursor are normal and keep being polled until the
     * per-destination deadline.
     *
     * @return array<string, true>
     */
    private function pollImmediateHotelIds(string $cursorToken): array
    {
        /** @var array<string, true> $hotelIds */
        $hotelIds = [];
        $cursor = $cursorToken;
        $deadline = time() + self::POLL_DEADLINE;

        while (time() < $deadline) {
            $response = $this->api->getHotelResults('', $cursor);
            if (!is_array($response)) {
                break;
            }

            $batch = TypeCoerce::toRowList($response['results'] ?? []);
            if ($batch !== []) {
                $hotelIds += OfferAvailability::collectImmediateHotelIds($batch);
            }

            $nextCursor = TypeCoerce::toString($response['cursor'] ?? '');
            if ($nextCursor === '') {
                break; // cursor:null → definitive end of search
            }
            $cursor = $nextCursor;

            if (time() < $deadline) {
                sleep($this->pollIntervalSecs);
            }
        }

        return $hotelIds;
    }
}
