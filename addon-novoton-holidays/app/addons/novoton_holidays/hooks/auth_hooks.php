<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Auth Hook Functions
 *
 * Responsible for:
 *   - user_login_post: Link session bookings to user account on login
 *   - create_user_post: the same on registration
 *
 * When a guest creates a booking and later logs in (or registers) in the same
 * browser session, these hooks associate the booking rows with the account.
 * Never by e-mail: CS-Cart does not verify the address an account is opened
 * with, so anyone could register a guest's address and read their bookings
 * (names, birth dates, contact details). Sphinx and Eurosite do the same.
 *
 * @package NovotonHolidays
 * @since   3.0.0
 */

use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Hook: After user login - link session bookings to user account.
 *
 * Links the bookings made in this browser session (see the file header for
 * why not by e-mail).
 *
 * @param array<string, mixed> $user_data User profile data
 * @param array<string, mixed> $auth      Authentication context
 */
function fn_novoton_holidays_user_login_post($user_data, $auth): void
{
    if (empty($auth['user_id'])) {
        return;
    }

    $user_id    = TypeCoerce::toInt($auth['user_id']);
    $session_id = session_id();
    $repo       = Container::getInstance()->bookingRepository();

    // Link by session
    if (!empty($session_id)) {
        $updated = $repo->linkToUserBySession($user_id, $session_id);

        if ($updated > 0) {
            fn_log_event('general', 'runtime', [
                'message'         => 'Novoton: Linked session bookings to user on login',
                'user_id'         => $user_id,
                'session_id'      => $session_id,
                'bookings_linked' => $updated,
            ]);
        }
    }
}

/**
 * Hook: After user registration - link this session's bookings.
 *
 * CS-Cart passes ($user_id, $user_data, $auth); only the id is needed.
 *
 * @param int $user_id Newly created user ID
 */
function fn_novoton_holidays_create_user_post($user_id): void
{
    $session_id = session_id();
    if (empty($user_id) || empty($session_id)) {
        return;
    }

    $updated = Container::getInstance()->bookingRepository()->linkToUserBySession((int)$user_id, $session_id);
    if ($updated > 0) {
        fn_log_event('general', 'runtime', [
            'message'         => 'Novoton: Linked session bookings to new user account',
            'user_id'         => (int)$user_id,
            'bookings_linked' => $updated,
        ]);
    }
}
