<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Travel Core list views (board 15) inside CS-Cart's own list frame, as
 * the store's core templates draw it (theme export of 4.21.2):
 * fn_get_products_views() lists every blocks/product_list_templates/*.tpl
 * of an active add-on, titled by its template-description language key;
 * the sorting bar draws each view's icon as .ty-icon-<view name>; the list
 * sits between the two common/pagination.tpl includes; list-load-more.js
 * reads the data attributes of the body.
 */
final class ListViewTemplateTest extends TestCase
{
    private const string TEMPLATES = '/design/themes/responsive/templates/addons/travel_core/';

    private static function addon(): string
    {
        return dirname(__DIR__, 7) . '/addon-travel-core';
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(self::addon() . $relative);
    }

    /** @return array<string, array{string, string}> */
    public static function views(): array
    {
        return [
            'list' => ['travelcore_cards_list', 'travel_core.list_view_list'],
            'grid' => ['travelcore_cards_grid', 'travel_core.list_view_grid'],
        ];
    }

    #[DataProvider('views')]
    public function testEachViewIsFoundTitledAndHasAnIcon(string $view, string $titleKey): void
    {
        $src = self::read(self::TEMPLATES . 'blocks/product_list_templates/' . $view . '.tpl');
        self::assertStringStartsWith('{** template-description:' . $titleKey . ' **}', $src);
        self::assertStringContainsString('{include file="addons/travel_core/components/list_view.tpl"', $src);

        $lang = require self::addon() . '/app/addons/travel_core/lang_keys.php';
        self::assertIsArray($lang);
        self::assertArrayHasKey($titleKey, $lang, 'the admin lists the view by this key');

        $css = self::read('/design/themes/responsive/css/addons/travel_core/list.css');
        self::assertStringContainsString('.ty-icon-' . str_replace('_', '-', $view) . '::before', $css, 'the view switch draws .ty-icon-<view>');
    }

    public function testTheBodySitsInsideCsCartsPaginationPairAndSortingBar(): void
    {
        $src = (string) preg_replace('/\{\*.*?\*\}/s', '', self::read(self::TEMPLATES . 'components/list_view.tpl'));

        self::assertSame(2, preg_match_all('/\{include file="common\/pagination\.tpl"/', $src), 'opens and closes #pagination_contents');
        $open = strpos($src, 'common/pagination.tpl');
        $sorting = strpos($src, 'views/products/components/sorting.tpl');
        $list = strpos($src, 'data-travel-list-items');
        $close = strrpos($src, 'common/pagination.tpl');
        self::assertTrue($open < $sorting && $sorting < $list && $list < $close, 'pagination, sorting, cards, pagination');
        self::assertStringContainsString('{script src="js/addons/travel_core/list-load-more.js"}', $src);
    }

    public function testTheScriptsMarkupContractHolds(): void
    {
        $tpl = self::read(self::TEMPLATES . 'components/list_view.tpl');
        $js = self::read('/js/addons/travel_core/list-load-more.js');

        foreach (['data-travel-list', 'data-travel-list-items', 'data-travel-list-more-box', 'data-travel-list-shown', 'data-travel-list-more'] as $attr) {
            self::assertStringContainsString($attr, $tpl);
            self::assertStringContainsString('[' . $attr . ']', $js);
        }
        foreach (['data-from', 'data-to', 'data-total', 'data-text'] as $attr) {
            self::assertStringContainsString($attr . '="', $tpl);
            self::assertStringContainsString("'" . $attr . "'", $js);
        }
    }

    public function testTheCardBooksNothing(): void
    {
        $card = self::read(self::TEMPLATES . 'components/list_card.tpl');

        // A hotel is booked on its page, with dates, room and turiști chosen.
        self::assertStringNotContainsString('add_to_cart', $card);
        self::assertStringNotContainsString('<form', $card);
        self::assertStringContainsString('"products.view?product_id=`$product.product_id`"|fn_url', $card);
    }
}
