<?php

declare(strict_types=1);

namespace {
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }
    if (!defined('CONTROLLER_STATUS_REDIRECT')) {
        define('CONTROLLER_STATUS_REDIRECT', 'redirect');
    }
    if (!function_exists('fn_set_notification')) {
        /** Records CS-Cart notifications (same recorder as CalculateCartItemsTest). */
        function fn_set_notification(string $type, string $title, string $message = ''): void
        {
            $GLOBALS['__nvt_test_notifications'][] = [$type, $title, $message];
        }
    }
}

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema {

    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
    use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

    /**
     * novoton_booking.request_alternatives sends a hotel_request to Novoton and
     * an e-mail to the address typed in. It now answers only a POST, drops
     * what fills the hidden honeypot field, limits how often one address is
     * mailed, and no longer takes the hotel's name from the form. Bookings are
     * linked to an account by browser session only, never by an e-mail
     * address nobody verified.
     */
    final class RequestAlternativesGuardTest extends TestCase
    {
        private const string CONTROLLER = '/controllers/frontend/novoton_booking/request_alternatives.php';

        /** @var array<string, mixed> */
        private array $serverBackup = [];

        /** @var array<string, mixed> */
        private array $requestBackup = [];

        protected function setUp(): void
        {
            $this->serverBackup = $_SERVER;
            $this->requestBackup = $_REQUEST;
            $GLOBALS['__nvt_test_notifications'] = [];
            DbStub::reset();
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->serverBackup;
            $_REQUEST = $this->requestBackup;
            unset($GLOBALS['__nvt_test_notifications']);
            DbStub::reset();
        }

        /** @param array<string, string> $request */
        private function dispatch(?string $method, array $request): mixed
        {
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
            $_REQUEST = $request + ['hotel_id' => '4535', 'check_in' => '2027-07-10', 'contact_email' => 'guest@example.com'];

            $dbTouched = false;
            $spy = static function () use (&$dbTouched) {
                $dbTouched = true;
                return null;
            };
            DbStub::$getRow = $spy;
            DbStub::$getField = $spy;
            DbStub::$query = $spy;

            $path = dirname(__DIR__, 3) . self::CONTROLLER;
            $result = (static fn () => include $path)();
            self::assertFalse($dbTouched, 'nothing is read or written');

            return $result;
        }

        public function testAGetLinkDoesNothing(): void
        {
            self::assertSame([CONTROLLER_STATUS_REDIRECT, 'novoton_booking.search'], $this->dispatch('GET', []));
            self::assertSame([], $GLOBALS['__nvt_test_notifications']);
        }

        public function testABotFillingTheHoneypotIsToldItWorked(): void
        {
            self::assertSame([CONTROLLER_STATUS_REDIRECT, 'novoton_booking.search'], $this->dispatch('POST', ['website' => 'https://spam.example']));
            self::assertSame([['N', 'notice', 'novoton_holidays.alternatives_request_sent']], $GLOBALS['__nvt_test_notifications']);
        }

        public function testTheHotelNameIsNotTakenFromTheForm(): void
        {
            $src = (string) file_get_contents(dirname(__DIR__, 3) . self::CONTROLLER);

            self::assertStringNotContainsString("'hotel_name'", $src);
            self::assertStringContainsString("checkRateLimit('alternatives_email_' . \$contact_email, 3, 86400)", $src);
        }

        public function testBothThemesCarryTheHoneypotAndNoHotelName(): void
        {
            $root = dirname(__DIR__, 6) . '/design/themes/';
            $tpl = '/templates/addons/novoton_holidays/views/novoton_booking/search.tpl';
            foreach (['responsive', 'nova_theme'] as $theme) {
                $src = (string) file_get_contents($root . $theme . $tpl);
                self::assertStringContainsString('<input type="text" name="website" value="" class="travel-avail-notify__trap" tabindex="-1" autocomplete="off" aria-hidden="true">', $src, $theme);
                self::assertStringNotContainsString('name="hotel_name"', $src, $theme);
            }
        }

        public function testBookingsAreNeverLinkedByEMail(): void
        {
            $hooks = (string) file_get_contents(dirname(__DIR__, 3) . '/hooks/auth_hooks.php');

            self::assertStringNotContainsString('linkToUserByEmail', $hooks);
            self::assertSame(2, substr_count($hooks, '->linkToUserBySession('));
            self::assertFalse(method_exists(BookingRepositoryInterface::class, 'linkToUserByEmail'));
        }
    }
}
