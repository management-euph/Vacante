<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Install\LanguageSeeder;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;

/**
 * The seeder is the self-heal path for stores installed while the .po pack was
 * missing or unparseable — the failure that made the whole settings form
 * render as blank ":" rows. Its three jobs are pinned here:
 *
 *   - variables(): merge lang_keys.php with addon.xml, addon.xml winning.
 *   - seedHash(): a stable stat fingerprint, so the init.php probe reseeds on
 *     a real edit and stays quiet otherwise.
 *   - mirrorSettingsDescriptions(): write labels AND tooltips into
 *     ?:settings_descriptions for the addon's own settings objects only.
 */
#[CoversClass(LanguageSeeder::class)]
final class LanguageSeederTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    // ── variables() ──────────────────────────────────────────────────────

    public function testVariablesMergeBothSourcesWithBothLanguages(): void
    {
        $vars = LanguageSeeder::variables();

        self::assertNotEmpty($vars);
        // From lang_keys.php (runtime-only keys, not declared in addon.xml):
        self::assertArrayHasKey('fgo_invoicing.manage_title', $vars);
        // From addon.xml <language_variables> (the addon name itself):
        self::assertArrayHasKey('fgo_invoicing', $vars);

        foreach ($vars as $name => $texts) {
            self::assertIsString($name);
            self::assertNotSame('', $name);
            self::assertArrayHasKey('en', $texts, $name);
            self::assertArrayHasKey('ro', $texts, $name);
        }
    }

    public function testAddonXmlWinsOverLangKeysOnConflict(): void
    {
        $vars = LanguageSeeder::variables();

        /** @var array<string, array<string, string>> $langKeys */
        $langKeys = require __DIR__ . '/../../../lang_keys.php';
        $xml = simplexml_load_file(__DIR__ . '/../../../addon.xml');
        self::assertNotFalse($xml);

        $fromXml = [];
        foreach ($xml->language_variables->item as $item) {
            $fromXml[(string) $item['id']][(string) $item['lang']] = (string) $item;
        }

        $conflicts = array_intersect_key($langKeys, $fromXml);
        foreach ($conflicts as $key => $_) {
            foreach ($fromXml[$key] as $lang => $text) {
                self::assertSame($text, $vars[$key][$lang] ?? null, "addon.xml must win for {$key}/{$lang}");
            }
        }

        // Keys unique to either source survive the merge.
        foreach (array_diff_key($langKeys, $fromXml) as $key => $texts) {
            self::assertSame($texts, $vars[$key], "lang_keys-only key {$key} was dropped");
        }
        foreach (array_diff_key($fromXml, $langKeys) as $key => $texts) {
            foreach ($texts as $lang => $text) {
                self::assertSame($text, $vars[$key][$lang] ?? null, "addon.xml-only key {$key} was dropped");
            }
        }
    }

    // ── seedHash() ───────────────────────────────────────────────────────

    public function testSeedHashIsAStableMd5OverBothSources(): void
    {
        $hash = LanguageSeeder::seedHash();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hash);
        self::assertSame($hash, LanguageSeeder::seedHash(), 'the probe must not reseed on every request');
    }

    // ── mirrorSettingsDescriptions() ─────────────────────────────────────

    public function testMirrorScopesItsLookupToTheAddonsOwnSettingsSection(): void
    {
        LanguageSeeder::mirrorSettingsDescriptions([]);

        $selects = DbStub::calls('settings_objects');
        self::assertCount(1, $selects);
        self::assertStringContainsString("name = 'fgo_invoicing'", $selects[0]['query']);
        self::assertSame([], DbStub::calls('settings_descriptions'), 'nothing to mirror, nothing written');
    }

    public function testMirrorWritesLabelAndTooltipPerLanguage(): void
    {
        DbStub::$rows = [['object_id' => 11, 'name' => 'client_code']];

        LanguageSeeder::mirrorSettingsDescriptions([
            'fgo_invoicing.client_code' => ['en' => 'Client code', 'ro' => 'Cod client'],
            'fgo_invoicing.client_code.tooltip' => ['en' => 'From FGO', 'ro' => 'De la FGO'],
        ]);

        $writes = DbStub::calls('settings_descriptions');
        self::assertCount(2, $writes);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $writes[0]['query'], 'must be idempotent');

        // params: object_id, lang_code, value, tooltip, value, tooltip
        self::assertSame([11, 'en', 'Client code', 'From FGO', 'Client code', 'From FGO'], $writes[0]['params']);
        self::assertSame([11, 'ro', 'Cod client', 'De la FGO', 'Cod client', 'De la FGO'], $writes[1]['params']);
    }

    public function testMirrorWritesAnEmptyTooltipWhenNoneIsDeclared(): void
    {
        DbStub::$rows = [['object_id' => 12, 'name' => 'sandbox']];

        LanguageSeeder::mirrorSettingsDescriptions([
            'fgo_invoicing.sandbox' => ['en' => 'Sandbox mode'],
        ]);

        $writes = DbStub::calls('settings_descriptions');
        self::assertCount(1, $writes);
        self::assertSame([12, 'en', 'Sandbox mode', '', 'Sandbox mode', ''], $writes[0]['params']);
    }

    public function testMirrorSkipsObjectsWithNoMatchingLanguageVariable(): void
    {
        DbStub::$rows = [
            ['object_id' => 13, 'name' => 'unknown_setting'],
            ['object_id' => 14, 'name' => 'known_setting'],
        ];

        LanguageSeeder::mirrorSettingsDescriptions([
            'fgo_invoicing.known_setting' => ['en' => 'Known'],
            // an empty text map must be skipped too, or the admin gets a blank row back
            'fgo_invoicing.unknown_setting' => [],
        ]);

        $writes = DbStub::calls('settings_descriptions');
        self::assertCount(1, $writes);
        self::assertSame(14, $writes[0]['params'][0]);
    }

    public function testMirrorToleratesMalformedRows(): void
    {
        DbStub::$rows = [
            ['object_id' => 15],           // no name — key becomes 'fgo_invoicing.', no match
            ['name' => 'client_code'],     // no object_id
        ];

        LanguageSeeder::mirrorSettingsDescriptions([
            'fgo_invoicing.client_code' => ['en' => 'Client code'],
            'fgo_invoicing.' => ['en' => 'should not be reachable by a named object'],
        ]);

        $writes = DbStub::calls('settings_descriptions');
        // The nameless row matches the degenerate key; the row without an
        // object_id still writes with a null id. Neither may throw — a schema
        // mismatch must never break the admin panel.
        self::assertCount(2, $writes);
        self::assertSame(15, $writes[0]['params'][0]);
        self::assertNull($writes[1]['params'][0]);
    }

    public function testMirrorLoadsVariablesItselfWhenNoneArePassed(): void
    {
        DbStub::$rows = [['object_id' => 16, 'name' => 'client_code']];

        LanguageSeeder::mirrorSettingsDescriptions();

        $writes = DbStub::calls('settings_descriptions');
        self::assertNotEmpty($writes, 'the no-argument call must fall back to variables()');
        foreach ($writes as $write) {
            self::assertSame(16, $write['params'][0]);
            self::assertNotSame('', $write['params'][2], 'a mirrored label must never be empty');
        }
    }

    // ── seed() ───────────────────────────────────────────────────────────

    public function testSeedMirrorsDescriptionsWithoutTravelCore(): void
    {
        self::assertFalse(
            class_exists(\Tygh\Addons\TravelCore\Install\LanguageDelivery::class),
            'fgo_invoicing must install on shops with no travel addons',
        );

        DbStub::$rows = [['object_id' => 17, 'name' => 'client_code']];

        LanguageSeeder::seed();

        self::assertNotEmpty(
            DbStub::calls('settings_descriptions'),
            'the standalone path still has to repair blank settings labels',
        );
    }
}
