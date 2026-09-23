<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The cron-key migration, RUN — not read.
 *
 * CronKeyConsolidationTest pins the migration's shape by reading its source,
 * because the real Tygh\Settings is CS-Cart kit code that is not in this
 * repository. A review proved that was not enough: five mutants of the
 * migration's logic — an inverted weak-value filter, a mint replaced by an
 * arbitrary winner, a message that no longer branched, a dropped abort, a
 * miscounted agreement — each survived every suite, because each left the
 * pinned strings in place.
 *
 * So this runs SettingsMigrator::consolidateCronKey() for real, in a clean
 * subprocess, against a fake settings store that mirrors the real class
 * (including its silent no-op on a missing row), and asserts on what the
 * store holds afterwards and what the operator was told. One test per cell of
 * the state table the migration is supposed to implement.
 */
#[CoversNothing]
final class CronKeyMigrationBehaviourTest extends TestCase
{
    private const string MINTED = '/^[0-9a-f]{32}$/';

    /**
     * @param array<string, mixed> $scenario
     *
     * @return array{changed: list<string>|null, rows: array<string, string>, notices: list<array{0: string, 1: string}>, logs: list<string>}
     */
    private static function simulate(array $scenario): array
    {
        $addonDir = dirname(__DIR__, 3);
        $fixture = $addonDir . '/tests/Fixtures/cron_key_consolidation_sim.php';

        // stdout carries the JSON result; stderr carries error_log() lines the
        // migration writes on purpose (e.g. "could not read ..."). Kept apart
        // so a diagnostic can never corrupt the result, and shown on failure.
        $process = proc_open(
            [PHP_BINARY, $fixture, $addonDir, (string) json_encode($scenario)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'could not start the simulation');
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, "simulation failed:\n{$output}\n{$errors}");
        $result = json_decode($output, true);
        self::assertIsArray($result, "simulation printed no JSON:\n{$output}\n{$errors}");

        /** @var array{changed: list<string>|null, rows: array<string, string>, notices: list<array{0: string, 1: string}>, logs: list<string>} $result */
        return $result;
    }

    /** @return array<string, string> the four legacy rows, all set to $value */
    private static function legacy(string $value): array
    {
        return [
            'travel_core.cron_access_key' => $value,
            'novoton_holidays.cron_access_key' => $value,
            'sphinx_holidays.cron_access_key' => $value,
            'eurosite.cron_access_key' => $value,
        ];
    }

    private static function legacyRowsLeft(array $rows): int
    {
        return count(array_filter(array_keys($rows), static fn (string $k): bool => str_ends_with($k, '.cron_access_key')));
    }

    public function testRowsThatAgreeAreCarriedOverAndTheOperatorIsToldNothingBroke(): void
    {
        $r = self::simulate(['rows' => ['travel_core.cron_key' => ''] + self::legacy('the-real-key')]);

        self::assertSame('the-real-key', $r['rows']['travel_core.cron_key']);
        self::assertSame(0, self::legacyRowsLeft($r['rows']));
        self::assertCount(1, $r['notices']);
        self::assertSame('N', $r['notices'][0][0]);
        self::assertStringContainsString('carried over', $r['notices'][0][1]);
        self::assertStringContainsString('keeps working', $r['notices'][0][1]);
    }

    /**
     * Disagreeing keys get a FRESH secret — not either of them — and the
     * operator is sent to every page a command has to be re-copied from.
     */
    public function testDisagreeingRowsMintAFreshKeyAndNameEveryPageToRecopyFrom(): void
    {
        $r = self::simulate(['rows' => [
            'travel_core.cron_key' => '',
            'novoton_holidays.cron_access_key' => 'novoton-key',
            'sphinx_holidays.cron_access_key' => 'sphinx-key',
        ]]);

        $key = $r['rows']['travel_core.cron_key'];
        self::assertMatchesRegularExpression(self::MINTED, $key);
        self::assertNotContains($key, ['novoton-key', 'sphinx-key'], 'an arbitrary winner was picked instead of minting');
        self::assertSame(0, self::legacyRowsLeft($r['rows']));

        self::assertCount(1, $r['notices']);
        [$type, $msg] = $r['notices'][0];
        self::assertSame('W', $type);
        self::assertStringContainsString('a new one was generated', $msg);
        self::assertStringNotContainsString('keeps working', $msg);
        foreach (['Travel Core -> Tools', 'Eurosite', 'Sphinx', 'Novoton'] as $place) {
            self::assertStringContainsString($place, $msg, "the minted notice never sends the operator to {$place}");
        }
    }

    /**
     * An existing key adopted over a weak one: neither "nothing broke" nor "a
     * new key was generated", and exactly the addon that used the weak value
     * is named.
     */
    public function testAKeyAdoptedOverAWeakRowNamesOnlyTheAddonThatMustChange(): void
    {
        $r = self::simulate(['rows' => [
            'travel_core.cron_key' => '',
            'travel_core.cron_access_key' => '1234',
            'eurosite.cron_access_key' => 'the-real-key',
        ]]);

        self::assertSame('the-real-key', $r['rows']['travel_core.cron_key']);
        self::assertSame(0, self::legacyRowsLeft($r['rows']));

        self::assertCount(1, $r['notices']);
        [$type, $msg] = $r['notices'][0];
        self::assertSame('W', $type);
        self::assertStringContainsString('existing key was kept', $msg);
        self::assertStringNotContainsString('a new one was generated', $msg);
        self::assertStringNotContainsString('keeps working', $msg);
        self::assertStringContainsString('Travel Core -> Tools', $msg, 'travel_core used the weak key and must be re-copied');
        self::assertStringNotContainsString('Eurosite dashboard', $msg, 'eurosite already held the kept key');
    }

