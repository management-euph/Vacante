<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Repository\FieldRuleRepository;
use Tygh\Addons\EuphProfileTypes\Repository\ProfileTypeRepository;
use Tygh\Addons\EuphProfileTypes\Service\LegacyImporter;
use Tygh\Addons\EuphProfileTypes\Tests\Support\FakeDatabase;
use Tygh\Addons\EuphProfileTypes\Tests\Support\Types;

/**
 * 1.x had type B ("Company") with its own copy of the customer fields: a
 * copy of "phone" (matched to the common field 30) and "company_tax", which
 * only type B had (it joins the common list).
 */
final class LegacyImporterTest extends TestCase
{
    private FakeDatabase $db;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->db->tables = ['?:cp_profile_types', '?:cp_profile_type_descriptions'];
        $this->db->tableColumns = [
            '?:users' => ['cp_profile_type', 'euph_profile_type'],
            '?:orders' => ['cp_profile_type', 'euph_profile_type'],
        ];
        $this->db->rows['FROM ?:euph_profile_types AS t'] = [Types::row(1, 'U', 'C', ['is_standard' => 'Y', 'name' => 'Customer'])];
        $this->db->rows['FROM ?:cp_profile_types ORDER BY'] = [
            ['type_id' => 7, 'code' => 'U', 'user_type' => 'C', 'status' => 'A', 'position' => 0, 'is_default' => 1, 'usergroup_ids' => ''],
            ['type_id' => 8, 'code' => 'b', 'user_type' => 'C', 'status' => 'A', 'position' => 10, 'is_default' => 0, 'usergroup_ids' => '4,5'],
            ['type_id' => 9, 'code' => 'V', 'user_type' => 'V', 'status' => 'A', 'position' => 0, 'is_default' => 0, 'usergroup_ids' => ''],
        ];
        $this->db->rows['FROM ?:cp_profile_type_descriptions'] = static fn (int $oldId): array => match ($oldId) {
            8 => [['lang_code' => 'en', 'name' => 'Company', 'description' => ''], ['lang_code' => 'ro', 'name' => 'Firmă', 'description' => '']],
            default => [],
        };
        $this->db->rows['FROM ?:profile_fields WHERE profile_type = ?s ORDER BY'] = static fn (string $code): array => $code === 'B' ? [
            ['field_id' => 130, 'field_name' => 'phone', 'section' => 'C', 'profile_type' => 'B', 'is_default' => 'N', 'field_type' => 'P', 'profile_show' => 'Y', 'profile_required' => 'Y', 'checkout_show' => 'Y', 'checkout_required' => 'Y', 'storefront_show' => 'N'],
            ['field_id' => 131, 'field_name' => 'company_tax', 'section' => 'C', 'profile_type' => 'B', 'is_default' => 'N', 'field_type' => 'I', 'profile_show' => 'Y', 'profile_required' => 'Y', 'checkout_show' => 'N', 'checkout_required' => 'N', 'storefront_show' => 'N'],
        ] : [];
        $this->db->rows['FROM ?:profile_fields WHERE profile_type = ?s'] = [
            ['field_id' => 30, 'field_name' => 'phone', 'section' => 'C', 'profile_type' => 'U', 'is_default' => 'N', 'field_type' => 'P', 'profile_show' => 'Y', 'profile_required' => 'N', 'checkout_show' => 'Y', 'checkout_required' => 'N', 'storefront_show' => 'N'],
        ];
        $this->db->rows['FROM ?:profile_fields_data WHERE field_id'] = [
            ['object_id' => 50, 'object_type' => 'U', 'field_id' => 130, 'value' => '+40 700 000 000'],
        ];
        $this->db->rows['FROM ?:euph_profile_type_fields'] = [];
        $this->db->insertIds = [2];
    }

    private function importer(): LegacyImporter
    {
        return new LegacyImporter($this->db, new ProfileTypeRepository($this->db), new FieldRuleRepository($this->db), false);
    }

    public function testTypesCustomersAndFieldsAreCarriedOver(): void
    {
        $stats = $this->importer()->import('en', ['en', 'ro']);

        self::assertSame(1, $stats['types'], 'B is new; U is the standard type; V is a vendor type of a store without Multi-Vendor');
        self::assertSame('B', $this->db->writes('INSERT INTO ?:euph_profile_types')[0]['params'][0]['code']);
        $names = array_map(static fn (array $write): string => $write['params'][0]['lang_code'] . ':' . $write['params'][0]['name'], $this->db->writes('REPLACE INTO ?:euph_profile_type_descriptions'));
        self::assertContains('ro:Firmă', $names);
        self::assertContains('en:Customer', $names, 'the standard type keeps its name: 1.x had none for it');
        self::assertNotContains('en:U', $names);
        self::assertCount(2, $this->db->writes('REPLACE INTO ?:euph_profile_type_links'));

        self::assertCount(2, $this->db->writes('SET euph_profile_type = UPPER(cp_profile_type)'));

        self::assertSame(1, $stats['adopted_fields'], 'company_tax joins the common list');
        self::assertSame(['U', 131], $this->db->writes('UPDATE ?:profile_fields SET profile_type')[0]['params']);

        $rules = array_map(static fn (array $write): array => [$write['params'][0]['type_id'], $write['params'][0]['field_id']], $this->db->writes('REPLACE INTO ?:euph_profile_type_fields'));
        self::assertSame([[2, 30], [2, 131], [1, 30]], $rules, 'B gets its checkboxes on the common fields; the standard type keeps the general ones');

        self::assertSame(1, $stats['values']);
        self::assertSame([50, 'U', 30, '+40 700 000 000'], $this->db->writes('INSERT IGNORE INTO ?:profile_fields_data')[0]['params']);
    }

    public function testNothingHappensWithoutTheOldTables(): void
    {
        $this->db->tables = [];

        self::assertSame(['types' => 0, 'users' => 0, 'orders' => 0, 'companies' => 0, 'rules' => 0, 'adopted_fields' => 0, 'values' => 0], $this->importer()->import('en', ['en']));
        self::assertSame([], $this->db->executed);
    }
}
