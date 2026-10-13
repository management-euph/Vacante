<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
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
}
