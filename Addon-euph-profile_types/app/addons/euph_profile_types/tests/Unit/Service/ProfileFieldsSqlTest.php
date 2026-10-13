<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Service\ProfileFieldsSql;

final class ProfileFieldsSqlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function restrictions(): iterable
    {
        yield 'profile, placeholder prefix' => [
            "?:profile_fields.profile_type = 'U' AND ?:profile_fields.profile_show = 'Y'",
            "?:profile_fields.profile_type = 'U'",
        ];
        yield 'checkout, in the middle' => [
            "?:profile_fields.profile_type = 'U' AND ?:profile_fields.checkout_show = 'Y' AND ?:profile_fields.section = 'C'",
            "?:profile_fields.profile_type = 'U' AND ?:profile_fields.section = 'C'",
        ];
        yield 'real prefix and backticks' => [
            "1 AND `cscart_profile_fields`.`profile_show` = \"Y\"",
            '1',
        ];
        yield 'storefront' => [
            "1 AND ?:profile_fields.storefront_show='Y'",
            '1',
        ];
    }

    #[DataProvider('restrictions')]
    public function testTheShowRestrictionIsDropped(string $condition, string $expected): void
    {
        [$result, $dropped] = ProfileFieldsSql::dropShowRestriction($condition);

        self::assertTrue($dropped);
        self::assertSame($expected, $result);
    }

    public function testOtherConditionsStay(): void
    {
        $condition = "?:profile_fields.profile_type = 'U' AND ?:profile_fields.is_default = 'N' AND ?:profile_fields.profile_required = 'Y'";

        self::assertSame([$condition, false], ProfileFieldsSql::dropShowRestriction($condition));
    }
}
