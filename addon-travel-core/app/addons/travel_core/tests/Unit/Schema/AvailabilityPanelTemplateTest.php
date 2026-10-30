<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the hotel page's availability panel
 * (components/availability_panel.tpl, docs/novoton-availability-gaps-plan.md
 * §7–8):
 *
 *  - the booking form mount includes it before the swap shell, so it sits
 *    above the search on both hotel page templates (and every template that
 *    renders the mount), and the inline search never replaces it;
 *  - its "notify me" form posts to travel_availability.notify with the
 *    security hash, a honeypot kept out of sight by a class (no inline
 *    style), a required email and an unticked consent box the guest must
 *    tick (§8);
 *  - the hook assigns it for every hotel page; AvailabilityPanelView says
 *    null while the panels are switched off.
 */
final class AvailabilityPanelTemplateTest extends TestCase
{
    private const REL = 'templates/addons/travel_core/components/availability_panel.tpl';

    private const MOUNT_REL = 'templates/addons/travel_core/components/booking_form_mount.tpl';

    private static function repoAddonRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function read(string $theme, string $rel): string
    {
        return (string) file_get_contents(self::repoAddonRoot() . '/design/themes/' . $theme . '/' . $rel);
    }

    public function testThePanelSitsAboveTheSearchOutsideTheSwapShellInBothThemes(): void
    {
        foreach (['responsive', 'nova_theme'] as $theme) {
            self::assertSame(self::read('responsive', self::REL), self::read($theme, self::REL), $theme . ': byte-identical copies (composer mirror)');

            $mount = self::read($theme, self::MOUNT_REL);
            $panel = strpos($mount, '{include file="addons/travel_core/components/availability_panel.tpl"}');
            $shell = strpos($mount, '<div class="travel-search-results-page travel-pdp-results">');
            self::assertIsInt($panel, $theme . ': the mount includes the panel');
            self::assertIsInt($shell, $theme . ': the swap shell is there');
            self::assertLessThan($shell, $panel, $theme . ': the panel comes first, outside the shell the inline search replaces');
            self::assertSame(1, substr_count($mount, '{include file="addons/travel_core/components/availability_panel.tpl"'), $theme . ': once');
        }
    }

    public function testTheNotifyFormCarriesWhatTheControllerAndTheConsentNeed(): void
    {
        $tpl = self::read('responsive', self::REL);

        foreach ([
            '<form class="travel-avail-notify__form" method="post"',
            '<input type="hidden" name="dispatch" value="travel_availability.notify">',
            '<input type="hidden" name="security_hash" value="{$security_hash}">',
            '<input type="hidden" name="product_id" value="{$travel_avail.product_id}">',
            'name="website" value="" class="travel-avail-notify__trap" tabindex="-1" autocomplete="off" aria-hidden="true"',
            'name="email" class="travel-avail-notify__email" required',
            '<input type="checkbox" name="consent" value="Y" required>',
            '{__("travel_core.notify_consent")}',
            '{__("travel_core.notify_me_`$travel_avail.notify`")}',
        ] as $needle) {
            self::assertStringContainsString($needle, $tpl);
        }
        self::assertDoesNotMatchRegularExpression('/name="consent"[^>]*checked/', $tpl, 'the consent box starts unticked');
        self::assertStringNotContainsString('style=', $tpl, 'no inline styles: booking-pages.css');
        self::assertStringContainsString('{if $travel_avail.notify}', $tpl, 'no form for a hotel that takes no request');
    }

    public function testEveryTextComesFromALanguageKeyBothLanguagesHave(): void
    {
        $tpl = self::read('responsive', self::REL);
        /** @var array<string, array<string, string>> $keys */
        $keys = require dirname(__DIR__, 3) . '/lang_keys.php';

        preg_match_all('/__\("(travel_core\.[a-z0-9_]+)"/', $tpl, $m);
        $used = array_unique([
            ...$m[1],
            'travel_core.notify_me_prices',
            'travel_core.notify_me_booking',
            'travel_core.notify_me_return',
            'travel_core.availability_panel_between_seasons',
            'travel_core.availability_panel_published_from',
            'travel_core.availability_panel_not_offered',
            'travel_core.availability_panel_dormant',
            'travel_core.availability_panel_unavailable',
        ]);
        self::assertGreaterThan(10, count($used));
        foreach ($used as $key) {
            self::assertNotSame('', $keys[$key]['en'] ?? '', $key . ' (en)');
            self::assertNotSame('', $keys[$key]['ro'] ?? '', $key . ' (ro)');
        }
    }

    public function testTheHookAssignsThePanelForEveryHotelPage(): void
    {
        $hooks = (string) file_get_contents(dirname(__DIR__, 3) . '/hooks/cart_hooks.php');

        self::assertStringContainsString("\$view->assign('travel_availability_panel', (new AvailabilityPanelView(", $hooks);
    }
}
