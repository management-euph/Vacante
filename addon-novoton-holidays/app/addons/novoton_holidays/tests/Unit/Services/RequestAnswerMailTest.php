<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\RequestAnswerMail;

/**
 * The hotel's answer to a booking on request (the owner's rule, 1 Nov 2026:
 * checkout charges every room; by card the guest is refunded for what the
 * hotel refuses, by bank transfer the guest pays once the hotel confirms):
 * confirmed, the guest is told; refused or cancelled, the store is told what
 * to refund or not to collect.
 */
#[CoversClass(RequestAnswerMail::class)]
final class RequestAnswerMailTest extends TestCase
{
    private const array TEXTS = [
        'novoton_holidays.dear_customer' => 'Dragă [name],',
        'novoton_holidays.request_confirmed_subject' => 'Hotelul a confirmat camerele: [hotel]',
        'novoton_holidays.request_confirmed_title' => 'Hotelul a confirmat camerele cerute',
        'novoton_holidays.request_confirmed_body' => 'Pentru comanda #[order_id], [hotel] a confirmat aceste camere pentru [from] – [to]:',
        'novoton_holidays.request_confirmed_amount' => 'Valoarea acestor camere: [amount].',
        'novoton_holidays.request_confirmed_pay' => 'Dacă plătești prin transfer bancar, poți plăti acum comanda #[order_id]: [total], cu datele bancare din emailul comenzii.',
        'novoton_holidays.request_confirmed_paid' => 'Am primit plata comenzii: nu mai ai nimic de făcut.',
        'novoton_holidays.request_confirmed_thanks' => 'Îți mulțumim și îți dorim un sejur minunat!',
    ];

    /** @var list<array{array<string, mixed>, string, string}> mails sent: params, area, language */
    private array $sent = [];

    /** @param array<string, mixed> $order the order of #55 */
    private function mail(array $order, string $storeEmail = 'orders@example.com', ?\Closure $deliver = null): RequestAnswerMail
    {
        $money = static fn (float $amount): string => number_format($amount, 2, ',', '.') . ' €';

        return new RequestAnswerMail(
            static fn (int $id): array => $id === 55 ? $order : [],
            $deliver ?? function (array $mail, string $area, string $lang): bool {
                $this->sent[] = [$mail, $area, $lang];

                return true;
            },
            static fn (string $key, array $replace, string $lang): string => strtr(self::TEXTS[$key] ?? $key, $replace),
            $money,
            $money,
            $storeEmail,
        );
    }

    /**
     * The rooms on request of a booking of several: their own record (G2).
     *
     * @return array<string, mixed>
     */
    private static function booking(array $more = []): array
    {
        return $more + [
            'booking_id' => 13,
            'order_id' => 55,
            'hotel_id' => '476',
            'hotel_name' => 'Admiral',
            'package_name' => 'ADMIRAL ***** +BEACH 2027',
            'check_in' => '2027-07-10',
            'check_out' => '2027-07-17',
            'rooms_data' => json_encode([
                ['room_id' => 'FAM 2+3 DELUXE', 'room_type_display' => 'Cameră Familială (FAM 2+3 DELUXE)', 'board_name' => 'Ultra All Inclusive', 'adults' => 2],
            ]),
            'total_price' => 2632.0,
            'holder_name' => 'Ana Pop',
            'guest_email' => 'ana@example.com',
            'novoton_invoice_id' => '311922',
        ];
    }

    /** @return array<string, mixed> */
    private static function order(string $status): array
    {
        return ['order_id' => 55, 'status' => $status, 'email' => 'ana.pop@example.com', 'firstname' => 'Ana', 'lang_code' => 'ro', 'total' => 9270.0];
    }

