<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * The language packs are what the panel and the storefront show: English
 * and Romanian must hold the same texts, and every text the add-on asks for
 * must be in them (a missing one shows as its raw name).
 */
final class LanguagePackTest extends TestCase
{
    private const ADDON = 'euph_profile_types';

    private static function root(): string
    {
        return dirname(__DIR__, 6);
    }

    /**
     * @return array<string, string> msgctxt => msgstr
     */
    private static function po(string $lang): array
    {
        $file = self::root() . '/var/langs/' . $lang . '/addons/' . self::ADDON . '.po';
        self::assertFileExists($file);
        preg_match_all('/msgctxt "((?:[^"\\\\]|\\\\.)*)"\nmsgid "(?:[^"\\\\]|\\\\.)*"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/', (string) file_get_contents($file), $matches, PREG_SET_ORDER);
        $entries = [];
        foreach ($matches as $match) {
            self::assertArrayNotHasKey($match[1], $entries, 'duplicate ' . $match[1]);
            $entries[$match[1]] = stripcslashes($match[2]);
        }

        return $entries;
    }

    public function testEnglishAndRomanianHoldTheSameTexts(): void
    {
        $en = self::po('en');
        $ro = self::po('ro');

        self::assertGreaterThan(100, count($en));
        self::assertSame(array_keys($en), array_keys($ro));
        foreach ($ro as $context => $text) {
            self::assertNotSame('', trim($text), $context . ' is empty in Romanian');
        }
    }

    public function testEveryTextTheAddonAsksForIsThere(): void
    {
        $en = self::po('en');
        $root = self::root();
        $files = [];
        foreach (['app/addons/' . self::ADDON, 'design', 'js'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = (string) $file;
                if (preg_match('~/(vendor|tests)/~', $path) !== 1 && preg_match('/\.(php|tpl|js)$/', $path) === 1) {
                    $files[] = $path;
                }
            }
        }

        $used = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/(?:__\(\s*["\']|fn_euph_profile_types_text\(\s*["\']|_\.tr\(\s*["\']|\'|")(euph_profile_types\.[a-z_]+)(["\'`])/', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if ($match[2] !== '`') {
                    $used[$match[1]] = basename($file);
                }
            }
        }
        // Built from parts in the templates.
        foreach (['link_open', 'link_locked', 'activation_store', 'activation_instant', 'activation_approval'] as $suffix) {
            $used['euph_profile_types.' . $suffix] = 'update.tpl';
        }
        unset($used['euph_profile_types.restore']); // the session storage key of func.js

        self::assertNotSame([], $used);
        foreach ($used as $name => $file) {
            self::assertArrayHasKey('Languages::' . $name, $en, $name . ' (used in ' . $file . ') is missing from the language pack');
        }
        foreach (['euph_customer_profile_types', 'euph_vendor_profile_types', 'euph_profile_type', 'privileges.view_euph_profile_types', 'privileges.manage_euph_profile_types'] as $name) {
            self::assertArrayHasKey('Languages::' . $name, $en);
        }
    }

    public function testEverySettingAndVariantIsNamed(): void
    {
        $en = self::po('en');
        $xml = simplexml_load_file(self::root() . '/app/addons/' . self::ADDON . '/addon.xml');
        self::assertNotFalse($xml);

        self::assertArrayHasKey('Addons::name::' . self::ADDON, $en);
        self::assertArrayHasKey('Addons::description::' . self::ADDON, $en);
        foreach ($xml->settings->sections->section as $section) {
            self::assertArrayHasKey('SettingsSections::' . self::ADDON . '::' . $section['id'], $en);
            foreach ($section->items->item as $item) {
                $id = (string) $item['id'];
                self::assertArrayHasKey('SettingsOptions::' . self::ADDON . '::' . $id, $en, $id);
                foreach ($item->variants->item ?? [] as $variant) {
                    self::assertArrayHasKey('SettingsVariants::' . self::ADDON . '::' . $id . '::' . $variant['id'], $en);
                }
            }
        }
    }

    public function testNoNameIsAPrefixOfAnotherByADot(): void
    {
        // CS-Cart splits dotted names into nested arrays: "a.b" and "a.b.c"
        // cannot both exist.
        $names = array_map(
            static fn (string $context): string => substr($context, strlen('Languages::')),
            array_filter(array_keys(self::po('en')), static fn (string $context): bool => str_starts_with($context, 'Languages::')),
        );
        foreach ($names as $name) {
            foreach ($names as $other) {
                self::assertFalse(str_starts_with($other, $name . '.'), $name . ' collides with ' . $other);
            }
        }
    }
}
