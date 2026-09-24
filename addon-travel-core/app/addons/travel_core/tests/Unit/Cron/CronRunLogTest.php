<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Cron\CronRunLog;

/**
 * The record every travel add-on's cron jobs write, and the page reads.
 *
 * The rule that matters most is the one that is easy to break: this runs
 * inside every scheduled sync, so it must never change what the job returns,
 * never swallow the job's exception, and never fail the job itself.
 */
#[CoversClass(CronRunLog::class)]
final class CronRunLogTest extends TestCase
{
    /** @var array<string, string> */
    private array $store = [];

    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->store = [];
        $this->now = 1_000_000;
        CronRunLog::useStorage(
            fn (string $k): ?string => $this->store[$k] ?? null,
            function (string $k, string $v): void {
                $this->store[$k] = $v;
            },
            fn (): int => $this->now,
        );
    }

    protected function tearDown(): void
    {
        CronRunLog::useStorage(null, null);
    }

    public function testASuccessfulRunIsRecordedAndItsResultPassedThroughUnchanged(): void
    {
        $result = CronRunLog::record('eurosite', 'hotels', fn (): array => ['success' => true, 'stats' => ['n' => 3]]);

        self::assertSame(['success' => true, 'stats' => ['n' => 3]], $result);
        $r = CronRunLog::get('eurosite', 'hotels');
        self::assertSame($this->now, $r['started']);
        self::assertSame($this->now, $r['finished']);
        self::assertTrue($r['ok']);
        self::assertSame('', $r['error']);
        self::assertSame('cli', $r['via'], 'PHPUnit runs from the CLI, like a server crontab');
    }

    public function testAFailedResultIsRecordedWithItsError(): void
    {
        CronRunLog::record('sphinx_holidays', 'hotels', fn (): array => ['success' => false, 'error' => 'API timeout']);

        $r = CronRunLog::get('sphinx_holidays', 'hotels');
        self::assertFalse($r['ok']);
        self::assertSame('API timeout', $r['error']);
    }

    /** Novoton's busy result carries the reason in `message` on some paths. */
    public function testTheMessageIsUsedWhenAFailureHasNoError(): void
    {
        CronRunLog::record('novoton_holidays', 'full', fn (): array => ['success' => false, 'message' => 'no data']);

        self::assertSame('no data', CronRunLog::get('novoton_holidays', 'full')['error']);
    }

    /** The job's own exception must reach the caller untouched — after being recorded. */
    public function testAThrowingJobIsRecordedAsFailedAndTheExceptionReThrown(): void
    {
        $thrown = new \RuntimeException('database gone away');

        try {
            CronRunLog::record('eurosite', 'full', static function () use ($thrown): array {
                throw $thrown;
            });
            self::fail('the exception was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame($thrown, $e);
        }

        $r = CronRunLog::get('eurosite', 'full');
        self::assertFalse($r['ok']);
        self::assertSame('database gone away', $r['error']);
    }

    /** A broken storage backend must never become a broken sync. */
    public function testAStorageFailureNeverReachesTheJob(): void
    {
        CronRunLog::useStorage(
            static function (string $k): ?string {
                throw new \RuntimeException('storage down');
            },
            static function (string $k, string $v): void {
                throw new \RuntimeException('storage down');
            },
        );

        $result = CronRunLog::record('eurosite', 'hotels', fn (): array => ['success' => true]);

        self::assertSame(['success' => true], $result);
        self::assertSame([], CronRunLog::get('eurosite', 'hotels'));
    }

    /** One key per (add-on, job): two jobs of the same add-on cannot overwrite each other. */
    public function testEachJobHasItsOwnRecord(): void
    {
        CronRunLog::record('sphinx_holidays', 'hotels', fn (): array => ['success' => true]);
        CronRunLog::record('sphinx_holidays', 'circuits', fn (): array => ['success' => false, 'error' => 'x']);

        self::assertTrue(CronRunLog::get('sphinx_holidays', 'hotels')['ok']);
        self::assertFalse(CronRunLog::get('sphinx_holidays', 'circuits')['ok']);
        self::assertCount(2, $this->store);
    }

    /** A long error cannot bloat the row. */
    public function testErrorsAreClipped(): void
    {
        CronRunLog::record('eurosite', 'hotels', fn (): array => ['success' => false, 'error' => str_repeat('e', 5000)]);

        self::assertSame(300, mb_strlen(CronRunLog::get('eurosite', 'hotels')['error']));
    }

    public function testAWrongKeyRefusalIsRecordedSeparatelyFromAMissingKey(): void
    {
        CronRunLog::refused('sphinx_holidays', false);
        self::assertSame(0, CronRunLog::lastWrongKeyRefusal('sphinx_holidays'), 'a probe with no key is not a stale crontab');

        CronRunLog::refused('sphinx_holidays', true);
        self::assertSame($this->now, CronRunLog::lastWrongKeyRefusal('sphinx_holidays'));
    }

    /** A flood of wrong-key requests must not become one database write each. */
    public function testRefusalsAreThrottled(): void
    {
        CronRunLog::refused('eurosite', true);
        $first = $this->now;

        $this->now += CronRunLog::REFUSAL_THROTTLE - 1;
        CronRunLog::refused('eurosite', true);
        self::assertSame($first, CronRunLog::lastWrongKeyRefusal('eurosite'), 'written again inside the throttle window');

        $this->now += 2;
        CronRunLog::refused('eurosite', true);
        self::assertSame($this->now, CronRunLog::lastWrongKeyRefusal('eurosite'));
    }

    public function testARunKeepsItsStartWhenItFinishes(): void
    {
        $this->now = 500;
        CronRunLog::record('eurosite', 'hotels', function (): array {
            $this->now = 800;

            return ['success' => true];
        });

        $r = CronRunLog::get('eurosite', 'hotels');
        self::assertSame(500, $r['started']);
        self::assertSame(800, $r['finished']);
    }
}
