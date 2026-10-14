<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Enum;

/**
 * How the orders of a type are invoiced: as a company (persoană juridică,
 * PJ), as a private person (persoană fizică, PF), or as the order's data
 * says (the invoicing add-on decides: FGO by a company name or a CIF).
 * Invoicing add-ons read it as $order_info['euph_profile_type_invoice_as'].
 */
enum InvoiceAs: string
{
    case OrderData = '';
    case Company = 'C';
    case Person = 'P';

    public static function fromValue(string $value): self
    {
        return self::tryFrom(strtoupper(trim($value))) ?? self::OrderData;
    }

    /** What the order says to invoicing add-ons: company, person, or nothing. */
    public function orderValue(): ?string
    {
        return match ($this) {
            self::Company => 'company',
            self::Person => 'person',
            self::OrderData => null,
        };
    }
}
