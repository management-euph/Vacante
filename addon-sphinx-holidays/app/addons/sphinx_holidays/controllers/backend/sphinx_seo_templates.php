<?php
declare(strict_types=1);
/**
 * Sphinx Holidays — SEO Templates Admin Page
 *
 * The template strings applied to Sphinx hotel products when they are
 * created, or all at once with "Apply templates now". The page itself is
 * Travel Core's shared one (components/seo_templates_page.tpl); this
 * controller only supplies what is Sphinx's: the linked-hotel fetcher and
 * the placeholder builder.
 *
 * Modes:
 *   - manage     (GET):  the page
 *   - save       (POST): mode, "Apply" ticks, per-language templates
 *   - bulk_apply (POST): save, then re-apply to every linked Sphinx product
 *
 * Settings are stored under addons.sphinx_holidays.seo_* keys; the engine
 * (fn_travel_core_apply_seo_fields) reads them from the Registry.
 *
 * @package SphinxHolidays
 * @since   1.3.0
 */

use Tygh\Addons\SphinxHolidays\Helpers\SphinxProductFactory;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Placeholders for one sphinx_hotels row, as the product factory builds them.
 *
 * @param array<mixed> $hotel
 * @return array<string, mixed>
 */
function _sphinx_seo_placeholders_for(array $hotel): array
{
    $hotelMap = TypeCoerce::toStringMap($hotel);

    return SphinxProductFactory::buildPlaceholders($hotelMap, [
        'city'    => $hotelMap['destination_name'] ?? '',
        'country' => $hotelMap['country_name'] ?? '',
        'region'  => $hotelMap['region_name'] ?? '',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'save') {
        fn_travel_core_seo_page_save('sphinx_holidays', $_REQUEST);

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_seo_templates.manage'];
    }

    if ($mode === 'bulk_apply') {
        $hotelRepo = Container::getHotelRepository();

        return fn_travel_core_seo_page_bulk_apply(
            'sphinx_holidays',
            $_REQUEST,
            static fn (int $offset, int $batch): array => $hotelRepo->fetchLinkedBatchForSeo($offset, $batch),
            static fn (array $hotel): array => _sphinx_seo_placeholders_for($hotel),
            'sphinx_seo_templates.manage',
        );
    }
}

if ($mode === 'manage' || $mode === '') {
    // The preview uses the first linked hotel.
    $sampleRows = Container::getHotelRepository()->fetchLinkedBatchForSeo(0, 1);
    $sample = $sampleRows === [] ? null : _sphinx_seo_placeholders_for($sampleRows[0]);

    fn_travel_core_seo_page_assign('sphinx_holidays', $sample, [
        'save'  => 'sphinx_seo_templates.save',
        'apply' => 'sphinx_seo_templates.bulk_apply',
        'title' => TypeCoerce::toString(__('travel_core.seo_templates')),
    ]);
}
