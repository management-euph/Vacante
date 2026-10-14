<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Db\Database;
use Tygh\Addons\EuphProfileTypes\Enum\Activation;
use Tygh\Addons\EuphProfileTypes\Enum\InvoiceAs;
use Tygh\Addons\EuphProfileTypes\Enum\LinkKind;
use Tygh\Addons\EuphProfileTypes\Enum\TypeStatus;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;
use Tygh\Addons\EuphProfileTypes\Repository\FieldRuleRepository;
use Tygh\Addons\EuphProfileTypes\Repository\ProfileTypeRepository;

/**
 * What the administrator does with types: create, edit, delete, reorder.
 *
 * Errors are returned as language variable names; the controller turns
 * them into notifications.
 */
final class TypeAdmin
{
    public function __construct(
        private readonly Database $db,
        private readonly ProfileTypeRepository $types,
        private readonly FieldRuleRepository $rules,
        private readonly bool $multiVendor,
    ) {
    }

    /**
     * Saves the type card.
     *
     * A new type starts with the field checkboxes of the standard type of its
     * kind. A changed code is carried over to the customers, vendors and
     * orders that have the old one. The standard types keep their code and
     * stay active: they are what everybody falls back to.
     *
     * @param array<array-key, mixed> $input type_data as posted
     * @param list<string> $langCodes languages a new type gets its name in
     *
     * @return array{type_id: int, errors: list<string>, previous_code: string}
     */
    public function save(int $typeId, array $input, string $langCode, array $langCodes): array
    {
        $existing = $typeId > 0 ? $this->types->find($typeId, $langCode) : null;
        if ($typeId > 0 && $existing === null) {
            return self::failure(['euph_profile_types.error_type_not_found']);
        }
        $kind = $existing !== null
            ? $existing->kind
            : (UserKind::tryFrom(Cast::string($input['user_type'] ?? '')) ?? UserKind::Customer);

        $errors = [];
        if ($kind === UserKind::Vendor && !$this->multiVendor) {
            $errors[] = 'euph_profile_types.error_vendor_types_need_multivendor';
        }
        $name = trim(Cast::string($input['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'euph_profile_types.error_name_required';
        }
        $code = $existing !== null && $existing->isStandard
            ? $existing->code
            : $this->validCode(Cast::string($input['code'] ?? ''), $typeId, $errors);
        if ($errors !== []) {
            return self::failure($errors);
        }

        $status = $existing !== null && $existing->isStandard
            ? TypeStatus::Active
            : (TypeStatus::tryFrom(Cast::string($input['status'] ?? '')) ?? TypeStatus::Active);
        $isDefault = Cast::flag($input['is_default'] ?? 'N');
        $fields = [
            'code' => $code,
            'status' => $status->value,
            'position' => Cast::int($input['position'] ?? 0),
            'activation' => (Activation::tryFrom(Cast::string($input['activation'] ?? '')) ?? Activation::StoreSetting)->value,
            // Customer types only: a vendor's application is not an order.
            'invoice_as' => $kind === UserKind::Customer
                ? InvoiceAs::fromValue(Cast::string($input['invoice_as'] ?? ''))->value
                : InvoiceAs::OrderData->value,
        ];
        if ($existing === null) {
            $fields['user_type'] = $kind->value;
            $fields['is_standard'] = 'N';
        } elseif ($existing->isDefault && !$isDefault) {
            $fields['is_default'] = 'N';
        }
        if ($existing !== null && Cast::flag($input['new_link_key'] ?? 'N')) {
            // New registration links: the ones sent so far stop working.
            $fields['link_key'] = ProfileTypeRepository::newLinkKey();
        }
        $links = [
            LinkKind::Usergroup->value => Cast::idList($input['usergroup_ids'] ?? []),
            LinkKind::Storefront->value => Cast::idList($input['storefront_ids'] ?? []),
        ];
        if ($kind === UserKind::Vendor) {
            $links[LinkKind::VendorPlan->value] = Cast::idList($input['vendor_plan_ids'] ?? []);
        }

        $savedId = $this->types->save(
            $typeId,
            $fields,
            $name,
            trim(Cast::string($input['description'] ?? '')),
            $langCode,
            $langCodes,
            $links,
        );
        if ($savedId <= 0) {
            return self::failure(['euph_profile_types.error_type_not_saved']);
        }

        $previousCode = '';
        if ($existing !== null && $existing->code !== $code) {
            $this->renameCode($existing->code, $code);
            $previousCode = $existing->code;
        }
        if ($existing === null) {
            $standard = $this->types->standard($kind, $langCode);
            if ($standard !== null) {
                $this->rules->copyType($standard->id, $savedId);
            }
        }
        if ($isDefault) {
            $this->types->setDefault($savedId, $kind);
        }

        return ['type_id' => $savedId, 'errors' => [], 'previous_code' => $previousCode];
    }

    /**
     * Deletes a type. Its customers (or vendors) move to the standard type of
     * the kind; orders keep the type they were placed with.
     *
     * @return array{errors: list<string>, type: ?ProfileType, standard: ?ProfileType, moved_user_ids: list<int>}
     */
    public function delete(int $typeId, string $langCode): array
    {
        $type = $this->types->find($typeId, $langCode);
        if ($type === null) {
            return ['errors' => ['euph_profile_types.error_type_not_found'], 'type' => null, 'standard' => null, 'moved_user_ids' => []];
        }
        if ($type->isStandard) {
            return ['errors' => ['euph_profile_types.error_standard_type_delete'], 'type' => $type, 'standard' => null, 'moved_user_ids' => []];
        }
        $standard = $this->types->standard($type->kind, $langCode);
        $toCode = $standard !== null ? $standard->code : $type->kind->standardCode();

        $userIds = Cast::idList($this->db->column(
            'SELECT user_id FROM ?:users WHERE euph_profile_type = ?s',
            $type->code,
        ));
        $this->db->execute('UPDATE ?:users SET euph_profile_type = ?s WHERE euph_profile_type = ?s', $toCode, $type->code);
        if ($type->kind === UserKind::Vendor && $this->multiVendor) {
            $this->db->execute('UPDATE ?:companies SET euph_profile_type = ?s WHERE euph_profile_type = ?s', $toCode, $type->code);
        }
        $this->rules->deleteForType($typeId);
        $this->types->delete($typeId);

        return ['errors' => [], 'type' => $type, 'standard' => $standard, 'moved_user_ids' => $userIds];
    }

    /**
     * The list page: positions, and the default type of the kind shown.
     *
     * @param array<array-key, mixed> $rows type id => ['position' => n]
     */
    public function saveList(array $rows, int $defaultTypeId, UserKind $kind): void
    {
        $positions = [];
        foreach ($rows as $typeId => $row) {
            $row = Cast::array($row);
            if (Cast::int($typeId) > 0 && array_key_exists('position', $row)) {
                $positions[Cast::int($typeId)] = Cast::int($row['position']);
            }
        }
        $this->types->savePositions($positions);
        if ($defaultTypeId > 0) {
            $this->types->setDefault($defaultTypeId, $kind);
        }
    }

    public function suggestCode(): string
    {
        return CodeValidator::suggest($this->types->codes());
    }

    /**
     * @param list<string> $errors
     */
    private function validCode(string $raw, int $typeId, array &$errors): string
    {
        $code = CodeValidator::normalize($raw);
        if ($code === '') {
            $code = $this->suggestCode();
        }
        if (!CodeValidator::isWellFormed($code)) {
            $errors[] = 'euph_profile_types.error_code_format';
        } elseif (in_array($code, [UserKind::Customer->standardCode(), UserKind::Vendor->standardCode()], true)) {
            $errors[] = 'euph_profile_types.error_code_reserved';
        } elseif ($this->types->codeExists($code, $typeId)) {
            $errors[] = 'euph_profile_types.error_code_taken';
        }

        return $code;
    }

    private function renameCode(string $from, string $to): void
    {
        $this->db->execute('UPDATE ?:users SET euph_profile_type = ?s WHERE euph_profile_type = ?s', $to, $from);
        $this->db->execute('UPDATE ?:orders SET euph_profile_type = ?s WHERE euph_profile_type = ?s', $to, $from);
        if ($this->multiVendor) {
            $this->db->execute('UPDATE ?:companies SET euph_profile_type = ?s WHERE euph_profile_type = ?s', $to, $from);
        }
    }

    /**
     * @param list<string> $errors
     *
     * @return array{type_id: int, errors: list<string>, previous_code: string}
     */
    private static function failure(array $errors): array
    {
        return ['type_id' => 0, 'errors' => $errors, 'previous_code' => ''];
    }
}
