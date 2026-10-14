<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Model\FieldRule;
use Tygh\Addons\EuphProfileTypes\Model\FieldRuleSet;
use Tygh\Addons\EuphProfileTypes\Service\FieldVisibility;

/**
 * The spec's example: the tax number is asked of a legal entity only, the
 * date of birth of a private person only; the phone of everybody, by its
 * general checkboxes.
 */
final class FieldVisibilityTest extends TestCase
{
    private const COMPANY = 2;
    private const PRIVATE = 3;
    private const PHONE = 30;
    private const TAX_NUMBER = 31;
    private const BIRTHDAY = 32;
    private const FAX = 33;

    private FieldVisibility $visibility;

    protected function setUp(): void
    {
        $this->visibility = new FieldVisibility(new FieldRuleSet([
            new FieldRule(self::COMPANY, self::TAX_NUMBER, profileShow: true, profileRequired: true, registrationShow: true, checkoutShow: true, checkoutRequired: true),
            new FieldRule(self::PRIVATE, self::BIRTHDAY, profileShow: true, registrationShow: false, checkoutShow: true),
        ]));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function fields(): array
    {
        return [
            'C' => [
                self::PHONE => self::field(self::PHONE, 'phone', ['profile_show' => 'Y', 'profile_required' => 'Y', 'checkout_show' => 'Y', 'checkout_required' => 'N']),
                self::TAX_NUMBER => self::field(self::TAX_NUMBER, 'company_tax', ['profile_show' => 'N', 'checkout_show' => 'N']),
                self::BIRTHDAY => self::field(self::BIRTHDAY, 'birthday', ['profile_show' => 'Y', 'checkout_show' => 'Y']),
                self::FAX => self::field(self::FAX, 'fax', ['profile_show' => 'N', 'checkout_show' => 'N']),
            ],
            'E' => [['field_name' => 'email', 'required' => 'Y']],
        ];
    }

    /**
     * @param array<string, string> $flags
     *
     * @return array<string, mixed>
     */
    private static function field(int $id, string $name, array $flags): array
    {
        return array_merge(['field_id' => $id, 'field_name' => $name, 'section' => 'C', 'required' => 'N'], $flags);
    }

    /**
     * @param array<array-key, mixed> $fields
     *
     * @return list<int|string>
     */
    private static function ids(array $fields, string $section = 'C'): array
    {
        return array_keys(is_array($fields[$section] ?? null) ? $fields[$section] : []);
    }

    public function testACompanySeesTheTaxNumberAndNotTheBirthday(): void
    {
        $fields = $this->visibility->apply(self::fields(), self::COMPANY, FieldArea::Profile, true);

        self::assertSame([self::PHONE, self::TAX_NUMBER], self::ids($fields));
        self::assertSame('Y', $fields['C'][self::TAX_NUMBER]['required']);
        self::assertSame('Y', $fields['C'][self::TAX_NUMBER]['profile_show'], 'the general columns tell the templates the field shows');
    }

    public function testAPrivatePersonSeesTheBirthdayAndNotTheTaxNumber(): void
    {
        $fields = $this->visibility->apply(self::fields(), self::PRIVATE, FieldArea::Profile, true);

        self::assertSame([self::PHONE, self::BIRTHDAY], self::ids($fields));
        self::assertSame('N', $fields['C'][self::BIRTHDAY]['required']);
    }

    public function testRegistrationHasItsOwnCheckbox(): void
    {
        $private = $this->visibility->apply(self::fields(), self::PRIVATE, FieldArea::Registration, true);
        $company = $this->visibility->apply(self::fields(), self::COMPANY, FieldArea::Registration, true);

        self::assertSame([self::PHONE], self::ids($private), 'the birthday lives in the account without making registration longer');
        self::assertSame([self::PHONE, self::TAX_NUMBER], self::ids($company));
        self::assertSame('Y', $company['C'][self::TAX_NUMBER]['required'], 'required in the profile, so required at registration');
    }

    public function testCheckoutUsesTheCheckoutCheckboxes(): void
    {
        $fields = $this->visibility->apply(self::fields(), self::COMPANY, FieldArea::Checkout, true);

        self::assertSame([self::PHONE, self::TAX_NUMBER], self::ids($fields));
        self::assertSame('N', $fields['C'][self::PHONE]['required'], 'the general checkout "required" of a field no type governs');
        self::assertSame('N', $fields['C'][self::PHONE]['checkout_required']);
        self::assertSame('Y', $fields['C'][self::TAX_NUMBER]['checkout_required']);
    }

    public function testAFieldNoTypeGovernsKeepsItsGeneralCheckboxes(): void
    {
        $filtered = $this->visibility->apply(self::fields(), self::COMPANY, FieldArea::Profile, true);
        $alreadyFiltered = $this->visibility->apply(self::fields(), self::COMPANY, FieldArea::Profile, false);

        self::assertNotContains(self::FAX, self::ids($filtered), 'general "show" is off');
        self::assertContains(self::FAX, self::ids($alreadyFiltered), 'CS-Cart already applied the general checkbox in its query');
    }

    public function testATypeWithoutRulesSeesNoGovernedField(): void
    {
        $fields = $this->visibility->apply(self::fields(), 99, FieldArea::Profile, true);

        self::assertSame([self::PHONE], self::ids($fields));
    }

    public function testEssentialsAndTheTypeChoiceAreLeftAlone(): void
    {
        $fields = self::fields();
        $fields['C'] = ['euph_profile_type' => ['field_id' => 'euph_profile_type', 'field_name' => 'euph_profile_type']] + $fields['C'];

        $applied = $this->visibility->apply($fields, self::COMPANY, FieldArea::Profile, true);

        self::assertSame($fields['E'], $applied['E']);
        self::assertSame('euph_profile_type', self::ids($applied)[0]);
    }

    public function testASectionLeftEmptyIsDropped(): void
    {
        $fields = ['B' => [self::FAX => self::field(self::FAX, 'b_fax', ['profile_show' => 'N'])]];

        self::assertSame([], $this->visibility->apply($fields, self::COMPANY, FieldArea::Profile, true));
    }

    public function testTheStorefrontCardIsNeverRequired(): void
    {
        $visibility = new FieldVisibility(new FieldRuleSet([new FieldRule(6, 40, storefrontShow: true, profileShow: true, profileRequired: true)]));
        $fields = $visibility->apply(['C' => [40 => self::field(40, 'company', ['storefront_show' => 'N'])]], 6, FieldArea::Storefront, true);

        self::assertSame('N', $fields['C'][40]['required']);
        self::assertSame('Y', $fields['C'][40]['storefront_show']);
    }
}
