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

    /** The rules hide and show products too (phase 3; not offered until then). */
    case Automatic = 'automatic';

    /** A stored setting; anything unknown or unset is the default, Report. */
    public static function fromSetting(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Report) : self::Report;
    }

    /**
     * The modes the settings page offers. Automatic joins when the gate that
     * acts on it ships, so nobody can pick a mode that does nothing yet.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        return [self::Off, self::Report];
    }
}
