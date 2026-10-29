<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\BookedOffer;
use Tygh\Addons\NovotonHolidays\Services\RoomOfferRows;

/**
 * The booked offer, asked for as the booking form's re-price and
 * add_to_cart ask for it: the room on its board, then every room and board
 * of the hotel.
 *
 * Holiday Park (270), 10–17 July 2027, 2 adults, as Novoton answered on
 * 29 Oct 2026: every room and board gives both packages' offers, the room
 * alone gives its own, but "DBL 2+0 ECONOMY" on "ALL INCL" gives an empty
 * document (getRoomPrice() false). add_to_cart asked only that and
 * rejected the booking the search and the booking form had shown.
 */
#[CoversClass(BookedOffer::class)]
final class BookedOfferTest extends TestCase
{
    private const string AQUA = 'HOLIDAY PARK **** +AQUAPARK IN GRADINA HOTEL AND AQUAPARK 2027';

    private const string PLAIN = 'HOLIDAY PARK **** 2027';

    /** @var list<array<string, mixed>> */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->asked = [];
    }

    public function testARoomNovotonPricesOnlyWithoutItsBoardIsFoundInTheAnswerOfEveryRoom(): void
    {
        $found = BookedOffer::find($this->holidayPark(), self::request('DBL 2+0 ECONOMY', 'ALL INCL'), self::PLAIN);

        self::assertNotNull($found);
        self::assertSame(
            ['price' => 613.5, 'room' => 'DBL 2+0 ECONOMY', 'board' => 'ALL INCL', 'package' => self::PLAIN, 'early_booking' => 25.0, 'idcont' => '1335102'],
            $found['offer'],
        );
        self::assertSame([['DBL 2+0 ECONOMY', 'ALL INCL'], ['', '']], $this->questions());
        self::assertSame(['nocache' => true], array_intersect_key($this->asked[1], ['nocache' => true]), 'the second question keeps the first one\'s options');
        // Its own terms, not the first room's of the answer
        self::assertStringContainsString('2027-06-20', RoomOfferRows::terms($found['answer'], $found['offer'])['terms_of_payment']);
    }

    public function testAnAnswerThatHasTheOfferIsTheOnlyQuestion(): void
    {
        $found = BookedOffer::find($this->holidayPark(), self::request('TRP 2+1 AQUA', 'ALL INCL'), self::AQUA);

        self::assertNotNull($found);
        self::assertSame(750.0, $found['offer']['price']);
        self::assertSame([['TRP 2+1 AQUA', 'ALL INCL']], $this->questions());
    }

    /**
     * The room on its board comes back in one package only; the clicked one
     * is in the answer of every room. It is priced from there, never from
     * the other package (BookedOffer::pick's rule).
     */
    public function testTheClickedPackageIsLookedForInBothAnswersBeforeAnotherPackagesPrice(): void
    {
        $ask = function (array $request): \SimpleXMLElement|false {
            $this->asked[] = $request;

            return $request['room_id'] === ''
                ? self::answer([self::offer('7', self::AQUA, 800.0, 'DBL 2+1', '2027-06-01'), self::offer('8', self::PLAIN, 655.5, 'DBL 2+1', '2027-06-20')])
                : self::answer([self::offer('7', self::AQUA, 800.0, 'DBL 2+1', '2027-06-01')]);
        };

        $found = BookedOffer::find($ask, self::request('DBL 2+1', 'ALL INCL'), self::PLAIN);

        self::assertNotNull($found);
        self::assertSame([655.5, self::PLAIN], [$found['offer']['price'], $found['offer']['package']]);
        self::assertCount(2, $this->asked);
    }

    /** No answer has the clicked package any more: the room + board is priced from another one, as before. */
    public function testAnotherPackageIsPricedOnlyWhenNoAnswerHasTheClickedOne(): void
    {
        $found = BookedOffer::find($this->holidayPark(), self::request('TRP 2+1 AQUA', 'ALL INCL'), 'HOLIDAY PARK **** EARLY 2027');

        self::assertNotNull($found);
        self::assertSame([750.0, self::AQUA], [$found['offer']['price'], $found['offer']['package']]);
        self::assertSame([['TRP 2+1 AQUA', 'ALL INCL'], ['', '']], $this->questions());
    }

    public function testARoomOrBoardNoAnswerOffersHasNoPrice(): void
    {
        self::assertNull(BookedOffer::find($this->holidayPark(), self::request('DBL 2+0 ECONOMY', 'BB'), self::PLAIN));
        self::assertNull(BookedOffer::find($this->holidayPark(), self::request('SUITE', 'ALL INCL'), ''));
        self::assertNull(BookedOffer::find(static fn (array $request): \SimpleXMLElement|false => false, self::request('DBL 2+0 ECONOMY', 'ALL INCL'), self::PLAIN));
    }

    public function testTheQuestionOfEveryRoomIsNotAskedTwice(): void
    {
        $found = BookedOffer::find($this->holidayPark(), self::request('', ''), self::PLAIN);

        self::assertNotNull($found);
        self::assertSame(613.5, $found['offer']['price'], 'the cheapest offer of the clicked package');
        self::assertSame([['', '']], $this->questions());
    }

    /**
     * Novoton's Holiday Park: an empty document (false) for a room on its
     * board in the plain package; the room's own offers without a board;
     * both packages for every room.
     *
     * @return \Closure(array<string, mixed>): (\SimpleXMLElement|false)
     */
    private function holidayPark(): \Closure
    {
        $offers = [
            self::offer('1334990', self::AQUA, 750.0, 'TRP 2+1 AQUA', '2027-06-01'),
            self::offer('1335102', self::PLAIN, 613.5, 'DBL 2+0 ECONOMY', '2027-06-20'),
            self::offer('1335102', self::PLAIN, 687.0, 'TRP 2+1', '2027-06-20'),
        ];

        return function (array $request) use ($offers): \SimpleXMLElement|false {
            $this->asked[] = $request;
            $room = (string) $request['room_id'];
            $board = (string) $request['board_id'];
            $mine = array_values(array_filter($offers, static fn (array $offer): bool => $room === '' || $offer['room'] === $room));
            if ($board !== '') {
                // A board asked for: only the first package answers it
                $mine = array_values(array_filter($mine, static fn (array $offer): bool => $offer['package'] === self::AQUA && $offer['board'] === $board));
            }

            return $mine === [] ? false : self::answer($mine);
        };
    }

    /** @return list<array{0: mixed, 1: mixed}> */
    private function questions(): array
    {
        return array_map(static fn (array $request): array => [$request['room_id'], $request['board_id']], $this->asked);
    }

    /** @return array<string, mixed> */
    private static function request(string $room, string $board): array
    {
        return [
            'hotel_id' => '270',
            'room_id' => $room,
            'board_id' => $board,
            'check_in' => '2027-07-10',
            'check_out' => '2027-07-17',
            'adults' => 2,
            'children' => [],
            'nocache' => true,
        ];
    }

    /** @return array{idcont: string, package: string, price: float, room: string, board: string, pay_until: string} */
    private static function offer(string $idcont, string $package, float $price, string $room, string $payUntil): array
    {
        return ['idcont' => $idcont, 'package' => $package, 'price' => $price, 'room' => $room, 'board' => 'ALL INCL', 'pay_until' => $payUntil];
    }

    /**
     * A flat room_price answer, element for element as Novoton sends it.
     *
     * @param list<array{idcont: string, package: string, price: float, room: string, board: string, pay_until: string}> $offers
     */
    private static function answer(array $offers): \SimpleXMLElement
    {
        $xml = '<room_price>';
        foreach ($offers as $offer) {
            $xml .= '<agent>TESTAGENT</agent><IdHotel>270</IdHotel><idcont>' . $offer['idcont'] . '</idcont>'
                . '<PackageName>' . $offer['package'] . '</PackageName><Price>' . number_format($offer['price'], 2, '.', '') . '</Price>'
                . '<IdRoom>' . $offer['room'] . '</IdRoom><IdStar>4*</IdStar><RoomPrice>No</RoomPrice><Board>' . $offer['board'] . '</Board>'
                . '<ExtBoard/><early_booking>25</early_booking><EBToDate>2027-02-28</EBToDate><extras/>'
                . '<TermsOfPayment><Percent tillDate="' . $offer['pay_until'] . '">100</Percent></TermsOfPayment>'
                . '<TermsOfCancellation><Penalty tillDate="2027-06-25" Type="Over Nights">0</Penalty></TermsOfCancellation>'
                . '<remark/><MoreInfo/><Important/>';
        }

        return new \SimpleXMLElement($xml . '</room_price>');
    }
}
