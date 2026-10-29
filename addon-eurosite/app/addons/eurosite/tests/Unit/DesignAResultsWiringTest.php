<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A Design A hotel page gets Eurosite's offers as room cards (board 13b;
 * docs/hotel-page-design-a-plan.md): the search builds them from the same
 * offers when the page asks (design=a) and the search is for one room, and
 * the results template shows them instead of the offer list; the card's
 * terms link loads the offer's terms from offer_terms (design=a). Every
 * other search renders as before.
 */
final class DesignAResultsWiringTest extends TestCase
{
    private static function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function source(string $path): string
    {
        return (string) file_get_contents(self::addonRoot() . '/' . $path);
    }

    public function testTheSearchBuildsTheCardsOnlyWhenADesignAPageAsksForOneRoom(): void
    {
        $controller = self::source('controllers/frontend/eurosite_booking/search.php');

        self::assertStringContainsString("\$designOffers[] = ['offer' => \$offer, 'key' => \$offerKeys[\$i] ?? ''];", $controller);
        self::assertStringContainsString('if (DesignA::requested($_REQUEST) && $roomCount === 1) {', $controller);
        self::assertStringContainsString('DesignARates::view($designOffers, $cartProductId, $checkIn, $checkOut, $adults, $childrenAges)', $controller);
        self::assertStringContainsString("if ((\$designA['rooms'] ?? []) !== []) {", $controller, 'nothing bookable (all stop sale): the list, which says so');
        self::assertStringContainsString("\$view->assign('travel_design_a_results', \$designAResults);", $controller);
    }

    public function testTheTemplateShowsThemInPlaceOfTheOfferList(): void
    {
        $src = (string) file_get_contents(dirname(self::addonRoot(), 3) . '/design/themes/responsive/templates/addons/eurosite/views/eurosite_booking/search.tpl');

        $form = strpos($src, '{$booking_engine_html nofilter}');
        $designA = strpos($src, '{if $travel_design_a_results}');
        $include = strpos($src, '{include file="addons/travel_core/components/design_a_results.tpl" results=$travel_design_a_results}');
        $list = strpos($src, '{foreach from=$eurosite_results item=hotel}');
        $close = strpos($src, '{/if}{* End of the Design A else *}');
        $modal = strpos($src, '<div id="info-modal"');
        foreach ([$form, $designA, $include, $list, $close, $modal] as $at) {
            self::assertIsInt($at);
        }
        self::assertLessThan($designA, $form, 'after the search bar, inside the region the inline search swaps');
        self::assertLessThan($include, $designA);
        self::assertLessThan($list, $include, 'the offer list is the else');
        self::assertLessThan($close, $list);
        self::assertLessThan($modal, $close);
    }

    public function testTheTermsEndpointAnswersTheCardWithSections(): void
    {
        $terms = self::source('controllers/frontend/eurosite_booking/offer_terms.php');

        self::assertStringContainsString('$designA = DesignA::requested($_REQUEST);', $terms);
        self::assertStringContainsString("['sections' => [], 'error' => 'offer_expired']", $terms);
        self::assertStringContainsString("echo json_encode(['sections' => DesignARates::termsSections(\$fees, \$paymentLines, \$currency, \$designATexts,", $terms);
        self::assertStringContainsString("echo json_encode(['sections' => DesignARates::termsSections([], \$paymentLines, \$currency, \$designATexts,", $terms, 'Eurosite down: the fees are confirmed at booking');
    }
}
