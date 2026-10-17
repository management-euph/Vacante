<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;
use Smarty\Template;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\Html;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\Pages;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\TemplateHarness;

/**
 * The templates as Smarty 5 draws them (Support\TemplateHarness): what the
 * shopper and the keyboard get from each layout.
 */
final class TemplateRenderTest extends TestCase
{
    private TemplateHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new TemplateHarness();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pages(): array
    {
        return [
            Pages::page(5, 'Check-in & <out>', '<p>From <strong>14:00</strong>.</p>'),
            Pages::link(6, 'Our partner', 'https://partner.example/', true),
            Pages::popup(7, 'Terms', '<p>The terms.</p>'),
            Pages::form(8, 'Contact us', '<p>Write to us.</p>'),
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<array<string, mixed>>|null $pages
     */
    private function draw(string $layout, array $properties = [], ?array $pages = null): Html
    {
        return new Html($this->harness->block($layout, $pages ?? self::pages(), Pages::block($properties)));
    }

    public function testEveryTemplateCompiles(): void
    {
        $root = dirname(__DIR__, 6) . '/design/themes/responsive/templates/';
        $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with((string) $file, '.tpl')) {
                $this->harness->compile(substr((string) $file, strlen($root)));
                ++$count;
            }
        }
        (new TemplateHarness('backend'))->compile('addons/euph_pages_in_blocks/settings/info.tpl');

        self::assertSame(8, $count);
    }

    public function testNoPagesNoMarkup(): void
    {
        foreach (['stacked', 'tabs', 'accordion', 'cards'] as $layout) {
            self::assertSame('', trim($this->harness->block($layout, [], Pages::block())), $layout);
        }
    }

    public function testStackedShowsEachPageWithItsName(): void
    {
        $html = $this->draw('stacked');

        $root = $html->one('//div[' . Html::cls('euph-pib') . ']');
        self::assertSame('euph-pib-12', $root->getAttribute('id'));
        self::assertStringContainsString('euph-pib--separators', $root->getAttribute('class'));
        self::assertSame(['euph-pib-12-p5', 'euph-pib-12-p6', 'euph-pib-12-p7', 'euph-pib-12-p8'], array_map(
            static fn (\DOMElement $item): string => $item->getAttribute('id'),
            $html->all('//div[' . Html::cls('euph-pib__item') . ']'),
        ));
        self::assertSame(['Check-in & <out>', 'Our partner (opens in a new window)', 'Terms', 'Contact us'], $html->texts('//h3'));
        // The page's own HTML, in the theme's text styles.
        self::assertSame('From 14:00.', Html::text($html->one('//div[@id="euph-pib-12-p5"]//div[' . Html::cls('ty-wysiwyg-content') . ']')));
        self::assertSame(1, $html->count('//div[@id="euph-pib-12-p5"]//strong'));
        // The name is printed escaped.
        self::assertStringContainsString('Check-in &amp; &lt;out&gt;', $html->html);
    }

    public function testStackedWithoutNamesStillNamesItsLinks(): void
    {
        $html = $this->draw('stacked', ['euph_pib_show_titles' => 'N', 'euph_pib_separators' => 'N']);

        self::assertStringNotContainsString('euph-pib--separators', $html->one('//div[@id="euph-pib-12"]')->getAttribute('class'));
        self::assertSame(['Our partner (opens in a new window)', 'Terms'], $html->texts('//h3'));
    }

    public function testALinkPageOpensWhereThePageSays(): void
    {
        $link = $this->draw('stacked')->one('//div[@id="euph-pib-12-p6"]//a');

        self::assertSame('https://partner.example/', $link->getAttribute('href'));
        self::assertSame('_blank', $link->getAttribute('target'));
        self::assertSame('noopener', $link->getAttribute('rel'));
        self::assertSame('(opens in a new window)', Html::text($link->getElementsByTagName('span')->item(0) ?? new \DOMText()));
    }

    public function testAPopupPageOpensInCsCartsDialog(): void
    {
        $html = $this->draw('stacked');
        $link = $html->one('//div[@id="euph-pib-12-p7"]//a');

        self::assertStringContainsString('cm-dialog-opener', $link->getAttribute('class'));
        self::assertSame('https://shop.example/index.php?dispatch=pages.view&page_id=7', $link->getAttribute('href'));
        self::assertSame('euph-pib-12-p7-dialog', $link->getAttribute('data-ca-target-id'));
        self::assertSame('nofollow', $link->getAttribute('rel'));
        self::assertFalse($link->hasAttribute('target'));
        $dialog = $html->one('//div[@id="euph-pib-12-p7-dialog"]');
        self::assertSame('hidden', $dialog->getAttribute('class'));
        self::assertSame('Terms', $dialog->getAttribute('title'));
        self::assertSame('', Html::text($dialog), 'the dialog loads the page');
    }

