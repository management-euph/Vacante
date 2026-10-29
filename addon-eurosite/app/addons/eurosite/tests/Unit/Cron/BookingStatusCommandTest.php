<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Cron\Commands\BookingStatusCommand;
use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\Eurosite\Services\BookingStatusService;
use Tygh\Addons\Eurosite\Tests\Support\DbStub;

/**
 * Eurosite's answer (28 Oct 2026) on bookings waiting for confirmation: 30
 * minutes after the booking, then every 30 minutes for two hours; if still
 * not final, 8 hours later, every 30 minutes for two hours again.
 */
final class BookingStatusCommandTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    /** @var list<int> */
    private array $asked = [];

    /** @var array<int, array<string, mixed>> */
    private array $stamped = [];

    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** @param list<array<string, mixed>> $awaiting */
    private function command(array $awaiting, string $answer = 'confirmed', ?string $error = null): BookingStatusCommand
    {
        $test = $this;
        $repo = new class ($awaiting, $test) extends EurositeBookingRepository {
            public ?string $since = null;

            /** @param list<array<string, mixed>> $rows */
            public function __construct(private readonly array $rows, private readonly BookingStatusCommandTest $test)
            {
            }

            #[\Override]
            public function findAwaitingConfirmation(string $since, int $limit = 50): array
            {
                $this->since = $since;

                return $this->rows;
            }

            #[\Override]
            public function update(int $bookingId, array $data): void
            {
                $this->test->stamp($bookingId, $data);
            }
        };
        $status = $this->createStub(BookingStatusService::class);
        $status->method('checkSingle')->willReturnCallback(function (int $id) use ($answer, $error): array {
            $this->asked[] = $id;

            return ['changed' => $error === null && $answer !== 'pending', 'old_status' => 'pending', 'new_status' => $error === null ? $answer : 'pending', 'error' => $error];
        });

        return new BookingStatusCommand($repo, $status, static fn (): int => self::NOW);
    }

    /** @param array<string, mixed> $data */
    public function stamp(int $bookingId, array $data): void
    {
        $this->stamped[$bookingId] = $data;
    }

    /** @return array<string, mixed> */
    private static function booking(int $id, int $bookedMinutesAgo, ?int $checkedMinutesAgo): array
    {
        return [
            'booking_id' => $id,
            'submitted_at' => date('Y-m-d H:i:s', self::NOW - $bookedMinutesAgo * 60),
            'status_checked_at' => $checkedMinutesAgo === null ? null : date('Y-m-d H:i:s', self::NOW - $checkedMinutesAgo * 60),
        ];
    }

    public function testOnlyTheBookingsDueOnEurositesScheduleAreAsked(): void
    {
        $result = $this->command([
            self::booking(1, 10, null),   // too early: first check at 30 minutes
            self::booking(2, 35, null),   // due: the first check
            self::booking(3, 70, 10),     // checked 10 minutes ago: not yet
            self::booking(4, 300, 150),   // the 8-hour pause
            self::booking(5, 640, 150),   // the second round
        ])->execute();

        self::assertSame([2, 5], $this->asked);
        self::assertSame(['status_checked_at' => date('Y-m-d H:i:s', self::NOW)], $this->stamped[2]);
        self::assertSame([2, 5], array_keys($this->stamped), 'only the bookings asked are stamped');
        self::assertTrue($result['success']);
    }

    public function testANoAnswerIsCountedAndTheBookingWaitsForItsNextCheck(): void
    {
        $this->command([self::booking(2, 35, null)], 'pending', 'timeout')->execute();

        self::assertSame([2], $this->asked);
        self::assertArrayHasKey(2, $this->stamped, 'stamped all the same: the next check comes half an hour later');
    }

    public function testItLooksBackOnlyAsFarAsTheScheduleGoes(): void
    {
        $command = $this->command([]);
        $command->execute();

        $repo = (new \ReflectionProperty($command, 'bookings'))->getValue($command);
        self::assertSame(date('Y-m-d H:i:s', self::NOW - 13 * 3600), $repo->since);
    }
}
