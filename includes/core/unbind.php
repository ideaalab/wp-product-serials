<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Does the user still have a registered (uid set) serial of any product
 * OTHER than $exclude_product_id whose `assign_roles` contains $role_key?
 */
function ial_user_other_products_with_role($user_id, $role_key, $exclude_product_id)
{
    global $wpdb;
    $user_id            = (int) $user_id;
    $exclude_product_id = (int) $exclude_product_id;
    $role_key           = sanitize_key($role_key);
    if (!$user_id || !$role_key) {
        return false;
    }

    $product_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT pm_prod.meta_value
           FROM {$wpdb->posts} s
           JOIN {$wpdb->postmeta} sm_uid  ON sm_uid.post_id  = s.ID AND sm_uid.meta_key  = 'uid' AND sm_uid.meta_value = %d
           JOIN {$wpdb->postmeta} sm_prod ON sm_prod.post_id = s.ID AND sm_prod.meta_key = 'production'
           JOIN {$wpdb->postmeta} pm_prod ON pm_prod.post_id = sm_prod.meta_value AND pm_prod.meta_key = 'product'
          WHERE s.post_type   = 'ial_serial'
            AND s.post_status = 'publish'
            AND pm_prod.meta_value <> %d",
        $user_id,
        $exclude_product_id
    ));

    foreach ((array) $product_ids as $pid) {
        $roles = get_post_meta((int) $pid, 'assign_roles', true);
        if (is_array($roles) && in_array($role_key, $roles, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Does the user still have a registered serial of any product OTHER than
 * $exclude_product_id whose `acymailing_list_id` equals $list_id?
 */
function ial_user_other_products_with_acym_list($user_id, $list_id, $exclude_product_id)
{
    global $wpdb;
    $user_id            = (int) $user_id;
    $exclude_product_id = (int) $exclude_product_id;
    $list_id            = (string) $list_id;
    if (!$user_id || $list_id === '') {
        return false;
    }

    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT pm_prod.meta_value)
           FROM {$wpdb->posts} s
           JOIN {$wpdb->postmeta} sm_uid   ON sm_uid.post_id   = s.ID AND sm_uid.meta_key  = 'uid' AND sm_uid.meta_value = %d
           JOIN {$wpdb->postmeta} sm_prod  ON sm_prod.post_id  = s.ID AND sm_prod.meta_key = 'production'
           JOIN {$wpdb->postmeta} pm_prod  ON pm_prod.post_id  = sm_prod.meta_value AND pm_prod.meta_key = 'product'
           JOIN {$wpdb->postmeta} pm_acym  ON pm_acym.post_id  = pm_prod.meta_value AND pm_acym.meta_key = 'acymailing_list_id'
          WHERE s.post_type   = 'ial_serial'
            AND s.post_status = 'publish'
            AND pm_prod.meta_value <> %d
            AND pm_acym.meta_value = %s",
        $user_id,
        $exclude_product_id,
        $list_id
    ));

    return $count > 0;
}

/**
 * Core unbind logic. Validates ownership, moves the registration data into the
 * `notes` audit line and clears it along with uid + a_uid, then fires
 * `ial_user_unbound_product` so listeners (roles, AcyMailing) can react.
 *
 * The listeners run after the response has been sent: the customer is waiting
 * on a modal and none of what they are told depends on an AcyMailing call or a
 * role plugin finishing first.
 *
 * Returns: array('ok' => bool, 'message' => string).
 */
