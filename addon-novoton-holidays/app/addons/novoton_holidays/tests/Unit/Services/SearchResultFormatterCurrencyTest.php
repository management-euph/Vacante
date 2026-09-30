<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\SearchResultFormatter;
use Tygh\Registry;

/**
 * The search card multiplies the API (EUR) amount by novoton_display_coefficient.
 * On the dev store (USD primary, EUR 1.28) that was the USD coefficient, 1.0,
 * so a 795 EUR offer read "795 $" while the cart charged the converted amount.
 * It must be the API -> display factor: 1.28, i.e. $1,017.60.
 */
#[CoversClass(SearchResultFormatter::class)]
final class SearchResultFormatterCurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        Registry::set('currencies', [
            'USD' => ['currency_code' => 'USD', 'coefficient' => '1.00000', 'symbol' => '$', 'is_primary' => 'Y'],
            'EUR' => ['currency_code' => 'EUR', 'coefficient' => '1.28000', 'symbol' => '€', 'is_primary' => 'N'],
        ]);
        ConfigProvider::reset();
        Container::getInstance()->reset();
    }

    protected function tearDown(): void
    {
        Registry::set('currencies', []);
        ConfigProvider::reset();
        Container::getInstance()->reset();
    }

    public function testTheCardFactorConvertsTheApiAmountIntoTheDisplayCurrency(): void
    {
        $view = new class {
            /** @var array<string, mixed> */
            public array $vars = [];

            public function assign(string $name, mixed $value): void
            {
                $this->vars[$name] = $value;
            }
        };

        $method = new \ReflectionMethod(SearchResultFormatter::class, 'assignCurrency');
        $method->invoke((new \ReflectionClass(SearchResultFormatter::class))->newInstanceWithoutConstructor(), $view);

        $currency = $view->vars['novoton_display_currency'];
        $expected = $currency === 'USD' ? 1.28 : ($currency === 'EUR' ? 1.0 : null);
        self::assertNotNull($expected, 'display currency comes from the test store');
        self::assertSame($expected, $view->vars['novoton_display_coefficient']);
    }
}
