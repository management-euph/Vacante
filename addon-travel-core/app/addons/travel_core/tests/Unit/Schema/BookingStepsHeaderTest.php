<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the booking page header (components/booking_steps.tpl): the page
 * title and the progress bar on one row, no breadcrumb.
 *
 * The stepper rule a later edit could quietly break: only the PAST step
 * (Search) is a link, back to the guest's own results; the current step is
 * marked; future steps are never links, because clicking ahead to payment
 * before the guest details are in only produces validation errors.
 */
final class BookingStepsHeaderTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 7);
    }

    private static function tpl(): string
    {
        return (string) file_get_contents(
            self::root() . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_steps.tpl',
        );
    }

    public function testTitleAndStepsShareOneHeaderRow(): void
    {
        $tpl = self::tpl();

        self::assertStringContainsString('<div class="travel-booking-head', $tpl);
        self::assertStringContainsString('<h1 class="travel-booking-head__title">', $tpl);
        // "Complete Booking" (short, so it fits beside the bar); edit mode keeps its own page title.
        self::assertStringContainsString('__("travel_core.complete_booking_title")', $tpl);
        self::assertStringContainsString('{$bs_default_title = $page_title', $tpl);
        self::assertStringContainsString('$bs_title|default:$bs_default_title', $tpl);
        self::assertLessThan(strpos($tpl, '<nav class="travel-steps-nav"'), strpos($tpl, '<h1'), 'title first, then the steps');
    }

    public function testOnlyTheSearchStepIsALink(): void
    {
        $tpl = self::tpl();

        self::assertSame(1, substr_count($tpl, '<a '), 'exactly one link: Search');
        $link = strpos($tpl, '<a class="travel-steps__link"');
        $done = strpos($tpl, 'travel-steps__item--done');
        $active = strpos($tpl, 'travel-steps__item--active');
        self::assertNotFalse($link);
        self::assertGreaterThan($done, $link);
        self::assertLessThan($active, $link, 'the link lives in the done step');
        self::assertStringContainsString('href="{$bs_back|fn_url}"', $tpl);
        self::assertStringContainsString('$bs_search_url|default:$travel_booking_sidebar.change_url', $tpl);

        // Upcoming steps are plain text.
        $upcoming = substr($tpl, (int) strpos($tpl, 'travel-steps__item--upcoming'));
        self::assertStringNotContainsString('<a ', $upcoming);
        self::assertSame(2, substr_count($tpl, 'travel-steps__item--upcoming'));
    }

    public function testCurrentStepIsMarkedForAssistiveTech(): void
    {
        $tpl = self::tpl();

        self::assertStringContainsString('travel-steps__item--active" aria-current="step"', $tpl);
        self::assertStringContainsString('travel_core.step_current', $tpl);
        self::assertStringContainsString('travel_core.step_locked_hint', $tpl);
        self::assertStringContainsString('<nav class="travel-steps-nav" aria-label=', $tpl);
    }

    public function testShortLabelsHaveBothLanguages(): void
    {
        $keys = require self::root() . '/addon-travel-core/app/addons/travel_core/lang_keys.php';
        self::assertIsArray($keys);
        foreach (['complete_booking_title' => 'Complete Booking', 'step_guests' => 'Guests', 'step_payment' => 'Payment', 'step_search_back' => null, 'step_locked_hint' => null, 'step_current' => null] as $key => $en) {
            $row = $keys['travel_core.' . $key] ?? null;
            self::assertIsArray($row, $key);
            self::assertNotSame('', $row['ro'] ?? '', $key . ' ro');
            if ($en !== null) {
                self::assertSame($en, $row['en'] ?? null);
            }
            self::assertStringContainsString('__("travel_core.' . $key . '")', self::tpl());
        }
    }

    /** The breadcrumb is gone from the three booking pages (the title row replaces it). */
    public function testBookingPagesAddNoBreadcrumb(): void
    {
        foreach ([
            'addon-sphinx-holidays/app/addons/sphinx_holidays/controllers/frontend/sphinx_booking/booking_form.php',
            'addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking/booking_form.php',
            'eurosite_addon/app/addons/eurosite/controllers/frontend/eurosite_booking/booking_form.php',
        ] as $controller) {
            $src = (string) file_get_contents(self::root() . '/' . $controller);
            self::assertStringNotContainsString('fn_add_breadcrumb(', $src, $controller);
            self::assertStringContainsString("'page_title'", $src, $controller . ' keeps the <title>');
        }
    }

    /**
     * The sidebar never gets its own scrollbar: the page scrolls as one and
     * only the price + cancellation cards stay pinned beside the form.
     */
    public function testSidebarHasNoInnerScrollbar(): void
    {
        $sidebar = (string) file_get_contents(
            self::root() . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_sidebar.tpl',
        );
        $sticky = strpos($sidebar, '<div class="travel-bsidebar__sticky">');
        self::assertNotFalse($sticky);
        self::assertLessThan(strpos($sidebar, 'travel-bcard--price'), $sticky, 'the price card is inside the pinned block');
        self::assertLessThan(strpos($sidebar, '</aside>'), (int) strpos($sidebar, 'id="travel-cancel-card"'));

        $css = (string) file_get_contents(
            self::root() . '/addon-travel-core/design/themes/responsive/css/addons/travel_core/booking-pages.css',
        );
        self::assertStringNotContainsString('max-height: calc(100vh', $css);
        self::assertMatchesRegularExpression('/\.travel-bsidebar__sticky \{\s*position: sticky;/', $css);
        self::assertDoesNotMatchRegularExpression('/\.travel-bsidebar \{[^}]*overflow-y: auto/', $css);
    }

    /**
     * Deposit / balance under the total: rendered by the page (sphinx) and
     * refilled by novoton's re-price, from one partial.
     */
    public function testPaymentSplitIsWiredForEveryPath(): void
    {
        $root = self::root();
        $sidebar = (string) file_get_contents($root . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_sidebar.tpl');
        self::assertStringContainsString('<div id="travel-price-split">{include file="addons/travel_core/components/booking_payment_split.tpl" ps=$tbs.payment_split', $sidebar);

        $partial = (string) file_get_contents($root . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_payment_split.tpl');
        foreach (['split_deposit', 'split_balance', 'due_by'] as $key) {
            self::assertStringContainsString('__("travel_core.' . $key . '"', $partial);
        }

        self::assertStringContainsString('$factory->split($installments, $primaryTotal)', (string) file_get_contents($root . '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/ViewModels/SphinxBookingSidebarBuilder.php'));
        self::assertStringContainsString('\'split_html\' => $split_html', (string) file_get_contents($root . '/addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking/ajax_recalculate_price.php'));
        self::assertStringContainsString('renderPaymentSplit(data, isMultiRoom);', (string) file_get_contents($root . '/addon-novoton-holidays/js/addons/novoton_holidays/booking-form.js'));

        $keys = require $root . '/addon-travel-core/app/addons/travel_core/lang_keys.php';
        self::assertIsArray($keys);
        self::assertSame('Avans', $keys['travel_core.split_deposit']['ro'] ?? null);
        self::assertSame('Rest de plată', $keys['travel_core.split_balance']['ro'] ?? null);
    }

    /** One h1 per page: the sidebar hotel name steps down to h2. */
    public function testSidebarHotelNameIsNotASecondH1(): void
    {
        $sidebar = (string) file_get_contents(
            self::root() . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_sidebar.tpl',
        );

        self::assertStringContainsString('<h2 class="travel-bsidebar-name">', $sidebar);
        self::assertStringNotContainsString('<h1', $sidebar);
    }
}
