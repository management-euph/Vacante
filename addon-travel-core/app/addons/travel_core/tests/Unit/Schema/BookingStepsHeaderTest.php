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
        self::assertStringContainsString('$bs_title|default:$page_title', $tpl);
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
        foreach (['step_guests' => 'Guests', 'step_payment' => 'Payment', 'step_search_back' => null, 'step_locked_hint' => null, 'step_current' => null] as $key => $en) {
            $row = $keys['travel_core.' . $key] ?? null;
            self::assertIsArray($row, $key);
            self::assertNotSame('', $row['ro'] ?? '', $key . ' ro');
            if ($en !== null) {
                self::assertSame($en, $row['en'] ?? null);
            }
            self::assertStringContainsString('{__("travel_core.' . $key . '")', self::tpl());
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
