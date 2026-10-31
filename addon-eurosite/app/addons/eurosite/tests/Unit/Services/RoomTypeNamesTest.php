<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\RoomTypeNames;

/**
 * A Eurosite room on a Romanian page reads by its general code; other
 * languages keep Eurosite's own name.
 */
final class RoomTypeNamesTest extends TestCase
{
    /** getRoomRequest as Eurosite answered it on 31 Oct 2026, with the agreed Romanian names. */
    private const array CATALOG = [
        'AP' => ['apartament', 'Apartament'],
        'DB' => ['double', 'Cameră dublă'],
        'DB3' => ['double 3 adults', 'Cameră dublă, 3 adulți'],
        'D3X' => ['double 3 adults 1 child over 2 years', 'Cameră dublă, 3 adulți, 1 copil peste 2 ani'],
        'D3C' => ['double 3 adults 1 child under 2 years', 'Cameră dublă, 3 adulți, 1 copil sub 2 ani'],
        'D2X' => ['double with 2 children', 'Cameră dublă cu 2 copii'],
        'DBX' => ['double with child ( age over 2 years )', 'Cameră dublă cu copil peste 2 ani'],
        'DBC' => ['double with child ( age under 2 years )', 'Cameră dublă cu copil sub 2 ani'],
        'Q' => ['quad', 'Cameră cvadruplă'],
        'SB' => ['single', 'Cameră single'],
        'S2X' => ['single as double with 2 children', 'Cameră single folosită ca dublă, cu 2 copii'],
        'SBC' => ['single with child ( age under 2 years )', 'Cameră single cu copil sub 2 ani'],
        'SD' => ['studio', 'Studio'],
        'TR' => ['triple', 'Cameră triplă'],
        'TRC' => ['triple with child ( age under 2 years )', 'Cameră triplă cu copil sub 2 ani'],
        'TB' => ['twin', 'Cameră twin (paturi separate)'],
        'TS' => ['twin for sole use', 'Cameră twin în regim single'],
        'TSC' => ['twin for sole use with child ( age under 2 years )', 'Cameră twin în regim single, cu copil sub 2 ani'],
        'TSX' => ['twin for sole use with child over 2 year2', 'Cameră twin în regim single, cu copil peste 2 ani'],
        'TBX' => ['twin with child ( age over 2 years )', 'Cameră twin cu copil peste 2 ani'],
        'TBC' => ['twin with child ( age under 2 years )', 'Cameră twin cu copil sub 2 ani'],
        'VL' => ['vila', 'Vilă'],
    ];

    public function testEveryCatalogRoomReadsInRomanian(): void
    {
        foreach (self::CATALOG as $code => [$name, $ro]) {
            self::assertSame($ro, RoomTypeNames::label($code, $name, 'ro'), $code);
        }
    }

    public function testAHotelsOwnWordingOfTheSameRoomReadsTheSame(): void
    {
        self::assertSame('Cameră dublă', RoomTypeNames::label('DB', 'Double Room', 'ro'));
        self::assertSame('Cameră dublă', RoomTypeNames::label('db', '', 'ro'), 'no name: the code alone');
    }

    public function testWhatTheHotelAddsIsKept(): void
    {
        self::assertSame('Cameră dublă (Double Room Sea View)', RoomTypeNames::label('DB', 'Double Room Sea View', 'ro'));
    }

    public function testAnOfferWithoutACodeIsReadByItsName(): void
    {
        self::assertSame('Vilă', RoomTypeNames::label('', 'Vila', 'ro'));
    }

    public function testOtherLanguagesAndUnknownCodesKeepEurositesName(): void
    {
        self::assertSame('Double Room', RoomTypeNames::label('DB', 'Double Room', 'en'));
        self::assertSame('Penthouse', RoomTypeNames::label('PH', 'Penthouse', 'ro'));
    }
}
