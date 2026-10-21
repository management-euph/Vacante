<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * How the add-on hands data to its script. CS-Cart's {scripts} block joins
 * the inline scripts of the index:scripts hook into one script, so a
 * <script type="application/json"> block there runs as JavaScript: a syntax
 * error that stops the scripts joined with it, and the data never arrives.
 * The data goes into the add-on's own inline script, as a JavaScript value.
 */
final class ScriptDataTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/func.php';
    }

    public function testNoTemplateHandsDataInAJsonBlock(): void
    {
        $design = dirname(__DIR__, 6) . '/design';
        $templates = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($design, \FilesystemIterator::SKIP_DOTS)),
            '/\.tpl$/',
        );

        $read = 0;
        foreach ($templates as $template) {
            $source = (string) file_get_contents((string) $template);
            self::assertStringNotContainsString('application/json', $source, (string) $template);
            ++$read;
        }
        self::assertGreaterThan(10, $read);

        $script = (string) file_get_contents(dirname(__DIR__, 6) . '/js/addons/euph_profile_types/func.js');
        self::assertStringNotContainsString("getElementById('euph_pt_", $script);
    }

    public function testTheInlineScriptsSetTheValues(): void
    {
        $design = dirname(__DIR__, 6) . '/design';
        $backend = (string) file_get_contents($design . '/backend/templates/addons/euph_profile_types/hooks/index/scripts.post.tpl');
        $storefront = (string) file_get_contents($design . '/themes/responsive/templates/addons/euph_profile_types/hooks/index/scripts.post.tpl');

        self::assertStringContainsString('list: {if $euph_pt_list_config}{$euph_pt_list_config nofilter}{else}null{/if}', $backend);
        self::assertStringContainsString('pills: {if $addons.euph_profile_types.choice_view == "pills"}true{else}false{/if}', $storefront);
    }

    public function testAValueCannotCloseTheScriptOrOpenAComment(): void
    {
        $data = ['name' => '</script><script>alert(1)</script>', 'note' => "it's \"quoted\" & <!-- not a comment", 'types' => [1, 2]];
        $value = fn_euph_profile_types_js_value($data);

        foreach (['<', '>', '&', "'"] as $character) {
            self::assertStringNotContainsString($character, $value);
        }
        self::assertSame($data, json_decode($value, true));
    }

    public function testWhatJsonCannotHoldDoesNotBreakTheScript(): void
    {
        self::assertSame('"a\ufffdb"', fn_euph_profile_types_js_value("a\xB1b"), 'invalid UTF-8 is replaced');
        self::assertSame('null', fn_euph_profile_types_js_value(NAN));
        self::assertSame('[]', fn_euph_profile_types_js_value([]));
    }
}
