<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Service\ProfileFieldsSql;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Hook handlers take defaults for every parameter: the arguments CS-Cart
 * passes differ between builds, and a missing one must not be a fatal error
 * on a checkout or a profile page.
 */

/**
 * Hook: get_profile_fields — before the field list is read.
 *
 * Works out the type and area the list is read for and, for a type-aware
 * read, drops CS-Cart's general "show" restriction from the query: a field a
 * type shows may have its general checkbox off. The context travels to the
 * post hook in the params.
 *
 * @param mixed $location
 * @param mixed $select
 * @param mixed $condition
 * @param mixed $params
 */
function fn_euph_profile_types_get_profile_fields($location = '', $select = '', &$condition = '', &$params = []): void
{
    if (!is_array($params) || Cast::flag($params['euph_skip'] ?? false)) {
        return;
    }
    $location = Cast::string($location);
    try {
        $context = fn_euph_profile_types_resolve_field_context($location, $params);
    } catch (\Throwable $e) {
        error_log('euph_profile_types: ' . $e->getMessage());

        return;
    }
    if ($context === null) {
        return;
    }
    $filterAll = true;
    if ($location !== 'ALL' && is_string($condition)) {
        [$condition, $filterAll] = ProfileFieldsSql::dropShowRestriction($condition);
    }
    $params['euph_context'] = $context->toParams() + ['filter_all' => $filterAll];
}

/**
 * Hook: get_profile_fields_post — after the field list is read (CS-Cart 4.9.1+).
 *
 * Applies the type's checkboxes, filters the vendor plans of the type and
 * draws the type choice.
 *
 * @param mixed $location
 * @param mixed $auth
 * @param mixed $lang_code
 * @param mixed $params
 * @param mixed $profile_fields
 * @param mixed $sections
 */
function fn_euph_profile_types_get_profile_fields_post(
    $location = '',
    $auth = [],
    $lang_code = '',
    $params = [],
    &$profile_fields = [],
    $sections = [],
): void {
    Registry::set('runtime.euph_profile_types.post_hook_ran', true);
    if (!is_array($params) || !is_array($profile_fields) || !is_array($params['euph_context'] ?? null)) {
        return;
    }
    try {
        $profile_fields = fn_euph_profile_types_apply_context($profile_fields, $params['euph_context']);
    } catch (\Throwable $e) {
        error_log('euph_profile_types: ' . $e->getMessage());
    }
}
