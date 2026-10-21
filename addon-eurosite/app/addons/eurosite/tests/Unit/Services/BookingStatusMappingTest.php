<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\BookingStatusService;

/**
 * Eurosite booking statuses onto the shared vocabulary. The sync read a
 * <Status> element the API never sends, so no booking status ever changed;
 * the API sends <ItemStatus Code="...">.
 */
final class BookingStatusMappingTest extends TestCase
{
    public function testItemStatusCodes(): void
    {
        self::assertSame('confirmed', BookingStatusService::mapApiStatus('C'));
        self::assertSame('pending', BookingStatusService::mapApiStatus('CP'));
        self::assertSame('failed', BookingStatusService::mapApiStatus('RJ'), 'rejected by the operator');
        self::assertSame('cancelled', BookingStatusService::mapApiStatus('X'));
        self::assertSame('', BookingStatusService::mapApiStatus('XP'), 'a cancellation still waiting changes nothing yet');
        self::assertSame('confirmed', BookingStatusService::mapApiStatus(' c '));
    }

    public function testLabelsWhenThereIsNoCode(): void
    {
        self::assertSame('pending', BookingStatusService::mapApiStatus('Confirmation pending'), 'not a confirmation');
        self::assertSame('pending', BookingStatusService::mapApiStatus('Cancel pending'));
        self::assertSame('confirmed', BookingStatusService::mapApiStatus('Confirmed'));
        self::assertSame('cancelled', BookingStatusService::mapApiStatus('Anulat'));
        self::assertSame('', BookingStatusService::mapApiStatus(''));
        self::assertSame('on hold', BookingStatusService::mapApiStatus('On Hold'), 'unknown values pass through');
    }
}
