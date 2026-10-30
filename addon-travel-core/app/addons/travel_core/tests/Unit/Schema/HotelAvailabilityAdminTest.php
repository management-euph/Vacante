<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Cron\CronOverview;
use Tygh\Addons\TravelCore\Services\AvailabilityReview;

/**
 * Pins the Hotel availability report's wiring
 * (docs/novoton-availability-gaps-plan.md §4.5, §6): the weekly review rides
 * on Travel Core's daily exchange-rates line in both cron entry points, with
 * no crontab line of its own, and the page is reachable, paged, exportable
 * and allowed.
 */
final class HotelAvailabilityAdminTest extends TestCase
{
    private static function file(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
    }

    private static function template(string $view): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 6) . '/design/backend/templates/addons/travel_core/views/' . $view,
        );
    }

    public function testTheReviewRidesOnTheDailyExchangeRatesRun(): void
    {
        foreach (['cron.php', 'controllers/frontend/travel_cron.php'] as $entry) {
            $source = self::file($entry);
            $rates = strpos($source, 'fn_travel_core_format_exchange_rate_output($result)');
            $review = strpos($source, 'AvailabilityReview::runDaily()');

            self::assertNotFalse($rates, $entry);
            self::assertNotFalse($review, "{$entry} never runs the weekly review");
            self::assertGreaterThan($rates, $review, "{$entry}: the review runs after the rates, which it must never hold up");
            self::assertStringNotContainsString("'hotel_availability'", $source, "{$entry}: no cron mode of its own");
        }

        self::assertContains(AvailabilityReview::JOB, CronOverview::CORE_MODES, 'its runs count in Travel Core\'s cron health');
    }

    public function testThePageListsPagesExportsAndReviews(): void
    {
        $controller = self::file('controllers/backend/travel_hotel_availability.php');
        self::assertStringContainsString("\$_SERVER['REQUEST_METHOD'] === 'POST' && \$mode === 'run'", $controller, 'Review now is POST only');
        self::assertStringContainsString('AvailabilityReview::run(true)', $controller);
        self::assertStringContainsString("\$mode === 'export'", $controller);
        self::assertStringContainsString('getListing', $controller);

        $tpl = self::template('travel_hotel_availability/manage.tpl');
        self::assertStringContainsString('common/pagination.tpl', $tpl);
        self::assertStringContainsString('{foreach from=$availability_rows', $tpl);
        self::assertStringContainsString('"travel_hotel_availability.run"|fn_url', $tpl);
        self::assertStringContainsString('name="security_hash"', $tpl);
        self::assertStringContainsString('$availability_export_url|fn_url', $tpl);
    }

    /** Decisions and the brake's confirmation change products: POST only, with the security hash, back to the same view. */
    public function testDecisionsAndTheBrakesConfirmationArePosted(): void
    {
        $controller = self::file('controllers/backend/travel_hotel_availability.php');
        self::assertStringContainsString("\$_SERVER['REQUEST_METHOD'] === 'POST' && \$mode === 'override'", $controller);
        self::assertStringContainsString("\$_SERVER['REQUEST_METHOD'] === 'POST' && \$mode === 'apply_gate'", $controller);
        self::assertStringContainsString("preg_replace('/[^A-Za-z0-9_=&%.+-]/', '', RequestCoerce::string(\$_REQUEST, 'return_query'))", $controller, 'never an outside address');
        self::assertStringNotContainsString('redirect_url', $controller);

        $tpl = self::template('travel_hotel_availability/manage.tpl');
        self::assertStringContainsString('<input type="hidden" name="dispatch" value="travel_hotel_availability.override" />', $tpl);
        self::assertStringContainsString('"travel_hotel_availability.apply_gate"|fn_url', $tpl);
        self::assertStringContainsString('class="btn btn-primary cm-confirm"', $tpl, 'the held hiding asks before it runs');
        self::assertSame(3, substr_count($tpl, 'name="security_hash" value="{$security_hash}"'), 'review, decision, confirmation');
        self::assertStringContainsString('{foreach from=$availability_gate_filters item=gate}', $tpl);
    }

    public function testThePageIsInTheMenusAndAllowed(): void
    {
        self::assertStringContainsString("'travel_hotel_availability.manage'", self::file('schemas/menu/actions.post.php'));
        self::assertStringContainsString("'travel_hotel_availability.manage'", self::file('schemas/menu/menu.post.php'));
        self::assertStringContainsString("\$schema['travel_hotel_availability']", self::file('schemas/permissions/admin.post.php'));
    }

    public function testTheToolsPageShowsTheLastReview(): void
    {
        self::assertStringContainsString('AvailabilityReview::lastTotals()', self::file('controllers/backend/travel_tools.php'));

        $tpl = self::template('travel_tools/manage.tpl');
        self::assertStringContainsString('$availability_totals', $tpl);
        self::assertStringContainsString('"travel_hotel_availability.manage"|fn_url', $tpl);
    }
}
