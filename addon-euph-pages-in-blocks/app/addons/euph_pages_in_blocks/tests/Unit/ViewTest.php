<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\Pages;

/**
 * What a Page content template draws (fn_euph_pages_in_blocks_present()):
 * the block's settings and one item per page.
 */
final class ViewTest extends TestCase
{
    /** @var array<string, int> the block DOM ids handed out in the "page" of a test */
    private array $ids = [];

    /**
     * @param list<array<string, mixed>> $pages
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function view(array $pages, string $layout = 'stacked', array $properties = []): array
    {
        return fn_euph_pages_in_blocks_present($pages, Pages::block($properties), $layout, $this->ids);
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
        self::assertSame([], $this->view([]));
        self::assertSame([], $this->view([Pages::page(5, '  ')]), 'a page with no name in this language');
        self::assertSame([], $this->view([Pages::page(0, 'No id')]));
    }

    public function testTheBlockAndItsSettings(): void
    {
        $view = $this->view([Pages::page(5, 'Delivery')], 'accordion', ['euph_pib_one_open' => 'N', 'euph_pib_separators' => 'N']);

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
        ], self::items($this->view([$page]))[0]);
    }

    public function testALinkPageLinksToItsAddress(): void
    {
        $items = self::items($this->view([
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
        $items = self::items($this->view([
            Pages::link(6, 'Empty', '  '),
            Pages::link(7, 'Script', 'javascript:alert(1)'),
            Pages::link(8, 'Script, padded', ' JavaScript:alert(1)'),
            Pages::link(9, 'Data', 'data:text/html,<b>x</b>'),
            Pages::link(10, 'Fine', 'https://fine.example/'),
        ]));

        self::assertSame([10], array_column($items, 'page_id'));
    }

    /**
     * Browsers drop tabs and line breaks anywhere in an address, and control
     * characters and spaces at its ends, before they read its scheme: to
     * them, each of these is a script address.
     *
     * @return iterable<string, array{string}>
     */
    public static function scriptAddresses(): iterable
    {
        yield 'tab in the scheme' => ["java\tscript:alert(1)"];
        yield 'line break in the scheme' => ["java\nscript:alert(1)"];
        yield 'carriage return in the scheme' => ["java\rscript:alert(1)"];
        yield 'tab before the colon' => ["javascript\t:alert(1)"];
        yield 'control character first' => ["\x01javascript:alert(1)"];
        yield 'NUL and space first' => ["\x00 JavaScript:alert(1)"];
        yield 'with index.php, kept as is by fn_url' => ["java\tscript:alert(1)//index.php"];
        yield 'vbscript, broken' => ["vb\nscript:msgbox(1)"];
        yield 'data, broken' => ["da\tta:text/html,<b>x</b>"];
    }

    #[DataProvider('scriptAddresses')]
    public function testALinkPageToAScriptAddressIsLeftOutHoweverItIsWritten(string $address): void
    {
        self::assertSame([], $this->view([Pages::link(6, 'Script', $address)]));
    }

    public function testALinkPageAddressIsPrintedAsTheBrowserReadsIt(): void
    {
        $items = self::items($this->view([
            Pages::link(6, 'Partner', " https://partner.example/\toffer\n"),
            Pages::link(7, 'Call us', 'tel:+40712345678'),
            Pages::link(8, 'WhatsApp', 'whatsapp://send?phone=40712345678'),
            Pages::link(9, 'Write to us', 'mailto:office@example.ro'),
        ]));

        self::assertSame(
            ['https://partner.example/offer', 'tel:+40712345678', 'whatsapp://send?phone=40712345678', 'mailto:office@example.ro'],
            array_column($items, 'url'),
        );
    }

    public function testAPopupPageOpensInTheDialog(): void
    {
        $item = self::items($this->view([Pages::page(7, 'Terms', '<p>T</p>', ['show_in_popup' => 'Y', 'new_window' => '1'])]))[0];

        self::assertSame('popup', $item['kind']);
        self::assertSame('https://shop.example/index.php?dispatch=pages.view&page_id=7', $item['url']);
        self::assertSame('euph-pib-12-p7-dialog', $item['dialog_id']);
        self::assertFalse($item['new_window'], 'the dialog is never a new window');
    }

    public function testAPopupLinkPageStaysALink(): void
    {
        $item = self::items($this->view([Pages::page(7, 'Out', '', ['page_type' => 'L', 'link' => 'https://out.example/', 'show_in_popup' => 'Y'])]))[0];

        self::assertSame('link', $item['kind']);
    }

