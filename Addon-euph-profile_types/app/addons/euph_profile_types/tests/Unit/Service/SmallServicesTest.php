<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Enum\CheckoutChoice;
use Tygh\Addons\EuphProfileTypes\Enum\TypeChangePolicy;
use Tygh\Addons\EuphProfileTypes\Enum\TypesFor;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;
use Tygh\Addons\EuphProfileTypes\Service\TypeChangeGuard;
use Tygh\Addons\EuphProfileTypes\Service\UsergroupDiff;

final class SmallServicesTest extends TestCase
{
    public function testCodesAreUpperCaseLettersDigitsAndUnderscores(): void
    {
        self::assertSame('DEALER_2', CodeValidator::normalize('  dealer_2 '));
        self::assertTrue(CodeValidator::isWellFormed('DEALER_2'));
        self::assertTrue(CodeValidator::isWellFormed('B'));
        self::assertFalse(CodeValidator::isWellFormed(''));
        self::assertFalse(CodeValidator::isWellFormed('A-B'));
        self::assertFalse(CodeValidator::isWellFormed(str_repeat('A', 33)));
    }

    public function testASuggestedCodeIsTheFirstFreeLetter(): void
    {
        self::assertSame('C', CodeValidator::suggest(['a', 'B', 'U', 'S']));
        self::assertSame('T1', CodeValidator::suggest(range('A', 'Z')));
        self::assertSame('T2', CodeValidator::suggest([...range('A', 'Z'), 'T1']));
    }

    /**
     * @return iterable<string, array{TypeChangePolicy, string, string, int, bool}>
     */
    public static function changes(): iterable
    {
        yield 'always' => [TypeChangePolicy::Always, 'B', 'P', 5, true];
        yield 'never' => [TypeChangePolicy::Never, 'B', 'P', 0, false];
        yield 'once, not yet changed' => [TypeChangePolicy::Once, 'B', 'P', 0, true];
        yield 'once, already changed' => [TypeChangePolicy::Once, 'B', 'P', 1, false];
        yield 'keeping the type is no change' => [TypeChangePolicy::Never, 'B', 'b', 0, true];
        yield 'no type yet: choosing one is no change' => [TypeChangePolicy::Never, '', 'P', 3, true];
    }

    #[DataProvider('changes')]
    public function testTheChangePolicy(TypeChangePolicy $policy, string $current, string $new, int $changes, bool $allowed): void
    {
        self::assertSame($allowed, TypeChangeGuard::allows($policy, $current, $new, $changes));
    }

    public function testTheChoiceIsOfferedInTheAccountOnlyWhenAChangeIsPossible(): void
    {
        self::assertTrue(TypeChangeGuard::mayChange(TypeChangePolicy::Once, 'B', 0));
        self::assertFalse(TypeChangeGuard::mayChange(TypeChangePolicy::Once, 'B', 1));
        self::assertFalse(TypeChangeGuard::mayChange(TypeChangePolicy::Never, 'B', 0));
        self::assertTrue(TypeChangeGuard::mayChange(TypeChangePolicy::Never, '', 0));
    }

    public function testAGroupBothTypesGrantIsLeftAsItIs(): void
    {
        self::assertSame(['remove' => [1], 'add' => [4]], UsergroupDiff::between([1, 2, 3], [2, 3, 4]));
        self::assertSame(['remove' => [], 'add' => [5]], UsergroupDiff::between([], [5]));
    }

    public function testCheckoutChoice(): void
    {
        self::assertTrue(CheckoutChoice::Everybody->offeredTo(true));
        self::assertTrue(CheckoutChoice::GuestsOnly->offeredTo(false));
        self::assertFalse(CheckoutChoice::GuestsOnly->offeredTo(true));
        self::assertFalse(CheckoutChoice::Nobody->offeredTo(false));
    }

    public function testTypesFor(): void
    {
        self::assertTrue(TypesFor::Both->covers(UserKind::Vendor));
        self::assertFalse(TypesFor::Customers->covers(UserKind::Vendor));
        self::assertFalse(TypesFor::Vendors->covers(UserKind::Customer));
    }

    public function testKindsMapToTheCommonFieldLists(): void
    {
        self::assertSame('U', UserKind::Customer->fieldProfileType());
        self::assertSame('S', UserKind::Vendor->standardCode());
        self::assertSame(UserKind::Vendor, UserKind::fromFieldProfileType('S'));
        self::assertNull(UserKind::fromFieldProfileType('B'));
    }
}
