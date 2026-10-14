<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Model\FieldRule;
use Tygh\Addons\EuphProfileTypes\Model\FieldRuleSet;
use Tygh\Addons\EuphProfileTypes\Service\OrderFields;

final class OrderFieldsTest extends TestCase
{
    /**
     * Company (2) has the company name (CS-Cart's own field 30) and a CIF
     * (custom field 41); Private (3) has a CNP (custom field 42). The phone
     * (43) is on its general checkboxes.
     */
    private static function rules(): FieldRuleSet
    {
        return new FieldRuleSet([
            new FieldRule(2, 30, profileShow: true, checkoutShow: true),
            new FieldRule(2, 41, profileShow: true, checkoutShow: true),
            new FieldRule(3, 42, profileShow: true, checkoutShow: true),
        ]);
    }

    /**
     * An order placed as a private person by a customer who used to be a
     * company: the profile still held the company name and the CIF.
     *
     * @return array<string, mixed>
     */
    private static function order(): array
    {
        return [
            'order_id' => 100,
            'email' => 'ana@example.ro',
            'firstname' => 'Ana',
            'company' => 'Old Company SRL',
            'fields' => [41 => 'RO12345678', 42 => '2900101123456', 43 => '0722000000'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function rows(): array
    {
        return [
            30 => ['field_id' => 30, 'field_name' => 'company', 'is_default' => 'Y'],
            41 => ['field_id' => 41, 'field_name' => 'cif', 'is_default' => 'N'],
            42 => ['field_id' => 42, 'field_name' => 'cnp', 'is_default' => 'N'],
            99 => ['field_id' => 99, 'field_name' => 'email', 'is_default' => 'Y'],
        ];
    }

    public function testATypeDoesNotHaveTheFieldsItShowsNowhere(): void
    {
        self::assertSame([30, 41], OrderFields::unseen(self::rules(), 3));
        self::assertSame([42], OrderFields::unseen(self::rules(), 2));
    }

    public function testAnOrderKeepsOnlyTheValuesOfTheFieldsOfItsType(): void
    {
        $rows = self::rows();
        $private = OrderFields::withoutUnseen(self::order(), [30 => $rows[30], 41 => $rows[41]]);

        self::assertSame('', $private['company'], "CS-Cart's own column is blanked: no company invoice");
        self::assertSame([42 => '2900101123456', 43 => '0722000000'], $private['fields'], 'the CIF is left out, the CNP and the phone stay');
        self::assertSame('Ana', $private['firstname']);

        $company = OrderFields::withoutUnseen(self::order(), [42 => $rows[42]]);
        self::assertSame('Old Company SRL', $company['company']);
        self::assertSame([41 => 'RO12345678', 43 => '0722000000'], $company['fields']);
    }

    public function testTheEmailAndWhatTheOrderDoesNotHoldAreLeftAlone(): void
    {
        $rows = self::rows();
        $order = OrderFields::withoutUnseen(['email' => 'ana@example.ro'], [99 => $rows[99], 30 => $rows[30], 41 => $rows[41]]);

        self::assertSame(['email' => 'ana@example.ro'], $order, 'no company key and no fields: nothing added');
    }
}
