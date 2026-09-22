<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Cron\AbstractCronCommand;
use Tygh\Addons\TravelCore\Helpers\CronRunLock;

/**
 * A long run must keep its own lock alive, without disturbing its output.
 *
 * novoton's dispatcher acquired a CronRunLock and never touched it. With
 * CronRunLock::STALE_THRESHOLD at 30 minutes and `full` documented at 5-30+,
 * the next tick saw a stale lock — and acquire() UNLINKS and reopens on that
 * path, so the original holder keeps flock on an orphaned inode while the
 * newcomer gets a fresh one. Two concurrent full syncs, writing the same
 * tables.
 *
 * sphinx and eurosite avoid this by touching the lock inside the output
 * callback they set at the dispatcher. novoton could not copy that:
 * setOutputCallback() REPLACES, and novoton's command constructor already
 * wires SyncLogger there on the HTTP path (and deliberately nothing on the
 * CLI path, where the logger is null). Hence a separate heartbeat: observe
 * output, do not reroute it.
 */
final class CommandHeartbeatTest extends TestCase
{
    private string $lockFile;

    protected function setUp(): void
    {
        $this->lockFile = sys_get_temp_dir() . '/travel_heartbeat_test_' . uniqid() . '.lock';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    private function command(): AbstractCronCommand
    {
        return new class extends AbstractCronCommand {
            /** @var list<string> */
            public array $emitted = [];

            /** @return array<string, mixed> */
            #[\Override]
            public function execute(): array
            {
                $this->output('working');

                return ['success' => true];
            }

            #[\Override]
            public static function getDescription(): string
            {
                return 'test command';
            }

            public function captureOutputTo(): void
            {
                $this->setOutputCallback(function (string $m, bool $n = true): void {
                    $this->emitted[] = $m;
                });
            }
        };
    }

    /** The mechanism: output() refreshes the lock, so it cannot be stolen. */
    public function testOutputKeepsTheRunLockAlive(): void
    {
        $holder = new CronRunLock($this->lockFile);
        self::assertTrue($holder->acquire());

        $command = $this->command();
        $command->setHeartbeat(static function () use ($holder): void {
            $holder->touch();
        });

        // Age the lock past the stale threshold, as a long run would.
        touch($this->lockFile, time() - CronRunLock::STALE_THRESHOLD - 60);
        clearstatcache();

        $command->execute();
        clearstatcache();

        $taker = new CronRunLock($this->lockFile);
        self::assertFalse(
            $taker->acquire(),
            'a run that is still emitting output must not have its lock taken over',
        );

        $holder->release();
    }

    /** Without a heartbeat the same run IS stolen — proving the test bites. */
    public function testWithoutAHeartbeatTheLockIsStolen(): void
    {
        $holder = new CronRunLock($this->lockFile);
        self::assertTrue($holder->acquire());

        $command = $this->command();

        touch($this->lockFile, time() - CronRunLock::STALE_THRESHOLD - 60);
        clearstatcache();

        $command->execute();
        clearstatcache();

        $taker = new CronRunLock($this->lockFile);
        self::assertTrue($taker->acquire(), 'this is the bug the heartbeat exists to prevent');
        $taker->release();
    }

    /** A heartbeat must not replace or suppress the command's own output. */
    public function testTheHeartbeatDoesNotDisturbOutputRouting(): void
    {
        $command = $this->command();
        $command->captureOutputTo();

        $beats = 0;
        $command->setHeartbeat(static function () use (&$beats): void {
            $beats++;
        });

        $command->execute();

        self::assertSame(['working'], $command->emitted, 'output must still reach its callback');
        self::assertSame(1, $beats, 'and the heartbeat must fire alongside it');
    }

    /** novoton's dispatcher must actually attach one to its lock. */
    public function testNovotonDispatcherAttachesTheHeartbeatToItsLock(): void
    {
        $dispatcher = (string) file_get_contents(
            dirname(__DIR__, 7)
            . '/addon-novoton-holidays/app/addons/novoton_holidays/src/Cron/CronDispatcher.php',
        );

        self::assertStringContainsString('setHeartbeat(', $dispatcher);
        self::assertStringContainsString('$lock?->touch()', $dispatcher);
        // And must NOT have reached for setOutputCallback, which would have
        // silenced SyncLogger on the HTTP path.
        self::assertStringNotContainsString('setOutputCallback(', $dispatcher);
    }
}
