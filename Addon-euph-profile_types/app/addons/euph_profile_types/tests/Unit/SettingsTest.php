<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\CheckoutChoice;
use Tygh\Addons\EuphProfileTypes\Enum\ChoiceView;
use Tygh\Addons\EuphProfileTypes\Enum\TypeChangePolicy;
use Tygh\Addons\EuphProfileTypes\Enum\TypesFor;
use Tygh\Addons\EuphProfileTypes\Settings;

final class SettingsTest extends TestCase
{
    public function testTheSettingsOfTheAddonAreRead(): void
    {
        $settings = Settings::fromArray([
            'types_for' => 'customers',
            'type_change' => 'once',
            'group_status' => 'P',
            'hide_standard_types' => 'Y',
            'choice_view' => 'select',
            'reload_on_change' => 'Y',
            'restrict_login' => 'Y',
            'checkout_choice' => 'guests',
            'vendor_type_in_product' => 'Y',
        ]);

        self::assertSame(TypesFor::Customers, $settings->typesFor);
        self::assertSame(TypeChangePolicy::Once, $settings->typeChange);
        self::assertSame('P', $settings->groupStatus);
        self::assertTrue($settings->hideStandardTypes);
        self::assertSame(ChoiceView::Select, $settings->choiceView);
        self::assertTrue($settings->reloadOnChange);
        self::assertTrue($settings->restrictLogin);
        self::assertSame(CheckoutChoice::GuestsOnly, $settings->checkoutChoice);
        self::assertTrue($settings->vendorTypeInProduct);
    }

    public function testMissingOrUnknownValuesFallBackToTheDefaultsOfAddonXml(): void
    {
        self::assertEquals(new Settings(), Settings::fromArray(null));
        $settings = Settings::fromArray(['types_for' => '?', 'group_status' => 'X', 'type_change' => 'sometimes']);
        self::assertSame(TypesFor::Both, $settings->typesFor);
        self::assertSame('A', $settings->groupStatus);
        self::assertSame(TypeChangePolicy::Never, $settings->typeChange);
    }

    public function testCast(): void
    {
        self::assertSame(12, Cast::int(' 12 '));
        self::assertSame(0, Cast::int('12abc'));
        self::assertSame(-3, Cast::int('-3'));
        self::assertSame('7', Cast::string(7));
        self::assertSame('', Cast::string(['x']));
        self::assertTrue(Cast::flag('Y'));
        self::assertFalse(Cast::flag('N'));
        self::assertSame([3, 1], Cast::idList('3,1,3,0,-2,x'));
        self::assertSame(['1' => 'a'], Cast::stringMap([1 => 'a']));
    }
}
