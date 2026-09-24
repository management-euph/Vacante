<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The settings heal finds the other add-ons in the STORE, not next to
 * travel_core's own source.
 *
 * On the Docker dev store every add-on is a symlink into the repo, and PHP
 * resolves symlinks in __DIR__: dirname(__DIR__, 2) from travel_core's
 * functions/ landed in addon-travel-core/app/addons, where only travel_core
 * lives. The heal skipped eurosite, sphinx and novoton silently, so a setting
 * added to their addon.xml (Eurosite's "Hotels root category ID") never
 * appeared on that store.
 */
final class SettingsHealStoreRootTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/tc-heal-' . bin2hex(random_bytes(4));
        // The repo side: travel_core's source, and eurosite's in its own top-level folder.
        mkdir($this->tmp . '/repo/addon-travel-core/app/addons/travel_core/functions', 0o777, true);
        copy(dirname(__DIR__, 3) . '/functions/self_heal.php', $this->tmp . '/repo/addon-travel-core/app/addons/travel_core/functions/self_heal.php');
        mkdir($this->tmp . '/repo/eurosite_addon/app/addons/eurosite', 0o777, true);
        file_put_contents($this->tmp . '/repo/eurosite_addon/app/addons/eurosite/addon.xml', '<addon/>');
        mkdir($this->tmp . '/repo/eurosite_addon/var/langs/en/addons', 0o777, true);
        file_put_contents($this->tmp . '/repo/eurosite_addon/var/langs/en/addons/eurosite.po', '');

        // The store side, linked the way docker/fullstore/link-addons.sh does it.
        mkdir($this->tmp . '/store/app/addons', 0o777, true);
        mkdir($this->tmp . '/store/var/langs/en/addons', 0o777, true);
        symlink($this->tmp . '/repo/addon-travel-core/app/addons/travel_core', $this->tmp . '/store/app/addons/travel_core');
        symlink($this->tmp . '/repo/eurosite_addon/app/addons/eurosite', $this->tmp . '/store/app/addons/eurosite');
        symlink($this->tmp . '/repo/eurosite_addon/var/langs/en/addons/eurosite.po', $this->tmp . '/store/var/langs/en/addons/eurosite.po');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    /** @return array{addons: string, langs: string} */
    private function resolve(bool $withDirRoot): array
    {
        $code = 'define("BOOTSTRAP", true);'
            . ($withDirRoot ? 'define("DIR_ROOT", ' . var_export($this->tmp . '/store', true) . ');' : '')
            . 'require ' . var_export($this->tmp . '/store/app/addons/travel_core/functions/self_heal.php', true) . ';'
            . 'echo json_encode(["addons" => fn_travel_core_store_path("app/addons"), "langs" => fn_travel_core_store_path("var/langs")]);';
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded, $out);

        /** @var array{addons: string, langs: string} $decoded */
        return $decoded;
    }

    public function testASymlinkedStoreStillFindsTheOtherAddonsAndTheirLabels(): void
    {
        $paths = $this->resolve(true);

        self::assertSame($this->tmp . '/store/app/addons', $paths['addons']);
        self::assertFileExists($paths['addons'] . '/eurosite/addon.xml');
        self::assertNotSame([], glob($paths['langs'] . '/*/addons/eurosite.po'));
    }

    /** What the old dirname(__DIR__, 2) saw on the same store: no eurosite. */
    public function testWithoutTheStoreRootASymlinkLandsInTheRepo(): void
    {
        $paths = $this->resolve(false);

        self::assertFileDoesNotExist($paths['addons'] . '/eurosite/addon.xml');
    }

    public function testBothHealEntryPointsUseTheStoreRoot(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/functions/self_heal.php');

        self::assertSame(2, substr_count($src, "\$addonsRoot = fn_travel_core_store_path('app/addons');"));
        self::assertStringContainsString("\$varLangs = fn_travel_core_store_path('var/langs');", $src);
        self::assertStringNotContainsString('$addonsRoot = dirname(__DIR__', $src);
    }
}
