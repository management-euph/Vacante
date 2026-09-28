<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Settings -> Travel Core -> "Skip the cart page for": which provider add-ons
 * send the customer from their booking form straight to checkout.
 *
 * checkout.checkout is the one CS-Cart checkout page for every add-on; the
 * setting only chooses whose booking buttons skip checkout.cart on the way.
 * A ticked add-on's booking button says "Continue to payment" (checkout is
 * the payment step), and the add-on returns there after the guest details
 * are edited. Nothing ticked (the default) keeps the cart step for everyone.
 *
 * Error redirects (booking not found, bad data) stay on the cart in every
 * case: that is where the customer sees what went wrong and can fix it.
 */
final class CartSkipPolicy
{
    public const string CART = 'checkout.cart';
    public const string CHECKOUT = 'checkout.checkout';
    // Skipping the cart lands on checkout, i.e. the Payment step, so the
    // button names it ("Continue booking" said nothing about where it led).
    public const string LABEL_SKIP = 'travel_core.continue_to_payment';
    // The booking page's next step is Payment (booking_steps.tpl), so the
    // button says so; "Continue to checkout" named a step the bar no longer has.
    public const string LABEL_DEFAULT = 'travel_core.continue_to_payment';

    /** @param list<string> $addons */
    public function __construct(private readonly array $addons)
    {
    }

    /** The saved setting, read once per request. */
    public static function current(): self
    {
        return self::fromSetting(TravelCoreConfig::getSetting('skip_cart_for'));
    }

    /**
     * CS-Cart hands a "multiple checkboxes" setting over as
     * ['novoton_holidays' => 'Y', 'sphinx_holidays' => 'N', …]; an older
     * store or a manual value may carry a comma list instead.
     */
    public static function fromSetting(mixed $value): self
    {
        $addons = [];
        if (is_array($value)) {
            foreach ($value as $key => $enabled) {
                if ($enabled === 'Y' || $enabled === true) {
                    $addons[] = trim((string) $key);
                }
            }
        } elseif (is_string($value)) {
            foreach (explode(',', $value) as $addon) {
                $addons[] = trim($addon);
            }
        }

        return new self(array_values(array_unique(array_filter(
            $addons,
            static fn (string $a): bool => $a !== '',
        ))));
    }

    public function skipsCart(string $addon): bool
    {
        return in_array($addon, $this->addons, true);
    }

    /** Where the add-to-cart (and the successful booking edit) lands. */
    public function afterAddToCart(string $addon): string
    {
        return $this->skipsCart($addon) ? self::CHECKOUT : self::CART;
    }

    /**
     * The language key of the booking button: "Continue to payment" when the
     * cart is skipped, else the form's own label.
     */
    public function ctaLabelKey(string $addon, string $defaultKey = self::LABEL_DEFAULT): string
    {
        return $this->skipsCart($addon) ? self::LABEL_SKIP : TypeCoerce::toString($defaultKey);
    }
}
