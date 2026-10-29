<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * A Design A hotel page gets Sphinx's offers as room cards (board 13b;
 * docs/hotel-page-design-a-plan.md) when it asks (design=a) for one room:
 * at once from the cache or a finished search; for a running search, a live
 * container the page's script fills from each poll (search_poll, design=a:
 * the cards for every offer so far). The cards' terms come from offer_terms
 * (design=a). Every other search renders as before.
 */
final class DesignAResultsWiringTest extends TestCase
{
    private static function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    private static function template(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 6) . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/search.tpl');
    }

    public function testTheSearchBuildsTheCardsOrTheLiveContainerForOneRoom(): void
    {
        $search = self::source('controllers/frontend/sphinx_booking/search.php');

        self::assertStringContainsString('$designA = DesignA::requested($_REQUEST) && count($occupancy) === 1;', $search);
        self::assertSame(2, substr_count($search, "\$view->assign('travel_design_a_results', DesignARates::view("), 'the cache hit and the finished search');
        self::assertStringContainsString("'design_a' => \$designA ? \$templateParams : null,", $search, 'the poll builds the cards for the same search');
        self::assertStringContainsString("\$view->assign('sphinx_design_a_live', [", $search);
        self::assertStringContainsString('DesignA::PARAM => DesignA::VALUE,', $search);
    }

    public function testThePollAnswersWithTheCardsForEveryOfferSoFar(): void
    {
        $poll = self::source('controllers/frontend/sphinx_booking/search_poll.php');

        self::assertStringContainsString("\$designASearch = DesignA::requested(\$_REQUEST) ? TypeCoerce::toStringMap(\$searchMeta['design_a'] ?? null) : [];", $poll);
        self::assertStringContainsString("echo json_encode(['status' => 'error', 'done' => true, 'html' => '']);", $poll, 'an expired search stops the page');
        self::assertStringContainsString('$cards = DesignARates::view($accumulated, $designASearch);', $poll);
        self::assertStringContainsString("\$html = \$view->fetch('addons/travel_core/components/design_a_results.tpl');", $poll);
        self::assertStringContainsString("'done' => \$terminal,", $poll);
        // The fields the cards read ride the slim rows (and so the cache).
        $start = (int) strpos($poll, '$templateFields = [');
        $fields = substr($poll, $start, (int) strpos($poll, '];', $start) - $start);
        foreach (["'room_code'", "'marketing_price'", "'deal_label'", "'free_cancellation'"] as $field) {
            self::assertStringContainsString($field, $fields);
        }
        self::assertStringContainsString("\$result['marketing_price'] = \$cartService->applyCommission(", $poll, 'the struck price on the price\'s scale');
        self::assertStringContainsString("\$result['marketing_price'] = \$cartService->applyCommission(", self::source('controllers/frontend/sphinx_booking/search.php'));
    }

    public function testTheTermsEndpointAnswersTheCardWithSections(): void
    {
        $terms = self::source('controllers/frontend/sphinx_booking/offer_terms.php');

        self::assertStringContainsString('$designA = DesignA::requested($_REQUEST);', $terms);
        self::assertStringContainsString("echo json_encode(\$designA ? ['sections' => DesignARates::termsSections(\$answer, [", $terms);
        self::assertStringNotContainsString('echo json_encode([', $terms, 'every answer goes through $respond');
        self::assertSame(5, substr_count($terms, '$respond(['));
    }

    public function testTheTemplateShowsThemInPlaceOfTheOfferList(): void
    {
        $src = self::template();
        $form = strpos($src, '{$booking_engine_html nofilter}');
        $cards = strpos($src, '{if $travel_design_a_results}');
        $include = strpos($src, '{include file="addons/travel_core/components/design_a_results.tpl" results=$travel_design_a_results}');
        $live = strpos($src, '{elseif $sphinx_design_a_live}');
        $skeleton = strpos($src, '<div class="sphinx-loading-skeleton"');
        $container = strpos($src, 'id="sphinx-results-container"');
        $close = strpos($src, '{/if}{* End of the Design A else *}');
        $noResults = strpos($src, 'id="sphinx-no-results"');
        foreach ([$form, $cards, $include, $live, $skeleton, $container, $close, $noResults] as $at) {
            self::assertIsInt($at);
        }
        self::assertLessThan($cards, $form, 'after the search bar, inside the region the inline search swaps');
        self::assertLessThan($include, $cards);
        self::assertLessThan($live, $include);
        self::assertLessThan($skeleton, $live, 'the skeleton and the offer list are the else: no second poller');
        self::assertLessThan($container, $skeleton);
        self::assertLessThan($close, $container);
        self::assertLessThan($noResults, $close, 'the no-results block stays for both');
        foreach (['data-travel-live', 'data-travel-live-url="{$sphinx_design_a_live.url|escape:html}"', 'data-travel-live-status', 'data-travel-live-results'] as $hook) {
            self::assertStringContainsString($hook, $src);
        }
    }

    public function testTheListsScriptShowsNoResultsWhenTheLiveCardsEndEmpty(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 6) . '/js/addons/sphinx_holidays/search-results.js');

        self::assertStringContainsString("document.addEventListener('travel:live-done', function (event) {", $js);
        self::assertStringContainsString('renderAlternatives(noResults, (detail.data && detail.data.alternatives) || []);', $js);
    }
}
