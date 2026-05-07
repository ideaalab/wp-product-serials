<?php

if (!defined('ABSPATH')) {
    exit;
}

// Load frontend scripts and styles.
function ial_enqueue_frontend_assets()
{
    global $post;

    // Conditional CSS loading.
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'ial_registration_form') || has_shortcode($post->post_content, 'ial_my_registered_products') || (function_exists('is_account_page') && is_account_page()))) {
        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'ial-frontend',
            plugin_dir_url(__FILE__) . '../../assets/css/ial-frontend.css',
            array(),
            IAL_REG_VERSION
        );
    }
}
add_action('wp_enqueue_scripts', 'ial_enqueue_frontend_assets');
