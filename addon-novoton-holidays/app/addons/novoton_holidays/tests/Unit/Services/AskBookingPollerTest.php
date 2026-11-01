<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Api\Contracts\ReservationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\AskBookingPoller;

/**
 * Novoton's answer (28 Oct 2026): poll only bookings still on request (ASK),
 * about every 15–30 minutes, and stop once the status is OK, ST or RQ.
 */
final class AskBookingPollerTest extends TestCase
{
    /** @var list<array{int, array<string, mixed>}> */
    private array $updates = [];

    /**
     * @param array<string, string|\Throwable> $answers reservation number => Status, or what the call throws
     * @param list<array<string, mixed>> $bookings
     * @param (\Closure(array<string, mixed>, string): string)|null $answered
     */
    private function poller(array $answers, array $bookings, ?\Closure $answered = null): AskBookingPoller
    {
        $reservations = $this->createStub(ReservationApiClientInterface::class);
        $reservations->method('getReservationInfo')->willReturnCallback(static function (string $id) use ($answers): \SimpleXMLElement {
            $answer = $answers[$id] ?? '';
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return new \SimpleXMLElement('<resinfo><Status>' . $answer . '</Status></resinfo>');
        });
        $api = $this->createStub(NovotonApiKitInterface::class);
        $api->method('reservations')->willReturn($reservations);

        $repo = $this->createStub(BookingRepositoryInterface::class);
        $repo->method('findByNovotonStatus')->willReturn($bookings);
        $repo->method('update')->willReturnCallback(function (int $id, array $data): bool {
            $this->updates[] = [$id, $data];

            return true;
        });

        return new AskBookingPoller($api, $repo, null, $answered);
    }

    /** @return array<string, mixed> */
    private static function booking(int $id, string $reservation): array
    {
        return ['booking_id' => $id, 'novoton_confirm_id' => $reservation, 'novoton_res_num' => '', 'status' => 'ask', 'novoton_status' => 'ASK'];
    }

    /** @return array<int, array<string, mixed>> booking id => what was written */
    private function written(): array
    {
        $out = [];
        foreach ($this->updates as [$id, $data]) {
            $out[$id] = $data;
        }

        return $out;
    }

    public function testOkStAndRqEndThePolling(): void
    {
        $stats = $this->poller(
            ['R1' => 'OK', 'R2' => 'ST', 'R3' => 'RQ', 'R4' => 'ASK'],
            [self::booking(1, 'R1'), self::booking(2, 'R2'), self::booking(3, 'R3'), self::booking(4, 'R4')],
        )->poll();

        $w = $this->written();
        self::assertSame(['confirmed', 'Good'], [$w[1]['status'], $w[1]['novoton_status']]);
        self::assertSame(['cancelled', 'ST'], [$w[2]['status'], $w[2]['novoton_status']]);
        self::assertSame(['pending', 'RQ'], [$w[3]['status'], $w[3]['novoton_status']], 'RQ leaves ASK: the alternatives jobs take it from there');
        self::assertArrayNotHasKey('novoton_status', $w[4], 'still ASK: asked again on the next run');
        self::assertArrayHasKey('last_status_check', $w[4]);
        self::assertSame(['checked' => 4, 'changed' => 3, 'errors' => 0], $stats);
    }

    public function testOneUnansweredBookingDoesNotStopTheOthers(): void
    {
        $stats = $this->poller(
            ['R1' => new ApiException('timeout'), 'R2' => 'OK'],
            [self::booking(1, 'R1'), self::booking(2, 'R2')],
        )->poll();

        self::assertSame(['checked' => 1, 'changed' => 1, 'errors' => 1], $stats);
        self::assertSame([2], array_keys($this->written()), 'nothing written for the one without an answer');
    }

    public function testWordsReadAsTheStatusCodes(): void
    {
        self::assertSame('Good', AskBookingPoller::novotonStatus('OK'));
        self::assertSame('Good', AskBookingPoller::novotonStatus('confirmed'));
        self::assertSame('ST', AskBookingPoller::novotonStatus('Rejected'));
        self::assertSame('WT', AskBookingPoller::novotonStatus('WT'));
    }

    /**
     * Each answer that ends the polling is handed on (RequestAnswerMail: the
     * guest when the hotel confirms, the store when it refuses or cancels),
     * with the booking as it now stands; one still ASK is not.
     */
    public function testEachAnswerThatEndsThePollingIsHandedOn(): void
    {
        $told = [];
        $answered = static function (array $booking, string $status) use (&$told): string {
            $told[] = [$booking['booking_id'], $status, $booking['status'], $booking['novoton_status']];

            return $status === 'Good' ? 'guest' : '';
        };

        $this->poller(
            ['R1' => 'OK', 'R2' => 'RQ', 'R3' => 'ASK', 'R4' => 'ST'],
            [self::booking(1, 'R1'), self::booking(2, 'R2'), self::booking(3, 'R3'), self::booking(4, 'R4')],
            $answered,
        )->poll();

        self::assertSame([
            [1, 'Good', 'confirmed', 'Good'],
            [2, 'RQ', 'pending', 'RQ'],
            [4, 'ST', 'cancelled', 'ST'],
        ], $told);
    }

    public function testAnEmailThatCannotGoOutNeverStopsTheRun(): void
    {
        $answered = static function (array $booking): string {
            if ($booking['booking_id'] === 1) {
                throw new \RuntimeException('SMTP down');
            }

            return 'guest';
        };

        $stats = $this->poller(['R1' => 'OK', 'R2' => 'OK'], [self::booking(1, 'R1'), self::booking(2, 'R2')], $answered)->poll();

        self::assertSame(['checked' => 2, 'changed' => 2, 'errors' => 0], $stats);
        self::assertSame(['confirmed', 'confirmed'], array_column($this->written(), 'status'), 'both answers stored');
    }

    public function testABookingWithoutAReservationNumberIsSkipped(): void
    {
        $stats = $this->poller([], [['booking_id' => 7, 'novoton_confirm_id' => '', 'novoton_res_num' => '']])->poll();

        self::assertSame(['checked' => 0, 'changed' => 0, 'errors' => 0], $stats);
        self::assertSame([], $this->updates);
    }
}
