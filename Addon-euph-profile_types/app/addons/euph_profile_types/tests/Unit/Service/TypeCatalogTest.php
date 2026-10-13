<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Enum\TypesFor;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;
use Tygh\Addons\EuphProfileTypes\Repository\ProfileTypeRepository;
use Tygh\Addons\EuphProfileTypes\Service\TypeCatalog;
use Tygh\Addons\EuphProfileTypes\Settings;
use Tygh\Addons\EuphProfileTypes\Tests\Support\FakeDatabase;
use Tygh\Addons\EuphProfileTypes\Tests\Support\Types;

final class TypeCatalogTest extends TestCase
{
    private static function catalog(Settings $settings = new Settings(), bool $multiVendor = true, ?FakeDatabase $db = null): TypeCatalog
    {
        return new TypeCatalog(new ProfileTypeRepository($db ?? Types::database()), $settings, $multiVendor);
    }

    /**
     * @param list<ProfileType> $types
     *
     * @return list<string>
     */
    private static function codes(array $types): array
    {
        return array_map(static fn (ProfileType $type): string => $type->code, $types);
    }

    public function testTheChoiceOffersActiveTypesThatWorkHere(): void
    {
        $catalog = self::catalog();

        self::assertSame(['U', 'B', 'P'], self::codes($catalog->selectable(UserKind::Customer, 1, 'en')), 'hidden and disabled types are not offered');
        self::assertSame(['S'], self::codes($catalog->selectable(UserKind::Vendor, 1, 'en')));
    }

    public function testHidingTheStandardTypesLeavesTheStoresOwn(): void
    {
        $catalog = self::catalog(new Settings(hideStandardTypes: true));

        self::assertSame(['B', 'P'], self::codes($catalog->selectable(UserKind::Customer, 1, 'en')));
        self::assertSame(['S'], self::codes($catalog->selectable(UserKind::Vendor, 1, 'en')), 'no own vendor type: the standard one stays');
    }

    public function testTheDefaultTypeIsTheMarkedOneTheFirstOfferedOrTheStandard(): void
    {
        self::assertSame('B', self::catalog()->defaultType(UserKind::Customer, 1, 'en')?->code);

        $db = new FakeDatabase();
        $db->rows['FROM ?:euph_profile_types AS t'] = [
            Types::row(1, 'U', 'C', ['is_standard' => 'Y', 'is_default' => 'Y']),
            Types::row(2, 'B', 'C', ['position' => 5]),
            Types::row(3, 'P', 'C', ['position' => 9]),
        ];
        $hidden = self::catalog(new Settings(hideStandardTypes: true), true, $db);
        self::assertSame('B', $hidden->defaultType(UserKind::Customer, 1, 'en')?->code, 'with the standard types hidden: the first of the store\'s own');

        $db = new FakeDatabase();
        $db->rows['FROM ?:euph_profile_types AS t'] = [
            Types::row(1, 'U', 'C', ['is_standard' => 'Y', 'status' => 'D']),
        ];
        self::assertSame('U', self::catalog(new Settings(), true, $db)->defaultType(UserKind::Customer, 1, 'en')?->code, 'nothing offered: the standard type');
    }

    public function testARegistrationLinkMayGiveAHiddenTypeOnItsStorefrontOnly(): void
    {
        $catalog = self::catalog();

        self::assertSame('D', $catalog->assignableOnStorefront(UserKind::Customer, 'D', 2, 'en')?->code);
        self::assertNull($catalog->assignableOnStorefront(UserKind::Customer, 'D', 1, 'en'), 'other storefront');
        self::assertNull($catalog->assignableOnStorefront(UserKind::Customer, 'O', 1, 'en'), 'disabled');
        self::assertNull($catalog->assignableOnStorefront(UserKind::Customer, 'S', 1, 'en'), 'a vendor type');
        self::assertNull($catalog->assignableOnStorefront(UserKind::Customer, 'X', 1, 'en'), 'no such type');
    }

    public function testTheAdministratorGivesAnyTypeButADisabledOne(): void
    {
        $catalog = self::catalog();

        self::assertSame('D', $catalog->assignableByAdmin(UserKind::Customer, 'd', 'en')?->code, 'codes are case-insensitive');
        self::assertNull($catalog->assignableByAdmin(UserKind::Customer, 'O', 'en'));
    }

    public function testVendorTypesNeedMultiVendorAndTheSetting(): void
    {
        self::assertTrue(self::catalog()->isEnabled(UserKind::Vendor));
        self::assertFalse(self::catalog(new Settings(), false)->isEnabled(UserKind::Vendor));
        self::assertFalse(self::catalog(new Settings(typesFor: TypesFor::Customers))->isEnabled(UserKind::Vendor));
        self::assertFalse(self::catalog(new Settings(typesFor: TypesFor::Vendors))->isEnabled(UserKind::Customer));
    }

    public function testTheStorefrontRuleOfACode(): void
    {
        $catalog = self::catalog();

        self::assertTrue($catalog->isCodeOnStorefront('D', 2, 'en'));
        self::assertFalse($catalog->isCodeOnStorefront('D', 3, 'en'));
        self::assertFalse($catalog->isCodeOnStorefront('NOPE', 3, 'en'));
    }

    public function testOptionsAreCodeToName(): void
    {
        $catalog = self::catalog();

        self::assertSame(['U' => 'Customer', 'B' => 'Company', 'P' => 'Private person'], TypeCatalog::options($catalog->selectable(UserKind::Customer, 1, 'en')));
    }
}
