<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Repository;

use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * ProfileFieldCatalog over `?:profile_fields` + `?:profile_field_descriptions`.
 *
 * Only CUSTOM fields (is_default = 'N') are listed: built-in fields live in
 * ?:orders columns, while custom ones are what fn_get_order_info() returns as
 * $order_info['fields'][field_id] — the only place a CIF typed at checkout
 * ends up.
 *
 * Only TEXT types are listed. A selectbox or radio stores a variant id, a
 * checkbox Y/N, a date a timestamp: none of them can hold a tax id, so
 * listing them would only offer the merchant wrong choices and give the
 * auto-detection false matches. The settings dropdown
 * (functions/settings_variants.php) applies the same filter and must stay in
 * sync with TEXT_FIELD_TYPES.
 *
 * Only CUSTOMER fields (profile_type = 'U'). Multi-Vendor also keeps seller
 * registration fields here (profile_type 'S'), often labelled "CIF" too, but
 * their values never reach an order: offering them would give the merchant a
 * look-alike choice that never resolves. The settings dropdown applies the
 * same filter.
 *
 * One query per instance; the Container keeps one instance per request.
 */
final class ProfileFieldRepository implements ProfileFieldCatalog
{
    /** ProfileFieldTypes::INPUT, ::TEXT_AREA. */
    public const TEXT_FIELD_TYPES = ['I', 'T'];

    /** @var array<int, array{field_name: string, section: string, descriptions: list<string>}>|null */
    private ?array $fields = null;

    #[\Override]
    public function fields(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }

        $rows = db_get_array(
            "SELECT f.field_id, f.field_name, f.section, d.description
             FROM ?:profile_fields AS f
             LEFT JOIN ?:profile_field_descriptions AS d
                    ON d.object_id = f.field_id AND d.object_type = 'F'
             WHERE f.is_default = 'N' AND f.profile_type = 'U' AND f.field_type IN (?a)
             ORDER BY f.field_id",
            self::TEXT_FIELD_TYPES,
        );

        $fields = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = TypeCoerce::toInt($row['field_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $fields[$id] ??= [
                'field_name' => trim(TypeCoerce::toString($row['field_name'] ?? '')),
                'section' => strtoupper(trim(TypeCoerce::toString($row['section'] ?? ''))),
                'descriptions' => [],
            ];
            $description = trim(TypeCoerce::toString($row['description'] ?? ''));
            if ($description !== '' && !in_array($description, $fields[$id]['descriptions'], true)) {
                $fields[$id]['descriptions'][] = $description;
            }
        }

        return $this->fields = $fields;
    }
}
