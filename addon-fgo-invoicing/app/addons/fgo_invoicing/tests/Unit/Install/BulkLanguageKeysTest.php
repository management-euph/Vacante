<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkUi;

/**
 * A key the store does not have renders as "_fgo_invoicing.x": in a menu, a
 * column header, a pre-check reason or a button of the page script. Every
 * key the add-on uses must exist in lang_keys.php (runtime heal + .po
 * parity) or addon.xml:
 *
 *   - literal __("fgo_invoicing.x") in templates, schemas, controllers,
 *     functions and hooks, and fn_fgo_invoicing_t('fgo_invoicing.x');
 *   - context-menu item names ('template' => 'fgo_invoicing.x');
 *   - the keys chosen in code (BulkUi: titles, verdicts, pre-check reason
 *     codes, the page script's strings);
 *   - the per-state keys the column template builds (state_<status>);
 *   - every i18n.<name> bulk.js reads is one the controller hands it.
 */
#[CoversNothing]
final class BulkLanguageKeysTest extends TestCase
{
    private const ADDON = __DIR__ . '/../../..';

    private static function package(): string
    {
        return dirname(__DIR__, 6);
    }

    /**
     * @return array<string, true>
     */
    private static function knownKeys(): array
    {
        /** @var array<string, mixed> $langKeys */
        $langKeys = require self::ADDON . '/lang_keys.php';
        $known = array_fill_keys(array_keys($langKeys), true);

        $xml = simplexml_load_file(self::ADDON . '/addon.xml');
        self::assertNotFalse($xml);
        foreach ($xml->language_variables->item as $item) {
            $known[(string) $item['id']] = true;
        }

        return $known;
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $roots = [
            self::package() . '/design/backend/templates/addons/fgo_invoicing',
            self::package() . '/design/backend/mail/templates/addons/fgo_invoicing',
            self::ADDON . '/schemas',
            self::ADDON . '/controllers',
            self::ADDON . '/functions',
            self::ADDON . '/hooks',
        ];
        $files = [];
        foreach ($roots as $root) {
            self::assertDirectoryExists($root);
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file instanceof \SplFileInfo && in_array($file->getExtension(), ['tpl', 'php'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    public function testEveryLiteralKeyInTheSourcesExists(): void
    {
        $known = self::knownKeys();
        $used = [];
        foreach (self::sourceFiles() as $file) {
            $src = (string) file_get_contents($file);
            preg_match_all(
                '/(?:__|fn_fgo_invoicing_t)\(\s*["\'](fgo_invoicing\.[a-z0-9_]+)["\']|\'template\'\s*=>\s*\'(fgo_invoicing\.[a-z0-9_]+)\'/',
                $src,
                $m,
                PREG_SET_ORDER,
            );
            foreach ($m as $match) {
                $key = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
                $used[$key][] = basename($file);
            }
        }

        self::assertGreaterThan(80, count($used), 'the scan found the templates');
        foreach ($used as $key => $where) {
            self::assertArrayHasKey($key, $known, "{$key} (used in " . implode(', ', array_unique($where)) . ') is not declared');
        }
    }

    public function testEveryKeyChosenInCodeExists(): void
    {
        $known = self::knownKeys();

        foreach (BulkUi::allKeys() as $key) {
            self::assertArrayHasKey($key, $known, $key);
        }
    }

    /**
     * addon.xml wins over lang_keys.php at runtime (LanguageSeeder), so a
     * bulk key declared in both would show the addon.xml text.
     */
    public function testNoBulkKeyIsShadowedByAddonXml(): void
    {
        $xml = simplexml_load_file(self::ADDON . '/addon.xml');
        self::assertNotFalse($xml);
        $xmlIds = [];
        foreach ($xml->language_variables->item as $item) {
            $xmlIds[(string) $item['id']] = true;
        }

        foreach (BulkUi::allKeys() as $key) {
            self::assertArrayNotHasKey($key, $xmlIds, $key);
        }
    }

    /**
     * manage_data.post.tpl renders __("fgo_invoicing.state_`$fgo_status`")
     * for the terminal states.
     */
    public function testEveryInvoiceStateHasALabel(): void
    {
        $known = self::knownKeys();

        foreach ([
            Constants::STATUS_ISSUED,
            Constants::STATUS_FAILED,
            Constants::STATUS_PENDING,
            Constants::STATUS_CANCELED,
            Constants::STATUS_REVERSED,
            Constants::STATUS_DELETED,
        ] as $status) {
            self::assertArrayHasKey('fgo_invoicing.state_' . $status, $known, $status);
        }
    }

    public function testEveryStringThePageScriptReadsIsHandedToIt(): void
    {
        $js = (string) file_get_contents(self::package() . '/js/addons/fgo_invoicing/bulk.js');
        preg_match_all('/i18n\.([a-z_]+)/', $js, $m);
        $provided = array_merge(array_keys(BulkUi::JS_STRINGS), ['verb', 'current', 'running']);

        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $name) {
            self::assertContains($name, $provided, "bulk.js reads i18n.{$name}, which the controller never sets");
        }

        // Built names: i18n['count_' + form], i18n['chip_' + key], i18n[outcome].
        foreach (['one', 'few', 'many'] as $form) {
            self::assertContains('count_' . $form, $provided);
        }
        foreach (['issued', 'done', 'failed', 'skipped', 'remaining'] as $chip) {
            self::assertContains('chip_' . $chip, $provided);
        }
        foreach (['issued', 'done', 'failed', 'skipped'] as $outcome) {
            self::assertContains($outcome, $provided);
        }
    }
}
