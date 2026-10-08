<?php

declare(strict_types=1);

namespace {
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }
    if (!defined('CONTROLLER_STATUS_REDIRECT')) {
        define('CONTROLLER_STATUS_REDIRECT', 'redirect');
    }
}

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema {

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

    /**
     * novoton_booking.update_booking rewrites a booking's travellers, e-mail,
     * phone and guests_data (later sent to Novoton), so it must only run on
     * POST — the edit form posts, and CS-Cart checks the form's security hash
     * on POST. A GET link must redirect before touching the session or the DB
     * (audit C1).
     */
    final class UpdateBookingPostOnlyTest extends TestCase
    {
        private const string CONTROLLER = '/controllers/frontend/novoton_booking/update_booking.php';

        /** @var array<string, mixed> */
        private array $serverBackup = [];

        /** @var array<string, mixed> */
        private array $requestBackup = [];

        protected function setUp(): void
        {
            $this->serverBackup = $_SERVER;
            $this->requestBackup = $_REQUEST;
            DbStub::reset();
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->serverBackup;
            $_REQUEST = $this->requestBackup;
            DbStub::reset();
        }

        /** @return array<string, array{0: string|null}> */
        public static function nonPostMethods(): array
        {
            return [
                'GET' => ['GET'],
                'HEAD' => ['HEAD'],
                'no method (CLI)' => [null],
            ];
        }

        #[DataProvider('nonPostMethods')]
        public function testNonPostRequestIsRedirectedBeforeAnyWork(?string $method): void
        {
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
            $_REQUEST = [
                'booking_id' => '1',
                'cart_id' => '123',
                'guests' => ['r1_a1' => ['first_name' => 'Evil', 'last_name' => 'Name']],
                'contact' => ['email' => 'attacker@example.com', 'phone' => '000'],
            ];

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

            self::assertSame([CONTROLLER_STATUS_REDIRECT, 'checkout.cart'], $result);
            self::assertFalse($dbTouched, 'a non-POST request must not reach the database');
        }

        public function testPostGuardRunsBeforeTheOwnershipCheck(): void
        {
            $src = (string) file_get_contents(dirname(__DIR__, 3) . self::CONTROLLER);

            $guard = strpos($src, "!== 'POST'");
            $ownership = strpos($src, '->checkOwnership(');
            self::assertNotFalse($guard, 'update_booking must refuse non-POST requests');
            self::assertNotFalse($ownership);
            self::assertLessThan($ownership, $guard);
        }
    }
}
