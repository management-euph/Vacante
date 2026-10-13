<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;

/**
 * Every hook init.php registers has its handler, and the handler gives every
 * parameter a default: the arguments CS-Cart passes differ between builds,
 * and a missing one must not be a fatal error on a checkout or a profile page.
 */
final class HookSignatureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/func.php';
    }

    /**
     * @return list<string>
     */
    private static function registeredHooks(): array
    {
        $init = (string) file_get_contents(dirname(__DIR__, 3) . '/init.php');
        self::assertSame(1, preg_match('/fn_register_hooks\((.*?)\);/s', $init, $match));
        preg_match_all("/'([a-z_]+)'/", $match[1], $hooks);

        return $hooks[1];
    }

    public function testEveryRegisteredHookHasAForgivingHandler(): void
    {
        $hooks = self::registeredHooks();
        self::assertContains('get_profile_fields_post', $hooks);

        foreach ($hooks as $hook) {
            $function = 'fn_euph_profile_types_' . $hook;
            self::assertTrue(function_exists($function), $function . ' is missing');
            foreach ((new \ReflectionFunction($function))->getParameters() as $parameter) {
                self::assertTrue($parameter->isDefaultValueAvailable(), $function . '($' . $parameter->getName() . ') needs a default');
            }
        }
    }

    public function testTheCheckoutBlocksAreServedByAnExistingFunction(): void
    {
        self::assertTrue(function_exists('fn_euph_profile_types_checkout_block_fields'));
        self::assertTrue(function_exists('fn_euph_profile_types_install'));
        self::assertTrue(function_exists('fn_euph_profile_types_uninstall'));
    }

    public function testThePublicFunctionsOfTheIntegrationListExist(): void
    {
        foreach ([
            'fn_euph_profile_types_get_current_customer_type',
            'fn_euph_profile_types_is_valid_type_code',
            'fn_euph_profile_types_get_selectable_types',
            'fn_euph_profile_types_is_code_on_storefront',
            'fn_euph_get_profile_types',
            'fn_euph_get_profile_type_data',
            'fn_euph_update_profile_type',
            'fn_euph_delete_profile_type',
        ] as $function) {
            self::assertTrue(function_exists($function), $function);
        }
    }
}
