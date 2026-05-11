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

/**
 * Count serials of a product, broken down by registration state.
 * Returns array('registered' => N, 'unregistered' => M, 'total' => N+M).
 */
function ial_count_product_serials_by_state($product_id)
{
    global $wpdb;
    $product_id = (int) $product_id;
    $out = array('registered' => 0, 'unregistered' => 0, 'total' => 0);
    if (!$product_id) {
        return $out;
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT (CASE WHEN sm_uid.meta_value IS NOT NULL AND sm_uid.meta_value <> '' THEN 1 ELSE 0 END) AS is_registered,
                COUNT(*) AS c
           FROM {$wpdb->posts} s
           JOIN {$wpdb->postmeta} sm_prod ON sm_prod.post_id = s.ID AND sm_prod.meta_key = 'production'
           JOIN {$wpdb->postmeta} pm_prod ON pm_prod.post_id = sm_prod.meta_value AND pm_prod.meta_key = 'product'
      LEFT JOIN {$wpdb->postmeta} sm_uid  ON sm_uid.post_id  = s.ID AND sm_uid.meta_key  = 'uid'
          WHERE s.post_type = 'ial_serial'
            AND s.post_status = 'publish'
            AND pm_prod.meta_value = %d
       GROUP BY is_registered",
        $product_id
    ));

    foreach ((array) $rows as $row) {
        if ((int) $row->is_registered === 1) {
            $out['registered'] = (int) $row->c;
        } else {
            $out['unregistered'] = (int) $row->c;
        }
    }
    $out['total'] = $out['registered'] + $out['unregistered'];
    return $out;
}

/**
 * Apply the currently-configured roles of a product to every user who has
 * registered a serial of it. Idempotent: skips role assignments already in
 * place. Returns stats.
 */
function ial_apply_roles_to_product_registrations($product_id)
{
    global $wpdb;
    $product_id = (int) $product_id;
    $result = array(
        'users_total'      => 0,
        'users_updated'    => 0,
        'roles_added'      => 0,
        'roles_configured' => array(),
    );
    if (!$product_id) {
        return $result;
    }

    $roles_configured = get_post_meta($product_id, 'assign_roles', true);
    if (!is_array($roles_configured) || empty($roles_configured)) {
        return $result;
    }

    $valid_role_keys = array_keys(wp_roles()->get_names());
    $roles_filtered  = array();
    foreach ($roles_configured as $role_key) {
        $role_key = sanitize_key($role_key);
        if ($role_key && in_array($role_key, $valid_role_keys, true)) {
            $roles_filtered[] = $role_key;
        }
    }
    if (empty($roles_filtered)) {
        return $result;
    }
    $result['roles_configured'] = $roles_filtered;

    // Distinct user IDs that registered any serial of this product.
    $user_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT sm_uid.meta_value
           FROM {$wpdb->posts} s
           JOIN {$wpdb->postmeta} sm_prod ON sm_prod.post_id = s.ID AND sm_prod.meta_key = 'production'
           JOIN {$wpdb->postmeta} pm_prod ON pm_prod.post_id = sm_prod.meta_value AND pm_prod.meta_key = 'product'
           JOIN {$wpdb->postmeta} sm_uid  ON sm_uid.post_id  = s.ID AND sm_uid.meta_key  = 'uid'
          WHERE s.post_type = 'ial_serial'
            AND s.post_status = 'publish'
            AND pm_prod.meta_value = %d
            AND sm_uid.meta_value <> ''",
        $product_id
    ));

    $result['users_total'] = count($user_ids);

    foreach ($user_ids as $uid) {
        $uid = (int) $uid;
        if (!$uid) {
            continue;
        }
        $user = get_userdata($uid);
        if (!$user) {
            continue;
        }
        $added_for_user = 0;
        foreach ($roles_filtered as $role_key) {
            if (!in_array($role_key, (array) $user->roles, true)) {
                $user->add_role($role_key);
                $added_for_user++;
            }
        }
        if ($added_for_user > 0) {
            $result['users_updated']++;
            $result['roles_added'] += $added_for_user;
        }
    }

    return $result;
}

/**
 * AJAX endpoint for the "Apply current roles to existing registrations"
 * button on the Product edit screen.
 */
add_action('wp_ajax_ial_apply_roles_retroactively', 'ial_ajax_apply_roles_retroactively');
function ial_ajax_apply_roles_retroactively()
{
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;

    if (!$product_id || !current_user_can('edit_post', $product_id)) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ial-reg')), 403);
    }

    check_ajax_referer('ial_apply_roles_' . $product_id, 'nonce');

    $stats = ial_apply_roles_to_product_registrations($product_id);
    wp_send_json_success($stats);
}
