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
