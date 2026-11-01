<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * A Design A hotel page gets Novoton's offers as room cards
 * (docs/hotel-page-design-a-plan.md, phase 4): the search builds them from
 * the same rows when the page asks (design=a) and the search is for one
 * room, and the results template shows them instead of today's cards.
 * Several rooms (phase 5, boards 13c): grouped by their guests when every
 * room has rates, booked by the same booking form. Every other search page
 * renders as before.
 */
final class DesignAResultsWiringTest extends TestCase
{
    private const TPL = 'templates/addons/novoton_holidays/views/novoton_booking/search.tpl';

    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function template(string $theme): string
    {
        return (string) file_get_contents(dirname(__DIR__, 6) . '/design/themes/' . $theme . '/' . self::TPL);
    }

    public function testTheSearchBuildsTheCardsOnlyWhenADesignAPageAsksForOneRoom(): void
    {
        $controller = (string) file_get_contents(self::addonRoot() . '/controllers/frontend/novoton_booking/search.php');

        self::assertStringContainsString(
            "if (DesignA::requested(\$_REQUEST) && \$results !== [] && !\$searchResult['is_multi_room']) {",
            $controller,
        );
        self::assertStringContainsString("\$view->assign('travel_design_a_results', DesignARates::view(\$results, \$novotonParams));", $controller);
        self::assertLessThan(
            strpos($controller, 'DesignARates::view('),
            (int) strpos($controller, '$formatter->assignToView('),
            'after the current view variables',
        );
    }

    public function testSeveralRoomsAreGroupedWhenEveryRoomHasRates(): void
    {
        $controller = (string) file_get_contents(self::addonRoot() . '/controllers/frontend/novoton_booking/search.php');

        self::assertStringContainsString("} elseif (DesignA::requested(\$_REQUEST) && \$searchResult['is_multi_room']", $controller);
        self::assertStringContainsString("&& DesignARates::everyRoomPriced(\$searchResult['all_room_results'], \$novotonParams)) {", $controller);
        self::assertStringContainsString("\$view->assign('travel_design_a_groups', DesignARates::groupsView(\$searchResult['all_room_results'], \$novotonParams));", $controller);
        self::assertStringContainsString("\$view->assign('travel_design_a_book', DesignARates::bookForm(\$novotonParams, TypeCoerce::toString(Registry::get('config.current_location'))));", $controller);
        self::assertStringContainsString('use Tygh\\Registry;', $controller);
    }

    public function testTheTemplateShowsThemInPlaceOfTodaysCards(): void
    {
        $src = self::template('responsive');
        self::assertSame($src, self::template('nova_theme'), 'nova_theme must match (composer mirror)');

        $results = strpos($src, '{if $novoton_results && $novoton_results|count > 0}');
        $designA = strpos($src, '{elseif $travel_design_a_results}');
        $groups = strpos($src, '{if $travel_design_a_groups}');
        self::assertIsInt($groups);
        self::assertLessThan($designA, $groups, 'several rooms first');
        self::assertStringContainsString('{include file="addons/travel_core/components/design_a_groups.tpl" groups=$travel_design_a_groups book=$travel_design_a_book}', $src);
        $include = strpos($src, '{include file="addons/travel_core/components/design_a_results.tpl" results=$travel_design_a_results}');
        $banner = strpos($src, 'novoton-early-banner');
        $close = strpos($src, '{/if}{* End of the Design A else *}');
        $alternatives = strpos($src, '{elseif $no_availability_message && $alternative_results');
        foreach ([$results, $designA, $include, $banner, $close, $alternatives] as $at) {
            self::assertIsInt($at);
        }
        self::assertLessThan($designA, $results);
        self::assertLessThan($include, $designA);
        self::assertLessThan($banner, $include, 'the early-booking banner and today\'s cards are the else');
        self::assertLessThan($alternatives, $close, 'no offers: the alternatives as before');
    }
}