    public function testTheGuestIsToldWhenTheHotelConfirmsAndCanPayByTransferNow(): void
    {
        self::assertSame(RequestAnswerMail::GUEST, $this->mail(self::order('O'))->answered(self::booking(), 'Good'));

        self::assertCount(1, $this->sent);
        [$mail, $area, $lang] = $this->sent[0];
        self::assertSame(['ana.pop@example.com', 'C', 'ro'], [$mail['to'], $area, $lang]);
        self::assertSame('Hotelul a confirmat camerele: Admiral', $mail['subj']);
        $body = html_entity_decode((string) $mail['body']);
        foreach ([
            'Dragă Ana,',
            'Pentru comanda #55, Admiral a confirmat aceste camere pentru 10.07.2027 – 17.07.2027:',
            '<li>Cameră Familială (FAM 2+3 DELUXE) · Ultra All Inclusive</li>',
            'Valoarea acestor camere: 2.632,00 €.',
            'poți plăti acum comanda #55: 9.270,00 €',
        ] as $line) {
            self::assertStringContainsString($line, $body);
        }
        self::assertStringNotContainsStringIgnoringCase('novoton', $body, 'the guest\'s email names no supplier');
    }

    public function testAPaidOrderNeedsNothingMore(): void
    {
        $this->mail(self::order('P'))->answered(self::booking(), 'Good');

        $body = (string) $this->sent[0][0]['body'];
        self::assertStringContainsString('Am primit plata comenzii', $body);
        self::assertStringNotContainsString('transfer', $body);
    }

    public function testTheStoreIsToldToRefundWhatThePaidOrderCharged(): void
    {
        self::assertSame(RequestAnswerMail::STORE, $this->mail(self::order('P'))->answered(self::booking(), 'RQ'));

        [$mail, $area] = $this->sent[0];
        self::assertSame(['orders@example.com', 'A'], [$mail['to'], $area]);
        self::assertSame('[Novoton] Refund 2.632,00 € - order #55 - Admiral', $mail['subj']);
        $body = (string) $mail['body'];
        self::assertStringContainsString('REQUEST REFUSED BY THE HOTEL', $body);
        self::assertStringContainsString('The order is paid: refund 2.632,00 € to the guest for these rooms.', $body);
        self::assertStringContainsString('reservation 311922', $body);
        self::assertStringContainsString('Novoton looks for alternatives', $body);
    }

    public function testAnUnpaidOrderIsPaidThatMuchLess(): void
    {
        $this->mail(self::order('O'))->answered(self::booking(), 'ST');

        $mail = $this->sent[0][0];
        self::assertSame('[Novoton] Do not collect 2.632,00 € - order #55 - Admiral', $mail['subj']);
        $body = (string) $mail['body'];
        self::assertStringContainsString('BOOKING ON REQUEST CANCELLED', $body);
        self::assertStringContainsString('the guest pays 2.632,00 € less for these rooms (bank transfer)', $body);
        self::assertStringNotContainsString('alternatives', $body, 'a cancellation is not looked for alternatives');
    }

    public function testNobodyIsToldOfAWaitingListOrOfABookingNoOrderHolds(): void
    {
        self::assertSame('', $this->mail(self::order('O'))->answered(self::booking(), 'WT'));
        self::assertSame('', $this->mail(self::order('O'))->answered(self::booking(), 'ASK'));
        self::assertSame('', $this->mail(self::order('O'))->answered(self::booking(['order_id' => 0]), 'Good'));
        self::assertSame('', $this->mail(self::order('O'))->answered(self::booking(['order_id' => 99]), 'Good'), 'no such order');
        self::assertSame('', $this->mail(self::order('P'), '')->answered(self::booking(), 'RQ'), 'no orders email set');
        self::assertSame([], $this->sent);
    }

    public function testAnEmailThatCannotGoOutIsLoggedNotThrown(): void
    {
        $mail = $this->mail(self::order('O'), 'orders@example.com', static function (): bool {
            throw new \RuntimeException('SMTP down');
        });

        self::assertSame('', $mail->answered(self::booking(), 'Good'));
    }

    public function testRoomsComeFromTheBookingsRoomsElseItsOwnRoom(): void
    {
        self::assertSame(['Cameră Familială (FAM 2+3 DELUXE) · Ultra All Inclusive'], RequestAnswerMail::rooms(self::booking()));
        self::assertSame(
            ['Cameră Dublă (DBL 2+0) · Mic Dejun'],
            RequestAnswerMail::rooms(['rooms_data' => '', 'room_type' => 'Cameră Dublă (DBL 2+0)', 'board_name' => 'Mic Dejun']),
        );
    }
}
