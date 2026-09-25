<?php
declare(strict_types=1);
/**
 * Novoton Holidays — SEO Templates Admin Page
 *
 * The template strings applied to Novoton hotel products when they are
 * created, or all at once with "Apply templates now". The page itself is
 * Travel Core's shared one (components/seo_templates_page.tpl); this
 * controller only supplies what is Novoton's: the linked-hotel fetcher and
 * the placeholder builder.
 *
 * Modes:
 *   - manage     (GET):  the page
 *   - save       (POST): mode, "Apply" ticks, per-language templates
 *   - bulk_apply (POST): save, then re-apply to every linked Novoton product
 *
 * Settings are stored under addons.novoton_holidays.seo_* keys; the engine
 * (fn_travel_core_apply_seo_fields) reads them from the Registry.
 *
 * @package NovotonHolidays
 * @since   3.4.0
 */

use Tygh\Addons\NovotonHolidays\Helpers\ProductFactory;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

/**
 * Placeholders for one novoton_hotels row. The name goes through the same
 * formatter as at product creation; the description is left out (a bulk run
 * doesn't call the API per hotel), and the engine then keeps the product's
 * own description.
 *
 * @param array<mixed> $hotel
 * @return array<string, mixed>
 */
function _novoton_seo_placeholders_for(array $hotel): array
{
    $hotelMap = TypeCoerce::toStringMap($hotel);
    $rawName = TypeCoerce::toString($hotelMap['hotel_name'] ?? '');
    $displayName = function_exists('fn_novoton_holidays_format_hotel_display_name')
        ? TypeCoerce::toString(fn_novoton_holidays_format_hotel_display_name($rawName))
        : $rawName;

    return ProductFactory::buildNovotonPlaceholders($hotelMap, $displayName);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'save') {
        fn_travel_core_seo_page_save('novoton_holidays', $_REQUEST);

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_seo_templates.manage'];
    }

    if ($mode === 'bulk_apply') {
        $hotelRepo = Container::getInstance()->hotelRepository();

        return fn_travel_core_seo_page_bulk_apply(
            'novoton_holidays',
            $_REQUEST,
            static fn (int $offset, int $batch): array => $hotelRepo->findLinkedForSeo($offset, $batch),
            static fn (array $hotel): array => _novoton_seo_placeholders_for($hotel),
            'novoton_seo_templates.manage',
        );
    }
}

if ($mode === 'manage' || $mode === '') {
    // The preview uses the first linked hotel.
    $sampleRows = Container::getInstance()->hotelRepository()->findLinkedForSeo(0, 1);
    $sample = $sampleRows === [] ? null : _novoton_seo_placeholders_for($sampleRows[0]);

    fn_travel_core_seo_page_assign('novoton_holidays', $sample, [
        'save'  => 'novoton_seo_templates.save',
        'apply' => 'novoton_seo_templates.bulk_apply',
        'title' => TypeCoerce::toString(__('travel_core.seo_templates')),
    ]);
}
