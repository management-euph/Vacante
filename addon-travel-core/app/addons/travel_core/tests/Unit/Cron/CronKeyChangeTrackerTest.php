<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Cron\CronKeyChangeTracker;

/**
 * The re-copy checklist: opened by any key change, closed by proof or by hand.
 */
#[CoversClass(CronKeyChangeTracker::class)]
final class CronKeyChangeTrackerTest extends TestCase
{
    /** @var array<string, string> */
    private array $store = [];

    private const array PAGES = ['travel_core', 'eurosite', 'sphinx_holidays', 'novoton_holidays'];

    protected function setUp(): void
    {
        $this->store = [];
        CronKeyChangeTracker::useStorage(
            fn (string $k): ?string => $this->store[$k] ?? null,
            function (string $k, string $v): void {
                $this->store[$k] = $v;
            },
        );
    }

    protected function tearDown(): void
    {
        CronKeyChangeTracker::useStorage(null, null);
    }

    /** The first key ever seen may be years old: record it, do not nag. */
    public function testTheFirstKeySeenOnlyRecordsItself(): void
    {
        $state = CronKeyChangeTracker::observe('key-a', self::PAGES, 1000);

        self::assertFalse($state['open']);
        self::assertSame(0, $state['at'], 'when a key first seen was set is unknown');
    }

    public function testTheSameKeyChangesNothing(): void
    {
        CronKeyChangeTracker::observe('key-a', self::PAGES, 1000);
        $state = CronKeyChangeTracker::observe('key-a', self::PAGES, 2000);

        self::assertFalse($state['open']);
    }

    /** Any change — button, migration or a hand edit in Settings — opens the checklist. */
    public function testADifferentKeyOpensAChecklistForEveryPage(): void
    {
        CronKeyChangeTracker::observe('key-a', self::PAGES, 1000);
        $state = CronKeyChangeTracker::observe('key-b', self::PAGES, 2000);

        self::assertTrue($state['open']);
        self::assertSame(2000, $state['at']);
        self::assertSame(self::PAGES, array_keys($state['pages']));
        self::assertTrue(CronKeyChangeTracker::isOpen($state, CronKeyChangeTracker::checklist($state, [])));
    }

    /** The key itself is never stored — only a short fingerprint. */
    public function testTheKeyIsNeverStored(): void
    {
        CronKeyChangeTracker::observe('3f7c1a9e5b2d8f406e1c9a7b5d3f2e18', self::PAGES, 1000);

        self::assertStringNotContainsString('3f7c1a9e5b2d8f406e1c9a7b5d3f2e18', implode('', $this->store));
    }

    /** A successful scheduled run after the change proves that crontab already has the new key. */
    public function testASuccessfulScheduledRunAfterTheChangeTicksThatPage(): void
    {
        CronKeyChangeTracker::observe('key-a', self::PAGES, 1000);
        $state = CronKeyChangeTracker::observe('key-b', self::PAGES, 2000);

        $list = CronKeyChangeTracker::checklist($state, ['eurosite' => 2500, 'sphinx_holidays' => 1500]);
        $byPage = array_column($list, null, 'page');

        self::assertTrue($byPage['eurosite']['done']);
        self::assertTrue($byPage['eurosite']['proved']);
        self::assertFalse($byPage['sphinx_holidays']['done'], 'a run BEFORE the change proves nothing');
    }

    public function testTickingEveryPageClosesTheChecklist(): void
    {
        CronKeyChangeTracker::observe('key-a', self::PAGES, 1000);
        CronKeyChangeTracker::observe('key-b', self::PAGES, 2000);
        foreach (self::PAGES as $p) {
            CronKeyChangeTracker::markDone($p, 3000);
        }

        $state = CronKeyChangeTracker::observe('key-b', self::PAGES, 3100);
        self::assertFalse(CronKeyChangeTracker::isOpen($state, CronKeyChangeTracker::checklist($state, [])));
    }

    public function testDismissClosesItWithoutTicking(): void
    {
        CronKeyChangeTracker::observe('key-a', self::PAGES, 1000);
        CronKeyChangeTracker::observe('key-b', self::PAGES, 2000);
        CronKeyChangeTracker::dismiss();

        $state = CronKeyChangeTracker::observe('key-b', self::PAGES, 3000);
        self::assertFalse(CronKeyChangeTracker::isOpen($state, CronKeyChangeTracker::checklist($state, [])));
    }

    /** A page nobody listed cannot be ticked into existence by a forged POST. */
    public function testMarkingAnUnknownPageDoesNothing(): void
    {
        CronKeyChangeTracker::observe('key-a', self::PAGES, 1000);
        CronKeyChangeTracker::observe('key-b', self::PAGES, 2000);
        CronKeyChangeTracker::markDone('not_an_addon', 3000);

        $state = CronKeyChangeTracker::observe('key-b', self::PAGES, 3100);
        self::assertArrayNotHasKey('not_an_addon', $state['pages']);
    }

    /** A second rotation starts over: earlier ticks were for the previous key. */
    public function testASecondChangeResetsTheTicks(): void
    {
        CronKeyChangeTracker::observe('key-a', self::PAGES, 1000);
        CronKeyChangeTracker::observe('key-b', self::PAGES, 2000);
        CronKeyChangeTracker::markDone('eurosite', 2100);

        $state = CronKeyChangeTracker::observe('key-c', self::PAGES, 3000);
        self::assertSame(0, $state['pages']['eurosite']);
    }

    /** A removed key leaves nothing to re-copy. */
    public function testRemovingTheKeyOpensNothing(): void
    {
        CronKeyChangeTracker::observe('key-a', self::PAGES, 1000);
        $state = CronKeyChangeTracker::observe('', self::PAGES, 2000);

        self::assertFalse($state['open']);
    }
}
