<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\FieldRule;
use Tygh\Addons\EuphProfileTypes\Model\FieldRuleSet;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;
use Tygh\Addons\EuphProfileTypes\Service\FieldsList;
use Tygh\Addons\EuphProfileTypes\Tests\Support\Types;

final class FieldsListTest extends TestCase
{
    /**
     * @return list<ProfileType>
     */
    private static function customerTypes(): array
    {
        return [
            ProfileType::fromRow(Types::row(1, 'U', 'C', ['is_standard' => 'Y', 'name' => 'Private person'])),
            ProfileType::fromRow(Types::row(2, 'B', 'C', ['name' => 'Company'])),
            ProfileType::fromRow(Types::row(5, 'O', 'C', ['name' => 'Old', 'status' => 'D'])),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function field(int $id, string $name, string $section = 'C', array $overrides = []): array
    {
        return array_merge([
            'field_id' => $id,
            'field_name' => $name,
            'section' => $section,
            'profile_type' => 'U',
            'is_default' => 'N',
            'matching_id' => 0,
            'profile_show' => 'Y',
            'profile_required' => 'N',
            'checkout_show' => 'Y',
            'checkout_required' => 'N',
            'storefront_show' => 'N',
        ], $overrides);
    }

    /**
     * Company name set per type (Company only), the e-mail, a general phone,
     * a billing city set per type and its shipping twin on its general
     * checkboxes.
     *
     * @return array{0: FieldRuleSet, 1: list<array<string, mixed>>}
     */
    private static function store(): array
    {
        $rules = new FieldRuleSet([
            new FieldRule(2, 20, profileShow: true, profileRequired: true, registrationShow: true, checkoutShow: true, checkoutRequired: true),
            new FieldRule(1, 31, profileShow: true),
        ]);
        $fields = [
            self::field(10, 'email', 'C', ['is_default' => 'Y', 'profile_required' => 'Y', 'checkout_required' => 'Y']),
            self::field(20, 'company'),
            self::field(21, 'phone', 'C', ['checkout_show' => 'N']),
            self::field(31, 'b_city', 'B', ['matching_id' => 32]),
            self::field(32, 's_city', 'S', ['matching_id' => 31]),
        ];

        return [$rules, $fields];
    }

    public function testTheAreasAndTheirCheckboxesByKind(): void
    {
        self::assertSame(
            ['profile' => ['profile_show', 'profile_required', 'registration_show'], 'checkout' => ['checkout_show', 'checkout_required']],
            FieldsList::areas(UserKind::Customer),
        );
        self::assertSame(['profile' => ['profile_show', 'profile_required', 'registration_show', 'storefront_show']], FieldsList::areas(UserKind::Vendor));
    }

    public function testTheCustomersFieldsGetTheCheckoutColumnBack(): void
    {
        // CS-Cart 4.18+: the customers' fields have only the Profile area.
        $schema = [
            'U' => ['name' => 'user', 'allowed_sections' => ['C', 'BS'], 'allowed_areas' => ['profile']],
            'S' => ['name' => 'seller', 'allowed_sections' => ['C'], 'allowed_areas' => ['profile']],
        ];
        $expected = $schema;
        $expected['U']['allowed_areas'] = ['profile', 'checkout'];

        self::assertSame($expected, FieldsList::withCheckoutArea($schema));
        self::assertSame(array_keys(FieldsList::areas(UserKind::Customer)), $expected['U']['allowed_areas'], 'the columns the list draws rows in');
        self::assertSame($expected, FieldsList::withCheckoutArea($expected), 'a core that has it keeps it once');
        self::assertSame(['S' => $schema['S']], FieldsList::withCheckoutArea(['S' => $schema['S']]), 'the vendors\' fields keep theirs');
        self::assertSame(['U' => ['name' => 'user']], FieldsList::withCheckoutArea(['U' => ['name' => 'user']]), 'an entry it does not know is left alone');
    }

    public function testTheLockedFieldsAreTheEmailAndAVendorsCompanyName(): void
    {
        self::assertTrue(FieldsList::isLocked(['field_name' => 'email', 'is_default' => 'Y'], UserKind::Customer));
        self::assertFalse(FieldsList::isLocked(['field_name' => 'email', 'is_default' => 'N'], UserKind::Customer));
        self::assertTrue(FieldsList::isLocked(['field_name' => 'company'], UserKind::Vendor));
        self::assertFalse(FieldsList::isLocked(['field_name' => 'company'], UserKind::Customer));
    }

    public function testEachFieldIsDrawnByTypesOnItsGeneralCheckboxesOrLocked(): void
    {
        [$rules, $fields] = self::store();
        $list = FieldsList::build(UserKind::Customer, self::customerTypes(), $rules, $fields);

        self::assertSame(
            [
                ['id' => 1, 'code' => 'U', 'name' => 'Private person', 'status' => 'A', 'tone' => 1],
                ['id' => 2, 'code' => 'B', 'name' => 'Company', 'status' => 'A', 'tone' => 2],
                ['id' => 5, 'code' => 'O', 'name' => 'Old', 'status' => 'D', 'tone' => 3],
            ],
            $list['types'],
        );
        self::assertSame('locked', $list['fields'][10]['mode']);
        self::assertSame('types', $list['fields'][20]['mode']);
        self::assertSame('general', $list['fields'][21]['mode']);
        // Billing and shipping are set each on its own.
        self::assertSame('types', $list['fields'][31]['mode']);
        self::assertSame('general', $list['fields'][32]['mode']);
        self::assertFalse($list['fields'][32]['governed']);

        // Set per type: each type its own rule, nothing for a type not ticked.
        self::assertSame('YYYYYN', $list['fields'][20]['rows'][2]['baseline']);
        self::assertSame('NNNNNN', $list['fields'][20]['rows'][1]['baseline']);
        self::assertSame('Y', $list['fields'][20]['rows'][2]['flags']['registration_show']);
        // On its general checkboxes: every type sees them, registration as the profile.
        self::assertSame('YNYNNN', $list['fields'][21]['rows'][1]['baseline']);
        self::assertSame($list['fields'][21]['rows'][1]['baseline'], $list['fields'][21]['rows'][5]['baseline']);
    }

    public function testTheTonesGoRoundAfterEightTypes(): void
    {
        $types = [];
        for ($id = 1; $id <= 9; $id++) {
            $types[] = ProfileType::fromRow(Types::row($id, 'T' . $id));
        }
        $list = FieldsList::build(UserKind::Customer, $types, new FieldRuleSet(), []);

        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 1], array_column($list['types'], 'tone'));
        self::assertSame([], $list['fields']);
    }

