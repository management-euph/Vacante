<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Container;
use Tygh\Addons\EuphProfileTypes\Db\CsCartDatabase;
use Tygh\Addons\EuphProfileTypes\Settings;
use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * The add-on's services, built once per request from the add-on settings.
 */
function fn_euph_profile_types_container(bool $reset = false): Container
{
    static $container;
    if ($reset || !$container instanceof Container) {
        $container = new Container(
            new CsCartDatabase(Cast::string(Registry::get('config.table_prefix'))),
            Settings::fromArray(Registry::get('addons.euph_profile_types')),
            fn_allowed_for('MULTIVENDOR') === true,
        );
    }

    return $container;
}

/** A service of the CS-Cart application container, or null. */
function fn_euph_profile_types_app(string $service): mixed
{
    $app = Tygh::$app;
    if ($app instanceof \ArrayAccess) {
        return $app->offsetExists($service) ? $app->offsetGet($service) : null;
    }

    return $app[$service] ?? null;
}

/** The language of the page. */
function fn_euph_profile_types_lang(): string
{
    return defined('CART_LANGUAGE') ? Cast::string(constant('CART_LANGUAGE'), 'en') : 'en';
}

/** The language the admin panel edits content in. */
function fn_euph_profile_types_descr_lang(): string
{
    return defined('DESCR_SL') ? Cast::string(constant('DESCR_SL'), 'en') : fn_euph_profile_types_lang();
}

/**
 * @return list<string>
 */
function fn_euph_profile_types_lang_codes(): array
{
    $codes = function_exists('fn_get_translation_languages') ? array_keys(Cast::array(fn_get_translation_languages())) : [];
    $codes = array_values(array_filter(array_map(static fn (mixed $code): string => Cast::string($code), $codes), static fn (string $code): bool => $code !== ''));

    return $codes !== [] ? $codes : [fn_euph_profile_types_lang()];
}

function fn_euph_profile_types_is_storefront(): bool
{
    return defined('AREA') && constant('AREA') === 'C';
}

/**
 * The storefront of the request (0 in the admin panel, or when the store
 * has no storefront service).
 */
function fn_euph_profile_types_storefront_id(): int
{
    if (!fn_euph_profile_types_is_storefront()) {
        return 0;
    }
    $storefront = fn_euph_profile_types_app('storefront');

    return is_object($storefront) ? Cast::int(fn_euph_profile_types_object_property($storefront, 'storefront_id')) : 0;
}

/**
 * Reads a property of a CS-Cart object, public or served by __get.
 */
function fn_euph_profile_types_object_property(object $object, string $property): mixed
{
    $public = get_object_vars($object);
    if (array_key_exists($property, $public)) {
        return $public[$property];
    }
    if ($object instanceof \ArrayAccess && $object->offsetExists($property)) {
        return $object->offsetGet($property);
    }
    if (method_exists($object, '__get')) {
        return $object->__get($property);
    }

    return null;
}

/**
 * Every storefront of the store, for the "works on" checkboxes of a type.
 *
 * @return array<int, string> storefront id => name
 */
function fn_euph_profile_types_get_storefronts(): array
{
    $db = fn_euph_profile_types_container()->db();
    if (!$db->tableExists('?:storefronts')) {
        return [];
    }
    $storefronts = [];
    foreach ($db->rows('SELECT * FROM ?:storefronts ORDER BY storefront_id ASC') as $row) {
        $id = Cast::int($row['storefront_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = trim(Cast::string($row['name'] ?? ''));
        $storefronts[$id] = $name !== '' ? $name : (trim(Cast::string($row['url'] ?? '')) ?: '#' . $id);
    }

    return $storefronts;
}

/* ── Session ───────────────────────────────────────────────────────────── */

/**
 * @return array<string, mixed>
 */
function fn_euph_profile_types_session_get(string $key): array
{
    $session = fn_euph_profile_types_app('session');
    if ($session instanceof \ArrayAccess) {
        return Cast::stringMap($session->offsetExists($key) ? $session->offsetGet($key) : []);
    }

    return [];
}

function fn_euph_profile_types_session_value(string $key): mixed
{
    $session = fn_euph_profile_types_app('session');

    return $session instanceof \ArrayAccess && $session->offsetExists($key) ? $session->offsetGet($key) : null;
}

function fn_euph_profile_types_session_put(string $key, mixed $value): void
{
    $session = fn_euph_profile_types_app('session');
    if ($session instanceof \ArrayAccess) {
        $session->offsetSet($key, $value);
    }
}

/**
 * @return array<string, mixed>
 */
function fn_euph_profile_types_auth(): array
{
    return fn_euph_profile_types_session_get('auth');
}

function fn_euph_profile_types_auth_user_id(): int
{
    return Cast::int(fn_euph_profile_types_auth()['user_id'] ?? 0);
}

/* ── View ──────────────────────────────────────────────────────────────── */

function fn_euph_profile_types_assign(string $name, mixed $value): void
{
    $view = fn_euph_profile_types_app('view');
    if (is_object($view) && method_exists($view, 'assign')) {
        $view->assign($name, $value);
    }
}

function fn_euph_profile_types_template_var(string $name): mixed
{
    $view = fn_euph_profile_types_app('view');
    if (is_object($view) && method_exists($view, 'getTemplateVars')) {
        return $view->getTemplateVars($name);
    }

    return null;
}

/**
 * A language variable, or the fallback when the language pack has none.
 *
 * @param array<string, string> $params
 */
function fn_euph_profile_types_text(string $name, string $fallback, array $params = [], string $langCode = ''): string
{
    $text = Cast::string($langCode !== '' ? __($name, $params, $langCode) : __($name, $params));

    return $text === '' || $text === $name || $text === '_' . $name ? strtr($fallback, $params) : $text;
}

function fn_euph_profile_types_notify(string $type, string $message): void
{
    if (function_exists('fn_set_notification')) {
        $title = match ($type) {
            'E' => __('error'),
            'W' => __('warning'),
            default => __('notice'),
        };
        fn_set_notification($type, Cast::string($title), $message);
    }
}

/**
 * The request parameter, normalized as a type code ('' when absent).
 */
function fn_euph_profile_types_requested_code(string $key = 'euph_profile_type'): string
{
    return \Tygh\Addons\EuphProfileTypes\Service\CodeValidator::normalize(Cast::string($_REQUEST[$key] ?? ''));
}
