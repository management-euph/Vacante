<?php

namespace Tygh\UpgradeCenter\Connectors\CpProfileTypes;

use Tygh\Addons\SchemesManager;
use Tygh\Http;
use Tygh\Registry;
use Tygh\Settings;
use Tygh\Tools\Url;
use Tygh\UpgradeCenter\Connectors\BaseAddonConnector;
use Tygh\UpgradeCenter\Connectors\IConnector;

class Connector extends BaseAddonConnector implements IConnector
{
    const ACTION_PARAM = 'action';
    const ACTION_CHECK_UPDATES = 'check';
    const ACTION_DOWNLOAD_PACKAGE = 'download';
    
    protected $addon_id = 'cp_profile_types';
    protected $addon_version;
    protected $product_url;

    public function __construct()
    {
        parent::__construct();

        $this->updates_server = 'https://store.cart-power.ru/addon_updates.php';

        $addon = SchemesManager::getScheme($this->addon_id);

        $this->addon_version = $addon->getVersion() ? $addon->getVersion() : '1.0';
        $this->license_number = (string) Settings::instance()->getValue('licensekey', $this->addon_id);

        $this->product_name = 'Cart-Power';
        $this->product_version = PRODUCT_VERSION;
        $this->product_build = PRODUCT_BUILD;
        $this->product_edition = PRODUCT_EDITION;
        $this->lang_code = CART_LANGUAGE;
        $this->main_domain = Registry::get('config.current_location');

        if (version_compare(PRODUCT_VERSION, '4.10.1', '>=')) {
            $opened_domains = db_get_fields('SELECT url FROM ?:storefronts WHERE status = ?s', 'N');
        } else {
            if (fn_allowed_for('ULTIMATE')) {
                $opened_domains = array();
                $domains = db_get_array('SELECT company_id, storefront FROM ?:companies');
                foreach ($domains as $domain) {
                    $closed = Settings::instance()->getValue('store_mode', 'General', $domain['company_id']);
                    if ($closed != 'Y') {
                        $opened_domains[] = $domain['storefront'];
                    }
                }
            } else {
                $storefront = Registry::get('runtime.company_data.storefront');
                if (!empty($storefront)) {
                    $opened_domains[] = $storefront;
                }
            }
        }
        $this->domains = !empty($opened_domains) ? implode(',', $opened_domains) : '';
    }

    public function getConnectionData()
    {
        $data = array(
            self::ACTION_PARAM => self::ACTION_CHECK_UPDATES,
            'addon_id'         => $this->addon_id,
            'addon_version'    => $this->addon_version,
            'license_number'   => $this->license_number,
            'product_version'  => $this->product_version,
            'product_edition'  => $this->product_edition,
            'product_build'    => $this->product_build,
            'main_domain'      => $this->main_domain,
            'domains'          => $this->domains,
            'lang_code'        => $this->lang_code,
            'test'             => defined('CP_AM_TEST') ? CP_AM_TEST : false
        );

        return array(
            'method'  => 'get',
            'url'     => $this->updates_server,
            'data'    => $data,
            'headers' => array()
        );
    }

    public function downloadPackage($schema, $package_path)
    {
        $download_url = new Url($this->updates_server);

        $download_url->setQueryParams(array_merge($download_url->getQueryParams(), array(
            self::ACTION_PARAM => self::ACTION_DOWNLOAD_PACKAGE,
            'package_id'       => $schema['package_id'],
            'addon_id'         => $this->addon_id,
            'addon_version'    => $this->addon_version,
            'license_number'   => $this->license_number,
            'product_version'  => $this->product_version,
            'product_edition'  => $this->product_edition,
            'product_build'    => $this->product_build,
            'main_domain'      => $this->main_domain,
            'domains'          => $this->domains,
            'test'             => defined('CP_AM_TEST') ? CP_AM_TEST : false
        )));

        $download_url = $download_url->build();

        $request_result = Http::get($download_url, array(), array(
            'write_to_file' => $package_path,
        ));

        if (!$request_result || strlen($error = Http::getError())) {
            $download_result = array(false, __('text_uc_cant_download_package'));
            fn_rm($package_path);
        } else {
            $download_result = array(true, '');
        }

        return $download_result;
    }
}