    /** Four rows all holding `1234` agree perfectly — and must still never be adopted. */
    public function testTheShippedDefaultIsNeverAdoptedAndNothingIsDeleted(): void
    {
        $r = self::simulate(['rows' => ['travel_core.cron_key' => ''] + self::legacy('1234')]);

        self::assertSame('', $r['rows']['travel_core.cron_key']);
        self::assertSame(4, self::legacyRowsLeft($r['rows']), 'a working (if weak) key was deleted with nowhere to go');
        self::assertSame([], $r['notices']);
    }

    public function testAllEmptyRowsLeaveTheKeyEmptyAndAreCleanedUp(): void
    {
        $r = self::simulate(['rows' => ['travel_core.cron_key' => ''] + self::legacy('')]);

        self::assertSame('', $r['rows']['travel_core.cron_key'], 'a key nobody asked for was invented');
        self::assertSame(0, self::legacyRowsLeft($r['rows']), 'dead empty fields were left behind');
        self::assertSame([], $r['notices']);
    }

    /** An unread row is not an empty row. */
    public function testARowThatCannotBeReadStopsEverythingEvenIfTheRestAreEmpty(): void
    {
        $r = self::simulate([
            'rows' => ['travel_core.cron_key' => ''] + self::legacy(''),
            'throw_on_read' => ['eurosite.cron_access_key'],
        ]);

        self::assertSame(4, self::legacyRowsLeft($r['rows']), 'rows were deleted although one could not be read');
        self::assertSame('', $r['rows']['travel_core.cron_key']);
    }

    /** updateValue() is a silent no-op when it fails; the delete must not follow it. */
    public function testAWriteThatDoesNotLandDeletesNothing(): void
    {
        $r = self::simulate(['rows' => ['travel_core.cron_key' => ''] + self::legacy('the-real-key'), 'writes_fail' => true]);

        self::assertSame(4, self::legacyRowsLeft($r['rows']), 'the only copies were deleted after an unverified write');
        self::assertSame([], $r['notices']);
    }

    public function testNothingHappensWhileTheSuccessorRowDoesNotExist(): void
    {
        $r = self::simulate(['rows' => self::legacy('the-real-key')]);

        self::assertSame(4, self::legacyRowsLeft($r['rows']));
        self::assertArrayNotHasKey('travel_core.cron_key', $r['rows']);
    }

    public function testAnExistingCoreKeyWinsAndTheLegacyRowsAreRetiredQuietly(): void
    {
        $r = self::simulate(['rows' => ['travel_core.cron_key' => 'core-key'] + self::legacy('old-key')]);

        self::assertSame('core-key', $r['rows']['travel_core.cron_key']);
        self::assertSame(0, self::legacyRowsLeft($r['rows']));
        self::assertSame([], $r['notices'], 'no crontab changed, so nothing should be announced');
    }

    /**
     * Every operator notice about the key also lands in Administration > Logs.
     * The heal can fire on a page nobody is watching — the login form — and a
     * notification shown there is simply gone.
     */
    public function testEveryNoticeIsAlsoWrittenToTheDurableLog(): void
    {
        $r = self::simulate(['rows' => [
            'travel_core.cron_key' => '',
            'novoton_holidays.cron_access_key' => 'a',
            'sphinx_holidays.cron_access_key' => 'b',
        ]]);

        self::assertNotEmpty($r['notices']);
        self::assertSame(array_column($r['notices'], 1), $r['logs']);
    }

    /**
     * Rotating the SHARED key is not "the default on travel_core, re-copy that
     * addon's dashboard": it has no default, and it breaks every addon's jobs.
     */
    public function testRotatingTheSharedKeyNamesEveryPageNotOneAddon(): void
    {
        $r = self::simulate(['mode' => 'rotation', 'rotation' => ['travel_core', 'cron_key']]);

        self::assertCount(1, $r['notices']);
        $msg = $r['notices'][0][1];
        self::assertStringNotContainsString('default', $msg);
        self::assertStringNotContainsString("that addon's dashboard", $msg);
        foreach (['Travel Core -> Tools', 'Eurosite', 'Sphinx', 'Novoton'] as $place) {
            self::assertStringContainsString($place, $msg);
        }
    }

    /**
     * A legacy key's rotation is normally superseded moments later by the
     * consolidation's own message — so it must say so, instead of sending the
     * operator to re-copy a key that is about to be deleted.
     */
    public function testRotatingALegacyKeyDefersToTheConsolidationMessage(): void
    {
        $r = self::simulate(['mode' => 'rotation', 'rotation' => ['sphinx_holidays', 'cron_access_key']]);

        $msg = $r['notices'][0][1];
        self::assertStringContainsString('follow that one instead', $msg);
        self::assertStringContainsString('the Sphinx dashboard', $msg);
    }
}
