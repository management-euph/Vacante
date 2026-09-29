<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Destination whitelist page: Travel Core's destination picker with
 * Eurosite's country catalog line on top, a country's cities loaded on open,
 * and a Save that refuses to sell nothing and never touches products.
 */
final class WhitelistEditorContractTest extends TestCase
{
    private const ADDON = __DIR__ . '/../..';

    private static function tpl(): string
    {
        return (string) file_get_contents(self::ADDON . '/../../../design/backend/templates/addons/eurosite/views/eurosite/whitelist.tpl');
    }

    private static function controller(): string
    {
        return (string) file_get_contents(self::ADDON . '/controllers/backend/eurosite.php');
    }

    /** @return string the body of one `if ($mode === '…') {` block */
    private static function mode(string $mode): string
    {
        $src = self::controller();
        $start = strpos($src, "if (\$mode === '{$mode}') {");
        self::assertIsInt($start, "mode {$mode} is missing");

        return substr($src, $start, 2600);
    }

    /** The picker brings its script and styles; inside the capture, they run after AJAX navigation too. */
    public function testThePageIsTheSharedPickerInsideTheCapture(): void
    {
        $tpl = self::tpl();
        $include = strpos($tpl, '{include file="addons/travel_core/components/destination_picker.tpl" dest=$eurosite_destinations dest_top=$smarty.capture.eurosite_dest_top}');
        self::assertIsInt($include);
        self::assertLessThan(strrpos($tpl, '{/capture}'), $include);
        self::assertFileDoesNotExist(self::ADDON . '/../../../js/addons/eurosite/whitelist.js', 'replaced by travel_core/destination-picker.js');
        self::assertStringNotContainsString('<script', $tpl);
    }

    /** The catalog line keeps Sync countries, which comes back to this page; its form is not the picker's. */
    public function testTheCountryCatalogLineStays(): void
    {
        $tpl = self::tpl();
        $top = substr($tpl, (int) strpos($tpl, '{capture name="eurosite_dest_top"}'));
        $top = substr($top, 0, (int) strpos($top, '{/capture}'));

        self::assertStringContainsString('<input type="hidden" name="sync_type" value="countries">', $top);
        self::assertStringContainsString('<input type="hidden" name="return_to" value="whitelist">', $top);
        self::assertStringContainsString('eurosite.whitelist_needs_countries', $top);
        self::assertStringContainsString('eurosite.whitelist_names_missing', $top);
        self::assertStringContainsString('{$eurosite_country_heal_error|escape:html}', $top);
        self::assertStringContainsString("'eurosite.whitelist'", substr(self::controller(), (int) strpos(self::controller(), "=== 'whitelist'\n")));
    }

    public function testACountrysCitiesLoadWhenItOpens(): void
    {
        $body = self::mode('whitelist_body');

        self::assertStringContainsString("fetch('addons/travel_core/components/destination_country_body.tpl')", $body);
        self::assertStringContainsString("echo json_encode(['html' => \$html]);", $body);
        self::assertStringContainsString('getCities($country)', $body, 'a country with no synced city is listed live from the API');
        self::assertStringContainsString("'body' => TypeCoerce::toString(fn_url('eurosite.whitelist_body'))", self::controller());
    }

    public function testTheSearchReturnsPickerHits(): void
    {
        $search = self::mode('search_destinations');

        self::assertStringContainsString("echo json_encode(['hits' => \$hits]);", $search);
        self::assertStringContainsString("'country' => \$cc", $search);
        self::assertStringContainsString("'item' => \$code", $search);
    }

    /** An empty whitelist stops every hotel sync; Save refuses it before touching the rows. */
    public function testSaveRefusesToSellNothing(): void
    {
        $save = self::mode('save_whitelist');

        self::assertStringContainsString('DestinationPicker::readPost($_POST)', $save);
        $guard = strpos($save, "__('travel_core.dest_none_sold')");
        self::assertIsInt($guard);
        self::assertLessThan(strpos($save, '$whitelist->replaceAll($rows)'), $guard);
        self::assertStringNotContainsString('?:products', $save, 'Save never touches products');
    }

    public function testDisablingIsLimitedToConfirmedOutsideProducts(): void
    {
        $disable = self::mode('disable_outside');

        self::assertStringContainsString('array_intersect($outside, $confirmed)', $disable);
        $repo = (string) file_get_contents(self::ADDON . '/src/Repository/DestinationWhitelistRepository.php');
        self::assertStringContainsString("UPDATE ?:products SET status = 'D' WHERE product_id IN (?n) AND status = 'A'", $repo);
        self::assertStringNotContainsString('DELETE FROM ?:products', $repo);
    }

    /** "Own cities" sells every own-offer city, including ones the own_cities sync adds later. */
    public function testOwnCitiesAreResolvedWhenRead(): void
    {
        $repo = (string) file_get_contents(self::ADDON . '/src/Repository/DestinationWhitelistRepository.php');

        self::assertStringContainsString("'own' => db_get_fields(\"SELECT city_code FROM ?:eurosite_cities WHERE country_code = ?s AND is_own = 'Y'\", \$countryCode)", $repo);
        $xml = (string) file_get_contents(self::ADDON . '/addon.xml');
        self::assertStringContainsString("`selection_type` ENUM('all','own','specific')", $xml);
    }
}
