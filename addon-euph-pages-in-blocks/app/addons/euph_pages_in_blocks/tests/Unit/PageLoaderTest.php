<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\PageLoader;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\Pages;

final class PageLoaderTest extends TestCase
{
    /** @var list<array{int, string}> */
    private array $calls = [];

    /**
     * @param array<int, mixed> $rows page id => what fn_get_page_data() answers
     */
    private function loader(array $rows): PageLoader
    {
        return new PageLoader(function (int $pageId, string $langCode) use ($rows): mixed {
            $this->calls[] = [$pageId, $langCode];

            return $rows[$pageId] ?? false;
        });
    }

    public function testThePagesComeInTheOrderTheyWerePicked(): void
    {
        $loader = $this->loader([3 => Pages::page(3, 'C'), 1 => Pages::page(1, 'A'), 2 => Pages::page(2, 'B')]);

        $pages = $loader->load([2, 3, 1], 'ro');

        self::assertSame(['B', 'C', 'A'], array_column($pages, 'page'));
        self::assertSame([[2, 'ro'], [3, 'ro'], [1, 'ro']], $this->calls);
    }

    public function testAPageTheVisitorMayNotSeeIsLeftOut(): void
    {
        // fn_get_page_data() answers false (or nothing) for a page that is
        // disabled, out of its period, for other groups or storefronts.
        $loader = $this->loader([1 => Pages::page(1, 'A'), 2 => false, 3 => [], 4 => 'garbage']);

        self::assertSame(['A'], array_column($loader->load([1, 2, 3, 4, 5], 'en'), 'page'));
    }

    public function testADisabledPageIsLeftOutInTheAdminToo(): void
    {
        $loader = $this->loader([1 => Pages::page(1, 'A', '', ['status' => 'D']), 2 => Pages::page(2, 'B', '', ['status' => 'H'])]);

        self::assertSame(['B'], array_column($loader->load([1, 2], 'en'), 'page'), 'a hidden page is shown: the block is how it is reached');
    }

    public function testARowOfAnotherPageIsNotTaken(): void
    {
        $loader = $this->loader([1 => Pages::page(9, 'Not one')]);

        self::assertSame([], $loader->load([1], 'en'));
    }

    public function testEachPageOnceAndAtMostFifty(): void
    {
        $rows = [];
        for ($id = 1; $id <= 60; ++$id) {
            $rows[$id] = Pages::page($id, 'P' . $id);
        }
        $loader = $this->loader($rows);

        self::assertCount(1, $loader->load([7, 7, 7], 'en'));
        $this->calls = [];
        self::assertCount(PageLoader::MAX_PAGES, $loader->load(range(1, 60), 'en'));
        self::assertCount(50, $this->calls, 'the pages over the limit are not even read');
    }
}
