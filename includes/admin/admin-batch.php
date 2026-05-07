<?php

if (!defined('ABSPATH')) {
    exit;
}

// Check global serial uniqueness.
function ial_serial_exists_globally($title, $exclude_post_id = 0)
{
    global $wpdb;
    $query = $wpdb->prepare(
        "SELECT ID FROM $wpdb->posts WHERE post_type = 'ial_serial' AND post_status = 'publish' AND post_title = %s AND ID != %d",
        $title,
        $exclude_post_id
    );
    return ($wpdb->get_var($query) !== null);
}

// Cache clearing helper.
function ial_clear_product_serial_count_cache($production_id)
{
    if (!$production_id)
        return;
    $pid = $production_id;
    if (is_object($production_id)) {
        $pid = $production_id->ID;
    }

    $product_id = get_post_meta($pid, 'product', true);
    if ($product_id) {
        delete_transient('ial_prod_serial_count_' . $product_id);
    }
}

function ial_clear_serial_product_name_cache($serial_id)
{
    if ($serial_id)
        delete_transient('ial_serial_prod_name_' . $serial_id);
}

// Main Batch Creation Logic. Hook on save_post.
add_action('save_post', 'ial_handle_batch_serial_creation', 20);
function ial_handle_batch_serial_creation($post_id)
{

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;
    if (get_post_type($post_id) != 'ial_serial' || wp_is_post_revision($post_id))
        return;

    if (!current_user_can('edit_post', $post_id))
        return;

    // Check if it is batch
    if (!isset($_POST['add_serials']))
        return;

    // Optimize for large batches to prevent 502 Bad Gateway
    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }

    // Flag to trigger redirect filter
    $is_batch = false;

    $batch_serials = sanitize_textarea_field($_POST['add_serials']);

    if (empty($batch_serials)) {
        return;
    }

    $production_id = isset($_POST['production']) ? sanitize_text_field($_POST['production']) : '';
    if (empty($production_id)) {
        set_transient('ial_batch_error_' . get_current_user_id(), 'Error: You must select a Production.', 60);
        return;
    }

    ial_clear_product_serial_count_cache($production_id);

    remove_action('save_post', 'ial_handle_batch_serial_creation', 20);

    $serials_raw = preg_split('/\r\n|\r|\n/', $batch_serials);
    $serials = array_unique(array_filter(array_map('trim', $serials_raw)));

    if (empty($serials)) {
        return;
    }

    $is_batch = true;
    $skipped_serials = array();
    $first_serial = sanitize_text_field(array_shift($serials));

    // 1. First Serial Handling (Current Post)
    if (ial_serial_exists_globally($first_serial, $post_id)) {
        $skipped_serials[] = $first_serial;
        wp_delete_post($post_id, true);
    } else {
        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => $first_serial,
            'post_name' => sanitize_title($first_serial),
            'post_status' => 'publish'
        ));
        update_post_meta($post_id, '_ial_batch_processed', 'yes');
    }

    // 2. Remaining Serials Handling (Create New Posts)
    if (!empty($serials)) {
        $field_values = array();
        $field_values['production'] = $production_id;
        $field_values['distributor'] = isset($_POST['distributor']) ? sanitize_text_field($_POST['distributor']) : '';
        $field_values['notes'] = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        $field_values['a_uid'] = isset($_POST['a_uid']) ? sanitize_text_field($_POST['a_uid']) : '';

        foreach ($serials as $serial_title) {
            $serial_title = sanitize_text_field($serial_title);

            if (ial_serial_exists_globally($serial_title)) {
                $skipped_serials[] = $serial_title;
                continue;
            }

            $new_post_id = wp_insert_post(array(
                'post_type' => 'ial_serial',
                'post_title' => $serial_title,
                'post_name' => sanitize_title($serial_title),
                'post_status' => 'publish'
            ));

            if ($new_post_id && !is_wp_error($new_post_id)) {
                foreach ($field_values as $key => $value) {
                    update_post_meta($new_post_id, $key, $value);
                }
                update_post_meta($new_post_id, '_ial_batch_processed', 'yes');
            }
        }
    }

    if (!empty($skipped_serials)) {
        $msg = 'Warning: Skipped duplicate serials: ' . implode(', ', $skipped_serials);
        set_transient('ial_batch_error_' . get_current_user_id(), $msg, 60);
    }

    // Set session for redirect
    if ($is_batch) {
        set_transient('ial_batch_redirect_' . get_current_user_id(), 'yes', 30);
    }
}

// Redirect Handler after Batch Processing.
add_filter('redirect_post_location', 'ial_batch_redirect_location', 10, 2);
function ial_batch_redirect_location($location, $post_id)
{
    if (get_transient('ial_batch_redirect_' . get_current_user_id())) {
        delete_transient('ial_batch_redirect_' . get_current_user_id());
        return admin_url('edit.php?post_type=ial_serial');
    }
    return $location;
}


// Remove "Add New" submenu.
add_action('admin_menu', function () {
    remove_submenu_page('edit.php?post_type=ial_product', 'post-new.php?post_type=ial_product');
}, 999);


// Admin Notification for Batch Errors.
add_action('admin_notices', function () {
    global $pagenow;
    if ($pagenow == 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] == 'ial_serial') {
        $batch_error = get_transient('ial_batch_error_' . get_current_user_id());
        if ($batch_error) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($batch_error) . '</p></div>';
            delete_transient('ial_batch_error_' . get_current_user_id());
        }
    }
});

// Conditional CSS: Hide Title field ONLY on "Add New" screen.
add_action('admin_enqueue_scripts', 'ial_admin_conditional_styles');
function ial_admin_conditional_styles()
{
    global $pagenow;
    $screen = get_current_screen();

    if (!$screen || $screen->post_type != 'ial_serial')
        return;

    if ($pagenow == 'post-new.php') {
        wp_add_inline_style('common', '#titlewrap { display: none !important; }');
    }
}

// UX Improvement: Change "Enter title here" to "Serial Number".
add_filter('enter_title_here', 'ial_change_title_placeholder', 10, 2);
function ial_change_title_placeholder($title, $post)
{
    if ($post->post_type == 'ial_serial') {
        return 'Serial Number';
    }
    return $title;
}

// Cache Cleaning on Deletion.
add_action('before_delete_post', function ($post_id) {
    if (get_post_type($post_id) == 'ial_serial') {
        $production_id = get_post_meta($post_id, 'production', true);
        ial_clear_product_serial_count_cache($production_id);
        ial_clear_serial_product_name_cache($post_id);
    }
});
