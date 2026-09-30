<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Cron\Commands\AbstractSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\CronDispatcher;

/**
 * The dispatcher auto-discovers commands via getModes() (one registration
 * pattern across both provider addons — novoton pioneered it). These pins
 * guarantee the migration from the hand-maintained 26-entry static map lost
 * no mode, that every command file self-registers, and that the dispatcher
 * runs on the shared CronRunLock instead of its historical inline copy.
 */
final class CronModeRegistrationTest extends TestCase
{
    private const array EXPECTED_MODES = [
        'add_circuit_products',
        'add_products',
        'assign_boards',
        'audit_facilities',
        'backfill_hotel_locations',
        'calendar_prices',
        'circuits',
        'cleanup',
        'deduplicate',
        'destinations',
        'diagnose_images',
        'diagnose_product_features',
        'diagnose_search',
        'diagnose_seo',
        'discover_boards',
        'enrich_hotel_data',
        'experiences',
        'full',
        'geocode_hotels',
        'hotels',
        'order_status',
        'package_routes',
        'process_image_queue',
        'reassign_features',
        'sync_and_upload_images',
        'sync_images',
        'update_products',
    ];

    protected function setUp(): void
    {
        CronDispatcher::reset();
    }

    protected function tearDown(): void
    {
        CronDispatcher::reset();
    }

    public function testAutoDiscoveryRegistersEveryExpectedMode(): void
    {
        $modes = array_keys(CronDispatcher::getAvailableModes());

        self::assertSame(self::EXPECTED_MODES, $modes, 'discovered cron modes drifted');
    }

    public function testEveryConcreteCommandSelfRegisters(): void
    {
        $namespace = 'Tygh\\Addons\\SphinxHolidays\\Cron\\Commands\\';
        $files = glob(dirname(__DIR__, 2) . '/../src/Cron/Commands/*Command.php') ?: [];
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            /** @var class-string $className */
            $className = $namespace . basename($file, '.php');
            if ($className === AbstractSyncCommand::class) {
                continue;
            }

            self::assertTrue(
                is_subclass_of($className, AbstractSyncCommand::class),
                "{$className} must extend AbstractSyncCommand to be dispatchable",
            );
            self::assertNotEmpty(
                $className::getModes(),
                "{$className} must self-register at least one mode via getModes()",
            );
        }
    }

    public function testDispatcherRunsOnTheSharedCronRunLock(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/../src/Cron/CronDispatcher.php');

        self::assertStringContainsString('use Tygh\Addons\TravelCore\Helpers\CronRunLock;', $src);
        // The historical inline lock copy is gone.
        self::assertStringNotContainsString('private function acquireLock', $src);
        self::assertStringNotContainsString('STALE_LOCK_THRESHOLD', $src);
        // And so is the hand-maintained mode map.
        self::assertStringNotContainsString('private static array $modes = [', $src);
    }
}