    public function testAnUntouchedListChangesNothing(): void
    {
        [$rules, $fields] = self::store();
        $list = FieldsList::build(UserKind::Customer, self::customerTypes(), $rules, $fields);
        $posted = [];
        foreach ([20, 21] as $fieldId) {
            foreach ($list['fields'][$fieldId]['rows'] as $typeId => $row) {
                // As the form sends it: the drawn checkboxes and the baseline, no storefront one.
                $sent = $row['flags'];
                unset($sent['storefront_show']);
                $posted[$fieldId][$typeId] = $sent + ['baseline' => $row['baseline']];
            }
            $posted[$fieldId]['split'] = 'N';
        }

        self::assertSame([], FieldsList::changes(UserKind::Customer, self::customerTypes(), $rules, self::byId($fields), $posted));
    }

    public function testAFieldSetPerTypeChangesWhenARowDiffersAndKeepsWhatWasNotDrawn(): void
    {
        [$rules, $fields] = self::store();
        $rules = new FieldRuleSet([
            new FieldRule(2, 20, profileShow: true, storefrontShow: true),
            new FieldRule(1, 20, checkoutShow: true),
        ]);
        $posted = [20 => [
            1 => ['profile_show' => 'N', 'profile_required' => 'N', 'registration_show' => 'N', 'checkout_show' => 'Y', 'checkout_required' => 'N', 'baseline' => 'NNNYNN'],
            // Company: "required" ticked now; the storefront checkbox was not drawn.
            2 => ['profile_show' => 'Y', 'profile_required' => 'Y', 'registration_show' => 'N', 'checkout_show' => 'N', 'checkout_required' => 'N', 'baseline' => 'YNNNNY'],
        ]];

        $changes = FieldsList::changes(UserKind::Customer, self::customerTypes(), $rules, self::byId($fields), $posted);

        self::assertSame([20], array_keys($changes));
        self::assertSame('YYNNNY', $changes[20][2]->signature());
        self::assertSame('NNNYNN', $changes[20][1]->signature());
        // A type the page did not draw keeps its rule.
        self::assertSame('NNNNNN', $changes[20][5]->signature());
    }

