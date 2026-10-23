<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ValueObjects;

/**
 * The numbers the availability rules use (docs/novoton-availability-gaps-plan.md §5).
 *
 * dormantMonths is the admin's "Hide a hotel after N months without
 * availability" (default 12, decided 2026-10-17) and onRequestCounts the
 * "on request counts as availability" decision (2026-10-21); the rest are
 * the plan's defaults. freshDays is 8, not 7: suppliers are checked weekly,
 * so a week-old check plus a day's slack still counts as recent.
 */
final readonly class AvailabilityThresholds
{
    public function __construct(
        public int $dormantMonths = 12,
        public bool $onRequestCounts = true,
        public int $freshDays = 8,
        public int $delistedRuns = 3,
        public int $seasonMemoryMonths = 13,
        public int $peerSharePercent = 25,
        public int $minPeers = 5,
        public int $notOfferedDays = 14,
        public int $soldOutDays = 30,
        public int $closedProposalMonths = 6,
    ) {
    }

    /**
     * From Travel Core's settings: what an admin set, the defaults for the rest.
     * A value out of its sane range falls back to the default.
     *
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings): self
    {
        $months = $settings['availability_dormant_months'] ?? null;
        $months = is_numeric($months) ? (int) $months : 0;

        return new self(
            dormantMonths: $months >= 1 && $months <= 60 ? $months : 12,
            onRequestCounts: ($settings['availability_on_request'] ?? 'Y') !== 'N',
        );
    }
}
