<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarViewModel;

/**
 * A trip in the booking summary (package, circuit): its kind over the name,
 * its journeys, and what the price includes and leaves out. A hotel leaves
 * them empty, and the sidebar shows none of it.
 */
final class BookingSidebarTripTest extends TestCase
{
    private static function sidebar(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 7) . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_sidebar.tpl',
        );
    }

    public function testAHotelCarriesNoTripParts(): void
    {
        $view = (new BookingSidebarViewModel(name: 'Adalya Elite Lara'))->toViewArray();

        self::assertSame('', $view['kind_label']);
        self::assertSame([], $view['legs']);
        self::assertSame([], $view['includes']);
        self::assertSame([], $view['excludes']);
    }

    public function testATripCarriesItsKindJourneysAndInclusions(): void
    {
        $legs = [
            ['title' => '✈ Dus · sâm. 17 iul. 2027', 'line' => '15:40 București (OTP) → 17:35 Heraklion (HER)', 'note' => 'Zbor ROT 5331'],
            ['title' => '✈ Întors · sâm. 24 iul. 2027', 'line' => '18:30 Heraklion (HER) → 20:25 București (OTP)', 'note' => 'Zbor ROT 5332'],
        ];
        $view = (new BookingSidebarViewModel(
            name: 'Hotel',
            kindLabel: 'Pachet · avion + hotel',
            legs: $legs,
            includes: ['zbor dus-întors', 'taxe de aeroport'],
            excludes: ['asigurare medicală'],
        ))->toViewArray();

        self::assertSame('Pachet · avion + hotel', $view['kind_label']);
        self::assertSame($legs, $view['legs']);
        self::assertSame(['zbor dus-întors', 'taxe de aeroport'], $view['includes']);
        self::assertSame(['asigurare medicală'], $view['excludes']);
    }

    public function testTheSidebarShowsThemOnlyWhenGiven(): void
    {
        $tpl = self::sidebar();

        $kind = strpos($tpl, '{if $tbs.kind_label|default:""}<p class="travel-bsidebar-kind">{$tbs.kind_label|escape:html}</p>{/if}');
        self::assertNotFalse($kind);
        self::assertLessThan(strpos($tpl, '<h2 class="travel-bsidebar-name">'), $kind, 'the kind sits over the name');
        self::assertStringContainsString('{if $tbs.legs|default:[]}', $tpl);
        self::assertStringContainsString('<div><dt>{$tbs_leg.title|escape:html}</dt><dd>{$tbs_leg.line|escape:html}{if $tbs_leg.note}<span class="travel-bsidebar-legs__note">{$tbs_leg.note|escape:html}</span>{/if}</dd></div>', $tpl);
        self::assertStringContainsString('{__("travel_core.trip_included")}', $tpl);
        self::assertStringContainsString('{__("travel_core.trip_not_included")}', $tpl);
        // The legs and inclusions sit in the summary card, under the room and board.
        self::assertLessThan(strpos($tpl, '{if $tbs.legs|default:[]}'), (int) strpos($tpl, '<div class="travel-bsidebar-board">'));
    }

    /**
     * A trip with a flight warns that the names reach the airline (amber);
     * its button keeps its own label when the cart is not skipped.
     */
    public function testATripPageWarnsAboutTheAirlineAndKeepsItsButtonLabel(): void
    {
        $root = dirname(__DIR__, 7) . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/';
        $hint = (string) file_get_contents($root . 'booking_guest_hint.tpl');
        self::assertStringContainsString('{if $gh_airline|default:false}', $hint);
        self::assertStringContainsString('<p class="travel-guest-hint travel-guest-hint--airline">', $hint);
        self::assertStringContainsString('{__("travel_core.guest_names_airline")}', $hint);

        $actions = (string) file_get_contents($root . 'booking_form_actions.tpl');
        self::assertStringContainsString('{if $fa_label_key|default:""}{fn_travel_core_booking_cta_label($fa_addon, $fa_label_key)}{else}{fn_travel_core_booking_cta_label($fa_addon)}{/if}', $actions);

        $keys = require dirname(__DIR__, 7) . '/addon-travel-core/app/addons/travel_core/lang_keys.php';
        self::assertIsArray($keys);
        foreach (['guest_names_airline', 'trip_included', 'trip_not_included'] as $key) {
            self::assertNotSame('', $keys['travel_core.' . $key]['ro'] ?? '', $key);
            self::assertNotSame('', $keys['travel_core.' . $key]['en'] ?? '', $key);
        }
    }
}