    public function testAFieldOnItsGeneralCheckboxesChangesOnlyWhenSplitAndARowDiffers(): void
    {
        [$rules, $fields] = self::store();
        $general = ['profile_show' => 'Y', 'profile_required' => 'N', 'registration_show' => 'Y', 'checkout_show' => 'N', 'checkout_required' => 'N'];
        $byId = self::byId($fields);

        // Ticked differently, but "Set per type" was not used: the general checkboxes decide.
        $notSplit = [21 => [2 => ['profile_required' => 'Y'] + $general, 'split' => 'N']];
        self::assertSame([], FieldsList::changes(UserKind::Customer, self::customerTypes(), $rules, $byId, $notSplit));

        // Split and nothing differs from the general checkboxes: still nothing to store.
        $same = [21 => [1 => $general, 2 => $general, 5 => $general, 'split' => 'Y']];
        self::assertSame([], FieldsList::changes(UserKind::Customer, self::customerTypes(), $rules, $byId, $same));

        // Split, Company requires the phone: every type gets its row, the others what they saw.
        $split = [21 => [1 => $general, 2 => ['profile_required' => 'Y'] + $general, 5 => $general, 'split' => 'Y']];
        $changes = FieldsList::changes(UserKind::Customer, self::customerTypes(), $rules, $byId, $split);

        self::assertSame('YYYNNN', $changes[21][2]->signature());
        self::assertSame('YNYNNN', $changes[21][1]->signature());
        self::assertSame('YNYNNN', $changes[21][5]->signature());
    }

    public function testLockedFieldsTwinsAndFieldsOfAnotherKindAreLeftAlone(): void
    {
        [$rules, $fields] = self::store();
        $byId = self::byId($fields);
        $byId[40] = self::field(40, 'shop', 'C', ['profile_type' => 'S']);
        $row = ['profile_show' => 'N', 'profile_required' => 'N', 'registration_show' => 'N', 'checkout_show' => 'N', 'checkout_required' => 'N'];
        $posted = [
            10 => [1 => $row, 2 => $row, 'split' => 'Y'],
            40 => [1 => $row, 'split' => 'Y'],
            99 => [1 => $row, 'split' => 'Y'],
        ];

        self::assertSame([], FieldsList::changes(UserKind::Customer, self::customerTypes(), $rules, $byId, $posted));
    }

    public function testASignatureGivesBackItsCheckboxes(): void
    {
        self::assertSame(
            ['profile_show' => 'Y', 'profile_required' => 'N', 'registration_show' => 'Y', 'checkout_show' => 'N', 'checkout_required' => 'N', 'storefront_show' => 'Y'],
            FieldRule::flagsFromSignature('YNYNNY'),
        );
        self::assertSame(['profile_show' => 'Y'], FieldRule::flagsFromSignature('Y?'));
        self::assertSame([], FieldRule::flagsFromSignature(''));
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return array<int, array<string, mixed>>
     */
    private static function byId(array $fields): array
    {
        $byId = [];
        foreach ($fields as $field) {
            $byId[(int) $field['field_id']] = $field;
        }

        return $byId;
    }
}
