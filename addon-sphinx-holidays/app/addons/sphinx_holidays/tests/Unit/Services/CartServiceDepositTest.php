<?php

declare(strict_types=1);

namespace {
    // The same stand-in as OrderHooksBindingTest (whichever file loads first
    // defines it): CS-Cart's notification, recorded there.
    if (!function_exists('fn_set_notification')) {
        function fn_set_notification(string $type, mixed $title, mixed $message): void
        {
            \Tygh\Addons\SphinxHolidays\Tests\Unit\Hooks\OrderHooksBindingTest::$notifications[] = [$type, (string) $message];
        }
    }
}

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services {

    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\SphinxHolidays\Services\CartService;
    use Tygh\Addons\SphinxHolidays\Tests\Unit\Hooks\OrderHooksBindingTest;
    use Tygh\Addons\TravelCore\Services\DepositPolicy;

    /**
     * "Pay a deposit" on a booking form: hotels, circuits and packages ask
     * CartService::chosenDeposit() for the plan, which works it out again
     * from the payment terms of what is booked. The form posts only the
     * choice (pay_mode); an amount in it counts for nothing.
     */
    final class CartServiceDepositTest extends TestCase
    {
        /** Sphinx's cumulative payment rules: 20 % on booking (today), the rest by 2027-07-01. */
        private const array TERMS_20_80 = ['is_loaded' => true, 'rules' => [
            ['until' => '2027-05-10', 'value' => 185.4],
            ['until' => '2027-07-01', 'value' => 927],
        ]];

        protected function setUp(): void
        {
            OrderHooksBindingTest::$notifications = [];
        }

        public function testTheDepositComesFromTheBookedOffersTerms(): void
        {
            $plan = CartService::chosenDeposit(
                ['pay_mode' => 'deposit', 'deposit' => '1', 'ratio' => '0.01'],
                null,
                self::TERMS_20_80,
                new DepositPolicy(true),
                '2027-05-10',
            );

            self::assertNotNull($plan);
            self::assertSame([0.2, '2027-07-01'], [$plan->ratio, $plan->balanceDue], 'the terms\' 20 %, never a figure from the form');
            self::assertSame([], OrderHooksBindingTest::$notifications);
        }

        public function testTheFullPriceUnlessADepositIsAskedForAndOffered(): void
        {
            $policy = new DepositPolicy(true);

            self::assertNull(CartService::chosenDeposit([], null, self::TERMS_20_80, $policy, '2027-05-10'));
            self::assertNull(CartService::chosenDeposit(['pay_mode' => 'full'], null, self::TERMS_20_80, $policy, '2027-05-10'));
            self::assertNull(
                CartService::chosenDeposit(['pay_mode' => 'deposit'], null, self::TERMS_20_80, new DepositPolicy(false), '2027-05-10'),
                'the store offers no deposits',
            );
            self::assertSame([], OrderHooksBindingTest::$notifications, 'nothing to tell: no deposit was asked for that could be given');
        }

        /** The terms no longer allow one (all due now): the full price, and the guest is told. */
        public function testADepositTheTermsNoLongerGiveFallsBackToTheFullPrice(): void
        {
            $allNow = ['is_loaded' => true, 'rules' => [['until' => '2027-05-10', 'value' => 927]]];

            self::assertNull(CartService::chosenDeposit(['pay_mode' => 'deposit'], null, $allNow, new DepositPolicy(true), '2027-05-10'));
            self::assertSame([['W', 'travel_core.deposit_unavailable']], OrderHooksBindingTest::$notifications);
        }
    }
}
