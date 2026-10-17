<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\CsCart;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\Pages;

/**
 * The block's items function: the pages picked on its Content tab, as the
 * storefront shows them.
 */
final class GetPagesTest extends TestCase
{
    protected function setUp(): void
    {
        CsCart::reset();
    }

    public function testThePagesComeInTheOrderTheyWerePicked(): void
    {
        CsCart::$pages = [3 => Pages::page(3, 'C'), 1 => Pages::page(1, 'A'), 2 => Pages::page(2, 'B')];
        $params = ['item_ids' => '2,3,1', 'block_data' => ['block_id' => 40]];

        [$pages, $returned] = fn_euph_pages_in_blocks_get_pages($params);

        self::assertSame(['B', 'C', 'A'], array_column($pages, 'page'));
        self::assertSame($params, $returned, 'the params go back as fn_get_pages() gives them');
        self::assertSame([[2, 'en'], [3, 'en'], [1, 'en']], CsCart::$pageCalls, 'in the storefront language');
    }

    public function testTheLanguageItIsGiven(): void
    {
        CsCart::$pages = [1 => Pages::page(1, 'Unu')];

        fn_euph_pages_in_blocks_get_pages(['item_ids' => [1], 'lang_code' => 'ro']);

        self::assertSame([[1, 'ro']], CsCart::$pageCalls);
    }

    public function testAPageTheVisitorMayNotSeeIsLeftOut(): void
    {
        // fn_get_page_data() answers false (or nothing) for a page that is
        // disabled, out of its period, for other groups or storefronts.
        CsCart::$pages = [1 => Pages::page(1, 'A'), 2 => false, 3 => [], 4 => 'garbage'];

        [$pages] = fn_euph_pages_in_blocks_get_pages(['item_ids' => '1,2,3,4,5']);

        self::assertSame(['A'], array_column($pages, 'page'));
    }

    public function testADisabledPageIsLeftOutInTheAdminToo(): void
    {
        CsCart::$pages = [1 => Pages::page(1, 'A', '', ['status' => 'D']), 2 => Pages::page(2, 'B', '', ['status' => 'H'])];

        [$pages] = fn_euph_pages_in_blocks_get_pages(['item_ids' => '1,2']);

        self::assertSame(['B'], array_column($pages, 'page'), 'a hidden page is shown: the block is how it is reached');
    }

    public function testARowOfAnotherPageIsNotTaken(): void
    {
        CsCart::$pages = [1 => Pages::page(9, 'Not one')];

        self::assertSame([], fn_euph_pages_in_blocks_get_pages(['item_ids' => '1'])[0]);
    }

    public function testEachPageOnceAndAtMostFifty(): void
    {
        for ($id = 1; $id <= 60; ++$id) {
            CsCart::$pages[$id] = Pages::page($id, 'P' . $id);
        }

        self::assertCount(1, fn_euph_pages_in_blocks_get_pages(['item_ids' => '7,7,7'])[0]);
        CsCart::$pageCalls = [];
        self::assertCount(50, fn_euph_pages_in_blocks_get_pages(['item_ids' => range(1, 60)])[0]);
        self::assertCount(50, CsCart::$pageCalls, 'the pages over the limit are not even read');
    }

    public function testNoPages(): void
    {
        self::assertSame([[], []], fn_euph_pages_in_blocks_get_pages());
        self::assertSame([[], []], fn_euph_pages_in_blocks_get_pages('garbage'));
        self::assertSame([[], ['item_ids' => '']], fn_euph_pages_in_blocks_get_pages(['item_ids' => '']));
        self::assertSame([], CsCart::$pageCalls);
    }
}
