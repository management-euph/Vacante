<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\BookingAdminProvider;
use Tygh\Addons\NovotonHolidays\Services\ReservationExchange;
use Tygh\Addons\NovotonHolidays\Services\SupplierRoomLabels;

/**
 * Guards two travel_bookings admin-grid fixes (devx report):
 *
 *  - Bug A: the "View in Novoton" icon opened the WRONG booking. getProviderViewUrl
 *    must route through novoton's own controller (which maps the novoton booking id
 *    to the unified travel_bookings surrogate), NOT straight at travel_bookings.view
 *    with the novoton id — those id-spaces collide (each provider's first booking is
 *    id 1).
 *  - Bug B: the "Provider Ref" column showed a meaningless internal "#1". The real
 *    reference is the Novoton confirmation / invoice / resinfo number; when none
 *    exists yet (pending) it must be empty, not the internal id.
 */
final class BookingAdminProviderTest extends TestCase
{
    /** @param array<string, mixed> $booking */
    private function providerReturning(array $booking): BookingAdminProvider
    {
        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->method('findById')->willReturn($booking);

        return new BookingAdminProvider($repo);
    }

    public function testProviderViewUrlRoutesThroughNovotonControllerNotUnifiedView(): void
    {
        $provider = new BookingAdminProvider($this->createMock(BookingRepositoryInterface::class));

        // Must translate through novoton_bookings.view (id -> surrogate), never
        // point the novoton id straight at the surrogate-keyed unified view.
        self::assertSame('novoton_bookings.view?booking_id=7', $provider->getProviderViewUrl('7'));
    }

    public function testProviderRefPrefersConfirmationId(): void
    {
        $display = $this->providerReturning([
            'novoton_confirm_id' => 'CONF123',
            'novoton_invoice_id' => 'INV999',
            'novoton_res_num'    => 'RES777',
        ])->getDisplayData('1');

        self::assertSame('NT CONF123', $display['provider_ref']);
    }

    public function testProviderRefFallsBackToInvoiceThenResNum(): void
    {
        $invoiceOnly = $this->providerReturning([
            'novoton_invoice_id' => 'INV999',
        ])->getDisplayData('1');
        self::assertSame('NT INV999', $invoiceOnly['provider_ref']);

        $resNumOnly = $this->providerReturning([
            'novoton_res_num' => 'RES777',
        ])->getDisplayData('1');
        self::assertSame('NT RES777', $resNumOnly['provider_ref']);
    }

    public function testProviderRefIsEmptyForPendingBookingWithNoApiReference(): void
    {
        // A pending booking has no confirmation/invoice/resinfo reference yet.
        // The grid renders "-" from an empty provider_ref (never the internal id).
        $display = $this->providerReturning([
            'status'         => 'pending',
            'novoton_status' => '',
        ])->getDisplayData('1');

        self::assertSame('', $display['provider_ref']);
    }

    public function testARefusedBookingOffersToRequestAlternatives(): void
    {
        $actions = $this->providerReturning([])->getAvailableActions([
            'provider_booking_id' => '12',
            'provider_display' => ['novoton_status' => 'ST'],
        ]);

        self::assertCount(1, $actions);
        self::assertSame('request_alternatives', $actions[0]['name']);
        self::assertSame('POST', $actions[0]['method']);
        self::assertSame('travel_bookings.provider_action', $actions[0]['url']);
        self::assertSame('12', $actions[0]['booking_id']);
        self::assertSame(['provider_action' => 'request_alternatives'], $actions[0]['extra_params']);
    }

    public function testThePageNamesEachRoomAsNovotonDoesAndTheListNeverAsks(): void
    {
        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->method('findById')->willReturn([
            'status' => 'pending',
            'hotel_id' => '4402',
            'room_id' => 'QUAT 2+2 FRENCH BALCONY',
            'rooms_data' => '[{"room_id":"QUAT 2+2 FRENCH BALCONY"}]',
            'api_request' => '{"hotel_id":"4402","room_id":"QUAT 2+2 FRENCH BALCONY"}',
        ]);
        $reads = 0;
        $rooms = new SupplierRoomLabels(static function () use (&$reads): array {
            $reads++;

            return ['QUAT 2+2 FRENCH BALCONY' => 'QUATRIPLE'];
        });
        $provider = new BookingAdminProvider($repo, $rooms, new ReservationExchange(static fn (): string => '<hotel_res_RQ/>', $rooms));

        self::assertArrayNotHasKey('supplier_rooms', $provider->getDisplayData('1'));
        self::assertSame(0, $reads, 'the bookings list reads no hotel for its rows');

        $details = $provider->getPageDetails('1');
        self::assertSame(['QUAT 2+2 FRENCH BALCONY (QUATRIPLE)'], $details['supplier_rooms']);
        self::assertContains('<hotel_res_RQ/>', array_column($details['technical'] ?? [], 'value'));
        self::assertSame(1, $reads, 'the page reads the hotel once');
    }

    public function testAMissingBookingHasNoPageDetails(): void
    {
        self::assertSame([], $this->providerReturning([])->getPageDetails('99'));
    }
}
