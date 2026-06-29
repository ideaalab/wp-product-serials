<?php

if (!defined('ABSPATH')) {
    exit;
}

// Registration Subscription Handling.
add_action('ial_user_registered_product', 'ial_handle_acymailing_subscription', 10, 3);
function ial_handle_acymailing_subscription($serial_id, $user_id, $product_id)
{

    // Check if AcyMailing classes exist
    if (!class_exists('AcyMailing\Classes\UserClass') || !class_exists('AcyMailing\Classes\ListClass')) {
        return;
    }

    // Get List ID assigned to the Product
    $list_id_to_subscribe = get_post_meta($product_id, 'acymailing_list_id', true);

    if (!$list_id_to_subscribe) {
        return;
    }

    // Determine User Email
    $user_email = '';
    if ($user_id) {
        $user_info = get_userdata($user_id);
        if ($user_info) {
            $user_email = $user_info->user_email;
        }
    }

    if (!$user_email || !is_email($user_email)) {
        return;
    }

    // AcyMailing Logic
    $userClass = new \AcyMailing\Classes\UserClass();

    // Check if the user exists in the AcyMailing table
    $acym_user = $userClass->getOneByEmail($user_email);
    $acym_user_id = 0;

    if (empty($acym_user)) {
        // Create new subscriber
        $new_user_data = new \stdClass();
        $new_user_data->email = $user_email;
        $new_user_data->active = 1;
        $new_user_data->source = 'Product Registration Plugin';

        if ($user_id) {
            $new_user_data->cms_id = $user_id;
        }

        // Saving returns the ID
        $acym_user_id = $userClass->save($new_user_data);
    } else {
        $acym_user_id = $acym_user->id;
    }

    // Subscribe to the List
    if ($acym_user_id) {
        $userClass->subscribe($acym_user_id, array($list_id_to_subscribe));

        // Save AcyMailing UID in Serial Record
        update_post_meta($serial_id, 'a_uid', $acym_user_id);
    }
}

/**
 * Unsubscribe the user from the product's AcyMailing list when they unbind a
 * serial — but only if they don't still hold a registered serial of another
 * product subscribed to the same list.
 */
add_action('ial_user_unbound_product', 'ial_handle_acymailing_unsubscribe_on_unbind', 10, 4);
function ial_handle_acymailing_unsubscribe_on_unbind($serial_id, $user_id, $product_id, $motivo)
{
    if (!class_exists('AcyMailing\Classes\UserClass')) {
        return;
    }
    $user_id    = (int) $user_id;
    $product_id = (int) $product_id;
    if (!$user_id || !$product_id) {
        return;
    }

    $list_id = get_post_meta($product_id, 'acymailing_list_id', true);
    if ($list_id === '' || $list_id === null) {
        return;
    }

    // Skip if another registered product keeps the user on the same list.
    if (function_exists('ial_user_other_products_with_acym_list')
        && ial_user_other_products_with_acym_list($user_id, $list_id, $product_id)) {
        return;
    }

    $user = get_userdata($user_id);
    if (!$user || !is_email($user->user_email)) {
        return;
    }

    $userClass = new \AcyMailing\Classes\UserClass();
    $acym_user = $userClass->getOneByEmail($user->user_email);
    if (empty($acym_user)) {
        return;
    }

    if (method_exists($userClass, 'unsubscribe')) {
        $userClass->unsubscribe((int) $acym_user->id, array((int) $list_id));
    }
}