    public function testThePageHooksAreDrawnAsOnThePageItself(): void
    {
        $this->draw('stacked');

        // Text and form: pages:page_content; the form also pages:page_extra.
        self::assertSame(['pages:page_content', 'pages:page_content', 'pages:page_extra'], $this->harness->hooks);
    }

    public function testAnAddonDrawsTheFormInPlaceOfTheText(): void
    {
        // What the Form builder's pages:page_content override does: it reads
        // the page as $page and draws its form; for other pages it prints
        // nothing, so their text stays.
        $this->harness->overrides['pages:page_content'] = static function (Template $template): string {
            $page = $template->getValue('page');
            if (!is_array($page) || ($page['page_type'] ?? '') !== 'F' || !is_array($page['form'] ?? null)) {
                return '';
            }

            return '<form class="ty-form-builder" data-page="' . $page['page_id'] . '" data-fields="' . count($page['form']['elements']) . '"></form>';
        };

        $html = $this->draw('stacked');

        $form = $html->one('//div[@id="euph-pib-12-p8"]//form');
        self::assertSame('8', $form->getAttribute('data-page'));
        self::assertSame('1', $form->getAttribute('data-fields'));
        self::assertSame('From 14:00.', Html::text($html->one('//div[@id="euph-pib-12-p5"]//div[' . Html::cls('euph-pib__content') . ']')));
    }

    public function testExcerptsLinkToThePage(): void
    {
        $html = $this->draw('stacked', ['euph_pib_content' => 'excerpt']);

        self::assertSame([], $this->harness->hooks, 'no page body, no page hooks');
        self::assertSame('From 14:00.', Html::text($html->one('//div[@id="euph-pib-12-p5"]//p[' . Html::cls('euph-pib__excerpt') . ']')));
        $more = $html->one('//div[@id="euph-pib-12-p5"]//a[' . Html::cls('euph-pib__more-link') . ']');
        self::assertSame('Read more', Html::text($more));
        self::assertSame('Read more about Check-in & <out>', $more->getAttribute('aria-label'));
        self::assertSame('https://shop.example/index.php?dispatch=pages.view&page_id=5', $more->getAttribute('href'));
    }

    public function testTabsReadInOrderUntilTheScriptRuns(): void
    {
        $html = $this->draw('tabs');

        $root = $html->one('//div[@id="euph-pib-12"]');
        self::assertTrue($root->hasAttribute('data-euph-pib-tabs'));
        $list = $html->one('//div[' . Html::cls('euph-pib__tablist') . ']');
        self::assertTrue($list->hasAttribute('hidden'), 'the tab row waits for the script');
        self::assertFalse($list->hasAttribute('role'), 'the roles come with the script that makes them work');

        $tabs = $html->all('//button[' . Html::cls('euph-pib__tab') . ']');
        self::assertCount(4, $tabs);
        self::assertSame('button', $tabs[0]->getAttribute('type'));
        self::assertSame('true', $tabs[0]->getAttribute('aria-selected'));
        self::assertFalse($tabs[1]->hasAttribute('aria-selected'));
        foreach ($tabs as $tab) {
            $panel = $html->one('//div[@id="' . $tab->getAttribute('data-euph-pib-target') . '"]');
            self::assertStringContainsString('euph-pib__panel', $panel->getAttribute('class'));
            self::assertSame($tab->getAttribute('data-euph-pib-target') . '-tab', $tab->getAttribute('id'));
            self::assertFalse($panel->hasAttribute('hidden'), 'every panel shows without the script');
            self::assertSame(Html::text($tab), Html::text($html->one('//div[@id="' . $panel->getAttribute('id') . '"]/h3')));
        }
    }

    public function testATabOfALinkPageHoldsTheLink(): void
    {
        $html = $this->draw('tabs');

        $link = $html->one('//div[@id="euph-pib-12-p6"]//a');
        self::assertStringContainsString('ty-btn', $link->getAttribute('class'));
        self::assertSame('Open the page (opens in a new window)', Html::text($link));
        self::assertSame('_blank', $link->getAttribute('target'));
        self::assertSame(1, $html->count('//div[@id="euph-pib-12-p7"]//a[' . Html::cls('cm-dialog-opener') . ']'));
        self::assertSame(1, $html->count('//div[@id="euph-pib-12-p7-dialog"]'));
    }

