<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\BlockSchema;
use Tygh\Addons\EuphPagesInBlocks\Enum\Layout;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\CsCart;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\Pages;
use Tygh\Addons\EuphPagesInBlocks\View\BlockPresenter;

final class BlockPresenterTest extends TestCase
{
    private static function presenter(): BlockPresenter
    {
        return new BlockPresenter(static fn (string $url): string => CsCart::url($url));
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private static function view(array $pages, Layout $layout = Layout::Stacked, array $properties = []): array
    {
        return self::presenter()->present($pages, Pages::block($properties), $layout);
    }

    /**
     * @param array<string, mixed> $view
     *
     * @return list<array<string, mixed>>
     */
    private static function items(array $view): array
    {
        self::assertIsArray($view['items'] ?? null);

        /** @var list<array<string, mixed>> */
        return $view['items'];
    }

    public function testNothingToShowIsAnEmptyView(): void
    {
        self::assertSame([], self::view([]));
        self::assertSame([], self::view([Pages::page(5, '  ')]), 'a page with no name in this language');
        self::assertSame([], self::view([Pages::page(0, 'No id')]));
    }

    public function testTheBlockAndItsSettings(): void
    {
        $view = self::view([Pages::page(5, 'Delivery')], Layout::Accordion, [BlockSchema::ONE_OPEN => 'N', BlockSchema::SEPARATORS => 'N']);

        self::assertSame('euph-pib-12', $view['id']);
        self::assertSame('accordion', $view['layout']);
        self::assertTrue($view['show_titles']);
        self::assertFalse($view['separators']);
        self::assertFalse($view['excerpts']);
        self::assertFalse($view['one_open']);
        self::assertSame(3, $view['columns']);
    }

    public function testATextPage(): void
    {
        $page = Pages::page(5, ' Delivery & returns ', '<p>We deliver.</p>');
        $item = self::items(self::view([$page]))[0];

        self::assertSame([
            'page_id' => 5,
            'title' => 'Delivery & returns',
            'type' => 'text',
            'kind' => 'content',
            'url' => 'https://shop.example/index.php?dispatch=pages.view&page_id=5',
            'new_window' => false,
            'excerpt' => '',
            'extra_hook' => false,
            'page' => $page,
            'id' => 'euph-pib-12-p5',
            'dialog_id' => '',
            'selected' => false,
            'open' => false,
        ], $item);
    }

    public function testALinkPageLinksToItsAddress(): void
    {
        $items = self::items(self::view([
            Pages::link(6, 'Partner', 'https://partner.example/offer', true),
            Pages::link(7, 'Same window', 'index.php?dispatch=products.search'),
        ]));

        self::assertSame(['link', 'link'], array_column($items, 'kind'));
        self::assertSame(['https://partner.example/offer', 'https://shop.example/index.php?dispatch=products.search'], array_column($items, 'url'));
        self::assertSame([true, false], array_column($items, 'new_window'));
        self::assertSame(['link', 'link'], array_column($items, 'type'));
    }

    public function testALinkPageWithoutAUsableAddressIsLeftOut(): void
    {
        $items = self::items(self::view([
            Pages::link(6, 'Empty', '  '),
            Pages::link(7, 'Script', 'javascript:alert(1)'),
            Pages::link(8, 'Script, padded', ' JavaScript:alert(1)'),
            Pages::link(9, 'Data', 'data:text/html,<b>x</b>'),
            Pages::link(10, 'Fine', 'https://fine.example/'),
        ]));

        self::assertSame([10], array_column($items, 'page_id'));
    }

    public function testAPopupPageOpensInTheDialog(): void
    {
        $item = self::items(self::view([Pages::page(7, 'Terms', '<p>T</p>', ['show_in_popup' => 'Y', 'new_window' => '1'])]))[0];

        self::assertSame('popup', $item['kind']);
        self::assertSame('https://shop.example/index.php?dispatch=pages.view&page_id=7', $item['url']);
        self::assertSame('euph-pib-12-p7-dialog', $item['dialog_id']);
        self::assertFalse($item['new_window'], 'the dialog is never a new window');
    }

    public function testAPopupLinkPageStaysALink(): void
    {
        $item = self::items(self::view([Pages::page(7, 'Out', '', ['page_type' => 'L', 'link' => 'https://out.example/', 'show_in_popup' => 'Y'])]))[0];

        self::assertSame('link', $item['kind']);
    }

    public function testFormsAndPollsTakeTheExtraHook(): void
    {
        $items = self::items(self::view([
            Pages::form(1, 'Form'),
            Pages::page(2, 'Poll', '', ['page_type' => 'P']),
            Pages::page(3, 'Text'),
            Pages::page(4, 'Blog post', '<p>Post</p>', ['page_type' => 'B']),
        ]));

        self::assertSame(['form', 'poll', 'text', 'text'], array_column($items, 'type'));
        self::assertSame([true, true, false, false], array_column($items, 'extra_hook'));
        self::assertSame(['content', 'content', 'content', 'content'], array_column($items, 'kind'));
        self::assertArrayHasKey('form', $items[0]['page'], 'what the Form builder attached reaches its hook');
    }

    public function testExcerptsWhereTheLayoutShowsThem(): void
    {
        $page = Pages::page(5, 'Rooms', '<p>' . str_repeat('Quiet rooms with a view. ', 20) . '</p>');

        self::assertSame('', self::items(self::view([$page], Layout::Stacked))[0]['excerpt']);
        self::assertSame('', self::items(self::view([$page], Layout::Tabs))[0]['excerpt']);
        self::assertSame('', self::items(self::view([$page], Layout::Accordion))[0]['excerpt']);

        $stacked = self::view([$page], Layout::Stacked, [BlockSchema::CONTENT => 'excerpt', BlockSchema::EXCERPT_LENGTH => '60']);
        self::assertTrue($stacked['excerpts']);
        self::assertSame('Quiet rooms with a view. Quiet rooms with a view. Quiet…', self::items($stacked)[0]['excerpt']);

        $cards = self::view([$page], Layout::Cards, [BlockSchema::CONTENT => 'full']);
        self::assertTrue($cards['excerpts'], 'cards always show an excerpt');
        self::assertStringEndsWith('…', self::items($cards)[0]['excerpt']);
        self::assertLessThanOrEqual(201, mb_strlen(self::items($cards)[0]['excerpt']));
    }

    public function testTheFirstTabIsSelected(): void
    {
        $pages = [Pages::page(1, 'A'), Pages::page(2, 'B'), Pages::page(3, 'C')];

        self::assertSame([true, false, false], array_column(self::items(self::view($pages, Layout::Tabs)), 'selected'));
        self::assertSame([false, false, false], array_column(self::items(self::view($pages, Layout::Accordion)), 'selected'));
    }

    public function testTheFirstSectionOpensWhenTheSettingSaysSo(): void
    {
        $pages = [Pages::page(1, 'A'), Pages::page(2, 'B')];

        self::assertSame([false, false], array_column(self::items(self::view($pages, Layout::Accordion)), 'open'));
        self::assertSame([true, false], array_column(self::items(self::view($pages, Layout::Accordion, [BlockSchema::OPEN_FIRST => 'Y'])), 'open'));
        self::assertSame([false, false], array_column(self::items(self::view($pages, Layout::Tabs, [BlockSchema::OPEN_FIRST => 'Y'])), 'open'));
    }

    public function testEachPageOnce(): void
    {
        $items = self::items(self::view([Pages::page(1, 'A'), Pages::page(2, 'B'), Pages::page(1, 'A again')]));

        self::assertSame([1, 2], array_column($items, 'page_id'));
        self::assertSame(['A', 'B'], array_column($items, 'title'));
    }

    public function testIdsAreStableAndUniqueInThePage(): void
    {
        $presenter = self::presenter();
        $pages = [Pages::page(5, 'A')];

        self::assertSame('euph-pib-12', $presenter->present($pages, Pages::block([], 12), Layout::Tabs)['id']);
        self::assertSame('euph-pib-13', $presenter->present($pages, Pages::block([], 13), Layout::Tabs)['id']);
        // The same placement drawn twice in one page.
        $again = $presenter->present($pages, Pages::block([], 12), Layout::Tabs);
        self::assertSame('euph-pib-12-2', $again['id']);
        self::assertSame('euph-pib-12-2-p5', self::items($again)[0]['id']);
        // No placement (a block drawn outside a layout): by the block's id.
        self::assertSame('euph-pib-b40', $presenter->present($pages, Pages::block([], 0, 40), Layout::Tabs)['id']);
        // An empty block takes no id.
        self::assertSame([], $presenter->present([], Pages::block([], 14), Layout::Tabs));
        self::assertSame('euph-pib-14', $presenter->present($pages, Pages::block([], 14), Layout::Tabs)['id']);
    }

    public function testFromTemplateNarrowsWhatSmartyHandsOver(): void
    {
        $presenter = self::presenter();

        self::assertSame([], $presenter->fromTemplate(null, null, null));
        self::assertSame([], $presenter->fromTemplate('items', 'block', 'tabs'));

        $view = $presenter->fromTemplate([3 => Pages::page(5, 'A'), 'x' => 'not a page'], Pages::block(), 'unknown');
        self::assertSame('stacked', $view['layout']);
        self::assertSame([5], array_column(self::items($view), 'page_id'));
        self::assertSame('cards', $presenter->fromTemplate([Pages::page(5, 'A')], Pages::block([], 20), 'cards')['layout']);
    }
}
