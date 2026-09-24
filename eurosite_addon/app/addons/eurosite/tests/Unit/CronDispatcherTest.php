<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Cron\CronDispatcher;

/**
 * Pins the auto-discovered cron mode map: every static-data catalog has a
 * sync mode, plus the composite/housekeeping modes. A command file that
 * fails to register its mode (typo'd getModes, wrong base class) breaks
 * here instead of silently vanishing from the cron surface.
 */
final class CronDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        CronDispatcher::reset();
    }

    protected function tearDown(): void
    {
        CronDispatcher::reset();
    }

    public function testAllExpectedModesAreDiscovered(): void
    {
        $modes = CronDispatcher::getAvailableModes();

        $expected = [
            'add_products', 'availability', 'cities', 'cleanup', 'countries', 'full', 'hotels',
            'own_cities', 'product_info', 'room_types', 'tags', 'update_products',
        ];
        self::assertSame($expected, array_keys($modes));
        foreach ($modes as $mode => $description) {
            self::assertNotSame('', trim($description), "mode {$mode} has no description");
        }
    }

    public function testUnknownModeIsRejected(): void
    {
        $dispatcher = new CronDispatcher();

        self::assertFalse($dispatcher->hasMode('definitely_not_a_mode'));
        $result = $dispatcher->dispatch('definitely_not_a_mode');
        self::assertFalse($result['success']);
        self::assertStringContainsString('Unknown mode', (string) $result['error']);
    }
}
