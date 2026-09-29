<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The destination picker's templates and its script agree: every hook the
 * script looks for is rendered, the choices post under dest[…], Save never
 * sends the "disable products" form, and the script and styles load inside
 * the caller's mainbox capture (they come with the component).
 */
final class DestinationPickerTemplatesTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function tpl(string $name): string
    {
        return (string) file_get_contents(self::root() . '/design/backend/templates/addons/travel_core/components/' . $name);
    }

    private static function templates(): string
    {
        return self::tpl('destination_picker.tpl') . self::tpl('destination_country.tpl') . self::tpl('destination_country_body.tpl');
    }

    public function testEveryHookTheScriptUsesIsRendered(): void
    {
        $js = (string) file_get_contents(self::root() . '/js/addons/travel_core/destination-picker.js');
        preg_match_all("/['\"\\[](data-(?:dest|hint|group-badge|chip-n|travel-dest)[a-z-]*)/", $js, $m);
        $hooks = array_unique($m[1]);
        self::assertGreaterThan(20, count($hooks), 'the scan found the hooks');

        $html = self::templates();
        $missing = [];
        foreach ($hooks as $hook) {
            if (!str_contains($html, $hook)) {
                $missing[] = $hook;
            }
        }
        self::assertSame([], $missing, 'hooks the script queries but no template renders');
    }

    public function testChoicesPostUnderDest(): void
    {
        $country = self::tpl('destination_country.tpl');
        $body = self::tpl('destination_country_body.tpl');

        self::assertStringContainsString('name="dest[{$_c.key|escape:html}][mode]"', $country);
        self::assertStringContainsString('name="dest[{$_c.key|escape:html}][items][]"', $body);
        self::assertStringContainsString('name="dest[{$_c.key|escape:html}][groups][]"', $body);
        self::assertStringContainsString('name="dest[{$_c.key|escape:html}][loaded]"', $body, 'a body never opened keeps what it had');
        self::assertStringContainsString('name="dest_json"', self::tpl('destination_picker.tpl'));
    }

    public function testDisablingIsItsOwnFormOutsideSave(): void
    {
        $page = self::tpl('destination_picker.tpl');
        $main = strpos($page, 'data-dest-form');
        self::assertIsInt($main);
        $mainEnd = strpos($page, '</form>', $main);
        $outside = strpos($page, '<form action="{$_d.outside_url}"');
        self::assertIsInt($outside);
        self::assertGreaterThan($mainEnd, $outside);
    }

    public function testTheComponentBringsItsScriptAndStyles(): void
    {
        $page = self::tpl('destination_picker.tpl');

        self::assertStringContainsString('{style src="addons/travel_core/destination-picker.css"}', $page);
        self::assertStringContainsString('{script src="js/addons/travel_core/destination-picker.js"}', $page);
        self::assertStringNotContainsString('{capture', $page, 'the caller wraps it in its mainbox capture');
        self::assertFileExists(self::root() . '/design/backend/css/addons/travel_core/destination-picker.css');
    }

    /** The page's top row holds the add-on's page links; Save sits in the summary and the save bar. */
    public function testSaveIsNotInTheTopRow(): void
    {
        $page = self::tpl('destination_picker.tpl');

        self::assertStringNotContainsString('name="buttons"', $page);
        self::assertSame(2, substr_count($page, 'type="submit" form="{$_d.id}-dest-form"'));
        self::assertStringContainsString('data-dest-savebar hidden', $page);
    }
}
