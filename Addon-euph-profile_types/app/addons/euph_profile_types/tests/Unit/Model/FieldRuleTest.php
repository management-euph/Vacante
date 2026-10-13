<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Model\FieldRule;

final class FieldRuleTest extends TestCase
{
    public function testFlagsAreReadAsYesNo(): void
    {
        $rule = FieldRule::fromFlags(2, 10, ['profile_show' => 'Y', 'profile_required' => 'N', 'checkout_show' => '1', 'storefront_show' => true]);

        self::assertTrue($rule->profileShow);
        self::assertFalse($rule->profileRequired);
        self::assertFalse($rule->registrationShow, 'a missing flag is N');
        self::assertTrue($rule->checkoutShow);
        self::assertTrue($rule->storefrontShow);
    }

    public function testNothingTickedIsEmpty(): void
    {
        self::assertTrue((new FieldRule(1, 1))->isEmpty());
        self::assertFalse((new FieldRule(1, 1, checkoutRequired: true))->isEmpty());
    }

    /**
     * @return iterable<string, array{FieldRule, FieldArea, bool, bool}>
     */
    public static function areas(): iterable
    {
        $rule = new FieldRule(1, 1, profileShow: true, profileRequired: true, registrationShow: true, checkoutShow: true, checkoutRequired: false, storefrontShow: true);
        yield 'profile' => [$rule, FieldArea::Profile, true, true];
        yield 'registration uses the profile\'s required' => [$rule, FieldArea::Registration, true, true];
        yield 'checkout has its own required' => [$rule, FieldArea::Checkout, true, false];
        yield 'storefront is never required' => [$rule, FieldArea::Storefront, true, false];

        $hidden = new FieldRule(1, 1, profileShow: false, profileRequired: true, checkoutShow: false, checkoutRequired: true);
        yield 'a hidden field is not required in the profile' => [$hidden, FieldArea::Profile, false, false];
        yield 'a hidden field is not required at checkout' => [$hidden, FieldArea::Checkout, false, false];
        yield 'not shown at registration' => [$hidden, FieldArea::Registration, false, false];
    }

    #[DataProvider('areas')]
    public function testShowsAndRequiresPerArea(FieldRule $rule, FieldArea $area, bool $shows, bool $requires): void
    {
        self::assertSame($shows, $rule->shows($area));
        self::assertSame($requires, $rule->requires($area));
    }

    public function testTheGeneralCheckboxesBecomeARuleWithRegistrationLikeTheProfile(): void
    {
        $rule = FieldRule::fromGeneralFlags(3, 12, [
            'profile_show' => 'Y',
            'profile_required' => 'Y',
            'checkout_show' => 'N',
            'checkout_required' => 'N',
            'storefront_show' => 'Y',
        ]);

        self::assertTrue($rule->registrationShow);
        self::assertSame('YYYNNY', $rule->signature());
    }

    public function testRowAndSignatureFollowTheFlagOrder(): void
    {
        $rule = new FieldRule(4, 9, profileShow: true, registrationShow: true, checkoutShow: true, checkoutRequired: true);

        self::assertSame([
            'type_id' => 4,
            'field_id' => 9,
            'profile_show' => 'Y',
            'profile_required' => 'N',
            'registration_show' => 'Y',
            'checkout_show' => 'Y',
            'checkout_required' => 'Y',
            'storefront_show' => 'N',
        ], $rule->toRow());
        self::assertSame('YNYYYN', $rule->signature());
        self::assertEquals($rule, FieldRule::fromRow($rule->toRow()));
    }

    public function testWithersKeepTheFlags(): void
    {
        $rule = new FieldRule(4, 9, checkoutShow: true);

        self::assertSame(['type_id' => 5, 'field_id' => 11], array_slice($rule->withTypeId(5)->withFieldId(11)->toRow(), 0, 2));
        self::assertTrue($rule->withTypeId(5)->checkoutShow);
    }

    public function testARuleNeedsATypeAndAField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FieldRule(1, 0);
    }
}