    public function testAccordionSectionsShareANameToStayOneOpen(): void
    {
        $html = $this->draw('accordion', ['euph_pib_open_first' => 'Y']);

        self::assertTrue($html->one('//div[@id="euph-pib-12"]')->hasAttribute('data-euph-pib-one-open'));
        $sections = $html->all('//details');
        self::assertCount(4, $sections);
        foreach ($sections as $section) {
            self::assertSame('euph-pib-12', $section->getAttribute('name'));
        }
        self::assertTrue($sections[0]->hasAttribute('open'));
        self::assertFalse($sections[1]->hasAttribute('open'));
        self::assertSame(['Check-in & <out>', 'Our partner', 'Terms', 'Contact us'], $html->texts('//details/summary'));
    }

    public function testAccordionSectionsOpenFreelyWhenAllowed(): void
    {
        $html = $this->draw('accordion', ['euph_pib_one_open' => 'N']);

        self::assertFalse($html->one('//div[@id="euph-pib-12"]')->hasAttribute('data-euph-pib-one-open'));
        self::assertSame(0, $html->count('//details[@name]'));
        self::assertSame(0, $html->count('//details[@open]'));
    }

    public function testCardsAreOneLinkEach(): void
    {
        $html = $this->draw('cards', ['euph_pib_columns' => '2', 'euph_pib_excerpt_length' => '40']);

        self::assertStringContainsString('euph-pib--cols-2', $html->one('//div[@id="euph-pib-12"]')->getAttribute('class'));
        self::assertSame('list', $html->one('//ul')->getAttribute('role'));
        $cards = $html->all('//li[' . Html::cls('euph-pib__card') . ']');
        self::assertCount(4, $cards);
        foreach ($cards as $card) {
            self::assertCount(1, $card->getElementsByTagName('a'), 'one link per card');
        }
        self::assertSame(
            ['https://shop.example/index.php?dispatch=pages.view&page_id=5', 'https://partner.example/', 'https://shop.example/index.php?dispatch=pages.view&page_id=7', 'https://shop.example/index.php?dispatch=pages.view&page_id=8'],
            array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'), $html->all('//a[' . Html::cls('euph-pib__card-link') . ']')),
        );
        self::assertSame('From 14:00.', Html::text($html->one('//li[@id="euph-pib-12-p5"]//p')));
        self::assertSame('true', $html->one('//li[@id="euph-pib-12-p5"]//span[' . Html::cls('euph-pib__more') . ']')->getAttribute('aria-hidden'));
        self::assertSame([], $this->harness->hooks);
    }

    public function testNoInlineScriptsOrStylesReachThePage(): void
    {
        foreach (['stacked', 'tabs', 'accordion', 'cards'] as $layout) {
            $html = $this->draw($layout, [], [Pages::page(5, 'A', '<p>Text</p>'), Pages::link(6, 'B', 'https://b.example/')]);
            self::assertSame(0, $html->count('//script'), $layout);
            self::assertSame(0, $html->count('//*[@style]'), $layout);
            self::assertSame(0, $html->count('//*[@*[starts-with(local-name(), "on")]]'), $layout);
        }
    }

    public function testTheAssetHooksLoadTheScriptAndTheStyles(): void
    {
        self::assertSame('<script src="js/addons/euph_pages_in_blocks/func.js"></script>', trim($this->harness->render('addons/euph_pages_in_blocks/hooks/index/scripts.post.tpl')));
        self::assertSame('<link rel="stylesheet" href="addons/euph_pages_in_blocks/styles.less">', trim($this->harness->render('addons/euph_pages_in_blocks/hooks/index/styles.post.tpl')));
    }

    public function testTheSettingsTabExplainsHowToPlaceTheBlock(): void
    {
        $html = new Html((new TemplateHarness('backend'))->render('addons/euph_pages_in_blocks/settings/info.tpl'));

        self::assertCount(4, $html->all('//ol/li'));
        self::assertStringContainsString('Page content', Html::text($html->one('//ol/li[2]')));
        $link = $html->one('//a');
        self::assertSame('https://shop.example/index.php?dispatch=block_manager.manage', $link->getAttribute('href'));
        self::assertSame('Open Design → Layouts', Html::text($link));
        self::assertStringNotContainsString('_euph_pages_in_blocks', $html->html, 'every text is in the language pack');
    }
}
