<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\SupplierRoomLabels;

/**
 * Each room of a booking as Novoton names it, for the admin: its IdRoom and,
 * from the hotel's stored hotelinfo, its Type.
 */
#[CoversClass(SupplierRoomLabels::class)]
final class SupplierRoomLabelsTest extends TestCase
{
    /** The rooms of hotelinfo 4402 as the sync stores them in hotel_data. */
    private const string ROOMS_4402 = '[{"IdRoom":"TRP 2+1","Type":"TRP","RB":"2","EB":"1","maxADT":"3","maxCHD":"2","minPAX":"1"},'
        . '{"IdRoom":"QUAT 2+2 FRENCH BALCONY","Type":"QUATRIPLE","RB":"2","EB":"2","maxADT":"2","maxCHD":"2","minPAX":"1"}]';

    /** Hotel 4658 (ARDO VEL) as a store keeps it: hotel_data's $.rooms. */
    private const string ROOMS_4658 = '[{"IdRoom":"DBL 2+0","Type":"DBL","RB":"2","EB":"0","maxADT":"2","maxCHD":"1","minPAX":"1"},'
        . '{"IdRoom":"SGL","Type":"SGL","RB":"1","EB":"0","maxADT":"1","maxCHD":"0","minPAX":"1"},'
        . '{"IdRoom":"STUDIO 2+1","Type":"STUDIO","RB":"2","EB":"1","maxADT":"3","maxCHD":"2","minPAX":"1"},'
        . '{"IdRoom":"FAM 2+2","Type":"FAMILY","RB":"2","EB":"2","maxADT":"3","maxCHD":"2","minPAX":"1"}]';

    private static function labels(): SupplierRoomLabels
    {
        return new SupplierRoomLabels(static fn (string $hotelId): array => match ($hotelId) {
            '4402' => SupplierRoomLabels::typesFromJson(self::ROOMS_4402),
            '4658' => SupplierRoomLabels::typesFromJson(self::ROOMS_4658),
            default => [],
        });
    }

    public function testEachRoomReadsIdRoomAndType(): void
    {
        self::assertSame(
            ['QUAT 2+2 FRENCH BALCONY (QUATRIPLE)', 'TRP 2+1 (TRP)'],
            self::labels()->labels('4402', ['QUAT 2+2 FRENCH BALCONY', 'TRP 2+1']),
        );
    }

    public function testAnEncodedCodeStillFindsItsType(): void
    {
        // Booking forms post the code URL-encoded ("+" as %2B).
        self::assertSame(['TRP 2+1 (TRP)'], self::labels()->labels('4402', ['TRP 2%2B1']));
    }

    public function testACodeTheHotelDoesNotListKeepsItsCodeAlone(): void
    {
        self::assertSame(['Doble room'], self::labels()->labels('4402', ['Doble room']));
        self::assertSame(['TRP 2+1'], self::labels()->labels('9999', ['TRP 2+1']), 'a hotel without stored rooms');
    }

    public function testATypeThatOnlyRepeatsTheCodeIsNotRepeated(): void
    {
        $labels = new SupplierRoomLabels(static fn (): array => ['DBL' => 'dbl']);

        self::assertSame(['DBL'], $labels->labels('1', ['DBL']));
    }

    public function testNoCodesGiveNoLabels(): void
    {
        self::assertSame([], self::labels()->labels('4402', []));
        self::assertSame([], self::labels()->labels('4402', ['', ' ']));
    }

    public function testAHotelWithOneRoomIsStoredAsAnObject(): void
    {
        self::assertSame(['TRP 2+1' => 'TRP'], SupplierRoomLabels::typesFromJson('{"IdRoom":"TRP 2+1","Type":"TRP"}'));
        self::assertSame([], SupplierRoomLabels::typesFromJson(''));
        self::assertSame([], SupplierRoomLabels::typesFromJson('null'));
    }

    public function testRoomIdsComeFromRoomsDataElseTheLineRoom(): void
    {
        self::assertSame(
            ['TRP 2+1', 'QUAT 2+2 FRENCH BALCONY'],
            SupplierRoomLabels::roomIds('[{"room_id":"TRP 2+1"},{"room_id":"QUAT 2+2 FRENCH BALCONY"}]', 'TRP 2+1'),
        );
        self::assertSame(['TRP 2+1'], SupplierRoomLabels::roomIds([['room_id' => 'TRP 2+1']], ''));
        self::assertSame(['DBL 2+0'], SupplierRoomLabels::roomIds(null, 'DBL 2+0'));
    }

    public function testTheHotelsOwnRoomsReadAsABookedOneDoes(): void
    {
        self::assertSame(['DBL 2+0 (DBL)', 'SGL', 'STUDIO 2+1 (STUDIO)', 'FAM 2+2 (FAMILY)'], self::labels()->hotelRooms('4658'));
        self::assertSame([], self::labels()->hotelRooms('9999'), 'a hotel without stored rooms');
        self::assertSame([], self::labels()->hotelRooms(''));
    }

    public function testARoomTheHotelDoesNotListShowsAgainstItsRooms(): void
    {
        // Booking #27 booked "Doble room"; ARDO VEL's hotelinfo lists other codes.
        self::assertSame(['Doble room'], self::labels()->labels('4658', ['Doble room']));
        self::assertNotContains('Doble room', self::labels()->hotelRooms('4658'));
    }

    public function testAnAllDigitRoomCodeReadsToo(): void
    {
        $labels = new SupplierRoomLabels(static fn (): array => SupplierRoomLabels::typesFromJson('[{"IdRoom":"101","Type":"DBL"}]'));

        self::assertSame(['101 (DBL)'], $labels->hotelRooms('1'));
        self::assertSame(['101 (DBL)'], $labels->labels('1', ['101']));
    }

    public function testEachHotelIsReadOnce(): void
    {
        $reads = 0;
        $labels = new SupplierRoomLabels(static function () use (&$reads): array {
            $reads++;

            return SupplierRoomLabels::typesFromJson(self::ROOMS_4658);
        });

        $labels->labels('4658', ['DBL 2+0']);
        $labels->hotelRooms('4658');

        self::assertSame(1, $reads);
    }
}
