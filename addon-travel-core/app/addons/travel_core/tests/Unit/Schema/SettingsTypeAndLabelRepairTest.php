<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * A setting that already exists follows its addon.xml when the repo changes
 * it: a new type (Eurosite's hotels_category_id went from a text field to a
 * category dropdown), or a label the repo reworded. An admin's own label is
 * never overwritten.
 */
final class SettingsTypeAndLabelRepairTest extends TestCase
{
    private static function migrator(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/src/Install/SettingsMigrator.php');
    }

    private static function method(string $name): string
    {
        $src = self::migrator();
        $start = (int) strpos($src, 'private static function ' . $name . '(');
        $end = strpos($src, "\n    }\n", $start);

        return substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
    }

    public function testAnExistingSettingGetsTheDeclaredType(): void
    {
        $src = self::migrator();
        $existing = substr($src, (int) strpos($src, 'if ($settings->isExists($name, $addon)) {'), 900);

        self::assertStringContainsString("self::repairType(\$sectionId, \$name, \$item['type'])", $existing);

        $repair = self::method('repairType');
        self::assertStringContainsString('self::TYPE_MAP[strtolower($declaredType)] ?? null', $repair);
        self::assertStringContainsString('if ($type === null) {', $repair, 'an unknown type is never guessed');
        self::assertStringContainsString(
            'UPDATE ?:settings_objects SET type = ?s WHERE section_id = ?i AND name = ?s AND type <> ?s',
            $repair,
            'this add-on\'s row only, and only when it differs; the value is not touched',
        );
    }

    public function testOnlyALabelTheRepoShippedIsReplaced(): void
    {
        $repair = self::method('repairDescriptions');

        self::assertStringContainsString(
            "!in_array(trim(\$existing), self::SUPERSEDED_LABELS[\$addon][\$name] ?? [], true)",
            $repair,
        );
        self::assertStringContainsString('already labelled', $repair);

        $src = self::migrator();
        self::assertStringContainsString("'hotels_category_id' => ['Hotels root category ID', 'ID categorie rădăcină hoteluri']", $src);
    }

    /** The label the store shows now is the one the .po ships. */
    public function testTheSupersededEurositeLabelIsNoLongerShipped(): void
    {
        $root = dirname(__DIR__, 7);
        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents($root . "/eurosite_addon/var/langs/{$lang}/addons/eurosite.po");
            self::assertStringNotContainsString('msgstr "Hotels root category ID"', $po);
            self::assertStringNotContainsString('msgstr "ID categorie rădăcină hoteluri"', $po);
        }
    }

    public function testTheCategoryListIsSharedByTheProviders(): void
    {
        $root = dirname(__DIR__, 7);

        self::assertFileExists(dirname(__DIR__, 3) . '/src/Helpers/CategoryOptions.php');
        self::assertStringContainsString(
            'return CategoryOptions::build();',
            (string) file_get_contents($root . '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Install/CategoryOptionsBuilder.php'),
        );
        self::assertStringContainsString(
            "function fn_settings_variants_addons_eurosite_hotels_category_id(): array\n{\n    return \\Tygh\\Addons\\TravelCore\\Helpers\\CategoryOptions::build();",
            (string) file_get_contents($root . '/eurosite_addon/app/addons/eurosite/func.php'),
        );
    }
}
