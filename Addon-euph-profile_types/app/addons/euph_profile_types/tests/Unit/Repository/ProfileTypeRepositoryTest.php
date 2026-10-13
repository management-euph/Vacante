<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Repository\ProfileTypeRepository;
use Tygh\Addons\EuphProfileTypes\Tests\Support\Types;

final class ProfileTypeRepositoryTest extends TestCase
{
    public function testTypesAreReadOncePerLanguageWithTheirLinks(): void
    {
        $db = Types::database();
        $repository = new ProfileTypeRepository($db);

        $types = $repository->all('en');
        $repository->all('en');

        self::assertCount(6, $types);
        self::assertSame([7], $types[1]->usergroupIds);
        self::assertSame([2], $repository->findByCode('d', 'en')?->storefrontIds);
        self::assertCount(2, array_filter($db->read, static fn (string $query): bool => str_contains($query, 'euph_profile_type')), 'one query for the types, one for the links');
    }

    public function testFinders(): void
    {
        $repository = new ProfileTypeRepository(Types::database());

        self::assertSame('B', $repository->find(2, 'en')?->code);
        self::assertNull($repository->find(42, 'en'));
        self::assertNull($repository->findByCode('', 'en'));
        self::assertSame('U', $repository->standard(UserKind::Customer, 'en')?->code);
        self::assertSame('S', $repository->standard(UserKind::Vendor, 'en')?->code);
        self::assertCount(5, $repository->ofKind(UserKind::Customer, 'en'));
    }

    public function testSavingANewTypeNamesItInEveryLanguageAndStoresItsLinks(): void
    {
        $db = Types::database();
        $db->insertIds = [11];
        $repository = new ProfileTypeRepository($db);

        $id = $repository->save(0, ['code' => 'W', 'user_type' => 'C'], 'Wholesale', '', 'en', ['en', 'ro'], ['G' => [7, 9], 'S' => []]);

        self::assertSame(11, $id);
        self::assertCount(2, $db->writes('REPLACE INTO ?:euph_profile_type_descriptions'));
        self::assertCount(2, $db->writes('DELETE FROM ?:euph_profile_type_links'), 'the given link kinds are replaced');
        self::assertCount(2, $db->writes('REPLACE INTO ?:euph_profile_type_links'));
    }

    public function testUsageCountsCustomersOrVendorsByCode(): void
    {
        $db = Types::database();
        $db->rows['FROM ?:users WHERE user_type'] = [['code' => 'B', 'cnt' => '12'], ['code' => 'U', 'cnt' => 3]];
        $db->rows['FROM ?:companies GROUP BY'] = [['code' => 'S', 'cnt' => 2]];
        $repository = new ProfileTypeRepository($db);

        self::assertSame(['B' => 12, 'U' => 3], $repository->usage(UserKind::Customer));
        self::assertSame(['S' => 2], $repository->usage(UserKind::Vendor));
    }
}
