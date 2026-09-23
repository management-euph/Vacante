<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * A shipped secret is a guessable secret, and blank must stay blank.
 *
 * Field state that prompted this: all four travel addons declared
 * `<default_value>1234</default_value>` for their cron access key, and three
 * of the four rows on the live store still held it — so every scheduled-sync
 * endpoint was reachable by guessing four digits.
 *
 * The part that made it unfixable from the admin panel is the reason this
 * test exists rather than a one-off cleanup. SettingsMigrator::repairValue()
 * writes the declared default into any row holding '', and its early return
 * only fires on a NON-empty current value. So clearing the key to close the
 * endpoint put `1234` back on the next admin page load. Blank was not a
 * stable state, and the one action an operator would take to protect
 * themselves was silently undone.
 *
 * Removing the defaults fixes it structurally: repairValue() returns early
 * when the declared default is empty, so a cleared secret stays cleared, and
 * every cron entry point already refuses an empty key (fail closed).
 *
 * Source-text assertions throughout — the CS-Cart kit is gitignored, so
 * nothing here can execute against a real Settings implementation.
 */
final class CronKeyDefaultsTest extends TestCase
{
    /** addon id => repo directory holding its app/addons tree. */
    private const ADDON_DIRS = [
        'travel_core' => 'addon-travel-core',
        'novoton_holidays' => 'addon-novoton-holidays',
        'sphinx_holidays' => 'addon-sphinx-holidays',
        'eurosite' => 'eurosite_addon',
        'fgo_invoicing' => 'addon-fgo-invoicing',
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    private static function addonXml(string $addon): \SimpleXMLElement
    {
        $path = self::repoRoot() . '/' . self::ADDON_DIRS[$addon] . '/app/addons/' . $addon . '/addon.xml';
        self::assertFileExists($path);
        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, "{$path} must be well-formed XML");

        return $xml;
    }

    /**
     * Provider API credentials, which MAY carry a placeholder default.
     *
     * The distinction this list encodes is the one that matters: these
     * authenticate us TO a provider. They are issued and rotated by Eurosite /
     * Novoton / Sphinx, a wrong value simply fails to reach them, and a
     * placeholder tells the operator what to replace. Everything else matching
     * the secret pattern authenticates OUR OWN endpoints and must ship empty.
     *
     * Explicit and fail-closed on purpose: a new store-owned secret is caught
     * by default and has to be argued onto this list, rather than being
     * silently exempted by a clever regex.
     *
     * @var list<string> "<addon>.<setting>"
     */
    private const array PROVIDER_CREDENTIALS = [
        'novoton_holidays.api_key',
        'novoton_holidays.api_password',
        'sphinx_holidays.api_key',
        'eurosite.api_password',
        'fgo_invoicing.private_key',
    ];

    /**
     * The regression pin: no secret that guards our own endpoints may ship a value.
     *
     * Deliberately matched on the setting NAME rather than `<type>password</type>`
     * — travel_core's cron key is declared `input`, so a type-based rule would
     * have missed the very setting that caused this.
     */
    public function testNoAddonShipsADefaultForAStoreOwnedSecret(): void
    {
        $checked = [];

        foreach (array_keys(self::ADDON_DIRS) as $addon) {
            foreach (self::addonXml($addon)->xpath('//settings//item[@id]') ?: [] as $item) {
                $id = (string) $item['id'];
                if (!preg_match('/(access_key|_secret|_key|password)$/', $id)) {
                    continue;
                }
                if (in_array("{$addon}.{$id}", self::PROVIDER_CREDENTIALS, true)) {
                    continue;
                }

                $checked[] = "{$addon}.{$id}";
                $default = trim((string) $item->default_value);
                self::assertSame(
                    '',
                    $default,
                    "{$addon}.{$id} declares a default secret ({$default}). A shipped secret is a "
                    . 'guessable secret, and a non-empty default lets repairValue() re-seed a key '
                    . 'the operator deliberately cleared. If this authenticates us to a PROVIDER '
                    . 'rather than guarding our own endpoint, add it to PROVIDER_CREDENTIALS and '
                    . 'say why.',
                );
            }
        }

        // Guard the guard: a rename that stopped matching the pattern would
        // make every assertion above vacuous.
        //
        // Named, not counted. There used to be four cron keys and the count
        // said so; consolidating them into travel_core.cron_key took the
        // number to one, and a count that just follows whatever is there
        // cannot tell "consolidated" from "the pattern stopped matching".
        self::assertContains(
            'travel_core.cron_key',
            $checked,
            'the shared cron key is not being checked — either it was renamed out of the secret '
            . 'pattern, or it is no longer declared at all',
        );
    }

