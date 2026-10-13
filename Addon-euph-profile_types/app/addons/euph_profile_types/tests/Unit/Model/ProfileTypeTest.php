<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Enum\Activation;
use Tygh\Addons\EuphProfileTypes\Enum\TypeStatus;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;
use Tygh\Addons\EuphProfileTypes\Tests\Support\Types;

final class ProfileTypeTest extends TestCase
{
    public function testItIsBuiltFromAJoinedRowAndItsLinks(): void
    {
        $type = ProfileType::fromRow(
            Types::row(4, 'D', 'C', ['status' => 'H', 'activation' => 'A', 'is_default' => 'Y', 'name' => 'Dealer', 'description' => 'Resellers']),
            ['G' => [7, 8], 'S' => [2], 'P' => [3]],
        );

        self::assertSame(4, $type->id);
        self::assertSame('D', $type->code);
        self::assertSame(UserKind::Customer, $type->kind);
        self::assertSame(TypeStatus::Hidden, $type->status);
        self::assertSame(Activation::Instant, $type->activation);
        self::assertTrue($type->isDefault);
        self::assertFalse($type->isStandard);
        self::assertSame('Dealer', $type->name);
        self::assertSame('Resellers', $type->description);
        self::assertSame([7, 8], $type->usergroupIds);
        self::assertSame([2], $type->storefrontIds);
        self::assertSame([3], $type->vendorPlanIds);
    }

    public function testUnknownValuesFallBackToSafeOnes(): void
    {
        $type = ProfileType::fromRow(Types::row(9, 'X', 'Z', ['status' => '?', 'activation' => '?']));

        self::assertSame(UserKind::Customer, $type->kind);
        self::assertSame(TypeStatus::Disabled, $type->status, 'an unknown status must not make a type selectable');
        self::assertSame(Activation::StoreSetting, $type->activation);
    }

    public function testAnEmptyStorefrontListMeansEveryStorefront(): void
    {
        $everywhere = ProfileType::fromRow(Types::row(2, 'B'));
        $somewhere = ProfileType::fromRow(Types::row(4, 'D'), ['S' => [2, 3]]);

        self::assertTrue($everywhere->worksOn(1));
        self::assertTrue($somewhere->worksOn(2));
        self::assertFalse($somewhere->worksOn(1));
        self::assertTrue($somewhere->worksOn(0), 'no storefront known (admin): no restriction');
    }

    public function testStatusDecidesWhetherTheTypeIsOfferedAndGiven(): void
    {
        $active = ProfileType::fromRow(Types::row(1, 'A1'));
        $hidden = ProfileType::fromRow(Types::row(2, 'H1', 'C', ['status' => 'H']));
        $disabled = ProfileType::fromRow(Types::row(3, 'D1', 'C', ['status' => 'D']));

        self::assertTrue($active->isSelectable());
        self::assertTrue($active->isAssignable());
        self::assertFalse($hidden->isSelectable());
        self::assertTrue($hidden->isAssignable());
        self::assertFalse($disabled->isSelectable());
        self::assertFalse($disabled->isAssignable());
    }

    public function testTheNameFallsBackToTheCode(): void
    {
        self::assertSame('B', ProfileType::fromRow(Types::row(2, 'B', 'C', ['name' => '']))->displayName());
    }

    public function testToArrayRoundTrips(): void
    {
        $type = ProfileType::fromRow(Types::row(6, 'S', 'V', ['is_standard' => 'Y']), ['G' => [5], 'P' => [1, 2]]);
        $array = $type->toArray();

        self::assertSame('V', $array['user_type']);
        self::assertSame('Y', $array['is_standard']);
        self::assertSame([5], $array['usergroup_ids']);
        self::assertSame([1, 2], $array['vendor_plan_ids']);
        self::assertEquals($type, ProfileType::fromRow($array, ['G' => $array['usergroup_ids'], 'S' => $array['storefront_ids'], 'P' => $array['vendor_plan_ids']]));
    }

    public function testATypeNeedsAnIdAndACode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProfileType::fromRow(Types::row(0, 'B'));
    }

    public function testATypeNeedsACode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProfileType::fromRow(Types::row(3, ''));
    }
}
