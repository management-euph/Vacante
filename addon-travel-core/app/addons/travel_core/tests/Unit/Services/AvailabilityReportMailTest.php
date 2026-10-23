<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\AvailabilityReportMail;

/**
 * The weekly email: sent only when the review found something new, with
 * each supplier's counts and its changes by hotel name.
 */
final class AvailabilityReportMailTest extends TestCase
{
    /** @var list<array<string, string>> */
    private array $sent = [];

    private function mail(): AvailabilityReportMail
    {
        return new AvailabilityReportMail(
            static fn (string $key, array $params): string => $key . ($params === [] ? '' : json_encode($params)),
            function (array $mail): bool {
                $this->sent[] = $mail;

                return true;
            },
        );
    }

    /**
     * @param list<array{hotel_id: string, hotel_name: string, product_id: int, from: string, to: string, reason: string}> $changes
     *
     * @return array{at: string, providers: array<string, array{mode: string, counts: array<string, int>, changed: int, first: int, changes: list<array{hotel_id: string, hotel_name: string, product_id: int, from: string, to: string, reason: string}>}>}
     */
    private static function summary(int $changed, int $first, array $changes = []): array
    {
        return ['at' => '2026-10-30 14:05:00', 'providers' => ['novoton' => [
            'mode' => 'report',
            'counts' => ['bookable' => 1, 'between_seasons' => 40, 'not_offered' => 0, 'dormant' => 0, 'left_feed' => 2, 'closed' => 0, 'no_data' => 0],
            'changed' => $changed,
            'first' => $first,
            'changes' => $changes,
        ]]];
    }

    public function testAQuietWeekSendsNothing(): void
    {
        self::assertNull($this->mail()->compose(self::summary(0, 0), [], 'admin.php'));
        self::assertFalse($this->mail()->send(self::summary(0, 0), [], 'admin.php', 'office@example.com'));
        self::assertSame([], $this->sent);
    }

    public function testTheEmailListsCountsAndChangesByHotelName(): void
    {
        $change = ['hotel_id' => '4', 'hotel_name' => 'Sol Nessebar', 'product_id' => 0, 'from' => 'between_seasons', 'to' => 'bookable', 'reason' => 'offer_seen'];
        $mail = $this->mail()->compose(self::summary(3, 0, [$change]), ['novoton' => 'Novoton Holidays'], 'admin.php?dispatch=travel_hotel_availability.manage');

        self::assertNotNull($mail);
        self::assertSame('travel_core.availability_mail_subject{"[changed]":"3"}', $mail['subject']);
        self::assertStringContainsString('== Novoton Holidays ==', $mail['body']);
        self::assertStringContainsString('travel_core.availability_state_between_seasons: 40', $mail['body']);
        self::assertStringNotContainsString('travel_core.availability_state_dormant', $mail['body'], 'empty states are left out');
        self::assertStringContainsString('- Sol Nessebar: travel_core.availability_state_between_seasons -> travel_core.availability_state_bookable', $mail['body']);
        self::assertStringContainsString('travel_core.availability_mail_more{"[n]":"2"}', $mail['body'], 'two more than listed');
    }

    public function testTheFirstReviewIsReportedWithoutListingEveryHotel(): void
    {
        $mail = $this->mail()->compose(self::summary(0, 43), [], 'admin.php');

        self::assertNotNull($mail);
        self::assertSame('travel_core.availability_mail_subject_first{"[n]":"43"}', $mail['subject']);
        self::assertStringContainsString('travel_core.availability_mail_first{"[n]":"43"}', $mail['body']);
    }

    public function testItGoesToTheOrdersDepartment(): void
    {
        self::assertTrue($this->mail()->send(self::summary(1, 0), [], 'admin.php', 'office@example.com'));
        self::assertSame('office@example.com', $this->sent[0]['to']);
        self::assertSame('default_company_orders_department', $this->sent[0]['from']);
    }
}
