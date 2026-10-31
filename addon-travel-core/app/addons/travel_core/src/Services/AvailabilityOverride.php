<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\HotelAvailabilitySourceInterface;
use Tygh\Addons\TravelCore\Enums\AvailabilityMode;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;

/**
 * An admin's decision on one hotel, from the Hotel availability page
 * (docs/novoton-availability-gaps-plan.md §10, "overrides win"): keep it
 * visible whatever the rules say, keep it hidden, or mark it closed; or
 * clear the decision and let the rules decide again.
 *
 * Stored on the hotel's record with who and when, logged, and applied at
 * once when the supplier is in Automatic mode (AvailabilityGate, this one
 * product: no brake); in Report only it waits for that mode.
 */
final class AvailabilityOverride
{
    /** The note's length in the table. */
    private const int NOTE_MAX = 255;

    /** @var \Closure(string): AvailabilityMode */
    private readonly \Closure $mode;

    /**
     * @param (\Closure(string): AvailabilityMode)|null $mode a supplier's availability mode
     * @param array<string, HotelAvailabilitySourceInterface>|null $sources the suppliers that record availability
     */
    public function __construct(
        private readonly AvailabilityGate $gate = new AvailabilityGate(),
        ?\Closure $mode = null,
        private readonly ?array $sources = null,
    ) {
        $this->mode = $mode ?? static fn (string $provider): AvailabilityMode => TravelCoreConfig::getAvailabilityMode($provider);
    }

    /**
     * @param string $override keep_visible, keep_hidden, closed, or '' to clear
     *
     * @return array{status: 'saved'|'unknown'|'invalid', product_id: int, automatic: bool, hidden: int, shown: int}
     */
    public function set(string $provider, string $hotelId, string $override, string $note, int $userId, \DateTimeImmutable $now): array
    {
        $result = ['status' => 'invalid', 'product_id' => 0, 'automatic' => false, 'hidden' => 0, 'shown' => 0];
        if ($override !== '' && !in_array($override, AvailabilityGate::OVERRIDES, true)) {
            return $result;
        }
        HotelAvailabilityRepository::ensureTables();
        $record = TypeCoerce::toStringMap(db_get_row(
            'SELECT product_id, state, override FROM ?:travel_hotel_availability WHERE provider = ?s AND hotel_id = ?s',
            $provider,
            $hotelId,
        ));
        if ($record === []) {
            return ['status' => 'unknown'] + $result;
        }
        $productId = TypeCoerce::toInt($record['product_id'] ?? 0);
        $stamp = $now->format('Y-m-d H:i:s');

        db_query(
            'UPDATE ?:travel_hotel_availability SET override = ?s, override_note = ?s, override_by = ?i, override_at = ?p
             WHERE provider = ?s AND hotel_id = ?s',
            $override,
            mb_substr(trim($note), 0, self::NOTE_MAX),
            $override === '' ? 0 : $userId,
            $override === '' ? 'NULL' : db_quote('?s', $stamp),
            $provider,
            $hotelId,
        );
        $state = TypeCoerce::toString($record['state'] ?? '');
        db_query(
            'INSERT INTO ?:travel_hotel_state_log (provider, hotel_id, product_id, old_state, new_state, reason, user_id, created_at)
             VALUES (?s, ?s, ?i, ?s, ?s, ?s, ?i, ?s)',
            $provider,
            $hotelId,
            $productId,
            $state,
            $state,
            $override === '' ? 'override_cleared' : 'override_' . $override,
            $userId,
            $stamp,
        );

        $automatic = $this->automatic($provider);
        $result = ['status' => 'saved', 'product_id' => $productId, 'automatic' => $automatic] + $result;
        if ($automatic && $productId > 0) {
            $gate = $this->gate->apply($provider, $stamp, true, true, $productId, $userId);
            $result['hidden'] = $gate['hidden'];
            $result['shown'] = $gate['shown'];
        }

        return $result;
    }

    /**
     * After the admin checked the list the brake held: the weekly run's
     * hiding, without the brake. Only for a supplier in Automatic mode.
     *
     * @return array{hidden: int, shown: int, released: int, would_hide: int, braked: int}|null null when the supplier is not in Automatic mode
     */
    public function confirmHiding(string $provider, int $userId, \DateTimeImmutable $now): ?array
    {
        if (!$this->automatic($provider)) {
            return null;
        }

        return $this->gate->apply($provider, $now->format('Y-m-d H:i:s'), true, true, 0, $userId, false);
    }

    /** Automatic mode, for a supplier the shared gate acts for. */
    private function automatic(string $provider): bool
    {
        $source = ($this->sources ?? TravelProviderRegistry::getAvailabilitySources())[$provider] ?? null;

        return $source !== null && $source->capabilities()->sharedGate && ($this->mode)($provider) === AvailabilityMode::Automatic;
    }
}
