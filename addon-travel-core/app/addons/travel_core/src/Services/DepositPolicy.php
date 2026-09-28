<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Settings -> Travel Core -> "Let guests pay a deposit": who may offer the
 * "Pay a deposit" choice, and whether a posted booking asked for it.
 *
 * Only the providers whose API sends a payment SCHEDULE (sphinx, novoton);
 * eurosite's terms are free text, so it has no deposit. The amount is never
 * taken from the request: the booking form posts only pay_mode=deposit and
 * add_to_cart works the deposit out again from the supplier's live terms
 * (DepositPlan). A request for a deposit the terms don't allow falls back to
 * the full price.
 */
final class DepositPolicy
{
    public const PROVIDERS = ['novoton_holidays', 'sphinx_holidays'];
    public const FIELD = 'pay_mode';
    public const MODE_DEPOSIT = 'deposit';
    public const MODE_FULL = 'full';

    public function __construct(private readonly bool $enabled)
    {
    }

    public static function current(): self
    {
        $value = TravelCoreConfig::getSetting('deposit_enabled');

        // Unset (a store that has not seen the setting yet) = the default, on.
        return new self($value === null || TypeCoerce::toString($value) === 'Y' || $value === true);
    }

    public function offers(string $addon): bool
    {
        return $this->enabled && in_array($addon, self::PROVIDERS, true);
    }

    /**
     * The deposit plan to charge, or null for the full price: the guest asked
     * for it, the provider offers it and the terms allow it.
     *
     * @param array<string, mixed> $request
     */
    public function chosen(string $addon, array $request, ?DepositPlan $plan): ?DepositPlan
    {
        if ($plan === null || !$this->offers($addon)) {
            return null;
        }

        return TypeCoerce::toString($request[self::FIELD] ?? '') === self::MODE_DEPOSIT ? $plan : null;
    }
}
