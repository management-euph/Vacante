<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Model\FieldRuleSet;

/**
 * An order as its type has it.
 *
 * A customer keeps the values of the fields of a type they no longer have
 * (switching back restores them), and CS-Cart copies the whole profile into
 * the cart: an order placed as a private person may carry the company name
 * and the tax id typed back when the customer was a company. Taken as they
 * are, they make the order look like a company's: an invoicing add-on such as
 * FGO issues a company invoice when it finds a company name or a CIF.
 *
 * So, read through fn_get_order_info(), an order keeps only the values of the
 * fields its type shows somewhere: profile, registration or checkout. The
 * values of the other fields some type governs are left out (custom fields)
 * or blank (CS-Cart's own columns, the e-mail excepted). Reading deletes
 * nothing: the order still holds them, and they are back if its type changes
 * to one that has the field (the order editor saves what it reads, though).
 */
final class OrderFields
{
    /** Where a customer type has a field. */
    public const AREAS = [FieldArea::Profile, FieldArea::Registration, FieldArea::Checkout];

    /**
     * The fields some type governs that a type does not have.
     *
     * @return list<int>
     */
    public static function unseen(FieldRuleSet $rules, int $typeId): array
    {
        return $rules->unseenBy($typeId, ...self::AREAS);
    }

    /**
     * @param array<array-key, mixed> $order fn_get_order_info()
     * @param array<int, array<array-key, mixed>> $unseenFields the ?:profile_fields rows of the fields the type does not have, by id
     *
     * @return array<array-key, mixed>
     */
    public static function withoutUnseen(array $order, array $unseenFields): array
    {
        $custom = is_array($order['fields'] ?? null) ? $order['fields'] : null;
        foreach ($unseenFields as $fieldId => $field) {
            $name = Cast::string($field['field_name'] ?? '');
            if (Cast::flag($field['is_default'] ?? 'N')) {
                if ($name !== '' && $name !== 'email' && array_key_exists($name, $order)) {
                    $order[$name] = '';
                }
            } elseif ($custom !== null) {
                unset($custom[$fieldId]);
            }
        }
        if ($custom !== null) {
            $order['fields'] = $custom;
        }

        return $order;
    }
}
