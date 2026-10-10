<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Cron\Commands\ProbeAvailabilityCommand;
use Tygh\Addons\Eurosite\Services\CronPlanBuilder;

/**
 * probe_availability searches the next months for dates with offers when
 * the configured availability dates find none. Read-only, never scheduled.
 */
final class ProbeAvailabilityTest extends TestCase
{
    public function testDatesStepThroughTheMonthsWithTheStayLength(): void
    {
        $dates = ProbeAvailabilityCommand::dates(new \DateTimeImmutable('2026-10-01'), 1, 14, 7);

        self::assertSame([
            ['check_in' => '2026-10-01', 'check_out' => '2026-10-08'],
            ['check_in' => '2026-10-15', 'check_out' => '2026-10-22'],
            ['check_in' => '2026-10-29', 'check_out' => '2026-11-05'],
        ], $dates);
        self::assertCount(27, ProbeAvailabilityCommand::dates(new \DateTimeImmutable('2026-10-01'), 12, 14, 7));
    }

    public function testItIsNeverOnTheScheduleAndWritesNothing(): void
    {
        self::assertContains('probe_availability', CronPlanBuilder::DIAGNOSTIC);

        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Cron/Commands/ProbeAvailabilityCommand.php');
        self::assertStringNotContainsString('applyAvailability', $src);
        self::assertStringNotContainsString('db_query', $src);
        self::assertStringNotContainsString('ProductGate', $src);
    }

    public function testTheDashboardFormRunsItInTheAdminInANewWindow(): void
    {
        $root = dirname(__DIR__, 3);
        $controller = (string) file_get_contents($root . '/controllers/backend/eurosite.php');
        self::assertStringContainsString("if (\$mode === 'probe_availability')", $controller);
        self::assertStringContainsString('new \\Tygh\\Addons\\Eurosite\\Cron\\Commands\\ProbeAvailabilityCommand()', $controller);

        $tpl = (string) file_get_contents(dirname($root, 3) . '/design/backend/templates/addons/eurosite/views/eurosite/manage.tpl');
        self::assertStringContainsString('name="dispatch" value="eurosite.probe_availability"', $tpl);
        self::assertStringContainsString('target="_blank"', $tpl);
        self::assertStringNotContainsString('value="probe_availability"', $tpl, 'no form to the storefront cron endpoint');
    }
}
