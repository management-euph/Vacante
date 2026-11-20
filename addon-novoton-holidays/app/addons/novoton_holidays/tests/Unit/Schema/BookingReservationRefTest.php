<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Regression + schema-contract pins for the booking "reservation reference".
 *
 * History: queries and writers referenced a `novoton_reservation_id` column
 * that NO schema ever created — the bulk reservation-status check died with
 * "Unknown column" on schema-faithful installs, and the RQ-alternatives cron
 * silently skipped every booking ("no reservation ID"). The fix then read
 * novoton_confirm_id and novoton_res_num, which nothing writes either:
 * submission keeps hotel_res_RQ's IdNum in novoton_invoice_id, so every job
 * that asks Novoton about a booking reads it through ReservationNumber.
 */
final class BookingReservationRefTest extends TestCase
{
    private static function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testFindWithReservationIdUsesRealReferenceColumns(): void
    {
        $src = self::read('src/Repository/BookingQueryRepository.php');

        self::assertStringNotContainsString(', novoton_reservation_id FROM', $src);
        self::assertStringContainsString("novoton_invoice_id IS NOT NULL AND novoton_invoice_id != ''", $src);
        self::assertStringContainsString("novoton_confirm_id IS NOT NULL AND novoton_confirm_id != ''", $src);
        self::assertStringContainsString("novoton_res_num IS NOT NULL AND novoton_res_num != ''", $src);
    }

    public function testEveryJobThatAsksNovotonReadsTheNumberSubmissionWrites(): void
    {
        $submission = self::read('src/Services/BookingSubmissionService.php');
        self::assertStringContainsString("'novoton_invoice_id' => \$novotonId", $submission);

        foreach (['functions/bookings.php', 'src/Cron/Commands/AlternativesCommand.php', 'src/Services/AskBookingPoller.php'] as $file) {
            $src = self::read($file);
            self::assertStringContainsString('ReservationNumber::of($booking)', $src, $file);
            self::assertStringNotContainsString("\$booking['novoton_confirm_id']", $src, $file);
            self::assertStringNotContainsString("\$booking['novoton_reservation_id']", $src, $file);
        }
    }

    public function testDeadPhantomColumnWriterIsGone(): void
    {
        self::assertStringNotContainsString(
            'setReservationId',
            self::read('src/Repository/BookingRepository.php'),
        );
        self::assertStringNotContainsString(
            'setReservationId',
            self::read('src/Repository/BookingRepositoryInterface.php'),
        );
    }

    /**
     * Schema contract: every column the listing queries select must exist in
     * the novoton_bookings CREATE TABLE — the guard that would have caught
     * the phantom column on day one.
     */
    public function testEveryListColumnExistsInTheSchema(): void
    {
        $repo = self::read('src/Repository/BookingQueryRepository.php');
        self::assertSame(
            1,
            preg_match("/LIST_COLUMNS = '([^']+)'/s", $repo, $m),
            'LIST_COLUMNS constant not found',
        );
        $columns = array_filter(array_map('trim', explode(',', $m[1])));
        self::assertGreaterThan(30, count($columns));

        $xml = self::read('addon.xml');
        $createPos = strpos($xml, 'CREATE TABLE IF NOT EXISTS `?:novoton_bookings`');
        self::assertNotFalse($createPos, 'novoton_bookings CREATE TABLE not found');
        $enginePos = strpos($xml, 'ENGINE=', $createPos);
        self::assertNotFalse($enginePos);
        $createBlock = substr($xml, $createPos, $enginePos - $createPos);

        foreach ($columns as $column) {
            self::assertStringContainsString(
                "`{$column}`",
                $createBlock,
                "Column `{$column}` is selected by BookingQueryRepository but missing from the novoton_bookings schema",
            );
        }
    }
}