function ial_unbind_serial($serial_id, $user_id, $motivo)
{
    $serial_id = (int) $serial_id;
    $user_id   = (int) $user_id;
    $motivo    = trim(wp_strip_all_tags((string) $motivo));

    $result = array('ok' => false, 'message' => '');

    if (!$serial_id || !$user_id) {
        $result['message'] = __('Petición inválida.', 'ial-reg');
        return $result;
    }
    if ($motivo === '') {
        $result['message'] = __('El motivo es obligatorio.', 'ial-reg');
        return $result;
    }

    $serial = get_post($serial_id);
    if (!$serial || $serial->post_type !== 'ial_serial') {
        $result['message'] = __('Número de serie no encontrado.', 'ial-reg');
        return $result;
    }

    $current_uid = (int) get_post_meta($serial_id, 'uid', true);
    if ($current_uid !== $user_id) {
        $result['message'] = __('No tienes permiso para desvincular este producto.', 'ial-reg');
        return $result;
    }

    $production_id = (int) get_post_meta($serial_id, 'production', true);
    $product_id    = $production_id ? (int) get_post_meta($production_id, 'product', true) : 0;

    $user       = get_userdata($user_id);
    $user_email = $user ? $user->user_email : '';

    $current_notes = (string) get_post_meta($serial_id, 'notes', true);
    $audit_line    = sprintf(
        '[%s] %s (ID %d, %s). %s %s',
        current_time('mysql'),
        __('Desvinculado por el usuario', 'ial-reg'),
        $user_id,
        $user_email,
        __('Motivo:', 'ial-reg'),
        $motivo
    );

    // The registration data is about to be cleared, so it moves into the notes
    // first. Notes are internal, and knowing who a unit came from is what tells
    // a second-hand sale apart from a return.
    $previous = array();
    foreach (array(
        'u_name'   => __('Nombre:', 'ial-reg'),
        'purchase' => __('Fecha de compra:', 'ial-reg'),
        'seller'   => __('Vendedor:', 'ial-reg'),
    ) as $meta_key => $label) {
        $value = trim((string) get_post_meta($serial_id, $meta_key, true));
        if ($value !== '') {
            $previous[] = $label . ' ' . $value;
        }
    }

    if (!empty($previous)) {
        $audit_line .= "\n" . __('Registro anterior:', 'ial-reg') . ' ' . implode(' | ', $previous);
    }

    $new_notes = $current_notes !== ''
        ? rtrim($current_notes) . "\n\n" . $audit_line
        : $audit_line;

    // Release the serial: it has to look untouched to whoever registers it next.
    delete_post_meta($serial_id, 'uid');
    delete_post_meta($serial_id, 'a_uid');
    delete_post_meta($serial_id, 'u_name');
    delete_post_meta($serial_id, 'purchase');
    delete_post_meta($serial_id, 'seller');
    update_post_meta($serial_id, 'notes', $new_notes);

    // Cheap, and the customer may look at their discount on the next page.
    if (function_exists('ial_loyalty_refresh_user')) {
        ial_loyalty_refresh_user($user_id);
    }

    /**
     * Fires after a user has successfully unbound a serial from their account.
     * Listeners can react to remove roles, unsubscribe from mailing lists, etc.
     *
     * @param int    $serial_id  The serial post ID that was unbound.
     * @param int    $user_id    The user that unbound it (already cleared from uid).
     * @param int    $product_id The product ID the serial belongs to.
     * @param string $motivo     Free-text reason supplied by the user.
     */
    $fire = function () use ($serial_id, $user_id, $product_id, $motivo) {
        do_action('ial_user_unbound_product', $serial_id, $user_id, $product_id, $motivo);
    };

    /**
     * Whether to run the unbind side effects after the response.
     *
     * @param bool   $deferred   True when the connection can be closed early.
     * @param int    $serial_id  Serial that was unbound.
     * @param int    $user_id    User it was unbound from.
     * @param int    $product_id Product the serial belongs to.
     * @param string $motivo     Reason the user gave.
     */
    $deferred = apply_filters('ial_defer_unbind_side_effects', ial_can_run_after_response(), $serial_id, $user_id, $product_id, $motivo);

    if ($deferred) {
        ial_run_after_response($fire);
    } else {
        $fire();
    }

    $result['ok']      = true;
    $result['message'] = __('Producto desvinculado correctamente.', 'ial-reg');
    return $result;
}

/**
 * Frontend AJAX endpoint invoked from the user's "My Products" page.
 */
add_action('wp_ajax_ial_unbind_serial', 'ial_ajax_unbind_serial');
function ial_ajax_unbind_serial()
{
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => __('Debes iniciar sesión.', 'ial-reg')), 401);
    }

    $serial_id = isset($_POST['serial_id']) ? (int) $_POST['serial_id'] : 0;
    $motivo    = isset($_POST['motivo']) ? wp_unslash((string) $_POST['motivo']) : '';

    if (!$serial_id) {
        wp_send_json_error(array('message' => __('Petición inválida.', 'ial-reg')), 400);
    }

    check_ajax_referer('ial_unbind_serial_' . $serial_id, 'nonce');

    $result = ial_unbind_serial($serial_id, get_current_user_id(), $motivo);
    if ($result['ok']) {
        wp_send_json_success(array('message' => $result['message']));
    }
    wp_send_json_error(array('message' => $result['message']), 400);
}
