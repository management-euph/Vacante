<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\BookOutcome;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * A book* answer is a confirmation only when Sphinx says so. Every answer
 * that did not throw used to mark the booking confirmed — a refusal (the
 * client answers null on an HTTP 4xx) included.
 */
#[CoversClass(BookOutcome::class)]
final class BookOutcomeTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function answers(): iterable
    {
        // The documented answer (hotels/book, packages/book).
        yield 'confirmed' => [['order_id' => 123456, 'booking_confirmation_number' => 'ABC123XYZ', 'status' => 'confirmed'], TravelConstants::STATUS_CONFIRMED, 'ABC123XYZ'];
        yield 'confirmed, any case and spaces' => [['booking_confirmation_number' => 'V-9', 'status' => ' Confirmed '], TravelConstants::STATUS_CONFIRMED, 'V-9'];
        // Older answers carried the voucher code without a status.
        yield 'a voucher code, no status' => [['booking_confirmation_number' => 'V-1'], TravelConstants::STATUS_CONFIRMED, 'V-1'];
        yield 'booking_reference, the old key' => [['booking_reference' => 'R-2'], TravelConstants::STATUS_CONFIRMED, 'R-2'];
        yield 'on request' => [['order_id' => 7, 'booking_confirmation_number' => 'OR-1', 'status' => 'on_request'], TravelConstants::STATUS_PENDING, 'OR-1'];
        yield 'processing' => [['order_id' => 7, 'status' => 'processing'], TravelConstants::STATUS_PENDING, ''];
        yield 'unconfirmed' => [['order_id' => 7, 'status' => 'unconfirmed'], TravelConstants::STATUS_FAILED, ''];
        yield 'errored' => [['order_id' => 7, 'status' => 'errored'], TravelConstants::STATUS_FAILED, ''];
        yield 'canceled' => [['order_id' => 7, 'status' => 'canceled'], TravelConstants::STATUS_FAILED, ''];
        yield 'an unknown status' => [['order_id' => 7, 'status' => 'queued'], TravelConstants::STATUS_PENDING, ''];
        yield 'neither status nor voucher' => [['order_id' => 7], TravelConstants::STATUS_PENDING, ''];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('answers')]
    public function testAnAnswerMeansWhatItsStatusSays(array $answer, string $status, string $voucher): void
    {
        $outcome = BookOutcome::of($answer, false, '');

        self::assertSame($status, $outcome->status);
        self::assertSame($voucher, $outcome->voucher);
        self::assertSame($status === TravelConstants::STATUS_CONFIRMED, $outcome->isConfirmed());
        self::assertSame($status === TravelConstants::STATUS_CONFIRMED, $outcome->reason === '', 'a reason exactly when not confirmed');
    }

    public function testARefusalFailsWithTheSuppliersReason(): void
    {
        $outcome = BookOutcome::of(null, false, 'Client error HTTP 422: {"message":"The price has changed"}');

        self::assertSame(TravelConstants::STATUS_FAILED, $outcome->status);
        self::assertSame('Client error HTTP 422: {"message":"The price has changed"}', $outcome->reason);
        self::assertFalse($outcome->isConfirmed());
    }

    public function testNoAnswerAndNoErrorStillFails(): void
    {
        self::assertSame('no answer', BookOutcome::of(null, false, '  ')->reason);
    }

    /**
     * A 5xx or a timeout: the booking may exist at Sphinx. Failed would
     * offer a retry, and a retry would book it twice.
     */
    public function testNoAnswerThatMayHaveBeenProcessedStaysPending(): void
    {
        $outcome = BookOutcome::of(null, true, 'Server error HTTP 502 (not resent: the request may have reached Sphinx)');

        self::assertSame(TravelConstants::STATUS_PENDING, $outcome->status);
        self::assertStringContainsString('Server error HTTP 502', $outcome->reason);
        self::assertStringContainsString('may exist at Sphinx', $outcome->reason);
    }

    public function testTheStatusesThatSayNoBookingStands(): void
    {
        foreach (['errored', 'unconfirmed', 'canceled', 'cancelled', ' Errored '] as $status) {
            self::assertTrue(BookOutcome::isNoBooking($status), $status);
        }
        foreach (['confirmed', 'on_request', 'processing', ''] as $status) {
            self::assertFalse(BookOutcome::isNoBooking($status), $status);
        }
    }

    public function testAnAnswerOutranksTheTransportFlag(): void
    {
        self::assertTrue(BookOutcome::of(['status' => 'confirmed'], true, 'stale error')->isConfirmed());
    }
}
