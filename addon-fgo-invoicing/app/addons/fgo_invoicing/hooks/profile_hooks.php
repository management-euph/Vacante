<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Every hook in this file gives its parameters defaults past the first — see
 * the note in hooks/order_hooks.php. fn_set_hook() dispatch is invisible to
 * static analysis, the argument count differs between CS-Cart builds, and the
 * penalty for guessing wrong is a fatal ArgumentCountError on a customer-facing
 * page (checkout, profile save), not a warning.
 */

/**
 * Hook: profile_fields_get_fields — expose FGO billing-extra columns to the
 * standard CS-Cart profile/checkout grid.
 *
 * @param array<string, mixed> $params
 * @param array<string, mixed> $fields
 */
function fn_fgo_invoicing_profile_fields_get_fields(&$params = [], &$fields = []): void
{
    // Stub: real wiring happens once we add localized labels via .po.
    // The columns are added to user_profiles by the install function;
    // they are saved/loaded via fn_fgo_invoicing_save_billing_extras /
    // fn_fgo_invoicing_get_billing_extras and surfaced in templates by the
    // hook profiles/profile_fields.post.tpl. For v1 we keep this hook as a
    // forward extension point — we do NOT inject synthetic field rows into
    // CS-Cart's profile_fields table.
}

/**
 * Hook: update_profile_fields_post — persist the FGO billing-extras posted
 * with the profile/checkout form.
 *
 * @param int $user_id
 * @param array<string, mixed> $user_data
 * @param string $action
 * @param int $profile_id
 */
function fn_fgo_invoicing_update_profile_fields_post(
    &$user_id,
    &$user_data = [],
    &$action = '',
    &$profile_id = 0,
): void
{
    $pid = \Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce::toInt($profile_id);
    if ($pid <= 0) {
        return;
    }
    fn_fgo_invoicing_save_billing_extras($pid, $user_data);
}
