<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\ReservationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\BookingSubmissionService;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

/**
 * CS-Cart runs place_order_post again for a cart whose booking was already
 * sent (order edits, the parent order after its vendor sub-orders), and two
 * place-order requests can run at once. A second hotel_res_RQ would be a
 * duplicate reservation at the hotel: only the request that claims the
 * booking for its order (BookingRepository::claimForOrder, a conditional
 * UPDATE) sends it.
 */
#[CoversClass(BookingSubmissionService::class)]
final class BookingSubmissionServiceResubmitTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigProvider::reset();
    }

    /** @return array<string, mixed> */
    private static function cart(): array
    {
        return ['products' => [
            'c1' => ['product_id' => 7, 'price' => 795.0, 'extra' => ['novoton_booking' => true, 'novoton_booking_id' => 12]],
        ]];
    }

    public function testABookingAnotherRequestClaimedIsNeverSent(): void
    {
        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->expects(self::once())->method('claimForOrder')->with(12, 55)->willReturn(false);
        $repo->expects(self::never())->method('update');
        $repo->expects(self::never())->method('create');
        $repo->expects(self::never())->method('findByIdHydrated');

        $reservations = $this->createMock(ReservationApiClientInterface::class);
        $reservations->expects(self::never())->method(self::anything());

        (new BookingSubmissionService($repo, $reservations))->submitOrder(55, self::cart());
    }

    public function testTheRequestThatClaimsTheBookingGoesOnToSubmission(): void
    {
        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->expects(self::once())->method('claimForOrder')->with(12, 55)->willReturn(true);
        // Reaching the DB hydration step proves the booking was not skipped.
        $repo->expects(self::once())->method('findByIdHydrated')->with(12)
            ->willThrowException(new \RuntimeException('stop here'));

        $reservations = $this->createMock(ReservationApiClientInterface::class);

        try {
            (new BookingSubmissionService($repo, $reservations))->submitOrder(55, self::cart());
        } catch (\RuntimeException $e) {
            self::assertSame('stop here', $e->getMessage());
        }
    }
}
