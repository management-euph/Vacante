<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The one-way move of four per-addon cron keys onto Travel Core's single one.
 *
 * This is the step that finishes what CronKeyService started. The service made
 * every reader agree on where the key lives; until the legacy rows are gone the
 * store still HAS four of them, and `getFor()`'s fallback means an operator who
 * only fixes one is none the wiser.
 *
 * The migration can go wrong in exactly one unrecoverable way — deleting the
 * legacy rows before the new key is safely in place — so the order of
 * operations is what this pins hardest. Everything else here degrades to "not
 * migrated yet", which the next heal retries.
 *
 * Source-text assertions, like the rest of this directory: the code calls into
 * Tygh\Settings, which is licensed CS-Cart code that is NOT in this repository
 * and cannot be instantiated in a unit test. Comments are stripped first
 * (see code()), so a docblock cannot satisfy a claim about the code.
 */
final class CronKeyConsolidationTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    /** The same file with every comment removed. */
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(self::src($rel)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * The legacy rows are deleted only AFTER the new key reads back correctly.
     *
     * This is the assertion that matters. On a store whose operator configured
     * the key once and pasted it into a crontab, those four rows hold the only
     * copy of a working secret; deleting them on the back of a write that
     * silently did not land would destroy it. updateValue() on a setting whose
     * row does not exist IS such a write — it is a no-op that reports success,
     * which is how this codebase learned to read back at all.
     */
    public function testLegacyRowsAreDeletedOnlyAfterTheNewKeyIsVerified(): void
    {
        $m = self::code('src/Install/SettingsMigrator.php');

        $verifyPos = strpos($m, "if (\$settings->getValue(self::CRON_KEY, 'travel_core') !== \$adopted)");
        $retirePos = strpos($m, 'self::retireLegacyCronKeys()');
        self::assertIsInt($verifyPos, 'the consolidated write is never read back');
        self::assertIsInt($retirePos);
        self::assertLessThan($retirePos, $verifyPos);

        // And the failed read-back must ABANDON the pass, not fall through to
        // the delete.
        self::assertStringContainsString(
            "if (\$settings->getValue(self::CRON_KEY, 'travel_core') !== \$adopted) {\n"
                . "                    return [];",
            $m,
        );
    }

    /**
     * Nothing happens at all until travel_core's own `cron_key` row exists.
     *
     * The heal creates it in travel_core's pass, which runs LAST (providers
     * first is load-bearing — see fn_travel_core_ensure_all_settings). A
     * consolidation that ran before that pass would find no successor to write
     * to, and updateValue() would no-op while reporting success — the delete
     * would then take the only copies with it.
     */
    public function testTheMoveIsSkippedEntirelyWhenTheSuccessorRowIsMissing(): void
    {
        $m = self::code('src/Install/SettingsMigrator.php');

        self::assertStringContainsString(
            "!\$settings->isExists(self::CRON_KEY, 'travel_core')",
            $m,
            'consolidateCronKey() must confirm the successor row exists before touching anything',
        );

        // It runs after the per-addon loop, for the same reason.
        $heal = self::code('functions/self_heal.php');
        $loopPos = strpos($heal, 'foreach (fn_travel_core_settings_heal_addons() as $addon)');
        $movePos = strpos($heal, 'SettingsMigrator::consolidateCronKey()');
        self::assertIsInt($loopPos, 'the per-addon heal loop is gone');
        self::assertIsInt($movePos, 'the heal never calls consolidateCronKey()');
        self::assertLessThan($movePos, $loopPos);
    }

    /**
     * The shipped `1234` is never carried into the new home.
     *
     * Four rows all holding `1234` agree perfectly, and "they agree, so adopt
     * it" would walk the default straight into Core — undoing, in the pass
     * meant to finish the cleanup, the rotation that removed it.
     */
    public function testAWeakValueIsNeverAdoptedEvenWhenEveryRowAgreesOnIt(): void
    {
        $m = self::code('src/Install/SettingsMigrator.php');

        $choose = strpos($m, 'private static function chooseCronKey');
        self::assertIsInt($choose);
        $body = substr($m, $choose, 600);

        self::assertStringContainsString('self::WEAK_VALUES', $body, 'chooseCronKey() does not filter weak values');
        // Filtered BEFORE the agreement test, or `1234` still counts as agreement.
        $filterPos = strpos($body, 'self::WEAK_VALUES');
        $countPos = strpos($body, 'count($distinct) === 1');
        self::assertIsInt($filterPos);
        self::assertIsInt($countPos);
        self::assertLessThan($countPos, $filterPos);
    }

    /**
     * Disagreeing keys produce a NEW secret, not an arbitrary winner.
     *
     * There is no choice that keeps every crontab working, so picking one
     * silently would leave the operator with some jobs running and some
     * refused, and nothing on the page to explain which. One clear break that
     * announces itself is the better failure.
     */
    public function testDisagreeingKeysMintAFreshSecretAndTellTheOperator(): void
    {
        $m = self::code('src/Install/SettingsMigrator.php');

        self::assertStringContainsString('bin2hex(random_bytes(16))', $m);
        self::assertStringContainsString('self::reportCronKeyMove(', $m);

        // The notice must distinguish the two outcomes: "your crontab still
        // works" and "re-copy everything" are opposite instructions.
        $report = strpos($m, 'private static function reportCronKeyMove');
        self::assertIsInt($report);
        $body = substr($m, $report, 1200);
        self::assertStringContainsString('$minted', $body);
        self::assertStringContainsString("fn_set_notification(", $body);
    }

    /**
     * An empty store stays empty — fail-closed beats a key nobody asked for.
     *
     * Minting one here would silently make every cron endpoint live, and the
     * operator would have no reason to look at the page that shows it. The
     * write is therefore conditional on having something to write.
     */
    public function testAStoreWithNothingToCarryIsLeftWithoutAKey(): void
    {
        $m = self::code('src/Install/SettingsMigrator.php');

        self::assertStringContainsString("if (\$adopted !== '') {", $m);

        // Nothing is written, and nothing is announced, when there is nothing
        // to carry: both the updateValue and the operator notice sit inside
        // that branch.
        $pos = strpos($m, "if (\$adopted !== '') {");
        self::assertIsInt($pos);
        $branch = substr($m, $pos, 900);
        self::assertStringContainsString('$settings->updateValue(self::CRON_KEY', $branch);
        self::assertStringContainsString('self::reportCronKeyMove(', $branch);
    }

    /**
     * The two "nothing to carry" cases are NOT the same, and must not be.
     *
     * Empty legacy rows are dead weight: CS-Cart renders the settings page
     * from the database, so dropping the <item> from a provider's addon.xml
     * does not remove the field — without a delete the operator is left with
     * an empty "Cron access key" input that does nothing, forever, because the
     * heal only re-runs when its fingerprint changes.
     *
     * A legacy row that HOLDS something chooseCronKey() refused (a `1234` the
     * rotation could not overwrite) is the opposite: deleting it takes the
     * store from "the crons work, insecurely" to "the crons refuse
     * everything", with no key anywhere to explain it.
     */
    public function testEmptyLegacyRowsAreCleanedUpButUncarryableOnesAreKept(): void
    {
        $m = self::code('src/Install/SettingsMigrator.php');

        self::assertStringContainsString(
            "if (!\$coreHasKey && \$adopted === '' && \$legacy !== []) {",
            $m,
            'the two nothing-to-carry cases must be distinguished by whether the rows hold anything',
        );

        // legacyCronKeys() is what makes $legacy === [] mean "all empty": it
        // only collects rows with a non-blank value. Without that, the guard
        // above would read "no rows at all" and the cleanup would never run.
        $pos = strpos($m, 'private static function legacyCronKeys');
        self::assertIsInt($pos);
        self::assertStringContainsString("trim(\$value) !== ''", substr($m, $pos, 900));

        // And a store that already HAS a Core key retires the legacy rows
        // unconditionally — they are dead weight there too.
        self::assertStringContainsString('$coreHasKey ? \'\' : self::chooseCronKey(', $m);
    }

    /**
     * A legacy row that could not be READ must never be deleted as "empty".
     *
     * This is the sharpest edge in the whole migration. The delete is licensed
     * by "every legacy row is empty, so there is nothing to lose" — and an
     * earlier version reached that conclusion by `continue`ing past any
     * getValue() that threw. A store where the reads failed then looked
     * identical to a store with four blank rows, and the rows were deleted
     * with the only copy of a working secret still in them.
     *
     * "I could not read it" and "it is empty" are different answers and the
     * code has to carry both.
     */
    public function testARowThatCouldNotBeReadIsNeverTreatedAsEmpty(): void
    {
        $m = self::code('src/Install/SettingsMigrator.php');

        // The reader reports the failure rather than swallowing it.
        $pos = strpos($m, 'private static function legacyCronKeys');
        self::assertIsInt($pos);
        $body = substr($m, $pos, 1400);
        self::assertStringContainsString('$unreadable = true;', $body);
        self::assertStringContainsString("'unreadable' =>", $body);

        // A bare `continue` in the catch, with no flag set first, is exactly
        // the bug: it discards the fact that the read failed.
        self::assertStringNotContainsString("catch (\\Throwable) {\n                continue;", $body);

        // And the caller abandons the pass on it, BEFORE anything can delete.
        self::assertStringContainsString("if (\$read['unreadable']) {", $m);
        $guardPos = strpos($m, "if (\$read['unreadable']) {");
        $deletePos = strpos($m, 'self::retireLegacyCronKeys()');
        self::assertIsInt($guardPos);
        self::assertIsInt($deletePos);
        self::assertLessThan($deletePos, $guardPos);
    }

    /**
     * "Your crontab keeps working" must be true of EVERY addon, not just one.
     *
     * The bug this pins: testing whether the adopted value appears anywhere in
     * the legacy set. With rows [travel_core => '1234', eurosite => 'realkey'],
     * chooseCronKey() drops the weak one and adopts 'realkey' — which IS in the
     * set — so the operator was told nothing had changed, while travel_core's
     * crontab, which had been authenticating with '1234', silently stopped
     * working. The reassuring message is the dangerous one: it is the case
     * where nobody goes looking.
     */
    public function testTheReassuringNoticeOnlyFiresWhenEveryRowHeldTheAdoptedKey(): void
    {
        $m = self::code('src/Install/SettingsMigrator.php');

        self::assertStringContainsString('$carriedForEveryAddon = $distinct === [$adopted];', $m);
        self::assertStringContainsString('self::reportCronKeyMove(!$carriedForEveryAddon);', $m);

        // The membership test that was wrong must not come back.
        self::assertStringNotContainsString(
            'self::reportCronKeyMove(!in_array($adopted, array_values($legacy), true));',
            $m,
            'a membership test cannot tell "every row agreed" from "one row agreed and the rest broke"',
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
        $m = self::code('src/Install/SettingsMigrator.php');

        $repoRoot = dirname(__DIR__, 7);
        $dirs = [
            'travel_core' => '/addon-travel-core/app/addons/travel_core',
            'novoton_holidays' => '/addon-novoton-holidays/app/addons/novoton_holidays',
            'sphinx_holidays' => '/addon-sphinx-holidays/app/addons/sphinx_holidays',
            'eurosite' => '/eurosite_addon/app/addons/eurosite',
        ];

        $listPos = strpos($m, 'private const array CRON_KEY_LEGACY_ADDONS');
        self::assertIsInt($listPos);
        $list = substr($m, $listPos, 300);

        foreach ($dirs as $addon => $dir) {
            self::assertStringContainsString("'{$addon}'", $list, "{$addon} is not retired by the move");
        }

        // Sanity, both directions: an add-on still DECLARING cron_access_key in
        // its addon.xml is one whose row will be recreated by its own heal
        // after we delete it — so the declaration has to go at the same time.
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
}
