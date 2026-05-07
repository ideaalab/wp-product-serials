<?php

if (!defined('ABSPATH')) {
    exit;
}

function ial_add_settings_page()
{
    add_submenu_page(
        'edit.php?post_type=ial_product',
        __('Settings', 'ial-reg'),
        __('Settings', 'ial-reg'),
        'manage_options',
        'ial-reg-settings',
        'ial_render_settings_page'
    );
}
add_action('admin_menu', 'ial_add_settings_page');

function ial_register_settings()
{
    register_setting('ial_settings_group', 'ial_registration_form_page_id', array('sanitize_callback' => 'intval'));
    add_settings_section('ial_general_settings', __('General Settings', 'ial-reg'), null, 'ial-reg-settings');
    add_settings_field('ial_registration_form_page_id', __('Registration Form Page', 'ial-reg'), 'ial_render_page_dropdown_field', 'ial-reg-settings', 'ial_general_settings');
}
add_action('admin_init', 'ial_register_settings');

function ial_render_page_dropdown_field()
{
    $saved = get_option('ial_registration_form_page_id');
    wp_dropdown_pages(array(
        'name' => 'ial_registration_form_page_id',
        'selected' => $saved,
        'show_option_none' => __('— Select a Page —', 'ial-reg'),
        'option_none_value' => '0',
    ));
    echo '<p class="description">' . __('Select the page where you have placed the <code>[ial_registration_form]</code> shortcode.', 'ial-reg') . '</p>';
}

// Load Media scripts for Meta Box
function ial_enqueue_admin_media_scripts()
{
    $screen = get_current_screen();

    // Only on 'ial_product' edit screen
    if ($screen && $screen->post_type === 'ial_product' && $screen->base === 'post') {
        wp_enqueue_media();
        wp_enqueue_script(
            'ial-admin-media',
            plugin_dir_url(__FILE__) . '../../assets/js/admin-media-upload.js',
            array('jquery'),
            '1.0.0',
            true
        );
    }

    // Validation script for all ial_ CPTs
    if ($screen && in_array($screen->post_type, array('ial_product', 'ial_production', 'ial_serial', 'ial_email_campaign')) && $screen->base === 'post') {
        wp_enqueue_script(
            'ial-admin-validation',
            plugin_dir_url(__FILE__) . '../../assets/js/admin-validation.js',
            array('jquery'),
            '1.0.0',
            true
        );
    }
}
add_action('admin_enqueue_scripts', 'ial_enqueue_admin_media_scripts');

function ial_render_settings_page()
{
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Registration Settings', 'ial-reg'); ?></h1>
        <form action="options.php" method="POST">
            <?php settings_fields('ial_settings_group');
            do_settings_sections('ial-reg-settings');
            submit_button(); ?>
        </form>
    </div>
    <?php
}
