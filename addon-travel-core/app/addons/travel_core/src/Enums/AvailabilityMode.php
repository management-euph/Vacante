<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Enums;

/**
 * What the hotel availability rules do for one supplier: a Travel Core
 * setting per supplier, because each supplier's records mature at a
 * different time (docs/novoton-availability-gaps-plan.md §15.3, point 8).
 */
enum AvailabilityMode: string
{
    /** The supplier's hotels are not classified. */
    case Off = 'off';

    /** They are classified and reported; no product is changed (the default). */
    case Report = 'report';

    /** The rules hide and show products too (AvailabilityGate), for a supplier that hands its hiding to it. */
    case Automatic = 'automatic';

    /** A stored setting; anything unknown or unset is the default, Report. */
    public static function fromSetting(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Report) : self::Report;
    }

    /**
     * The modes the settings page offers a supplier. Automatic only for one
     * whose hotels Travel Core's gate hides and shows (its capabilities'
     * sharedGate), so nobody can pick a mode that does nothing.
     *
     * @return list<self>
     */
    public static function offered(bool $sharedGate = false): array
    {
        return $sharedGate ? [self::Off, self::Report, self::Automatic] : [self::Off, self::Report];
    }
}
