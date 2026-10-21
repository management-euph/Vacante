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
 *  - LINKED: decided per PRODUCT, since sibling hotel ids can share one
 *    (available when any of them has an immediate offer). An Active product
 *    is set to HIDDEN (reachable by direct link, absent from listings and
 *    searches) and flagged gate_hidden. Nothing is deleted: a seasonal hotel
 *    keeps its product page, URL and SEO, and when a later probe finds an
 *    immediate offer again the product is reactivated. Products the admin
 *    Hid or Disabled by hand are never touched in either direction (both
 *    status flips are guarded on the current status), and a gate-hidden
 *    product the admin changed by hand is released: the gate leaves it as
 *    the admin set it until the hotel has an immediate offer again.
 *
 * Hotels skipped for other reasons are never candidates.
 *
 * Flagging/hiding is scoped to destinations that ANSWERED: every probe
 * window's search accepted and polled to its end (cursor:null). A failed
 * search, a poll that fails or runs out of time, or a circuit-breaker stop
 * leaves the destination unanswered, so an API problem never mass-affects
 * hotels — only clearing/reactivating (always safe) still applies, on the
 * offers seen before the cut.
 *
 * Extracted from HotelSyncService so the availability concern lives behind its
 * own collaborator, paired with HotelSkipRepository. The inter-destination and
 * poll delays and the poll deadline are injectable (defaulting to the
 * production constants) so tests can run without real sleeps.
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
        private readonly int $pollDeadlineSecs = self::POLL_DEADLINE,
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

        // Probe each destination with candidates across the probe windows;
        // collect the set of hotel IDs with at least one immediate-confirmation
        // offer in ANY window, and which destinations answered in full.
        $currency = ConfigProvider::getDefaultCurrency();
        $debug = ConfigProvider::isDebugLogging();

        // Per-destination candidate hotel sets: only these destinations are
        // searched, and one stops probing further windows once every
        // candidate is already available.
        /** @var array<int, array<string, true>> $candidatesByDest */
        $candidatesByDest = [];
        foreach ($candidates as $row) {
            $hid = TypeCoerce::toString($row['hotel_id'] ?? '');
            $did = ValidationHelpers::toInt($row['destination_id'] ?? 0);
            if ($hid !== '' && $did > 0) {
                $candidatesByDest[$did][$hid] = true;
            }
        }

        $output(sprintf(
            '    %s: availability gate — %d candidate hotel(s) across %d destination(s)',
            $countryCode,
            count($candidates),
            count($candidatesByDest),
        ));

        /** @var array<string, true> $availableSet */
        $availableSet = [];
        /** @var array<int, true> $probedDestinations */
        $probedDestinations = [];
        $errors = 0;
        $stopProbing = false;
        $httpClient = $this->api->getHttpClient();

        foreach ($destIds as $destId) {
            if (!isset($candidatesByDest[$destId])) {
                continue; // no hotel to decide there: a search would change nothing
            }

            // Every window this destination ran answered in full.
            $answered = true;
            foreach (self::PROBE_WINDOWS_DAYS_AHEAD as $daysAhead) {
                if ($httpClient->isCircuitOpen()) {
                    $output('    Circuit breaker open — stopping availability probe early (unprobed hotels left unchanged)');
                    $stopProbing = true;
                    $answered = false;
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
                    $answered = false;
                    if ($errors <= 5) {
                        $output("    [WARN] availability search failed for destination {$destId} (+{$daysAhead}d window)");
                    }
                    usleep($this->destDelayUs);
                    continue;
                }

                // Inline results (synchronous completion) + cursor-polled results.
                // Zero offers is a legitimate "no availability" answer, but only
                // once the search has ended: a poll cut short proves nothing,
                // though the offers it saw still count.
                $availableSet += OfferAvailability::collectImmediateHotelIds(
                    TypeCoerce::toRowList($searchResponse['results'] ?? []),
                );
                $cursor = TypeCoerce::toString($searchResponse['cursor'] ?? '');
                if ($cursor !== '') {
                    $poll = $this->pollImmediateHotelIds($cursor);
                    $availableSet += $poll['ids'];
                    if (!$poll['complete']) {
                        $errors++;
                        $answered = false;
                        if ($errors <= 5) {
                            $output("    [WARN] availability search for destination {$destId} (+{$daysAhead}d window) did not finish — its hotels are left as they are");
                        }
                    }
                }

                if ($debug) {
                    $output("    [DEBUG] dest={$destId} +{$daysAhead}d: " . count($availableSet) . ' immediate hotel(s) so far');
                }

                usleep($this->destDelayUs);

                // All of this destination's candidates already available —
                // further windows can't change the partition below.
                if (array_diff_key($candidatesByDest[$destId], $availableSet) === []) {
                    break;
                }
            }

            if ($answered) {
                $probedDestinations[$destId] = true;
            }
            if ($stopProbing) {
                break;
            }
        }

        // Partition candidates. A hotel WITHOUT a product is decided on its
        // own: unavailable in a successfully-probed destination, it waits
        // (flagged no_availability) for an immediate offer before becoming a
        // product; available, the flag is cleared. A hotel WITH a product
        // feeds its product's verdict (gateProducts).
        /** @var list<string> $toWait */
        $toWait = [];
        /** @var list<string> $toClear */
        $toClear = [];
        /** @var array<int, true> $linkedProducts */
        $linkedProducts = [];
        /** @var array<int, true> $availableProducts some sibling has an immediate offer */
        $availableProducts = [];
        /** @var array<int, true> $unprobedProducts some sibling's destination did not answer */
        $unprobedProducts = [];
        foreach ($candidates as $row) {
            $hid = TypeCoerce::toString($row['hotel_id'] ?? '');
            if ($hid === '') {
                continue;
            }
            $available = isset($availableSet[$hid]);
            $probed = isset($probedDestinations[ValidationHelpers::toInt($row['destination_id'] ?? 0)]);
            $flagged = TypeCoerce::toString($row['product_skip_reason'] ?? '') === HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY;
            $productId = ValidationHelpers::toInt($row['product_id'] ?? 0);

            if ($productId <= 0) {
                if ($available && $flagged) {
                    $toClear[] = $hid;
                } elseif (!$available && $probed) {
                    $toWait[] = $hid;
                }
                continue;
            }

            // A linked hotel's state lives in gate_hidden / gate_released; a
            // no_availability left on it (a waiting hotel that got linked) goes.
            if ($flagged) {
                $toClear[] = $hid;
            }
            $linkedProducts[$productId] = true;
            if ($available) {
                $availableProducts[$productId] = true;
            } elseif (!$probed) {
                $unprobedProducts[$productId] = true;
            }
        }

        $waiting = $this->skipRepo->markSkippedBatch($toWait, HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);
        $cleared = $this->skipRepo->clearSkipReasonBatch($toClear, HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);
        $products = $this->gateProducts($linkedProducts, $availableProducts, $unprobedProducts);

        $stats['availability_probed'] = count($probedDestinations);
        $stats['availability_waiting'] = $waiting;
        $stats['availability_products_hidden'] = $products['hidden'];
        $stats['availability_products_restored'] = $products['restored'];
        $stats['availability_products_released'] = $products['released'];
        $stats['availability_cleared'] = $cleared;
        $stats['availability_errors'] = $errors;

        $output(sprintf(
            '    %s: availability gate — %d newly waiting (no product, no immediate-confirmation offer), %d product(s) hidden, %d cleared, %d product(s) reactivated, %d product(s) changed by an admin and left alone, %d search error(s) or unfinished search(es)',
            $countryCode,
            $waiting,
            $products['hidden'],
            $cleared,
            $products['restored'],
            $products['released'],
            $errors,
        ));

        return $stats;
    }

    /**
     * The gate for hotels with a product, decided per product on its state
     * read now, after the searches (an admin may have changed it meanwhile):
     *
     *  - gate-hidden but no longer Hidden: the admin changed it by hand, so
     *    the gate releases it and leaves it as the admin set it;
     *  - an immediate offer (any sibling): show it if the gate hid it, and
     *    end a release, so the next loss hides it again;
     *  - no immediate offer and every sibling's destination answered: hide
     *    it if it is Active and neither gate-hidden nor released.
     *
     * @param array<int, true> $products every linked product among the candidates
     * @param array<int, true> $available products with an immediate offer
     * @param array<int, true> $unprobed products with an unanswered destination
     * @return array{hidden: int, restored: int, released: int}
     */
    private function gateProducts(array $products, array $available, array $unprobed): array
    {
        $toHide = [];
        $toShow = [];
        $toRelease = [];
        $toEndRelease = [];
        foreach ($this->skipRepo->findGateStates(array_keys($products)) as $productId => $state) {
            $hidden = $state['hidden'];
            $released = $state['released'];
            if ($hidden && $state['status'] !== 'H') {
                $toRelease[] = $productId;
                $hidden = false;
                $released = true;
            }

            if (isset($available[$productId])) {
                if ($hidden) {
                    $toShow[] = $productId;
                }
                if ($released) {
                    $toEndRelease[] = $productId;
                }
            } elseif (!isset($unprobed[$productId]) && !$hidden && !$released && $state['status'] === 'A') {
                $toHide[] = $productId;
            }
        }

        $released = $this->skipRepo->releaseProductsBatch($toRelease);
        $this->skipRepo->clearReleasedBatch($toEndRelease);

        return [
            'hidden' => $this->skipRepo->hideProductsBatch($toHide),
            'restored' => $this->skipRepo->showProductsBatch($toShow),
            'released' => $released,
        ];
    }

    /**
     * Poll a search cursor to completion, collecting hotel IDs with an
     * immediate-confirmation offer. Memory stays flat: each batch is reduced to
     * the hotel-id set instead of being accumulated.
     *
     * The Sphinx spec's only definitive terminal is a cursor:null page; empty
     * pages with a live cursor are normal and keep being polled until the
     * per-destination deadline. A poll that fails or reaches the deadline is
     * not complete.
     *
     * @return array{ids: array<string, true>, complete: bool}
     */
    private function pollImmediateHotelIds(string $cursorToken): array
    {
        /** @var array<string, true> $hotelIds */
        $hotelIds = [];
        $cursor = $cursorToken;
        $deadline = time() + $this->pollDeadlineSecs;

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
                return ['ids' => $hotelIds, 'complete' => true]; // cursor:null → definitive end of search
            }
            $cursor = $nextCursor;

            if (time() < $deadline) {
                sleep($this->pollIntervalSecs);
            }
        }

        return ['ids' => $hotelIds, 'complete' => false];
    }
}
