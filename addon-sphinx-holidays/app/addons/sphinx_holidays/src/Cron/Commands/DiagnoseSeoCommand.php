<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Helpers\SphinxProductFactory;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\RegistryCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: diagnose SEO field population for a specific hotel/product.
 *
 * Audits the Registry templates, resolves placeholders, renders what SEO
 * fields would be written, and compares against what is stored in
 * product_descriptions. Safe to run at any time — does NOT modify data
 * unless &apply=Y is passed.
 *
 * Usage:
 *   cron_mode=diagnose_seo&hotel_id=99224
 *   cron_mode=diagnose_seo&hotel_id=99224&apply=Y   — also write meta fields to product
 */
class DiagnoseSeoCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['diagnose_seo'];
    }

    /** All 13 seo_* addon setting keys managed by fn_sphinx_holidays_seed_seo_defaults(). */
    private const array SEO_KEYS = [
        'seo_overwrite_mode',
        'seo_product_name',
        'seo_page_title',
        'seo_meta_description',
        'seo_meta_keywords',
        'seo_name_slug',
        'seo_full_description',
        'seo_field_product_name',
        'seo_field_page_title',
        'seo_field_meta_description',
        'seo_field_meta_keywords',
        'seo_field_name_slug',
        'seo_field_full_description',
    ];

    #[\Override]
    public static function getDescription(): string
    {
        return 'Diagnose SEO field population for a single hotel (read-only unless &apply=Y)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $hotelId = TypeCoerce::toString($params['hotel_id'] ?? '');
        $doApply = TypeCoerce::toString($params['apply'] ?? '') === 'Y';

        if ($hotelId === '') {
            $this->output('ERROR: &hotel_id=<id> is required. Example: &cron_mode=diagnose_seo&hotel_id=99224');
            return ['success' => false, 'error' => 'hotel_id required'];
        }

        $this->output("=== Diagnosing SEO for hotel [{$hotelId}] ===");
        $this->output('Mode: ' . ($doApply ? 'DIAGNOSE + APPLY' : 'DIAGNOSE ONLY (add &apply=Y to also write meta fields)'));

        // ── 1. Seed defaults + Registry audit ────────────────────────
        // Mirrors what sphinx_cron.php does before dispatching any cron mode.
        if (function_exists('fn_sphinx_holidays_seed_seo_defaults')) {
            fn_sphinx_holidays_seed_seo_defaults();
        }

        $this->output('');
        $this->output('--- 1. Registry state (after seeder) ---');

        $settings = RegistryCoerce::stringMap('addons.sphinx_holidays');

        foreach (self::SEO_KEYS as $key) {
            $val = array_key_exists($key, $settings) ? $settings[$key] : null;
            if ($val === null) {
                $status = 'MISSING';
            } elseif ($val === '') {
                $status = 'EMPTY';
            } else {
                $strVal = TypeCoerce::toString($val);
                $display = strlen($strVal) > 80 ? substr($strVal, 0, 80) . '…' : $strVal;
                $status = "OK ({$display})";
            }
            $this->output("  {$key}: {$status}");
        }

        // The SEO Templates page saves each language's own template
        // ("<key>__<lang>"); those win over the keys above.
        if (function_exists('fn_travel_core_seo_settings')) {
            $this->output('  Per-language templates (SEO Templates page; these win):');
            $perLang = 0;
            foreach (fn_travel_core_seo_settings('sphinx_holidays') as $key => $val) {
                if (str_contains($key, '__') && str_starts_with($key, 'seo_')) {
                    $strVal = TypeCoerce::toString($val);
                    $this->output("    {$key}: " . ($strVal === '' ? 'EMPTY' : (strlen($strVal) > 80 ? substr($strVal, 0, 80) . '…' : $strVal)));
                    $perLang++;
                }
            }
            if ($perLang === 0) {
                $this->output('    (none saved)');
            }
        }

        // ── 2. DB hotel record ────────────────────────────────────────
        $this->output('');
        $this->output('--- 2. Hotel DB record ---');

        $hotel = Container::getHotelRepository()->findById($hotelId);
        if ($hotel === null) {
            $this->output("ERROR: Hotel [{$hotelId}] not found in sphinx_hotels table.");
            return ['success' => false, 'error' => 'hotel not found'];
        }

        $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
        $this->output('  name           = ' . TypeCoerce::toString($hotel['name'] ?? ''));
        $this->output('  product_id     = ' . $productId . ($productId === 0 ? '  [WARNING: not linked — run cron_mode=add_products first]' : ''));
        $this->output('  country        = ' . TypeCoerce::toString($hotel['country_name'] ?? ''));
        $this->output('  destination    = ' . TypeCoerce::toString($hotel['destination_name'] ?? ''));
        $this->output('  classification = ' . TypeCoerce::toString($hotel['classification'] ?? ''));
        $this->output('  property_type  = ' . TypeCoerce::toString($hotel['property_type'] ?? ''));
        $facilitiesJson = TypeCoerce::toString($hotel['facilities_json'] ?? '');
        $this->output('  facilities_json= ' . (strlen($facilitiesJson) > 120 ? substr($facilitiesJson, 0, 120) . '…' : ($facilitiesJson ?: '(empty)')));

        // ── 3. Placeholder resolution ─────────────────────────────────
        $this->output('');
        $this->output('--- 3. Placeholder values ---');

        $placeholders = SphinxProductFactory::buildPlaceholders($hotel);
        foreach ($placeholders as $key => $val) {
            if (is_array($val)) {
                $display = implode(', ', array_slice($val, 0, 5));
                if (count($val) > 5) {
                    $display .= ' … (' . count($val) . ' total)';
                }
            } else {
                $display = $val;
            }
            $flag = ($display === '') ? '  [EMPTY — may cause blank template output]' : '';
            $this->output('  {{' . $key . '}} = ' . ($display ?: '(empty)') . $flag);
        }

        // ── 4. SEO field rendering ────────────────────────────────────
        $this->output('');
        $this->output('--- 4. SEO rendering, per product language (as a re-apply would write it) ---');

        $languages = ConfigProvider::getProductLanguages();
        if ($languages === []) {
            $languages = [defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en'];
        }

        /** @var array<string, array<string, mixed>> $renderedByLang */
        $renderedByLang = [];
        if (function_exists('fn_travel_core_apply_seo_fields')) {
            foreach ($languages as $lang) {
                $renderedByLang[$lang] = fn_travel_core_apply_seo_fields('sphinx_holidays', $placeholders, $productId, $hotelId, $lang);
            }
        } else {
            $this->output('  WARNING: fn_travel_core_apply_seo_fields() not found — travel_core may not be active.');
        }
        /** @var array<string, mixed> $rendered the first (primary) language's */
        $rendered = $renderedByLang === [] ? [] : reset($renderedByLang);

        $fieldLabels = [
            'product' => 'product (name)',
            'page_title' => 'page_title',
            'meta_description' => 'meta_description',
            'meta_keywords' => 'meta_keywords',
            'seo_name' => 'seo_name (URL slug)',
            'full_description' => 'full_description',
        ];

        foreach ($renderedByLang as $lang => $langRendered) {
            $this->output("  [{$lang}]");
            foreach ($fieldLabels as $renderKey => $label) {
                if (!array_key_exists($renderKey, $langRendered)) {
                    $this->output("  {$label}: SKIPPED (toggle off or template empty)");
                    continue;
                }
                $renderVal = TypeCoerce::toString($langRendered[$renderKey] ?? '');
                if ($renderVal === '') {
                    $this->output("  {$label}: EMPTY");
                } else {
                    $display = strlen($renderVal) > 100 ? substr($renderVal, 0, 100) . '…' : $renderVal;
                    $this->output("  {$label}: OK — {$display}");
                }
            }
        }

        // ── 5. Current product_descriptions ──────────────────────────
        $this->output('');
        $this->output('--- 5. Current product_descriptions ---');

        if ($productId === 0) {
            $this->output('  (skipped — hotel has no linked product)');
        } else {
            /** @var mixed $rawRows */
            $rawRows = db_get_array(
                'SELECT lang_code, product, page_title, meta_description, meta_keywords FROM ?:product_descriptions WHERE product_id = ?i ORDER BY lang_code',
                $productId,
            );
            $rows = is_array($rawRows) ? $rawRows : [];

            if (empty($rows)) {
                $this->output("  No rows found in product_descriptions for product #{$productId}");
            } else {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $lang = TypeCoerce::toString($row['lang_code'] ?? '');
                    $cols = [
                        'product' => TypeCoerce::toString($row['product'] ?? ''),
                        'page_title' => TypeCoerce::toString($row['page_title'] ?? ''),
                        'meta_description' => TypeCoerce::toString($row['meta_description'] ?? ''),
                        'meta_keywords' => TypeCoerce::toString($row['meta_keywords'] ?? ''),
                        // The address lives in ?:seo_names, not here.
                        'seo_name' => TypeCoerce::toString(db_get_field(
                            "SELECT name FROM ?:seo_names WHERE object_id = ?i AND type = 'p' AND lang_code = ?s",
                            $productId,
                            $lang,
                        )),
                    ];
                    $parts = [];
                    foreach ($cols as $col => $colVal) {
                        $preview = $colVal !== '' ? (strlen($colVal) > 50 ? substr($colVal, 0, 50) . '…' : $colVal) : '(empty)';
                        $parts[] = "{$col}={$preview}";
                    }
                    $this->output("  lang={$lang}: " . implode(', ', $parts));
                }
            }
        }

        // ── 6. Diagnosis summary ──────────────────────────────────────
        $this->output('');
        $hasTemplates = isset($settings['seo_page_title']) && TypeCoerce::toString($settings['seo_page_title']) !== '';
        $hasRendered = !empty($rendered['page_title']) || !empty($rendered['meta_description']);

        if (!$hasTemplates) {
            $this->output('=== DIAGNOSIS: seo_page_title template missing/empty in Registry — SEO meta fields will not be written. ===');
        } elseif (!$hasRendered) {
            $this->output('=== DIAGNOSIS: Templates present but rendering produced empty page_title / meta_description. Check placeholder values above. ===');
        } else {
            $suffix = ($productId > 0) ? ' See DB values above to confirm what is stored.' : '';
            $this->output("=== DIAGNOSIS: Templates configured and rendering OK.{$suffix} ===");
            if ($productId > 0 && !$doApply) {
                $this->output('    To write meta fields to all languages of product #' . $productId . ', add &apply=Y');
            }
        }

        // ── 7. Apply ─────────────────────────────────────────────────
        if ($doApply) {
            $this->output('');
            $this->output('--- Applying SEO fields ---');

            if ($productId === 0) {
                $this->output('SKIPPED — hotel has no linked product.');
            } elseif (empty($rendered)) {
                $this->output('Nothing to write — rendering produced no fields.');
            } else {
                $pageTitle = TypeCoerce::toString($rendered['page_title'] ?? '');
                $metaDesc = TypeCoerce::toString($rendered['meta_description'] ?? '');
                $metaKw = TypeCoerce::toString($rendered['meta_keywords'] ?? '');
                $written = [];

                if ($pageTitle !== '') {
                    db_query('UPDATE ?:product_descriptions SET page_title = ?s WHERE product_id = ?i', $pageTitle, $productId);
                    $written[] = 'page_title';
                }
                if ($metaDesc !== '') {
                    db_query('UPDATE ?:product_descriptions SET meta_description = ?s WHERE product_id = ?i', $metaDesc, $productId);
                    $written[] = 'meta_description';
                }
                if ($metaKw !== '') {
                    db_query('UPDATE ?:product_descriptions SET meta_keywords = ?s WHERE product_id = ?i', $metaKw, $productId);
                    $written[] = 'meta_keywords';
                }

                if (empty($written)) {
                    $this->output('Nothing to write — all meta fields rendered empty.');
                } else {
                    $this->output('Updated product_descriptions (all languages, product #' . $productId . '): ' . implode(', ', $written));
                }

                if (array_key_exists('seo_name', $rendered) && TypeCoerce::toString($rendered['seo_name'] ?? '') !== '') {
                    $this->output('NOTE: seo_name requires SEO addon handling — not applied here. Use Bulk Apply in admin.');
                }
            }
        }

        return [
            'success' => true,
            'hotel_id' => $hotelId,
            'product_id' => $productId,
            'templates_ok' => $hasTemplates,
            'rendering_ok' => $hasRendered,
        ];
    }
}
