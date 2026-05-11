<?php

if (!defined('ABSPATH')) {
    exit;
}

// Register all custom post types for the plugin.
function ial_register_cpts()
{

    // 1. CPT: Product (ial_product)
    $product_labels = array(
        'name' => _x('Products', 'post type general name', 'ial-reg'),
        'singular_name' => _x('Product', 'post type singular name', 'ial-reg'),
        'menu_name' => _x('Product Serials', 'admin menu', 'ial-reg'),
        'all_items' => __('All Products', 'ial-reg'),
        'add_new' => _x('Add New', 'product', 'ial-reg'),
        'add_new_item' => __('Add New Product', 'ial-reg'),
        'edit_item' => __('Edit Product', 'ial-reg'),
        'view_item' => __('View Product', 'ial-reg'),
    );

    $product_args = array(
        'labels' => $product_labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'menu_position' => 25,
        'menu_icon' => 'dashicons-archive',
        'supports' => array('title', 'custom-fields'),
        'show_in_rest' => true,
        'description' => __('Defines the products that can be registered by users.', 'ial-reg'),
    );

    register_post_type('ial_product', $product_args);

    // 2. CPT: Production (ial_production)
    $production_labels = array(
        'name' => _x('Productions', 'post type general name', 'ial-reg'),
        'singular_name' => _x('Production', 'post type singular name', 'ial-reg'),
        'menu_name' => _x('Productions', 'admin menu', 'ial-reg'),
        'all_items' => __('All Productions', 'ial-reg'),
        'add_new_item' => __('Add New Production', 'ial-reg'),
        'edit_item' => __('Edit Production', 'ial-reg'),
    );

    $production_args = array(
        'labels' => $production_labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'edit.php?post_type=ial_product',
        'supports' => array('title', 'custom-fields'),
        'show_in_rest' => true,
        'description' => __('Represents a batch of manufactured products.', 'ial-reg'),
    );

    register_post_type('ial_production', $production_args);

    // 3. CPT: Serial (ial_serial)
    $serial_labels = array(
        'name' => _x('Serials', 'post type general name', 'ial-reg'),
        'singular_name' => _x('Serial', 'post type singular name', 'ial-reg'),
        'menu_name' => _x('Serials', 'admin menu', 'ial-reg'),
        'all_items' => __('All Serials', 'ial-reg'),
        'add_new_item' => __('Add New Serial', 'ial-reg'),
        'edit_item' => __('Edit Serial', 'ial-reg'),
    );

    $serial_args = array(
        'labels' => $serial_labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'edit.php?post_type=ial_product',
        'supports' => array('title', 'custom-fields'),
        'show_in_rest' => true,
        'description' => __('Individual serial numbers for product registration.', 'ial-reg'),
    );

    register_post_type('ial_serial', $serial_args);

    // 4. CPT: Email Campaigns (ial_email_campaign)
    $campaign_labels = array(
        'name' => _x('Email Campaigns', 'post type general name', 'ial-reg'),
        'singular_name' => _x('Email Campaign', 'post type singular name', 'ial-reg'),
        'menu_name' => _x('Email Campaigns', 'admin menu', 'ial-reg'),
        'all_items' => __('All Campaigns', 'ial-reg'),
        'add_new_item' => __('Create New Campaign', 'ial-reg'),
        'edit_item' => __('Edit Campaign', 'ial-reg'),
    );

    $campaign_args = array(
        'labels' => $campaign_labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'edit.php?post_type=ial_product',
        'supports' => array('title'),
        'show_in_rest' => false,
        'description' => __('Manage mass email campaigns for registered users.', 'ial-reg'),
    );

    register_post_type('ial_email_campaign', $campaign_args);
}
add_action('init', 'ial_register_cpts');