<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\Enum\Layout;

/**
 * The template files themselves: named for the Template list, shipped for
 * nova_theme too, and written with what CS-Cart's Smarty 5 knows.
 */
final class TemplateSourceTest extends TestCase
{
    private static function themes(): string
    {
        return dirname(__DIR__, 6) . '/design/themes/';
    }

    /**
     * @return array<string, string> path under the theme => source, of every responsive file
     */
    private static function responsiveFiles(): array
    {
        $root = self::themes() . 'responsive/';
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr((string) $file, strlen($root))] = (string) file_get_contents((string) $file);
        }
        ksort($files);

        return $files;
    }

    /**
     * @return array<string, string> path => source of the .tpl files
     */
    private static function templates(): array
    {
        return array_filter(self::responsiveFiles(), static fn (string $path): bool => str_ends_with($path, '.tpl'), ARRAY_FILTER_USE_KEY);
    }

    public function testEachLayoutTemplateIsNamedOnItsFirstLine(): void
    {
        foreach (Layout::cases() as $layout) {
            $path = self::themes() . 'responsive/templates/' . $layout->template();
            self::assertFileExists($path);
            $first = strtok((string) file_get_contents($path), "\n");
            // CS-Cart reads block-description as a language variable; without
            // it the Template list shows the file name.
            self::assertSame('{** block-description:' . $layout->descriptionKey() . ' **}', $first, $layout->value);
        }
    }

    public function testThereAreNoOtherBlockTemplates(): void
    {
        $dir = self::themes() . 'responsive/templates/' . Layout::TEMPLATE_DIR;
        $files = array_map('basename', glob($dir . '*.tpl') ?: []);
        sort($files);

        self::assertSame(['accordion.tpl', 'cards.tpl', 'stacked.tpl', 'tabs.tpl'], $files, 'a template in the folder but not in the schema would not be offered');
    }

    /**
     * On a nova_theme store the block manager offers a block type only when
     * its templates are in nova_theme itself: every file ships there too,
     * byte for byte (composer mirror keeps them so, ThemeMirrorTest checks).
     */
    public function testNovaThemeGetsTheSameFiles(): void
    {
        $nova = self::themes() . 'nova_theme/';
        $files = self::responsiveFiles();
        self::assertCount(10, $files);
        foreach ($files as $path => $source) {
            self::assertFileExists($nova . $path);
            self::assertSame($source, (string) file_get_contents($nova . $path), $path . ' differs in nova_theme: run composer mirror');
        }
        $novaFiles = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($nova, \FilesystemIterator::SKIP_DOTS)) as $file) {
            ++$novaFiles;
        }
        self::assertSame(count($files), $novaFiles, 'no file only in nova_theme');
    }

    public function testOnlyModifiersAndFunctionsCsCartKnows(): void
    {
        // Smarty 5 dropped PHP functions as modifiers (|sizeof and the like):
        // the templates use Smarty's own, and CS-Cart's __() and fn_url.
        $allowedModifiers = ['default', 'fn_url'];
        $allowedFunctions = ['__', 'fn_euph_pages_in_blocks_view'];
        foreach (self::templates() as $path => $source) {
            $code = (string) preg_replace('/\{\*.*?\*\}/s', '', $source);
            preg_match_all('/\{[^}]*\}/', $code, $tags);
            foreach ($tags[0] as $tag) {
                $tag = (string) preg_replace('/"[^"]*"/', '""', $tag);
                preg_match_all('/\|([a-z_]+)/', $tag, $modifiers);
                foreach ($modifiers[1] as $modifier) {
                    self::assertContains($modifier, $allowedModifiers, $path . ': ' . $tag);
                }
                preg_match_all('/\b([a-z_][a-z0-9_]*)\(/i', $tag, $calls);
                foreach ($calls[1] as $function) {
                    self::assertContains($function, $allowedFunctions, $path . ': ' . $tag);
                }
            }
            self::assertStringNotContainsString('{php', $code, $path);
            self::assertStringNotContainsString('$smarty.const', $code, $path . ': the presenter works the page types out');
        }
    }

    public function testNoScriptsOrInlineStylesInTheTemplates(): void
    {
        foreach (self::templates() as $path => $source) {
            self::assertStringNotContainsString('<script', $source, $path . ': the script lives in js/addons/euph_pages_in_blocks/func.js');
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/', $source, $path);
        }
    }

    public function testEveryIncludedTemplateExists(): void
    {
        $root = self::themes() . 'responsive/templates/';
        foreach (self::templates() as $path => $source) {
            preg_match_all('/\{include file="([^"]+)"/', $source, $includes);
            foreach ($includes[1] as $include) {
                self::assertFileExists($root . $include, $path);
            }
        }
    }

    public function testTheAssetsTheHooksLoadExist(): void
    {
        $addon = dirname(self::themes(), 2);
        self::assertFileExists($addon . '/js/addons/euph_pages_in_blocks/func.js');
        self::assertFileExists(self::themes() . 'responsive/css/addons/euph_pages_in_blocks/styles.less');
    }

    /**
     * CS-Cart compiles the storefront styles with lessphp, which fails on
     * custom-property declarations and evaluates calc() and slashes: the
     * stylesheet is plain CSS that only reads var(--euph-pib-accent).
     */
    public function testTheStylesheetIsPlainCss(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(self::themes() . 'responsive/css/addons/euph_pages_in_blocks/styles.less'));

        self::assertDoesNotMatchRegularExpression('/^\s*--[a-z-]+\s*:/m', $css, 'no custom-property declarations');
        self::assertDoesNotMatchRegularExpression('/\b(?:calc|min|max|clamp)\(/', $css);
        self::assertDoesNotMatchRegularExpression('/@(?!media\b)|~"|&|\.[a-z-]+\(\)/', $css, 'no LESS variables, escapes, parent selectors or mixins');
        self::assertDoesNotMatchRegularExpression('/:\s*[^;{}]*\d\s*\/\s*\d/', $css, 'no slash between numbers');
        self::assertSame(substr_count($css, '{'), substr_count($css, '}'));
        preg_match_all('/var\(([^,)]+)/', $css, $vars);
        self::assertSame(['--euph-pib-accent'], array_values(array_unique(array_map('trim', $vars[1]))));
    }
}
