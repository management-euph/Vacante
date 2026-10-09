<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the Romanian terms-label word order to the English source: "Payment
 * and cancellation" → "Condiții de Plată și Anulare". The old reversed
 * "Anulare și Plată" must not reappear in the novoton templates or the RO
 * translation catalogue (search cards, product hooks, perk line).
 */
final class TermsLabelOrderTest extends TestCase
{
    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * The customer order page's booking block is travel_core's shared card
     * now (hooks/orders/product_info.post.tpl, "order" form) for every
     * provider; novoton no longer ships its own text block there, so its
     * terms label lives in the catalogue only (below).
     */
    public function testOrderPageBlockIsTheSharedCard(): void
    {
        foreach (['responsive', 'nova_theme'] as $theme) {
            $novoton = dirname(self::addonRoot(), 3)
                . "/design/themes/{$theme}/templates/addons/novoton_holidays/hooks/orders/product_info.post.tpl";
            $shared = dirname(self::addonRoot(), 4)
                . "/addon-travel-core/design/themes/{$theme}/templates/addons/travel_core/hooks/orders/product_info.post.tpl";

            self::assertFileDoesNotExist($novoton, $theme);
            self::assertFileExists($shared, $theme);
        }
    }

    public function testRomanianCatalogueUsesPaymentFirstOrder(): void
    {
        $po = dirname(self::addonRoot(), 3) . '/var/langs/ro/addons/novoton_holidays.po';
        self::assertFileExists($po);
        $src = (string) file_get_contents($po);

        self::assertStringContainsString('Condiții de Plată și Anulare', $src);
        self::assertStringContainsString('Se aplică Condiții de Plată și Anulare', $src);
        self::assertStringNotContainsString('Condiții de Anulare și Plată', $src);
    }
}
