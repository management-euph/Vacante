<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Tests\Support\SourceCode;

/**
 * The parts of the cron-key move that can only be checked by reading source.
 *
 * The migration's LOGIC — what gets adopted, minted, kept or deleted, and what
 * the operator is told — is tested by running it: see
 * Unit/Install/CronKeyMigrationBehaviourTest, which drives
 * SettingsMigrator::consolidateCronKey() against a fake settings store.
 *
 * This file used to pin that logic by reading its source instead, and a review
 * proved those pins could not hold it: five mutants of the logic — an inverted
 * weak-value filter, an arbitrary winner instead of a mint, a message that no
 * longer branched, a dropped abort, a miscounted agreement — each survived,
 * because each left the pinned strings in place. Those pins are gone; the
 * behavioural tests fail on every one of those mutants.
 *
 * What remains here is what a run of the migrator cannot see: WHERE the heal
 * calls it, and which addons the repository declares.
 */
final class CronKeyConsolidationTest extends TestCase
{
    private static function path(string $rel): string
    {
        return dirname(__DIR__, 3) . '/' . $rel;
    }

    /**
     * The consolidation runs AFTER the per-addon heal loop, not before it and
     * not inside it.
     *
     * Before the loop, travel_core's own `cron_key` row does not exist yet (its
     * pass runs last), so the move finds nothing to write to; the heal guard
     * stamps the run anyway, and the store is stuck until the next deploy.
     * Inside the loop, it would run once per addon, and the first pass would
     * delete rows the later passes still expect.
     *
     * Scoped to fn_travel_core_ensure_all_settings() on purpose. The same
     * foreach header also appears in fn_travel_core_heal_settings_once()'s
     * fingerprint loop earlier in the file, and an earlier version of this
     * test anchored on THAT one — which comes before every possible placement
     * of the call, so the assertion could never fail. A review proved it: the
     * call moved above the loop, and every suite stayed green.
     */
    public function testTheMoveRunsAfterThePerAddonHealLoopAndOutsideIt(): void
    {
        $heal = self::path('functions/self_heal.php');
        $fn = 'function fn_travel_core_ensure_all_settings()';
        $call = 'SettingsMigrator::consolidateCronKey()';
        $loopHeader = 'foreach (fn_travel_core_settings_heal_addons() as $addon)';

        $body = SourceCode::body($heal, $fn);
        $loop = SourceCode::body($heal, $loopHeader, $fn);

        self::assertStringContainsString($call, $body, 'the heal never calls consolidateCronKey()');
        self::assertStringNotContainsString($call, $loop, 'the move runs once per addon, inside the loop');

        $loopAt = strpos($body, $loop);
        $callAt = strpos($body, $call);
        self::assertIsInt($loopAt);
        self::assertIsInt($callAt);
        self::assertGreaterThan(
            $loopAt + strlen($loop),
            $callAt,
            'the move runs before the per-addon loop has created its successor row',
        );
    }

    /**
     * Every add-on that ever declared a `cron_access_key` is in the move list.
     *
     * A provider left out keeps its own row forever, and getFor() keeps
     * answering from it — so that provider silently stays on a different key
     * from the other three. That is the exact failure this whole change is
     * for, and it is how eurosite was missed the first time.
     */
    public function testEveryAddonThatShippedALegacyKeyIsRetired(): void
    {
        $m = SourceCode::code(self::path('src/Install/SettingsMigrator.php'));

        $repoRoot = dirname(__DIR__, 7);
        $dirs = [
            'travel_core' => '/addon-travel-core/app/addons/travel_core',
            'novoton_holidays' => '/addon-novoton-holidays/app/addons/novoton_holidays',
            'sphinx_holidays' => '/addon-sphinx-holidays/app/addons/sphinx_holidays',
            'eurosite' => '/eurosite_addon/app/addons/eurosite',
        ];

        self::assertSame(
            1,
            preg_match('/private const array CRON_KEY_LEGACY_ADDONS = \[(.*?)\];/s', $m, $match),
            'CRON_KEY_LEGACY_ADDONS is gone',
        );

        foreach ($dirs as $addon => $dir) {
            self::assertStringContainsString("'{$addon}'", $match[1], "{$addon} is not retired by the move");
        }

        // An add-on still DECLARING cron_access_key in its addon.xml is one
        // whose row its own heal recreates right after we delete it — so the
        // declaration has to go at the same time.
        foreach ($dirs as $addon => $dir) {
            $xml = (string) file_get_contents($repoRoot . $dir . '/addon.xml');
            self::assertStringNotContainsString(
                '<item id="cron_access_key">',
                $xml,
                "{$addon}/addon.xml still declares cron_access_key — the settings heal would "
                    . 'recreate the row on the next request and the move would never finish',
            );
        }
    }

    /** The fixture the behavioural tests depend on must exist and be the real one. */
    public function testTheBehaviouralTestsAreWiredToTheRealMigrator(): void
    {
        $fixture = self::path('tests/Fixtures/cron_key_consolidation_sim.php');
        self::assertFileExists($fixture);

        $src = (string) file_get_contents($fixture);
        self::assertStringContainsString('SettingsMigrator::consolidateCronKey()', $src);
        // It must load the addon's own classes, not a copy of them.
        self::assertStringContainsString("require \$addonDir . '/vendor/autoload.php';", $src);
    }
}
