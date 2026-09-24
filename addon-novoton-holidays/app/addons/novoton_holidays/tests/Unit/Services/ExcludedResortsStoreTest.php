<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\ExcludedResortsStore;

/**
 * Saving the dashboard's excluded resorts.
 *
 * The Save used a raw UPDATE on ?:addon_options, a table CS-Cart 4.x does not
 * have, so every save stopped with "Table 'cscart_addon_options' doesn't
 * exist (1146)". It goes through the Settings API now.
 */
final class ExcludedResortsStoreTest extends TestCase
{
    public function testTheStoredValueIsAJsonListTheReaderReadsBack(): void
    {
        $value = ExcludedResortsStore::encode(['ALBENA', ' VARNA ', '', 'ALBENA', 'ST.CONSTANTINE & ELENA']);

        self::assertSame('["ALBENA","VARNA","ST.CONSTANTINE & ELENA"]', $value);
        self::assertSame(['ALBENA', 'VARNA', 'ST.CONSTANTINE & ELENA'], ConfigProvider::parseResortList($value));
    }

    /**
     * array_unique() on the posted list, as the old save did, keeps the
     * original keys; json_encode then writes an OBJECT ({"0":"A","2":"B"}),
     * not a list. encode() always writes a list.
     */
    public function testGapsInThePostedListNeverTurnIntoAJsonObject(): void
    {
        self::assertSame('["A","B"]', ExcludedResortsStore::encode([0 => 'A', 1 => 'A', 2 => 'B']));
        self::assertSame('[]', ExcludedResortsStore::encode([]));
        self::assertSame('["A"]', ExcludedResortsStore::encode(['A', ['nested'], null]));
    }

    public function testTheSaveUsesTheSettingsApiAndReadsTheValueBack(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/ExcludedResortsStore.php');

        self::assertStringContainsString('$settings->updateValue(self::SETTING, $value, ConfigProvider::ADDON_ID);', $src);
        self::assertStringContainsString('$settings->getValue(self::SETTING, ConfigProvider::ADDON_ID) === $value', $src);
        self::assertStringContainsString('fn_travel_core_ensure_settings(', $src);

        $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/novoton_holidays.php');
        self::assertStringContainsString('ExcludedResortsStore::save($value)', $controller);
        // A failed save says so instead of claiming success.
        self::assertStringContainsString("__('novoton_holidays.dash_resorts_save_failed')", $controller);
    }

    /** No code in any add-on writes to the table CS-Cart 4.x does not have. */
    public function testNothingQueriesAddonOptions(): void
    {
        $repo = dirname(__DIR__, 6) . '/..';
        $offenders = [];
        foreach (['addon-novoton-holidays', 'addon-sphinx-holidays', 'addon-travel-core', 'eurosite_addon'] as $addon) {
            $dir = $repo . '/' . $addon . '/app/addons';
            if (!is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/vendor/') || str_contains($file->getPathname(), '/tests/')) {
                    continue;
                }
                $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($file->getPathname()));
                if (str_contains($code, '?:addon_options')) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        self::assertSame([], $offenders, '?:addon_options does not exist in CS-Cart 4.x; use Tygh\Settings');
    }
}
