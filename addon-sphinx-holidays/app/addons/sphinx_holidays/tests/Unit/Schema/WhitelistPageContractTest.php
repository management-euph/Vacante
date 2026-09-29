<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The Destination whitelist page: Travel Core's destination picker (no
 * inline script any more), a country's tree loaded on open, and a Save that
 * refuses to sell nothing, seeds the region mappings and never touches
 * products.
 */
final class WhitelistPageContractTest extends TestCase
{
    private static function tpl(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 6) . '/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/whitelist.tpl');
    }

    private static function controller(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/sphinx_holidays.php');
    }

    private static function mode(string $mode): string
    {
        $src = self::controller();
        $start = strpos($src, "if (\$mode === '{$mode}') {");
        self::assertIsInt($start, "mode {$mode} is missing");

        return substr($src, $start, 1800);
    }

    public function testThePageIsTheSharedPickerWithNoInlineScript(): void
    {
        $tpl = self::tpl();
        $include = strpos($tpl, '{include file="addons/travel_core/components/destination_picker.tpl" dest=$sphinx_destinations dest_top=$smarty.capture.sphinx_dest_top}');

        self::assertIsInt($include);
        self::assertLessThan(strrpos($tpl, '{/capture}'), $include);
        self::assertStringNotContainsString('<script', $tpl);
        self::assertStringContainsString('sphinx_holidays.no_destinations_synced', $tpl);
    }

    public function testACountrysTreeLoadsWhenItOpens(): void
    {
        $body = self::mode('whitelist_body');

        self::assertStringContainsString("fetch('addons/travel_core/components/destination_country_body.tpl')", $body);
        self::assertStringContainsString("echo json_encode(['html' => \$html]);", $body);
        self::assertStringNotContainsString("\$mode === 'get_destinations_tree'", self::controller(), 'the old page\'s tree endpoint');
        self::assertStringNotContainsString("\$mode === 'get_whitelist_children'", self::controller());
    }

    public function testSaveRefusesToSellNothingAndSeedsRegionMappings(): void
    {
        $save = self::mode('save_whitelist');

        self::assertStringContainsString('DestinationPicker::readPost($_POST)', $save);
        $guard = strpos($save, "__('travel_core.dest_none_sold')");
        self::assertIsInt($guard);
        self::assertLessThan(strpos($save, '->replaceAll($rows)'), $guard);
        self::assertStringContainsString('fn_sphinx_holidays_seed_region_mappings();', $save);
        self::assertStringNotContainsString('?:products', $save);
    }

    public function testDisablingIsLimitedToConfirmedOutsideProducts(): void
    {
        $disable = self::mode('disable_outside');

        self::assertStringContainsString('array_intersect($outside, DestinationPicker::productIds(', $disable);
        $repo = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Repository/WhitelistPickerRepository.php');
        self::assertStringContainsString("UPDATE ?:products SET status = 'D' WHERE product_id IN (?n) AND status = 'A'", $repo);
        self::assertStringNotContainsString('DELETE FROM ?:products', $repo);
    }

    /**
     * The init.php heal is stamp-gated and skips a failed ALTER quietly, so
     * the page cannot rely on it: a store opened it with "Unknown column
     * 'first_seen_at'". The controller applies the deltas before any mode.
     */
    public function testTheControllerAppliesTheSchemaBeforeReadingNewColumns(): void
    {
        $src = self::controller();
        $ensure = strpos($src, 'fn_sphinx_holidays_ensure_schema();');

        self::assertIsInt($ensure);
        self::assertLessThan(strpos($src, "if (\$mode === 'save_whitelist') {"), $ensure);
        self::assertStringContainsString("'first_seen_at' =>", (string) file_get_contents(dirname(__DIR__, 3) . '/src/Install/SchemaMigrator.php'));
    }
}
