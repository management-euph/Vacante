<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\StateManager;
use Tygh\Addons\NovotonHolidays\Services\PathResolver;
use Tygh\Registry;

/**
 * A batched job's progress survives a CS-Cart cache clear during the run.
 *
 * The state folder (var/cache/misc/novoton/) was created only when the
 * StateManager was built. A cache clear mid-run — Travel Core clears the
 * cache after seeding new labels, an admin can press "Clear cache" — deleted
 * it, and hotel_facilities_batched then printed
 *   fopen(…/batch_hotel_facilities_state.json.lock): Failed to open stream
 *   file_put_contents(…/batch_hotel_facilities_state.json.tmp): …
 * and lost its progress, so every run started from the first hotel again.
 */
final class StateManagerCacheClearTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nvt-state-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->root, 0o755, true);
        Registry::set('config.dir.cache_misc', $this->root);
        PathResolver::reset();
    }

    protected function tearDown(): void
    {
        self::rmTree($this->root);
        Registry::del('config.dir.cache_misc');
        PathResolver::reset();
    }

    public function testProgressIsSavedAfterTheCacheFolderWasDeleted(): void
    {
        $state = new StateManager('hotel_facilities');
        $state->start('hotel_facilities', ['1', '2', '3']);

        // The cache clear: the whole misc cache, our folder included.
        self::rmTree($this->root);
        self::assertDirectoryDoesNotExist($this->root . 'novoton');

        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;

            return true;
        });
        try {
            $saved = $state->updateProgress(1, 1, 0);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings, 'writing the state must not warn');
        self::assertSame(1, $saved['processed']);
        self::assertFileExists($state->getStateFilePath());
        self::assertSame(1, (new StateManager('hotel_facilities'))->load()['processed'], 'the next run resumes where this one stopped');
    }

    public function testTheLockWorksAfterTheFolderWasDeleted(): void
    {
        $state = new StateManager('hotel_facilities');
        self::rmTree($this->root);

        self::assertTrue(@$state->acquireLock(1), 'the lock file is created in a re-made folder');
        $state->releaseLock();
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
