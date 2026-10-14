<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes;

use Tygh\Addons\EuphProfileTypes\Enum\CheckoutChoice;
use Tygh\Addons\EuphProfileTypes\Enum\ChoiceView;
use Tygh\Addons\EuphProfileTypes\Enum\TypeChangePolicy;
use Tygh\Addons\EuphProfileTypes\Enum\TypesFor;

/**
 * The add-on settings, typed. Built at the CS-Cart boundary from
 * Registry::get('addons.euph_profile_types'); an unknown or missing value
 * falls back to the default of addon.xml.
 */
final readonly class Settings
{
    public function __construct(
        public TypesFor $typesFor = TypesFor::Both,
        public TypeChangePolicy $typeChange = TypeChangePolicy::Never,
        public string $groupStatus = 'A',
        public bool $hideStandardTypes = false,
        public ChoiceView $choiceView = ChoiceView::Radio,
        public bool $reloadOnChange = false,
        public bool $restrictLogin = false,
        public CheckoutChoice $checkoutChoice = CheckoutChoice::Everybody,
        public bool $invoiceDetailsFromProfile = false,
        public bool $vendorTypeInProduct = false,
    ) {
    }

    public static function fromArray(mixed $raw): self
    {
        $settings = Cast::array($raw);
        $groupStatus = Cast::string($settings['group_status'] ?? 'A');

        return new self(
            typesFor: TypesFor::tryFrom(Cast::string($settings['types_for'] ?? '')) ?? TypesFor::Both,
            typeChange: TypeChangePolicy::tryFrom(Cast::string($settings['type_change'] ?? '')) ?? TypeChangePolicy::Never,
            groupStatus: $groupStatus === 'P' ? 'P' : 'A',
            hideStandardTypes: Cast::flag($settings['hide_standard_types'] ?? 'N'),
            choiceView: ChoiceView::tryFrom(Cast::string($settings['choice_view'] ?? '')) ?? ChoiceView::Radio,
            reloadOnChange: Cast::flag($settings['reload_on_change'] ?? 'N'),
            restrictLogin: Cast::flag($settings['restrict_login'] ?? 'N'),
            checkoutChoice: CheckoutChoice::tryFrom(Cast::string($settings['checkout_choice'] ?? '')) ?? CheckoutChoice::Everybody,
            invoiceDetailsFromProfile: Cast::flag($settings['invoice_details_from_profile'] ?? 'N'),
            vendorTypeInProduct: Cast::flag($settings['vendor_type_in_product'] ?? 'N'),
        );
    }
}
