<?php

declare(strict_types=1);

namespace {
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }
    if (!defined('CART_LANGUAGE')) {
        define('CART_LANGUAGE', 'en');
    }

    if (!function_exists('fn_unitslug_seo_defaults')) {
        /** @return array<string, string> */
        function fn_unitslug_seo_defaults(): array
        {
            return ['seo_name_slug' => '{{name}}-{{city}}-{{country}}'];
        }
    }

    require_once dirname(__DIR__, 3) . '/functions/seo.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\TravelCore\Tests\Support\DbStub;
    use Tygh\Registry;

    /**
     * Two Sphinx hotels named "Akra -V" in Antalya City: the second product
     * got "akra-v-antalya-city-turkey-en" from CS-Cart, with a notice. The
     * check that appends the hotel id ran only when fn_generate_seo_name()
     * existed, a function CS-Cart does not define.
     */
    final class SeoSlugUniquenessTest extends TestCase
    {
        /** @var array<string, string> */
        private const array PLACEHOLDERS = ['name' => 'Akra -V', 'city' => 'Antalya City', 'country' => 'Turkey'];

        protected function setUp(): void
        {
            DbStub::reset();
            Registry::set('addons.unitslug', []);
        }

        protected function tearDown(): void
        {
            DbStub::reset();
        }

        public function testAnAddressAnotherProductHoldsGetsTheHotelId(): void
        {
            self::assertFalse(function_exists('fn_generate_seo_name'), 'as on a CS-Cart store');
            $asked = [];
            DbStub::$getField = static function (string $query, mixed ...$params) use (&$asked): ?int {
                $asked[] = [$query, $params];

                return 486;
            };

            $fields = fn_travel_core_apply_seo_fields('unitslug', self::PLACEHOLDERS, 0, '3359878');

            self::assertSame('akra-v-antalya-city-turkey-3359878', $fields['seo_name'] ?? null);
            self::assertStringContainsString("FROM ?:seo_names WHERE name = ?s AND type = 'p' AND object_id != ?i", $asked[0][0]);
            self::assertSame(['akra-v-antalya-city-turkey', 0], $asked[0][1]);
        }

        public function testAFreeAddressIsKept(): void
        {
            DbStub::$getField = static fn (): ?int => null;

            self::assertSame('akra-v-antalya-city-turkey', fn_travel_core_apply_seo_fields('unitslug', self::PLACEHOLDERS, 489, '3359878')['seo_name'] ?? null);
        }
    }
}
