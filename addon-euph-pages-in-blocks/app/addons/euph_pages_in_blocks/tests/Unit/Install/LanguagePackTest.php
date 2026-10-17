<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\LanguagePack;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\Schema;

/**
 * The language packs are what the panel and the storefront show: English
 * and Romanian hold the same texts, and every text the add-on asks for is
 * in them (a missing one shows as its raw name).
 */
final class LanguagePackTest extends TestCase
{
    public function testEnglishAndRomanianHoldTheSameTexts(): void
    {
        $en = LanguagePack::load('en');
        $ro = LanguagePack::load('ro');

        self::assertGreaterThan(25, count($en->entries));
        self::assertSame(array_keys($en->entries), array_keys($ro->entries));
        foreach ($en->entries as $context => $text) {
            self::assertSame($text, $en->sources[$context], $context . ': the English text is its own source');
            self::assertSame($en->sources[$context], $ro->sources[$context], $context . ': both packs translate the same source');
            self::assertNotSame('', trim($ro->entries[$context]), $context . ' is empty in Romanian');
        }
    }

    public function testTheRomanianPackIsTranslated(): void
    {
        $en = LanguagePack::load('en')->entries;
        $ro = LanguagePack::load('ro')->entries;
        $same = array_keys(array_intersect_assoc($en, $ro));

        // Only what reads the same in both languages.
        self::assertSame(['SettingsSections::euph_pages_in_blocks::general'], $same);
        self::assertStringContainsString('ă', implode(' ', $ro));
    }

    public function testThePlaceholdersAreKept(): void
    {
        $en = LanguagePack::load('en')->entries;
        $ro = LanguagePack::load('ro')->entries;
        foreach ($en as $context => $text) {
            preg_match_all('/\[[a-z_]+\]/', $text, $placeholders);
            foreach ($placeholders[0] as $placeholder) {
                self::assertStringContainsString($placeholder, $ro[$context], $context);
            }
        }
    }

    public function testTheAddonAndItsSettingAreNamed(): void
    {
        $en = LanguagePack::english()->entries;

        foreach (['Addons::name::euph_pages_in_blocks', 'Addons::description::euph_pages_in_blocks', 'SettingsSections::euph_pages_in_blocks::general', 'SettingsOptions::euph_pages_in_blocks::info'] as $context) {
            self::assertArrayHasKey($context, $en);
        }
    }

    public function testTheBlockTypeTemplatesAndSettingsAreNamed(): void
    {
        $variables = LanguagePack::english()->languageVariables();

        self::assertArrayHasKey('block_' . Schema::BLOCK_TYPE, $variables);
        self::assertArrayHasKey('block_' . Schema::BLOCK_TYPE . '_description', $variables);
        foreach (Schema::LAYOUTS as $layout) {
            self::assertArrayHasKey('tmpl_euph_pib_' . $layout, $variables);
            foreach (Schema::settings($layout) as $name => $setting) {
                self::assertArrayHasKey($name, $variables, $name . ' labels the setting');
                if (empty($setting['no_lang']) && is_array($setting['values'] ?? null)) {
                    foreach ($setting['values'] as $label) {
                        self::assertIsString($label);
                        self::assertArrayHasKey($label, $variables, $label . ' labels a choice of ' . $name);
                    }
                }
            }
        }
    }

    public function testEveryTextTheTemplatesAskForIsThere(): void
    {
        $variables = LanguagePack::english()->languageVariables();
        $root = dirname(__DIR__, 6) . '/design';
        $used = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!str_ends_with((string) $file, '.tpl')) {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            preg_match_all('/__\("([a-z0-9_.]+)"/', $source, $calls);
            preg_match_all('/block-description:([a-z0-9_]+)/', $source, $descriptions);
            foreach (array_merge($calls[1], $descriptions[1]) as $name) {
                $used[$name] = basename((string) $file);
            }
        }

        self::assertGreaterThan(10, count($used));
        foreach ($used as $name => $file) {
            self::assertArrayHasKey($name, $variables, $name . ' (used in ' . $file . ') is missing from the language pack');
        }
    }

    public function testNoNameIsAPrefixOfAnotherByADot(): void
    {
        // CS-Cart splits dotted names into nested arrays: "a.b" and "a.b.c"
        // cannot both exist.
        $names = array_keys(LanguagePack::english()->languageVariables());
        foreach ($names as $name) {
            foreach ($names as $other) {
                self::assertFalse(str_starts_with($other, $name . '.'), $name . ' collides with ' . $other);
            }
        }
    }
}
