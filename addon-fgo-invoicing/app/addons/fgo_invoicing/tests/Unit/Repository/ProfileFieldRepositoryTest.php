<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Repository\ProfileFieldRepository;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;

/**
 * The catalog the resolver auto-detects from. Pinned: custom text fields only,
 * every language's description kept (the resolver runs in any language), and
 * exactly one query however often it is asked — it is consulted per issuance.
 */
#[CoversClass(ProfileFieldRepository::class)]
final class ProfileFieldRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testRowsAreGroupedPerFieldWithEveryLanguagesDescription(): void
    {
        DbStub::$rows = [
            ['field_id' => '36', 'field_name' => '', 'section' => 'b', 'description' => 'Cod fiscal'],
            ['field_id' => '36', 'field_name' => '', 'section' => 'b', 'description' => 'Tax ID'],
            ['field_id' => '36', 'field_name' => '', 'section' => 'b', 'description' => ' Tax ID '],
            ['field_id' => '37', 'field_name' => ' s_cif ', 'section' => 'S', 'description' => null],
            ['field_id' => '38', 'field_name' => 'cnp', 'section' => 'C', 'description' => ''],
        ];

        self::assertSame([
            36 => ['field_name' => '', 'section' => 'B', 'descriptions' => ['Cod fiscal', 'Tax ID']],
            37 => ['field_name' => 's_cif', 'section' => 'S', 'descriptions' => []],
            38 => ['field_name' => 'cnp', 'section' => 'C', 'descriptions' => []],
        ], (new ProfileFieldRepository())->fields());
    }

    public function testOnlyCustomTextFieldsAreQueriedAndOnlyOnce(): void
    {
        DbStub::$rows = [['field_id' => 5, 'field_name' => '', 'section' => 'C', 'description' => 'CIF']];
        $repo = new ProfileFieldRepository();

        $first = $repo->fields();
        $second = $repo->fields();

        self::assertSame($first, $second);
        $calls = DbStub::calls('?:profile_fields');
        self::assertCount(1, $calls, 'cached for the lifetime of the instance');
        self::assertStringContainsString("f.is_default = 'N'", $calls[0]['query']);
        self::assertStringContainsString(
            "f.profile_type = 'U'",
            $calls[0]['query'],
            'customer fields only: a Multi-Vendor seller "CIF" field never carries an order value',
        );
        self::assertStringContainsString("d.object_type = 'F'", $calls[0]['query']);
        self::assertSame([ProfileFieldRepository::TEXT_FIELD_TYPES], $calls[0]['params']);
        self::assertSame(['I', 'T'], ProfileFieldRepository::TEXT_FIELD_TYPES);
    }

    public function testMalformedRowsAreSkipped(): void
    {
        DbStub::$rows = [
            ['field_id' => 0, 'description' => 'CIF'],
            ['field_id' => 'x', 'description' => 'CIF'],
            ['description' => 'no id'],
            ['field_id' => 9, 'description' => 'CUI'],
        ];

        self::assertSame(
            [9 => ['field_name' => '', 'section' => '', 'descriptions' => ['CUI']]],
            (new ProfileFieldRepository())->fields(),
        );
    }

    public function testNoCustomFieldsMeansAnEmptyCatalog(): void
    {
        self::assertSame([], (new ProfileFieldRepository())->fields());
    }
}
