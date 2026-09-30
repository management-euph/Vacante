<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The stay summary block (block type travel_stay_summary): placed at the top
 * of the checkout layout, it shows the booking card on phones, where the
 * order summary drops below the form. Pins its Block Manager registration
 * (a dedicated type, never the shared 'template' pool the core Search block
 * selects from), the template contract and the responsive swap in CSS.
 */
final class StaySummaryBlockTest extends TestCase
{
    /** Core schema shape: 'templates' is a directory-scan string. */
    private const CORE_TEMPLATE_TYPE = [
        'templates' => 'blocks/static_templates',
        'wrappers'  => 'blocks/wrappers',
    ];

    /** @return array<string, mixed> */
    private static function applySchema(): array
    {
        $schema = ['template' => self::CORE_TEMPLATE_TYPE];
        $result = (static function (array $schema) {
            return include dirname(__DIR__, 3) . '/schemas/block_manager/blocks.post.php';
        })($schema);
        self::assertIsArray($result);

        /** @var array<string, mixed> $result */
        return $result;
    }

    private static function design(string $rel): string
    {
        $path = dirname(__DIR__, 6) . '/design/themes/responsive/' . $rel;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testBlockTypeIsDedicatedAndResolvesToAShippedTemplate(): void
    {
        $schema = self::applySchema();

        self::assertSame(self::CORE_TEMPLATE_TYPE, $schema['template'] ?? null, 'the shared template pool must stay untouched');
        self::assertIsArray($schema['travel_stay_summary'] ?? null);
        $type = $schema['travel_stay_summary'];
        self::assertSame('blocks/wrappers', $type['wrappers'] ?? null);
        self::assertArrayNotHasKey('cache', $type, "the shopper's own cart: never cached");
        self::assertIsArray($type['templates'] ?? null);
        self::assertSame(['addons/travel_core/blocks/stay_summary.tpl'], array_keys($type['templates']));
        self::design('templates/addons/travel_core/blocks/stay_summary.tpl');
    }

    public function testBlockRendersTheSharedCardForEachTravelLine(): void
    {
        $tpl = (string) preg_replace('/\{\*.*?\*\}/s', '', self::design('templates/addons/travel_core/blocks/stay_summary.tpl'));

        self::assertStringContainsString('{$tss_products = $cart_products|default:$cart.products|default:[]}', $tpl);
        self::assertStringContainsString('{$tss = fn_travel_core_cart_booking_card($tss_product, $tss_key)}', $tpl);
        // One card, computed once and handed to the shared component.
        self::assertStringContainsString('tcc_card=$tss tcc_context="summary"', $tpl);
        self::assertStringContainsString('<details class="travel-stay-summary">', $tpl);
        self::assertStringNotContainsString('<script', $tpl);
        self::assertStringNotContainsString('style=', $tpl);
    }

    public function testPhonesSwapTheSidebarCardForTheBlock(): void
    {
        $css = self::design('css/addons/travel_core/booking-pages.css');

        self::assertMatchesRegularExpression('/@media \(min-width: 768px\) \{\s*\.travel-stay-summary \{ display: none; \}/', $css);
        self::assertMatchesRegularExpression('/@media \(max-width: 767px\) \{\s*body:has\(\.travel-stay-summary\) \.travel-ccard--sidebar \{ display: none; \}/', $css);
    }

    public function testBlockLangKeysAreSeeded(): void
    {
        $vars = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertIsArray($vars);
        foreach (['block_travel_stay_summary', 'travel_core.stay_details', 'travel_core.stay_hide'] as $key) {
            self::assertArrayHasKey($key, $vars, $key);
        }
    }

    public function testUninstallRemovesPlacedBlocks(): void
    {
        $func = (string) file_get_contents(dirname(__DIR__, 3) . '/func.php');
        $start = strpos($func, 'function fn_travel_core_uninstall(): bool');
        self::assertNotFalse($start);
        $body = substr($func, $start, (int) strpos($func, "\nfunction ", $start + 10) - $start);

        self::assertStringContainsString("WHERE type = 'travel_stay_summary'", $body);
        foreach (['bm_blocks_descriptions', 'bm_snapping', 'bm_blocks'] as $table) {
            self::assertStringContainsString('DELETE FROM ?:' . $table . ' WHERE block_id IN (?n)', $body, $table);
        }
        self::assertStringContainsString("name = 'block_travel_stay_summary'", $body);
    }
}
