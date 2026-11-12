<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Creates CS-Cart products from synced Sphinx circuits so the circuit
 * booking flow has a cart target — without a product,
 * circuit_add_to_cart dead-ends on "product not found".
 *
 * Deliberately leaner than SphinxProductFactory (hotels): circuits need no
 * country validation, no dedup tiers and no feature assignment. Product
 * codes use the dedicated SPXC prefix so they can never collide with the
 * hotels' `<country-code><hotel_id>` scheme and stay recognizable in the
 * catalog.
 */
class CircuitProductFactory
{
    public const string PRODUCT_CODE_PREFIX = 'SPXC';

    public function __construct(private readonly CircuitRepository $circuitRepo)
    {
    }

    /**
     * The product's Full description: the API's summary, then its
     * description, each a paragraph. Both are plain text (line breaks,
     * quotes), so they are escaped and the breaks kept; a part the API leaves
     * empty is left out.
     */
    public static function fullDescription(string $summary, string $description): string
    {
        $paragraphs = [];
        foreach ([$summary, $description] as $text) {
            $text = trim($text);
            if ($text !== '') {
                $paragraphs[] = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
            }
        }

        return implode("\n", $paragraphs);
    }

    /**
     * @param array<string, mixed> $circuit Row from sphinx_circuits
     * @return array{status: string, product_id: int, reason: string}
     */
    public function createFromCircuit(array $circuit): array
    {
        $circuitId = TypeCoerce::toInt($circuit['circuit_id'] ?? 0);
        if ($circuitId <= 0) {
            return ['status' => 'skipped', 'product_id' => 0, 'reason' => 'missing circuit_id'];
        }

        $productCode = self::PRODUCT_CODE_PREFIX . $circuitId;

        // Re-run after partial failure: adopt an existing product by code.
        $existingProductId = TypeCoerce::toInt(db_get_field(
            'SELECT product_id FROM ?:products WHERE product_code = ?s',
            $productCode,
        ));
        if ($existingProductId > 0) {
            $this->circuitRepo->linkToProduct($circuitId, $existingProductId);

            return ['status' => 'linked', 'product_id' => $existingProductId, 'reason' => 'existing'];
        }

        $categoryId = ConfigProvider::getCircuitsCategoryId();
        if ($categoryId <= 0) {
            return ['status' => 'skipped', 'product_id' => 0, 'reason' => 'circuits_category_id not configured'];
        }

        $name = trim(TypeCoerce::toString($circuit['name'] ?? ''));
        if ($name === '') {
            return ['status' => 'skipped', 'product_id' => 0, 'reason' => 'unnamed circuit'];
        }

        $productData = [
            'product' => $name,
            'product_code' => $productCode,
            // Catalog price = the synced from-price; the real charge is the
            // live quote committed by circuit_add_to_cart (stored_price).
            'price' => TypeCoerce::toFloat($circuit['min_price'] ?? 0),
            'amount' => ConfigProvider::getDefaultProductQuantity(),
            'status' => 'A',
            'company_id' => ConfigProvider::getCompanyId(),
            'main_category' => $categoryId,
            'category_ids' => [$categoryId],
            'short_description' => TypeCoerce::toString($circuit['summary'] ?? ''),
            'full_description' => self::fullDescription(
                TypeCoerce::toString($circuit['summary'] ?? ''),
                TypeCoerce::toString($circuit['description'] ?? ''),
            ),
        ];

        $configuredLanguages = ConfigProvider::getProductLanguages();
        $primaryLang = TypeCoerce::toString(!empty($configuredLanguages) ? reset($configuredLanguages) : CART_LANGUAGE);

        $productId = TypeCoerce::toInt(fn_update_product($productData, 0, $primaryLang));
        if ($productId === 0) {
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx: fn_update_product() returned 0 — circuit product not created',
                'circuit_id' => $circuitId,
                'product_code' => $productCode,
            ]);

            return ['status' => 'failed', 'product_id' => 0, 'reason' => 'product creation'];
        }

        // Replicate the description to the other configured storefront
        // languages (same approach as the hotel factory).
        foreach (array_diff($configuredLanguages, [$primaryLang]) as $lc) {
            db_query(
                'INSERT INTO ?:product_descriptions (product_id, lang_code, product, full_description, short_description)
                 VALUES (?i, ?s, ?s, ?s, ?s)
                 ON DUPLICATE KEY UPDATE product = ?s, full_description = ?s, short_description = ?s',
                $productId,
                $lc,
                $name,
                $productData['full_description'],
                $productData['short_description'],
                $name,
                $productData['full_description'],
                $productData['short_description'],
            );
        }

        $this->circuitRepo->linkToProduct($circuitId, $productId);

        return ['status' => 'added', 'product_id' => $productId, 'reason' => ''];
    }
}
