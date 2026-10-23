# Provider-framework convergence ledger

The novoton and sphinx addons grew in parallel and share ~18 same-named
classes. This ledger records, per pair, whether it has been converged, how
far, and — for the pairs deliberately left apart — **why**, so the roadmap
stops re-litigating them. Update it whenever a tranche lands.

Companion guard: `travel_core .../Schema/ConvergenceLedgerTest.php` pins the
"converged" and "contract" rows below (an implementation that drops the
shared contract fails the suite).

## Converged — one shared implementation in travel_core

| Pair | Shared piece | Tranche |
| --- | --- | --- |
| retry/backoff + circuit breaker | `Http\ResiliencePolicy` (half-open mode per client) | R1 |
| Cron command discovery | `Cron\CommandDiscovery` (both dispatchers delegate) | C1 |
| Cron command base | `Cron\AbstractCronCommand` (both addon bases extend it) | earlier |
| Cron run lock | `Helpers\CronRunLock` | earlier |
| Booking mirror | `Repository\TravelBookingMirror` | earlier |
| Hotel header assembly | `ViewModels\HotelHeaderFactory` + `HotelHeaderViewModel` + shared component tpl | R2 / Arch #3 |
| Location line / map URL | `Services\HotelLocationLine` / `HotelMapUrl` | earlier |
| Cache contract + sphinx impl | `Contracts\CacheServiceInterface` (novoton extends it; sphinx implements it as an instance service) | Arch #2 |
| Availability-check dates (eurosite's `AvailabilityPlan` date logic) | `Services\AvailabilityWindows` (near days + peak-season dates; eurosite calls it) | A1 |
| Hotel availability rules (new, all suppliers) | `Services\AvailabilityPolicy` (pure classifier) + `ValueObjects\AvailabilityThresholds` | A1 |
| A supplier answer's offers, per hotel (new, all suppliers) | `Services\OfferTally` (eurosite and sphinx fold their offers into it for `recordCheck` / `recordOffersSeen`) | A1 |

## Contract-converged — shared interface, provider implementations

Implementations differ because the provider payloads/flows differ; the
CONTRACT is the shared surface new code must type against.

| Pair | Contract | Notes |
| --- | --- | --- |
| SecurityService (617L / 211L) | `Contracts\SecurityServiceInterface` | both implement |
| PreOrderPriceVerifier (455L / 179L) | `Contracts\PreOrderPriceVerifierInterface` | both implement |
| BookingAdminProvider | `Contracts\BookingAdminProviderInterface` | both implement |
| HotelRepository | `Contracts\HotelRepositoryInterface` | both implement |
| CronDispatcher | `Contracts\CronDispatcherInterface` | both implement; discovery shared (C1) |
| ConfigProvider | `AbstractConfigProvider` (+ sphinx `ConfigProviderInterface`) | per-addon settings by design |
| Availability records source | `Contracts\HotelAvailabilitySourceInterface` | novoton, eurosite and sphinx implement it (`NovotonAvailabilitySource`, `EurositeAvailabilitySource`, `SphinxAvailabilitySource`), each pinned in `ConvergenceLedgerTest`. They write through `Repository\HotelAvailabilityRepository`, folding their own offer shapes into `Services\OfferTally` (docs/novoton-availability-gaps-plan.md §16) |

## Intentionally divergent — reviewed, left apart (with reasons)

| Pair | Verdict (2026-07-23 review) |
| --- | --- |
| TermsFormatter | Different provider payloads AND outputs: novoton parses provider **XML strings** into display strings; sphinx transforms verify-API **arrays** into timeline `lines`/`rules`/`increments`. Converging means redesigning novoton's terms pipeline onto the rules shape — a feature project, not a refactor. Revisit only if novoton's terms UI moves to the timeline modal. |
| SyncLogRepository | Different tables and schemas (`novoton_sync_log` with `sync_date`/`sync_type`, rich CRUD vs `sphinx_sync_log` with `started_at`, one read query). A parametrized base would be thinner than the duplication it removes. |
| CalendarPriceBuilder (325L / 126L) | Same role, provider-specific price sources and shapes; no shared contract worth extracting yet. Candidate if a third provider lands. |
| HotelSearchRepository | novoton implements its own interface; sphinx's is a plain provider-schema repository. Search flows are provider-specific. |
| Container | Per-addon service wiring is the POINT of each container; only the resolved services converge. |
| Cron commands (FullSync/Cleanup/CalendarPrices/AddProducts/ReassignFeatures) | Provider-specific by nature; they already share the travel_core command base + discovery + lock. |
| SecurityService / PreOrderPriceVerifier implementations | See contract-converged — merging bodies would couple different provider risk models. |

## How to add a tranche

1. Prefer contracts first (interface in `travel_core/src/Contracts`), then
   move genuinely provider-agnostic logic (pure policies, data, discovery).
2. One pair per commit, behavior-preserving, with the shared piece
   unit-tested in travel_core.
3. Update this ledger + `ConvergenceLedgerTest` in the same commit.