    /** Every exemption must still name a setting that exists. */
    public function testProviderCredentialExemptionsAreNotStale(): void
    {
        foreach (self::PROVIDER_CREDENTIALS as $entry) {
            [$addon, $id] = explode('.', $entry, 2);
            $found = self::addonXml($addon)->xpath('//settings//item[@id="' . $id . '"]');
            self::assertNotEmpty(
                $found,
                "{$entry} is exempted but no longer declared — drop it from PROVIDER_CREDENTIALS",
            );
        }
    }

    /** The specific value that was live, by name, so it cannot come back. */
    public function testTheShippedCronKeyIsGoneEverywhere(): void
    {
        foreach (array_keys(self::ADDON_DIRS) as $addon) {
            $path = self::repoRoot() . '/' . self::ADDON_DIRS[$addon] . '/app/addons/' . $addon . '/addon.xml';
            self::assertStringNotContainsString(
                '<default_value>1234</default_value>',
                (string) file_get_contents($path),
                "{$addon} still ships 1234 as a default",
            );
        }
    }

    /**
     * The guard that makes an empty default safe. Without this early return,
     * removing the defaults would achieve nothing — repairValue() would write
     * '' over '' forever and, worse, a future default would silently re-arm.
     */
    public function testRepairValueLeavesSettingsWithNoDeclaredDefaultAlone(): void
    {
        $migrator = self::src('src/Install/SettingsMigrator.php');

        $pos = strpos($migrator, 'private static function repairValue(');
        self::assertIsInt($pos);
        $body = substr($migrator, $pos, 700);

        self::assertStringContainsString("if (\$default === '') {", $body);
        self::assertStringContainsString('return false;', $body);

        // And the docblock must not repeat the claim that was false: that a
        // deliberately cleared field is never overridden. It is — that is the
        // whole reason the defaults had to go.
        $docStart = strrpos(substr($migrator, 0, $pos), '/**');
        self::assertIsInt($docStart);
        $doc = substr($migrator, $docStart, $pos - $docStart);
        self::assertStringNotContainsString('is never overridden', $doc);
    }

    /**
     * Rotating an existing `1234` row must be an EXPLICIT list.
     *
     * "Randomise every password-typed setting still holding its default" would
     * reach api_password and destroy the provider credentials the store runs
     * on. Same reasoning as SettingsMigrator::RETIRED, which says so out loud.
     */
    public function testWeakSecretRotationIsExplicitAndVerified(): void
    {
        $migrator = self::src('src/Install/SettingsMigrator.php');

        self::assertStringContainsString('private const array WEAK_SECRETS', $migrator);
        self::assertStringContainsString('private const array WEAK_VALUES', $migrator);
        self::assertStringContainsString('rotateWeakSecrets(', $migrator);

        // The live key must be covered by the rotation. It is the only one
        // declared now, and it ships no default — but an operator can still
        // type `1234` into the field, and a four-digit secret is no better
        // for being hand-written.
        self::assertStringContainsString("'cron_key'", $migrator);
        $weakPos = strpos($migrator, 'private const array WEAK_SECRETS');
        self::assertIsInt($weakPos);
        $weak = substr($migrator, $weakPos, 900);
        self::assertStringContainsString("'travel_core' => ['cron_access_key', 'cron_key']", $weak);

        // The legacy per-addon entries stay until consolidateCronKey() has
        // removed those rows everywhere. A store mid-migration still HAS them,
        // and dropping the entries would leave a live `1234` unrotated on the
        // exact stores the migration has not reached yet.
        foreach (['novoton_holidays', 'sphinx_holidays', 'eurosite'] as $addon) {
            self::assertStringContainsString("'{$addon}' => ['cron_access_key']", $weak);
        }

        // The write is read back — a silent no-op would leave 1234 live while
        // reporting a rotation, which is worse than not trying.
        $pos = strpos($migrator, 'private static function rotateWeakSecrets(');
        self::assertIsInt($pos);
        $body = substr($migrator, $pos, 1600);
        self::assertStringContainsString('$settings->getValue($name, $addon) !== $fresh', $body);
        self::assertStringContainsString('random_bytes(16)', $body);
        // And it can never take the admin down: this runs on every admin page.
        self::assertStringContainsString('catch (\Throwable $e)', $body);

        // A rotated key breaks the crontab the operator pasted — say so.
        self::assertStringContainsString('reportRotation(', $migrator);
        self::assertStringContainsString('fn_set_notification', $migrator);
    }

    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }
}
