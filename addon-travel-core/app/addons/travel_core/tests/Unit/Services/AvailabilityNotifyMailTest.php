<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\AlternativeRequestRepository;
use Tygh\Addons\TravelCore\Repository\NotifyRequestRepository;
use Tygh\Addons\TravelCore\Services\AvailabilityNotifyMail;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * The hotel page's "notify me" requests (docs/novoton-availability-gaps-plan.md
 * §7–8): stored with consent, one email once booking opens, the address
 * removed after it, or after 12 months at the latest.
 */
final class AvailabilityNotifyMailTest extends TestCase
{
    private const string NOW = '2026-10-26 07:00:00';

    /** @var list<string> statements run, placeholders filled */
    private array $sql = [];

    /** @var list<array{mail: array<string, string>, lang: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        DbStub::reset();
        DbStub::$query = function (string $query, mixed ...$params): int {
            if (preg_match('/^\s*(CREATE|ALTER) TABLE/', $query) !== 1) {
                $this->sql[] = db_quote($query, ...$params);
            }

            return 1;
        };
        DbStub::$getField = static fn (): string => 'present'; // the table has every column
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        AlternativeRequestRepository::resetEnsured();
    }

    /** @return array{request_id: int, provider: string, hotel_id: string, hotel_name: string, product_id: int, email: string, lang_code: string, watch_for: string, created_at: string} */
    private static function request(int $id, string $hotelId, string $watchFor = 'booking', string $createdAt = '2026-09-01 10:00:00', string $lang = 'ro', int $productId = 0): array
    {
        return ['request_id' => $id, 'provider' => 'novoton', 'hotel_id' => $hotelId, 'hotel_name' => 'Hotel ' . $hotelId, 'product_id' => $productId, 'email' => "guest{$id}@example.com", 'lang_code' => $lang, 'watch_for' => $watchFor, 'created_at' => $createdAt];
    }

    /**
     * @param list<array<string, mixed>> $requests rows as the table holds them
     * @param list<array<string, mixed>> $records availability records
     * @param list<int> $openProducts products that are Active or Hidden
     */
    private function mail(array $requests, array $records, array $openProducts, bool $mailWorks = true): AvailabilityNotifyMail
    {
        DbStub::$getArray = static function (string $query) use ($requests, $records, $openProducts): array {
            if (str_contains($query, 'FROM ?:travel_alternative_requests')) {
                return array_map(static fn (array $r): array => ['contact_email' => $r['email']] + $r, $requests);
            }
            if (str_contains($query, 'FROM ?:travel_hotel_availability')) {
                return $records;
            }

            return array_map(static fn (int $id): array => ['product_id' => $id], $openProducts);
        };

        return new AvailabilityNotifyMail(
            new NotifyRequestRepository(),
            function (array $mail, string $lang) use ($mailWorks): bool {
                $this->mails[] = ['mail' => $mail, 'lang' => $lang];

                return $mailWorks;
            },
            static fn (int $productId, string $lang): string => "https://shop.example/{$lang}/hotel-{$productId}",
            static fn (string $key, array $params, string $lang): string => $lang . ':' . $key . ' ' . json_encode($params, JSON_UNESCAPED_SLASHES),
        );
    }

    public function testBookingOpensWithAnOfferSeenAfterTheRequest(): void
    {
        $request = ['created_at' => '2026-09-01 10:00:00', 'watch_for' => 'booking'];

        self::assertNull(AvailabilityNotifyMail::opened($request, null, true, '2026-10-26'), 'no record yet');
        self::assertNull(AvailabilityNotifyMail::opened($request, ['offer_seen_at' => '2026-08-20 10:00:00'], true, '2026-10-26'), 'the offer it knew of');
        self::assertSame('booking', AvailabilityNotifyMail::opened($request, ['offer_seen_at' => '2026-10-25 04:00:00'], true, '2026-10-26'));
        self::assertNull(
            AvailabilityNotifyMail::opened($request, ['offer_seen_at' => '2026-10-25 04:00:00', 'immediate_seen_at' => null], false, '2026-10-26'),
            'on request does not count when the admin said so',
        );
        self::assertNull(AvailabilityNotifyMail::opened($request, ['contract_to' => '2027-09-30'], true, '2026-10-26'), 'prices only for a guest waiting for prices');
        self::assertSame('prices', AvailabilityNotifyMail::opened(['watch_for' => 'prices'] + $request, ['contract_to' => '2027-09-30'], true, '2026-10-26'));
        self::assertNull(AvailabilityNotifyMail::opened(['watch_for' => 'prices'] + $request, ['contract_to' => '2026-09-30'], true, '2026-10-26'), 'last season\'s prices');
    }

