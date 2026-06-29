<?php

if (!defined('ABSPATH')) {
    exit;
}

// Load frontend scripts and styles.
function ial_enqueue_frontend_assets()
{
    global $post;

    $is_my_products = is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'ial_my_registered_products');
    $is_registration = is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'ial_registration_form');
    $is_account     = function_exists('is_account_page') && is_account_page();

    // Conditional CSS loading.
    if ($is_my_products || $is_registration || $is_account) {
        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'ial-frontend',
            plugin_dir_url(__FILE__) . '../../assets/css/ial-frontend.css',
            array(),
            IAL_REG_VERSION
        );
    }

    // Unbind modal JS only where the My Products shortcode (or My Account
    // tab that includes it) is on the page, and the user is logged in.
    if (is_user_logged_in() && ($is_my_products || $is_account)) {
        wp_enqueue_script(
            'ial-frontend-unbind',
            plugin_dir_url(__FILE__) . '../../assets/js/frontend-unbind.js',
            array(),
            IAL_REG_VERSION,
            true
        );
        wp_localize_script('ial-frontend-unbind', 'ialUnbind', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'errMotivo'  => __('El motivo es obligatorio.', 'ial-reg'),
            'errGeneric' => __('No se pudo desvincular el producto. Vuelve a intentarlo.', 'ial-reg'),
            'working'    => __('Procesando…', 'ial-reg'),
        ));
    }
}
add_action('wp_enqueue_scripts', 'ial_enqueue_frontend_assets');