    public function testFormsAndPollsTakeTheExtraHook(): void
    {
        $items = self::items($this->view([
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

        self::assertSame('', self::items($this->view([$page], 'stacked'))[0]['excerpt']);
        self::assertSame('', self::items($this->view([$page], 'tabs', ['euph_pib_content' => 'excerpt']))[0]['excerpt']);
        self::assertSame('', self::items($this->view([$page], 'accordion'))[0]['excerpt']);

        $stacked = $this->view([$page], 'stacked', ['euph_pib_content' => 'excerpt', 'euph_pib_excerpt_length' => '60']);
        self::assertTrue($stacked['excerpts']);
        self::assertSame('Quiet rooms with a view. Quiet rooms with a view. Quiet…', self::items($stacked)[0]['excerpt']);

        $cards = $this->view([$page], 'cards', ['euph_pib_content' => 'full']);
        self::assertTrue($cards['excerpts'], 'cards always show an excerpt');
        self::assertStringEndsWith('…', self::items($cards)[0]['excerpt']);
        self::assertLessThanOrEqual(201, mb_strlen(self::items($cards)[0]['excerpt']));
    }

    public function testTheFirstTabIsSelected(): void
    {
        $pages = [Pages::page(1, 'A'), Pages::page(2, 'B'), Pages::page(3, 'C')];

        self::assertSame([true, false, false], array_column(self::items($this->view($pages, 'tabs')), 'selected'));
        self::assertSame([false, false, false], array_column(self::items($this->view($pages, 'accordion')), 'selected'));
    }

    public function testTheFirstSectionOpensWhenTheSettingSaysSo(): void
    {
        $pages = [Pages::page(1, 'A'), Pages::page(2, 'B')];

        self::assertSame([false, false], array_column(self::items($this->view($pages, 'accordion')), 'open'));
        self::assertSame([true, false], array_column(self::items($this->view($pages, 'accordion', ['euph_pib_open_first' => 'Y'])), 'open'));
        self::assertSame([false, false], array_column(self::items($this->view($pages, 'tabs', ['euph_pib_open_first' => 'Y'])), 'open'));
    }

    public function testEachPageOnce(): void
    {
        $items = self::items($this->view([Pages::page(1, 'A'), Pages::page(2, 'B'), Pages::page(1, 'A again')]));

        self::assertSame([1, 2], array_column($items, 'page_id'));
        self::assertSame(['A', 'B'], array_column($items, 'title'));
    }

    public function testIdsAreStableAndUniqueInThePage(): void
    {
        $ids = [];
        $pages = [Pages::page(5, 'A')];

        self::assertSame('euph-pib-12', fn_euph_pages_in_blocks_present($pages, Pages::block([], 12), 'tabs', $ids)['id']);
        self::assertSame('euph-pib-13', fn_euph_pages_in_blocks_present($pages, Pages::block([], 13), 'tabs', $ids)['id']);
        // The same placement drawn twice in one page.
        $again = fn_euph_pages_in_blocks_present($pages, Pages::block([], 12), 'tabs', $ids);
        self::assertSame('euph-pib-12-2', $again['id']);
        self::assertSame('euph-pib-12-2-p5', self::items($again)[0]['id']);
        // No placement (a block drawn outside a layout): by the block's id.
        self::assertSame('euph-pib-b40', fn_euph_pages_in_blocks_present($pages, Pages::block([], 0, 40), 'tabs', $ids)['id']);
        // An empty block takes no id.
        self::assertSame([], fn_euph_pages_in_blocks_present([], Pages::block([], 14), 'tabs', $ids));
        self::assertSame('euph-pib-14', fn_euph_pages_in_blocks_present($pages, Pages::block([], 14), 'tabs', $ids)['id']);
        self::assertSame(['euph-pib-12' => 2, 'euph-pib-13' => 1, 'euph-pib-b40' => 1, 'euph-pib-14' => 1], $ids);
    }

    public function testWhatSmartyHandsOverIsNarrowed(): void
    {
        self::assertSame([], fn_euph_pages_in_blocks_present(null, null, null, $this->ids));
        self::assertSame([], fn_euph_pages_in_blocks_present('items', 'block', 'tabs', $this->ids));

        $view = fn_euph_pages_in_blocks_present([3 => Pages::page(5, 'A'), 'x' => 'not a page'], Pages::block(), 'unknown', $this->ids);
        self::assertSame('stacked', $view['layout']);
        self::assertSame([5], array_column(self::items($view), 'page_id'));
        self::assertSame('cards', fn_euph_pages_in_blocks_present([Pages::page(5, 'A')], Pages::block([], 20), 'cards', $this->ids)['layout']);
    }

    public function testTheTemplatesFunctionKeepsTheIdsOfTheRequest(): void
    {
        $view = fn_euph_pages_in_blocks_view([Pages::page(5, 'Delivery')], Pages::block([], 901), 'tabs');

        self::assertSame('euph-pib-901', $view['id']);
        self::assertSame('tabs', $view['layout']);
        self::assertSame('https://shop.example/index.php?dispatch=pages.view&page_id=5', self::items($view)[0]['url']);
        self::assertSame('euph-pib-901-2', fn_euph_pages_in_blocks_view([Pages::page(5, 'Delivery')], Pages::block([], 901))['id']);
        self::assertSame([], fn_euph_pages_in_blocks_view(null, null));
    }
}
