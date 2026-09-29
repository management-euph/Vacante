<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Repository;

/**
 * Read port over the store's custom profile fields (Administration > Profile
 * fields), the merchant-owned source of CIF / Reg. Com. / CNP.
 *
 * An interface so BillingExtrasResolver stays pure: production reads the
 * database once per request (ProfileFieldRepository), tests hand it an
 * in-memory list.
 *
 * Every description the field has, in every language, is exposed rather than
 * the one for the current language: the resolver runs from the storefront,
 * the admin panel and cron alike, and a field labelled "Cod fiscal" in
 * Romanian and "Tax ID" in English must be recognised either way.
 */
interface ProfileFieldCatalog
{
    /**
     * Keyed by field_id. `section` is CS-Cart's C (contact) / B (billing) /
     * S (shipping) code; `field_name` is the optional admin "Code" and is
     * often ''.
     *
     * @return array<int, array{field_name: string, section: string, descriptions: list<string>}>
     */
    public function fields(): array;
}
