<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\FieldRule;
use Tygh\Addons\EuphProfileTypes\Repository\FieldRuleRepository;
use Tygh\Addons\EuphProfileTypes\Repository\ProfileTypeRepository;
use Tygh\Addons\EuphProfileTypes\Service\TypeAdmin;
use Tygh\Addons\EuphProfileTypes\Tests\Support\FakeDatabase;
use Tygh\Addons\EuphProfileTypes\Tests\Support\Types;

final class TypeAdminTest extends TestCase
{
    private FakeDatabase $db;

    protected function setUp(): void
    {
        $this->db = Types::database();
        $this->db->rows['FROM ?:euph_profile_type_fields'] = [(new FieldRule(1, 30, profileShow: true))->toRow()];
        $this->db->columns['SELECT code FROM ?:euph_profile_types'] = ['U', 'B', 'P', 'D', 'O', 'S'];
    }

    private function admin(bool $multiVendor = true): TypeAdmin
    {
        return new TypeAdmin($this->db, new ProfileTypeRepository($this->db), new FieldRuleRepository($this->db), $multiVendor);
    }

    public function testANewTypeIsSavedNamedEverywhereAndStartsAsTheStandardForm(): void
    {
        $this->db->insertIds = [12];

        $result = $this->admin()->save(0, [
            'user_type' => 'C',
            'name' => ' Wholesale ',
            'code' => 'wholesale',
            'status' => 'H',
            'activation' => 'P',
            'is_default' => 'Y',
            'usergroup_ids' => ['7', '9'],
        ], 'en', ['en', 'ro']);

        self::assertSame(['type_id' => 12, 'errors' => [], 'previous_code' => ''], $result);
        $insert = $this->db->writes('INSERT INTO ?:euph_profile_types')[0]['params'][0];
        self::assertIsArray($insert);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $insert['link_key'] ?? '', 'the key of its registration links');
        unset($insert['link_key']);
        self::assertSame(['code' => 'WHOLESALE', 'status' => 'H', 'position' => 0, 'activation' => 'P', 'user_type' => 'C', 'is_standard' => 'N'], $insert);
        self::assertCount(2, $this->db->writes('REPLACE INTO ?:euph_profile_type_descriptions'));
        self::assertSame('Wholesale', $this->db->writes('REPLACE INTO ?:euph_profile_type_descriptions')[0]['params'][0]['name']);
        self::assertSame(12, $this->db->writes('REPLACE INTO ?:euph_profile_type_fields')[0]['params'][0]['type_id'], 'the rules of the standard type are copied');
        self::assertNotSame([], $this->db->writes("SET is_default = ?s WHERE type_id"));
    }

    public function testAnEmptyCodeGetsTheFirstFreeLetter(): void
    {
        $this->db->insertIds = [13];

        $this->admin()->save(0, ['user_type' => 'C', 'name' => 'Partner', 'code' => ''], 'en', ['en']);

        self::assertSame('A', $this->db->writes('INSERT INTO ?:euph_profile_types')[0]['params'][0]['code']);
    }

    public function testInvalidInputIsRefusedWithReasons(): void
    {
        $admin = $this->admin();

        self::assertSame(['euph_profile_types.error_name_required'], $admin->save(0, ['name' => '', 'code' => 'Z'], 'en', ['en'])['errors']);
        self::assertSame(['euph_profile_types.error_code_format'], $admin->save(0, ['name' => 'X', 'code' => 'a-b'], 'en', ['en'])['errors']);
        self::assertSame(['euph_profile_types.error_code_reserved'], $admin->save(0, ['name' => 'X', 'code' => 's'], 'en', ['en'])['errors']);
        $this->db->values['WHERE code = ?s AND type_id != ?i'] = 3;
        self::assertSame(['euph_profile_types.error_code_taken'], $admin->save(0, ['name' => 'X', 'code' => 'P'], 'en', ['en'])['errors']);
        self::assertSame(['euph_profile_types.error_type_not_found'], $admin->save(77, ['name' => 'X'], 'en', ['en'])['errors']);
        self::assertSame([], $this->db->writes('INSERT INTO'));
    }

    public function testVendorTypesNeedMultiVendor(): void
    {
        self::assertSame(
            ['euph_profile_types.error_vendor_types_need_multivendor'],
            $this->admin(false)->save(0, ['user_type' => 'V', 'name' => 'Shop', 'code' => 'SH'], 'en', ['en'])['errors'],
        );
    }

    public function testARenamedCodeFollowsItsCustomersOrdersAndVendors(): void
    {
        $result = $this->admin()->save(2, ['name' => 'Company', 'code' => 'CO', 'status' => 'A'], 'en', ['en']);

        self::assertSame('B', $result['previous_code']);
        foreach (['?:users', '?:orders', '?:companies'] as $table) {
            $writes = $this->db->writes('UPDATE ' . $table . ' SET euph_profile_type');
            self::assertCount(1, $writes, $table);
            self::assertSame(['CO', 'B'], $writes[0]['params']);
        }
    }

    public function testTheStandardTypeKeepsItsCodeAndStaysActive(): void
    {
        $this->admin()->save(1, ['name' => 'Private person', 'code' => 'PRIV', 'status' => 'D'], 'en', ['en']);

        $update = $this->db->writes('UPDATE ?:euph_profile_types SET ?u')[0]['params'][0];
        self::assertSame('U', $update['code']);
        self::assertSame('A', $update['status']);
        self::assertSame([], $this->db->writes('UPDATE ?:users'));
    }

    public function testNewLinksGiveTheTypeANewKey(): void
    {
        $this->admin()->save(4, ['name' => 'Dealer', 'code' => 'D', 'status' => 'H', 'new_link_key' => 'Y'], 'en', ['en']);
        $this->admin()->save(4, ['name' => 'Dealer', 'code' => 'D', 'status' => 'H', 'new_link_key' => 'N'], 'en', ['en']);

        $updates = $this->db->writes('UPDATE ?:euph_profile_types SET ?u');
        $renewed = $updates[0]['params'][0];
        self::assertIsArray($renewed);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $renewed['link_key'] ?? '');
        self::assertNotSame(Types::DEALER_KEY, $renewed['link_key']);
        self::assertIsArray($updates[1]['params'][0]);
        self::assertArrayNotHasKey('link_key', $updates[1]['params'][0], 'a plain save keeps the links');
    }

    public function testUntickingDefaultClearsIt(): void
    {
        $this->admin()->save(2, ['name' => 'Company', 'code' => 'B', 'is_default' => 'N'], 'en', ['en']);

        self::assertSame('N', $this->db->writes('UPDATE ?:euph_profile_types SET ?u')[0]['params'][0]['is_default'] ?? null);
    }

    public function testDeletingATypeMovesItsCustomersToTheStandardType(): void
    {
        $this->db->columns['SELECT user_id FROM ?:users WHERE euph_profile_type'] = [5, '8'];

        $result = $this->admin()->delete(2, 'en');

        self::assertSame([], $result['errors']);
        self::assertSame([5, 8], $result['moved_user_ids']);
        self::assertSame('U', $result['standard']?->code);
        self::assertSame(['U', 'B'], $this->db->writes('UPDATE ?:users SET euph_profile_type')[0]['params']);
        self::assertSame([], $this->db->writes('UPDATE ?:orders'), 'orders keep the type they were placed with');
        self::assertCount(1, $this->db->writes('DELETE FROM ?:euph_profile_type_fields WHERE type_id'));
        self::assertCount(1, $this->db->writes('DELETE FROM ?:euph_profile_types WHERE type_id'));
    }

    public function testAStandardTypeCannotBeDeleted(): void
    {
        self::assertSame(['euph_profile_types.error_standard_type_delete'], $this->admin()->delete(1, 'en')['errors']);
        self::assertSame([], $this->db->writes('DELETE'));
    }

    public function testTheListSavesPositionsAndTheDefault(): void
    {
        $this->admin()->saveList(['2' => ['position' => '15'], '3' => ['position' => 20], 'x' => ['position' => 1]], 3, UserKind::Customer);

        $positions = array_map(static fn (array $write): array => $write['params'], $this->db->writes('SET position'));
        self::assertSame([[15, 2], [20, 3]], $positions);
        self::assertSame(['Y', 3, 'C'], $this->db->writes("SET is_default = ?s WHERE type_id")[0]['params']);
    }
}
