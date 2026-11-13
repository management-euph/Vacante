<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\Services\CircuitFeatures;
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
     * Brings the descriptions of the circuits' products up to date with what
     * Sphinx says now: the short description is the summary, the full one the
     * summary then the description (fullDescription()), in every language the
     * product was made in. Only the two description fields: name, price,
     * category and images are not touched. A circuit whose summary and
     * description are both empty keeps its product's text (a bad answer must
     * not blank a page), and a product already up to date is not written.
     *
     * @param list<array<string, mixed>> $circuits normalized rows (circuit_id, summary, description)
     * @param list<string> $languages the languages the products have descriptions in
     *
     * @return int how many products' descriptions changed
     */
    public function refreshDescriptions(array $circuits, array $languages): int
    {
        if ($circuits === [] || $languages === []) {
            return 0;
        }
        $productIds = $this->circuitRepo->productIds(array_map(
            static fn (array $circuit): int => TypeCoerce::toInt($circuit['circuit_id'] ?? 0),
            $circuits,
        ));
        $changed = 0;
        foreach ($circuits as $circuit) {
            $productId = $productIds[TypeCoerce::toInt($circuit['circuit_id'] ?? 0)] ?? 0;
            $summary = TypeCoerce::toString($circuit['summary'] ?? '');
            $description = TypeCoerce::toString($circuit['description'] ?? '');
            if ($productId <= 0 || (trim($summary) === '' && trim($description) === '')) {
                continue;
            }
            $full = self::fullDescription($summary, $description);
            $affected = TypeCoerce::toInt(db_query(
                'UPDATE ?:product_descriptions SET short_description = ?s, full_description = ?s'
                . ' WHERE product_id = ?i AND lang_code IN (?a)'
                . ' AND NOT (short_description <=> ?s AND full_description <=> ?s)',
                $summary,
                $full,
                $productId,
                $languages,
                $summary,
                $full,
            ));
            if ($affected > 0) {
                $changed++;
            }
        }

        return $changed;
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
        // Its features, as mapped in Feature Mappings › Circuits.
        (new CircuitFeatures($this->circuitRepo))->applyTo($circuitId);

        return ['status' => 'added', 'product_id' => $productId, 'reason' => ''];
    }
}
