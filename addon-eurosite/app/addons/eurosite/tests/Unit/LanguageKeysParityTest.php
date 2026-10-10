<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * lang_keys.php (the runtime self-heal source) and the two .po packs (the
 * install-time source) describe the same labels — this pins them together.
 * Drift here is exactly the "menu shows _eurosite.dashboard" bug: a key
 * present in one delivery path but not the other renders raw on whichever
 * store used the other path.
 */
final class LanguageKeysParityTest extends TestCase
{
    private const ADDON_ROOT = __DIR__ . '/../..';

    /**
     * @return array<string, array<string, string>> key => [lang => text]
     */
    private static function loadLangKeys(): array
    {
        /** @var array<string, array<string, string>> $vars */
        $vars = require self::ADDON_ROOT . '/lang_keys.php';

        return $vars;
    }

    /**
     * @return array<string, string> key => msgstr
     */
    private static function parsePo(string $lang): array
    {
        $path = self::ADDON_ROOT . '/../../../var/langs/' . $lang . '/addons/eurosite.po';
        $src = (string) file_get_contents($path);
        $out = [];
        preg_match_all(
            '/msgctxt "Languages::(eurosite\.[a-z_]+)"\nmsgid "(?:[^"\\\\]|\\\\.)*"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/',
            $src,
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $m) {
            $out[$m[1]] = str_replace('\\"', '"', $m[2]);
        }

        return $out;
    }

    public function testLangKeysAndPoPacksCarryTheSameKeys(): void
    {
        $keys = self::loadLangKeys();
        $en = self::parsePo('en');
        $ro = self::parsePo('ro');

        self::assertNotEmpty($keys);
        self::assertSame([], array_diff_key($keys, $en), 'keys in lang_keys.php missing from en po');
        self::assertSame([], array_diff_key($en, $keys), 'keys in en po missing from lang_keys.php');
        self::assertSame(array_keys($en), array_keys($ro), 'en/ro po key sets differ');
    }

    public function testLangKeysTextsMatchThePoPacks(): void
    {
        $keys = self::loadLangKeys();
        $en = self::parsePo('en');
        $ro = self::parsePo('ro');

        foreach ($keys as $key => $texts) {
            self::assertSame($en[$key] ?? null, $texts['en'] ?? null, "en text drift for {$key}");
            self::assertSame($ro[$key] ?? null, $texts['ro'] ?? null, "ro text drift for {$key}");
        }
    }

    public function testEveryLangKeyHasBothLanguages(): void
    {
        foreach (self::loadLangKeys() as $key => $texts) {
            self::assertArrayHasKey('en', $texts, $key);
            self::assertArrayHasKey('ro', $texts, $key);
            self::assertNotSame('', trim($texts['en']), $key);
            self::assertNotSame('', trim($texts['ro']), $key);
        }
    }
}
