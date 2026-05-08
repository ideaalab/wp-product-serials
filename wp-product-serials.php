<?php
/**
 * Plugin Name:       WP Product Serials
 * Description:       Serial-number registration system for physical products. Manages products, production batches and serials.
 * Version:           3.4.0
 * Author:            IDEAA Lab | Michael Di Desidero
 * License:           GPL v2 or later
 * Text Domain:       ial-reg
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Auto-update from GitHub
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
    'https://github.com/ideaalab/wp-product-serials/',
    __FILE__,
    'wp-product-serials'
);

define('IAL_REG_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('IAL_REG_VERSION', '3.4.0');

// Main plugin class for IdeaaLab Registrations.
final class IdeaaLab_Registrations
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Private constructor to prevent direct instantiation.
    private function __construct()
    {
        add_action('plugins_loaded', array($this, 'init_plugin'));
        register_activation_hook(__FILE__, array($this, 'handle_activation'));
        add_action('admin_init', array($this, 'check_version_and_flush'));

        $plugin_basename = plugin_basename(__FILE__);
        add_filter("plugin_action_links_{$plugin_basename}", array($this, 'add_settings_link'));
    }

    // Initialize the plugin components.
    public function init_plugin()
    {
        load_plugin_textdomain('ial-reg', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        $this->includes();
    }

    // Include all required files for the plugin.
    private function includes()
    {
        // 1. CORE
        require_once IAL_REG_PLUGIN_PATH . 'includes/core/cpt-definitions.php';
        require_once IAL_REG_PLUGIN_PATH . 'includes/core/woocommerce-integration.php';
        require_once IAL_REG_PLUGIN_PATH . 'includes/core/role-assignment.php';
        require_once IAL_REG_PLUGIN_PATH . 'includes/integrations/acymailing.php';

        // 2. ADMIN
        if (is_admin()) {
            require_once IAL_REG_PLUGIN_PATH . 'includes/admin/meta-boxes.php'; // Native Meta Boxes
            require_once IAL_REG_PLUGIN_PATH . 'includes/admin/admin-settings.php';
            require_once IAL_REG_PLUGIN_PATH . 'includes/admin/admin-columns.php';
            require_once IAL_REG_PLUGIN_PATH . 'includes/admin/admin-batch.php';
            require_once IAL_REG_PLUGIN_PATH . 'includes/admin/admin-charts.php';
            require_once IAL_REG_PLUGIN_PATH . 'includes/admin/admin-emails.php'; // New Email System
        }

        // 3. FRONTEND
        if (!is_admin() || wp_doing_ajax()) {
            require_once IAL_REG_PLUGIN_PATH . 'includes/frontend/shortcode-form.php';
            require_once IAL_REG_PLUGIN_PATH . 'includes/frontend/shortcode-my-products.php';
            require_once IAL_REG_PLUGIN_PATH . 'includes/frontend/frontend-assets.php';
        }
    }

    public function handle_activation()
    {
        set_transient('ial_reg_flush_needed', true, 60);
    }

    public function check_version_and_flush()
    {
        $flush_needed = get_transient('ial_reg_flush_needed');
        $current_version = get_option('ial_reg_version', '0.0.0');

        if ($flush_needed || version_compare($current_version, IAL_REG_VERSION, '!=')) {
            flush_rewrite_rules();
            delete_transient('ial_reg_flush_needed');
            update_option('ial_reg_version', IAL_REG_VERSION);
        }
    }

    public function add_settings_link($links)
    {
        $settings_url = admin_url('edit.php?post_type=ial_product&page=ial-reg-settings');
        $settings_link = sprintf('<a href="%s">%s</a>', esc_url($settings_url), __('Settings', 'ial-reg'));
        array_unshift($links, $settings_link);
        return $links;
    }
}

IdeaaLab_Registrations::get_instance();