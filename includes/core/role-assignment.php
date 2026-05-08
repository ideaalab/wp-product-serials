<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assign configured roles to a user when they successfully register a serial.
 *
 * Hooks into the existing `ial_user_registered_product` action fired from the
 * frontend registration form. Reads the `assign_roles` meta from the product
 * and adds each role to the user (additive — existing roles are preserved).
 *
 * After role assignment, fires the public action `ial_user_product_registered`
 * for third-party extensions.
 */
add_action('ial_user_registered_product', 'ial_assign_roles_on_registration', 10, 3);
function ial_assign_roles_on_registration($serial_id, $user_id, $product_id)
{
    $user_id    = (int) $user_id;
    $product_id = (int) $product_id;

    if (!$user_id || !$product_id) {
        return;
    }

    $user = get_userdata($user_id);
    if (!$user) {
        return;
    }

    $roles_to_assign = get_post_meta($product_id, 'assign_roles', true);
    if (!is_array($roles_to_assign) || empty($roles_to_assign)) {
        $roles_to_assign = array();
    }

    if (!empty($roles_to_assign)) {
        $valid_role_keys = array_keys(wp_roles()->get_names());

        foreach ($roles_to_assign as $role_key) {
            $role_key = sanitize_key($role_key);
            if (!$role_key || !in_array($role_key, $valid_role_keys, true)) {
                continue;
            }
            // WP_User::add_role() is idempotent: no-op if the user already has it.
            $user->add_role($role_key);
        }
    }

    /**
     * Fires after a user has registered a serial of a product and any
     * configured roles have been assigned.
     *
     * @param int $user_id        ID of the user who completed the registration.
     * @param int $ial_product_id ID of the `ial_product` whose serial was registered.
     */
    do_action('ial_user_product_registered', $user_id, $product_id);
}
