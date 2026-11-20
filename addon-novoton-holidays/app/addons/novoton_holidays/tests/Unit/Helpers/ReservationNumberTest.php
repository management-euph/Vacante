<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\ReservationNumber;

/**
 * Submission keeps hotel_res_RQ's IdNum in novoton_invoice_id; the jobs
 * that ask Novoton about a booking read it from there first.
 */
final class ReservationNumberTest extends TestCase
{
    public function testTheNumberSubmissionWritesComesFirst(): void
    {
        self::assertSame('77123', ReservationNumber::of(['novoton_invoice_id' => '77123', 'novoton_confirm_id' => null, 'novoton_res_num' => null]));
        self::assertSame('77123', ReservationNumber::of(['novoton_invoice_id' => ' 77123 ', 'novoton_confirm_id' => 'C1']));
    }

    public function testOlderColumnsAreReadWhenItIsEmpty(): void
    {
        self::assertSame('C1', ReservationNumber::of(['novoton_invoice_id' => '', 'novoton_confirm_id' => 'C1', 'novoton_res_num' => 'R1']));
        self::assertSame('R1', ReservationNumber::of(['novoton_invoice_id' => '0', 'novoton_res_num' => 'R1']));
    }

    public function testABookingNotSentYetHasNone(): void
    {
        self::assertSame('', ReservationNumber::of([]));
        self::assertSame('', ReservationNumber::of(['novoton_invoice_id' => '', 'novoton_confirm_id' => null, 'novoton_res_num' => '']));
    }
}
