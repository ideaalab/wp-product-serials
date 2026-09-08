<?php

if (!defined('ABSPATH')) {
    exit;
}

// Set up all WooCommerce integration hooks.
function ial_woocommerce_integration_setup()
{
    // Proceed only if WooCommerce is active.
    if (!class_exists('WooCommerce')) {
        return;
    }

    // 1. Register a new rewrite endpoint for the "My Account" page.
    add_rewrite_endpoint('productos-registrados', EP_PAGES);

    // 2. Add a new item to the "My Account" menu.
    add_filter('woocommerce_account_menu_items', 'ial_add_my_products_menu_item', 10);

    // 3. Define the content for the new endpoint.
    add_action('woocommerce_account_productos-registrados_endpoint', 'ial_my_products_endpoint_content');
}
add_action('init', 'ial_woocommerce_integration_setup');

// Add the "Tus productos registrados" link to the WooCommerce account menu.
function ial_add_my_products_menu_item($items)
{
    $new_items = array();
    $inserted = false;

    foreach ($items as $key => $value) {
        $new_items[$key] = $value;
        // Insert after a known item, like 'downloads'.
        if ('downloads' === $key) {
            $new_items['productos-registrados'] = __('Tus productos registrados', 'ial-reg');
            $inserted = true;
        }
    }

    // Fallback in case the 'downloads' item is not present.
    if (!$inserted) {
        $new_items['productos-registrados'] = __('Tus productos registrados', 'ial-reg');
    }

    return $new_items;
}

// Render the content for the "productos-registrados" endpoint.
function ial_my_products_endpoint_content()
{
    $title = __('Tus productos registrados', 'ial-reg');

    // Get the URL for the registration page from plugin settings.
    $registration_page_id = get_option('ial_registration_form_page_id');
    $registration_page_url = $registration_page_id ? get_permalink($registration_page_id) : false;

    // Prepare the "Register New Product" button HTML.
    $register_button_html = '';
    if ($registration_page_url) {
        $register_button_html = sprintf(
            '<a href="%1$s" class="woocommerce-Button button ial-button"><span class="ial-button-icon" aria-hidden="true">+</span>%2$s</a>',
            esc_url($registration_page_url),
            esc_html__('Registrar nuevo producto', 'ial-reg')
        );
    }

    // Output the header with title and button.
    echo '<div class="ial-my-products-header">';
    echo '<h2>' . esc_html($title) . '</h2>';
    echo $register_button_html;
    echo '</div>';

    // 1. The products the customer has registered.
    echo do_shortcode('[ial_my_registered_products]');

    // 2. "Descuento permanente" + tier progress, then "Colección" + the full
    //    grid. Both headings are printed by the shortcode itself.
    echo do_shortcode('[ial_product_collection]');
}

// Detects the activation of the WooCommerce plugin.
function ial_detect_woocommerce_activation($plugin)
{
    if ('woocommerce/woocommerce.php' === $plugin) {
        set_transient('ial_reg_flush_needed', true, 60);
    }
}
add_action('activated_plugin', 'ial_detect_woocommerce_activation');
