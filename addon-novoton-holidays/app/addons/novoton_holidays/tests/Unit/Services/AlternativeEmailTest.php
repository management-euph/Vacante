<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\AlternativeEmail;

/**
 * The alternatives emails go out on the templates addon.xml registers
 * (area C) and carry what those templates print: a name to greet and, per
 * alternative, a hotel and a room — alternative_RS sends only ids.
 */
#[CoversClass(AlternativeEmail::class)]
final class AlternativeEmailTest extends TestCase
{
    public function testTheMailerGetsARegisteredCustomerTemplate(): void
    {
        $params = AlternativeEmail::params(AlternativeEmail::ALTERNATIVES_FOUND, 'guest@example.com', ['hotel_name' => 'Edart']);

        self::assertSame('novoton_holidays_alternatives_available', $params['template_code']);
        self::assertSame('guest@example.com', $params['to']);
        self::assertSame(['hotel_name' => 'Edart'], $params['data']);
        self::assertArrayNotHasKey('tpl', $params, 'no fallback to a .tpl file that does not exist');
        self::assertSame('C', AlternativeEmail::AREA);
    }

    public function testEachAlternativeGetsAHotelNameAndARoom(): void
    {
        $request = ['request_id' => 12, 'hotel_id' => '4535', 'hotel_name' => 'Edart', 'check_in' => '2027-07-10', 'check_out' => '2027-07-17', 'contact_email' => 'guest@example.com'];
        $alternatives = [
            ['hotel_id' => '4535', 'room_id' => 'DBL 2+0', 'board_id' => 'BB', 'check_in' => '2027-07-12', 'check_out' => '2027-07-19', 'total' => '840'],
            ['hotel_id' => '777', 'room_id' => 'STUDIO', 'board_id' => '', 'check_in' => '2027-07-10', 'check_out' => '2027-07-17', 'total' => '700'],
            ['hotel_id' => '888', 'room_id' => 'DBL', 'board_id' => 'AI', 'total' => '990'],
            'junk',
        ];
        $names = ['777' => 'Sol Nessebar'];

        $data = AlternativeEmail::foundData($request, $alternatives, 'customer', static fn (string $id): string => $names[$id] ?? '');

        self::assertSame('customer', $data['customer_name'], '"Dear customer," — the form asks for no name');
        self::assertSame('Edart', $data['hotel_name']);
        self::assertSame('2027-07-10', $data['check_in']);
        self::assertSame(12, $data['request_id']);

        $rows = $data['alternatives'];
        self::assertIsArray($rows);
        self::assertCount(3, $rows);
        self::assertSame(['Edart', 'DBL 2+0 BB', '840'], [$rows[0]['hotel_name'], $rows[0]['room_type'], $rows[0]['total']], 'same hotel: the request\'s name');
        self::assertSame(['Sol Nessebar', 'STUDIO'], [$rows[1]['hotel_name'], $rows[1]['room_type']], 'another hotel: looked up');
        self::assertSame('Edart', $rows[2]['hotel_name'], 'an unknown hotel falls back to the requested one');
    }

    public function testNamesTheAlternativeAlreadyCarriesAreKept(): void
    {
        $data = AlternativeEmail::foundData(
            ['hotel_id' => '1', 'hotel_name' => 'A'],
            [['hotel_id' => '2', 'hotel_name' => 'Given', 'room_type' => 'Suite', 'room_id' => 'X']],
            'customer',
            static fn (string $id): string => 'Looked up',
        );
        $rows = $data['alternatives'];
        self::assertIsArray($rows);

        self::assertSame(['Given', 'Suite'], [$rows[0]['hotel_name'], $rows[0]['room_type']]);
    }
}
