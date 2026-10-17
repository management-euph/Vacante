<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * The add-on is VacanteLitoral.ro's own: nothing in it names another
 * vendor or its add-ons, and it speaks English and Romanian only.
 */
final class CleanSourceTest extends TestCase
{
    /**
     * @return list<string> every source file of the add-on, its dependencies aside
     */
    private static function files(): array
    {
        $root = dirname(__DIR__, 6);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = (string) $file;
            if (preg_match('~/(vendor|\.phpunit\.cache)/~', $path) === 1 || preg_match('/\.(php|tpl|js|less|css|xml|po|json|md|mjs|html)$/', $path) !== 1) {
                continue;
            }
            $files[] = $path;
        }
        self::assertGreaterThan(30, count($files));

        return $files;
    }

    public function testNoOtherVendorIsNamed(): void
    {
        // Built from parts, so that this file passes its own check.
        $patterns = [
            '/\b' . 'ecl' . '_[a-z]/i',
            '/' . 'ecom' . '[\s_-]*' . 'labs' . '/i',
            '/' . 'ecom' . '-' . 'labs' . '\.com/i',
        ];
        foreach (self::files() as $file) {
            $source = (string) file_get_contents($file);
            foreach ($patterns as $pattern) {
                self::assertDoesNotMatchRegularExpression($pattern, $source, $file);
            }
        }
    }

    public function testNoRussianOrOtherCyrillicText(): void
    {
        foreach (self::files() as $file) {
            self::assertDoesNotMatchRegularExpression('/\p{Cyrillic}|&#x4[0-4][0-9a-f];/iu', (string) file_get_contents($file), $file);
        }
    }

    public function testTheLanguagePacksAreEnglishAndRomanianOnly(): void
    {
        $langs = array_map('basename', glob(dirname(__DIR__, 6) . '/var/langs/*', GLOB_ONLYDIR) ?: []);
        sort($langs);

        self::assertSame(['en', 'ro'], $langs);
    }
}
