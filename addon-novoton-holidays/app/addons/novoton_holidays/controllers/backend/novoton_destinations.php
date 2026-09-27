<?php
declare(strict_types=1);
/**
 * Novoton Holidays — Destinations (the destination whitelist)
 *
 * What we sell from Novoton, per country: not sold / all resorts / only
 * selected resorts. It replaces the dashboard's "Excluded resorts" and the
 * "Selected countries" setting once saved; until then the page shows those
 * older settings as a whitelist, so the first Save changes nothing.
 *
 * Modes:
 *   - manage          (GET):  the page
 *   - save            (POST): replace the whitelist
 *   - disable_outside (POST): disable the live products outside it (confirmed
 *                             on the page; Save itself never touches products)
 *
 * @package NovotonHolidays
 */

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Repository\DestinationWhitelistRepository;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\NovotonHolidays\Services\DestinationsPage;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/** @return array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>, outside: list<array{product_id: int, hotel_name: string, country: string, resort: string}>} */
function _novoton_destinations_page(DestinationWhitelistRepository $repo): array
{
    return DestinationsPage::build(
        DestinationScope::current(),
        $repo->catalog(),
        Constants::COUNTRIES,
        array_values(ConfigProvider::getSettingCountries()),
        ConfigProvider::getExcludedResorts(),
        ConfigProvider::getHiddenResorts(),
        $repo->liveProducts(),
    );
}

$repo = new DestinationWhitelistRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    if ($mode === 'save') {
        $known = array_map(
            static fn (array $c): string => TypeCoerce::toString($c['country']),
            _novoton_destinations_page($repo)['countries'],
        );
        $posted = $_POST['destinations'] ?? [];
        $rows = DestinationsPage::rowsFromPost(is_array($posted) ? $posted : [], $known, ConfigProvider::getHiddenResorts());
        $sold = array_filter($rows, static fn (array $r): bool => $r['resort'] === '');

        // No country at all would read as "not configured", i.e. the older
        // settings — the opposite of what an empty choice means.
        if ($sold === []) {
            fn_set_notification('E', __('error'), __('novoton_holidays.dest_none_sold'));

            return [CONTROLLER_STATUS_REDIRECT, 'novoton_destinations.manage'];
        }

        try {
            $repo->replaceAll($rows, date('Y-m-d H:i:s'));
            DestinationScope::setCurrent(null);
            fn_set_notification('N', __('notice'), __('novoton_holidays.dest_saved', [
                '[countries]' => count($sold),
                '[resorts]' => count($rows) - count($sold),
            ]));
        } catch (\Throwable $e) {
            error_log('novoton_holidays: could not save the destination whitelist — ' . $e->getMessage());
            fn_set_notification('E', __('error'), __('novoton_holidays.dest_save_failed'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_destinations.manage'];
    }

    if ($mode === 'disable_outside') {
        // Only the products the admin confirmed AND still outside the saved
        // whitelist: a stale page never disables something now sold.
        $confirmed = array_map(static fn (mixed $id): int => TypeCoerce::toInt($id), is_array($_POST['product_ids'] ?? null) ? $_POST['product_ids'] : []);
        $outside = array_map(
            static fn (array $p): int => $p['product_id'],
            _novoton_destinations_page($repo)['outside'],
        );
        $ids = array_values(array_intersect($outside, $confirmed));

        $n = $repo->disableProducts($ids);
        fn_set_notification('N', __('notice'), __('novoton_holidays.dest_disabled', ['[n]' => $n]));

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_destinations.manage'];
    }

    return [CONTROLLER_STATUS_REDIRECT, 'novoton_destinations.manage'];
}

if ($mode === 'manage' || $mode === '') {
    /** @var \Smarty $view */
    $view = Tygh::$app['view'];
    $view->assign('novoton_destinations', _novoton_destinations_page($repo));
}
