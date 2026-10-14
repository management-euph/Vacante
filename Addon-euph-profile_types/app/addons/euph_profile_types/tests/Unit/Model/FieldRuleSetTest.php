<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Model\FieldRule;
use Tygh\Addons\EuphProfileTypes\Model\FieldRuleSet;

final class FieldRuleSetTest extends TestCase
{
    public function testAFieldIsGovernedOnlyOnceSomethingIsTickedForAType(): void
    {
        $set = new FieldRuleSet([
            new FieldRule(2, 10, profileShow: true),
            new FieldRule(3, 11),
        ]);

        self::assertTrue($set->isGoverned(10));
        self::assertFalse($set->isGoverned(11), 'a rule with nothing ticked is the same as no rule');
        self::assertFalse($set->isGoverned(12));
        self::assertSame([10], $set->governedFieldIds());
    }

    public function testRulesAreFoundByFieldAndType(): void
    {
        $company = new FieldRule(2, 10, profileShow: true);
        $private = new FieldRule(3, 10, checkoutShow: true);
        $other = new FieldRule(2, 20, checkoutShow: true);
        $set = new FieldRuleSet([$company, $private, $other]);

        self::assertSame($company, $set->ruleFor(10, 2));
        self::assertNull($set->ruleFor(10, 4));
        self::assertSame([2 => $company, 3 => $private], $set->rulesForField(10));
        self::assertSame([$company, $other], $set->rulesOfType(2));
    }

    public function testATypeDoesNotHaveTheGovernedFieldsItShowsNowhere(): void
    {
        $set = new FieldRuleSet([
            new FieldRule(2, 10, profileShow: true, checkoutShow: true), // company name: Company only
            new FieldRule(2, 11, checkoutShow: true),                    // CIF: Company, at checkout only
            new FieldRule(3, 12, registrationShow: true),                 // CNP: Private, at registration only
            new FieldRule(3, 13),                                         // nothing ticked: not governed
        ]);
        $areas = [FieldArea::Profile, FieldArea::Registration, FieldArea::Checkout];

        self::assertSame([12], $set->unseenBy(2, ...$areas));
        self::assertSame([10, 11], $set->unseenBy(3, ...$areas), 'no rule for the type: it does not have the field');
        self::assertSame([10, 11, 12], $set->unseenBy(9, ...$areas));
        self::assertSame([11, 12], $set->unseenBy(2, FieldArea::Profile), 'only the areas asked about count');
    }
}
