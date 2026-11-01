<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * A board's supplement ("BB" + "HB" is half board, OfferBoard) travels with
 * the offer from the search card to the reservation. The services are
 * covered by their own tests; this pins the templates and controllers that
 * hand it from one to the next, so a link or a form field cannot drop it
 * again (it was dropped everywhere: half board was booked as breakfast).
 */
final class BoardSupplementWiringTest extends TestCase
{
    private const string VIEWS = '/templates/addons/novoton_holidays/views/novoton_booking/';

    private static function addonRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function read(string $path): string
    {
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return list<string> */
    private static function themes(): array
    {
        return ['responsive', 'nova_theme'];
    }

    public function testTheSearchCardShowsAndSendsTheSupplement(): void
    {
        foreach (self::themes() as $theme) {
            $tpl = self::read(self::addonRoot() . '/design/themes/' . $theme . self::VIEWS . 'search.tpl');

            // The meal the guest gets names the option: the supplement, else the board
            self::assertSame(2, substr_count($tpl, '{$__bcode = $result.ext_board_id|default:$result.board_id|default:\'\'|trim|upper|replace:\' \':\'\'}'), $theme);
            self::assertStringContainsString('&ext_board_id=`$result.ext_board_id|default:\'\'|escape:\'url\'`', $tpl, $theme . ': the Book link');
            self::assertStringContainsString('data-ext-board-id="{$result.ext_board_id|default:\'\'}"', $tpl, $theme . ': the multi-room radios');
        }
    }

    public function testTheBookingFormPostsAndRepricesTheSupplement(): void
    {
        foreach (self::themes() as $theme) {
            $tpl = self::read(self::addonRoot() . '/design/themes/' . $theme . self::VIEWS . 'booking_form.tpl');

            self::assertStringContainsString('<input type="hidden" name="ext_board_id" value="{$booking_data.ext_board_id|default:\'\'}" />', $tpl, $theme);
            self::assertStringContainsString('extBoardId: \'{$booking_data.ext_board_id|default:""|escape:"javascript"}\',', $tpl, $theme);
        }

        $controller = self::read(dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/booking_form.php');
        self::assertStringContainsString("'ext_board_id' => PriceInfoFormatter::toScalar(\$bookingData['ext_board_id'] ?? ''),", $controller);
    }

    public function testTheRepriceAndTheCartLineCarryIt(): void
    {
        $recalc = self::read(dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/ajax_recalculate_price.php');
        self::assertStringContainsString("\$ext_board_id = PriceInfoFormatter::toScalar(\$data['ext_board_id'] ?? '');", $recalc);
        self::assertMatchesRegularExpression('/BookedOffer::find\([\s\S]*?\$package_name,\s*\$ext_board_id,/', $recalc);

        $cart = self::read(dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/add_to_cart.php');
        self::assertStringContainsString("'ext_board_id' => \$first_room['ext_board_id'],", $cart);
        self::assertStringContainsString("fn_novoton_holidays_format_board_name(\$priced_room['board_id'], \$priced_room['ext_board_id'])", $cart);
    }
}