    public function testEachGuestGetsOneEmailInTheirLanguageAndTheAddressGoes(): void
    {
        $mail = $this->mail(
            [self::request(1, '476'), self::request(2, '477', 'prices', '2026-09-01 10:00:00', 'en'), self::request(3, '478')],
            [
                ['provider' => 'novoton', 'hotel_id' => '476', 'product_id' => '15', 'offer_seen_at' => '2026-10-25 04:00:00'],
                ['provider' => 'novoton', 'hotel_id' => '477', 'product_id' => '16', 'contract_to' => '2027-09-30'],
                ['provider' => 'novoton', 'hotel_id' => '478', 'product_id' => '17', 'offer_seen_at' => '2026-08-01 04:00:00'],
            ],
            [15, 16, 17],
        );

        $result = $mail->run(new \DateTimeImmutable(self::NOW), true);

        self::assertSame(['sent' => 2, 'failed' => 0, 'waiting' => 1, 'expired' => 1], $result);
        self::assertCount(2, $this->mails);
        self::assertSame('guest1@example.com', $this->mails[0]['mail']['to']);
        self::assertSame('ro', $this->mails[0]['lang']);
        self::assertStringStartsWith('ro:travel_core.notify_mail_subject_booking', $this->mails[0]['mail']['subject']);
        self::assertStringContainsString('"[hotel]":"Hotel 476","[link]":"https://shop.example/ro/hotel-15"', $this->mails[0]['mail']['body']);
        self::assertStringStartsWith('en:travel_core.notify_mail_body_prices', $this->mails[1]['mail']['body']);

        self::assertStringContainsString("SET status = 'expired', contact_email = NULL", $this->sql[0]);
        self::assertStringContainsString("created_at < '2025-10-26 07:00:00'", $this->sql[0], '12 months at the latest');
        self::assertStringContainsString("SET status = 'notified', contact_email = NULL", $this->sql[1]);
        self::assertStringContainsString('request_id IN (1,2)', str_replace(', ', ',', $this->sql[1]));
    }

    public function testNoEmailWhileTheHotelPageCannotBeOpenedOrWhenSendingFails(): void
    {
        $records = [['provider' => 'novoton', 'hotel_id' => '476', 'product_id' => '15', 'offer_seen_at' => '2026-10-25 04:00:00']];

        $this->mail([self::request(1, '476')], $records, [])->run(new \DateTimeImmutable(self::NOW), true);
        self::assertSame([], $this->mails, 'a disabled product: the request waits');

        $result = $this->mail([self::request(1, '476')], $records, [15], false)->run(new \DateTimeImmutable(self::NOW), true);
        self::assertSame(1, $result['failed']);
        self::assertStringNotContainsString("'notified'", implode("\n", $this->sql), 'it is tried again tomorrow');
    }

    public function testOneRunSendsAtMostItsShare(): void
    {
        $requests = [];
        $records = [];
        for ($i = 1; $i <= AvailabilityNotifyMail::PER_RUN + 5; $i++) {
            $requests[] = self::request($i, (string) (1000 + $i), 'booking', '2026-09-01 10:00:00', 'ro', 15);
            $records[] = ['provider' => 'novoton', 'hotel_id' => (string) (1000 + $i), 'product_id' => '15', 'offer_seen_at' => '2026-10-25 04:00:00'];
        }

        $result = $this->mail($requests, $records, [15])->run(new \DateTimeImmutable(self::NOW), true);

        self::assertSame(AvailabilityNotifyMail::PER_RUN, $result['sent']);
        self::assertSame(5, $result['waiting']);
    }

    public function testARequestIsStoredOnceWithItsConsent(): void
    {
        $repo = new NotifyRequestRepository();
        $request = ['provider' => 'sphinx', 'hotel_id' => 'H1', 'hotel_name' => 'Hotel Alpha', 'product_id' => 0, 'user_id' => 0, 'email' => 'guest@example.com', 'lang_code' => 'ro', 'watch_for' => 'prices', 'consent_version' => '2026-10-17'];

        $captured = [];
        DbStub::$getField = static fn (string $query): string => str_contains($query, 'SHOW COLUMNS') ? 'present' : '';
        DbStub::$query = static function (string $query, mixed ...$params) use (&$captured): int {
            $captured[] = [$query, $params];

            return 41;
        };
        self::assertSame(41, $repo->create($request, self::NOW));
        $insert = (array) end($captured);
        self::assertSame('INSERT INTO ?:travel_alternative_requests ?e', $insert[0]);
        self::assertSame([
            'provider' => 'sphinx', 'hotel_id' => 'H1', 'hotel_name' => 'Hotel Alpha', 'contact_email' => 'guest@example.com',
            'request_type' => 'notify', 'watch_for' => 'prices', 'lang_code' => 'ro', 'consent_at' => self::NOW,
            'consent_version' => '2026-10-17', 'status' => 'pending', 'created_at' => self::NOW,
        ], $insert[1][0], 'no product or user: their columns stay NULL');

        DbStub::$getField = static fn (string $query): string => str_contains($query, 'SHOW COLUMNS') ? 'present' : '9';
        $captured = [];
        self::assertSame(9, $repo->create($request, self::NOW), 'the same address for the same hotel: the waiting request');
        self::assertSame([], array_filter($captured, static fn (array $c): bool => str_starts_with($c[0], 'INSERT')));
    }

    public function testCancellingRemovesTheAddress(): void
    {
        (new NotifyRequestRepository())->cancel(7);

        self::assertStringContainsString("SET status = 'cancelled', contact_email = NULL", $this->sql[0]);
        self::assertStringContainsString("request_type = 'notify' AND request_id = 7", $this->sql[0]);
    }
}
