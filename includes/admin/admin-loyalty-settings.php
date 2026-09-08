<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings UI for the loyalty discount, added as a section of the existing
 * plugin settings page. Everything lives in a single `ial_loyalty_settings`
 * option array.
 */

add_action('admin_init', 'ial_loyalty_register_settings');
function ial_loyalty_register_settings()
{
    register_setting('ial_settings_group', 'ial_loyalty_settings', array(
        'sanitize_callback' => 'ial_loyalty_sanitize_settings',
        'default'           => ial_loyalty_default_settings(),
    ));

    add_settings_section(
        'ial_loyalty_section',
        __('Loyalty Discount (WooCommerce)', 'ial-reg'),
        'ial_loyalty_render_section_intro',
        'ial-reg-settings'
    );

    $fields = array(
        'enabled'     => __('Enable discount', 'ial-reg'),
        'basis'       => __('What levels the customer up', 'ial-reg'),
        'tiers'       => __('Discount tiers', 'ial-reg'),
        'max_percent' => __('Global cap', 'ial-reg'),
        'filter'      => __('Where it applies', 'ial-reg'),
        'display'     => __('Where it is shown', 'ial-reg'),
    );

    foreach ($fields as $key => $label) {
        add_settings_field(
            'ial_loyalty_' . $key,
            $label,
            'ial_loyalty_render_field_' . $key,
            'ial-reg-settings',
            'ial_loyalty_section'
        );
    }
}

function ial_loyalty_render_section_intro()
{
    if (!class_exists('WooCommerce')) {
        echo '<div class="notice notice-warning inline"><p>'
            . esc_html__('WooCommerce is not active. These settings are saved but nothing is applied until it is.', 'ial-reg')
            . '</p></div>';
    }

    echo '<p class="description" style="max-width:46em;">'
        . esc_html__('Customers get a percentage off based on how many products they have registered. The discount is applied to the cart line prices, so taxes are calculated by WooCommerce as usual.', 'ial-reg')
        . '</p>';
    echo '<p class="description" style="max-width:46em;"><strong>'
        . esc_html__('It never stacks:', 'ial-reg')
        . '</strong> '
        . esc_html__('on each line the bigger of the product offer and this discount wins, and against a coupon entered by the customer the bigger of the two wins.', 'ial-reg')
        . '</p>';
}

function ial_loyalty_render_field_enabled()
{
    $s = ial_loyalty_get_settings();
    ?>
    <label>
        <input type="checkbox" name="ial_loyalty_settings[enabled]" value="1" <?php checked($s['enabled'], 1); ?>>
        <?php esc_html_e('Apply the discount automatically at checkout', 'ial-reg'); ?>
    </label>
    <p class="description"><?php esc_html_e('Only logged-in customers can receive it — guests have no registered products.', 'ial-reg'); ?></p>
    <?php
}

function ial_loyalty_render_field_basis()
{
    $s = ial_loyalty_get_settings();
    ?>
    <select name="ial_loyalty_settings[basis]" class="regular-text">
        <?php foreach (ial_loyalty_get_basis_options() as $key => $label): ?>
            <option value="<?php echo esc_attr($key); ?>" <?php selected($s['basis'], $key); ?>>
                <?php echo esc_html($label); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="description">
        <?php esc_html_e('"Different products" counts each model once, so registering several units of the same product does not level anyone up. "Serials" counts every unit.', 'ial-reg'); ?>
    </p>
    <?php
}

function ial_loyalty_render_field_tiers()
{
    $s     = ial_loyalty_get_settings();
    $tiers = $s['tiers'];
    if (empty($tiers)) {
        $tiers = array(array('threshold' => 1, 'percent' => 5, 'label' => ''));
    }
    ?>
    <div class="ial-tiers" id="ial-tiers">
        <div class="ial-tiers-head">
            <span><?php esc_html_e('From this many', 'ial-reg'); ?></span>
            <span><?php esc_html_e('Discount %', 'ial-reg'); ?></span>
            <span><?php esc_html_e('Name (optional)', 'ial-reg'); ?></span>
            <span></span>
        </div>

        <div class="ial-tiers-rows">
            <?php foreach ($tiers as $index => $tier): ?>
                <div class="ial-tier-row">
                    <input type="number" min="1" step="1" required
                        name="ial_loyalty_settings[tiers][<?php echo (int) $index; ?>][threshold]"
                        value="<?php echo esc_attr($tier['threshold']); ?>">
                    <input type="number" min="0" max="100" step="0.01" required
                        name="ial_loyalty_settings[tiers][<?php echo (int) $index; ?>][percent]"
                        value="<?php echo esc_attr($tier['percent']); ?>">
                    <input type="text"
                        name="ial_loyalty_settings[tiers][<?php echo (int) $index; ?>][label]"
                        value="<?php echo esc_attr($tier['label']); ?>"
                        placeholder="<?php esc_attr_e('e.g. Collector', 'ial-reg'); ?>">
                    <button type="button" class="button-link ial-tier-remove"
                        aria-label="<?php esc_attr_e('Remove tier', 'ial-reg'); ?>">&times;</button>
                </div>
            <?php endforeach; ?>
        </div>

        <p>
            <button type="button" class="button" id="ial-tier-add"><?php esc_html_e('+ Add tier', 'ial-reg'); ?></button>
        </p>
    </div>

    <template id="ial-tier-template">
        <div class="ial-tier-row">
            <input type="number" min="1" step="1" required name="ial_loyalty_settings[tiers][__i__][threshold]" value="">
            <input type="number" min="0" max="100" step="0.01" required name="ial_loyalty_settings[tiers][__i__][percent]" value="">
            <input type="text" name="ial_loyalty_settings[tiers][__i__][label]" value=""
                placeholder="<?php esc_attr_e('e.g. Collector', 'ial-reg'); ?>">
            <button type="button" class="button-link ial-tier-remove"
                aria-label="<?php esc_attr_e('Remove tier', 'ial-reg'); ?>">&times;</button>
        </div>
    </template>

    <p class="description">
        <?php esc_html_e('Add as many layers as you need. One row with 10% is a flat discount; several rows make it incremental. Rows are sorted by threshold when saved, and empty or zero rows are dropped.', 'ial-reg'); ?>
    </p>
    <?php
}

function ial_loyalty_render_field_max_percent()
{
    $s = ial_loyalty_get_settings();
    ?>
    <input type="number" min="0" max="100" step="0.01" class="small-text"
        name="ial_loyalty_settings[max_percent]" value="<?php echo esc_attr($s['max_percent']); ?>"> %
    <p class="description">
        <?php esc_html_e('Safety net: no customer is ever discounted more than this, whatever the tiers say.', 'ial-reg'); ?>
    </p>
    <?php
}

function ial_loyalty_render_field_filter()
{
    $s = ial_loyalty_get_settings();

    if (!class_exists('WooCommerce')) {
        echo '<p class="description">' . esc_html__('Available once WooCommerce is active.', 'ial-reg') . '</p>';
        return;
    }
    ?>
    <fieldset class="ial-filter">
        <p>
            <label>
                <input type="radio" name="ial_loyalty_settings[filter_mode]" value="exclude"
                    <?php checked($s['filter_mode'], 'exclude'); ?>>
                <?php esc_html_e('Everywhere except what I pick below', 'ial-reg'); ?>
            </label><br>
            <label>
                <input type="radio" name="ial_loyalty_settings[filter_mode]" value="include"
                    <?php checked($s['filter_mode'], 'include'); ?>>
                <?php esc_html_e('Only on what I pick below', 'ial-reg'); ?>
            </label>
        </p>

        <p>
            <label class="ial-filter-label" for="ial_loyalty_filter_cats">
                <?php esc_html_e('Categories', 'ial-reg'); ?>
            </label>
            <input type="hidden" name="ial_loyalty_settings[filter_cats][]" value="">
            <?php
            $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            if (is_wp_error($terms) || empty($terms)) {
                echo '<span class="description">' . esc_html__('No product categories found.', 'ial-reg') . '</span>';
            } else {
                ?>
                <select name="ial_loyalty_settings[filter_cats][]" id="ial_loyalty_filter_cats" multiple
                    class="wc-enhanced-select" style="width:100%; max-width:520px;"
                    data-placeholder="<?php esc_attr_e('Any category', 'ial-reg'); ?>">
                    <?php foreach ($terms as $term): ?>
                        <option value="<?php echo esc_attr($term->term_id); ?>"
                            <?php selected(in_array((int) $term->term_id, $s['filter_cats'], true)); ?>>
                            <?php echo esc_html($term->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php
            }
            ?>
        </p>

        <p>
            <label class="ial-filter-label" for="ial_loyalty_filter_products">
                <?php esc_html_e('Individual products', 'ial-reg'); ?>
            </label>
            <input type="hidden" name="ial_loyalty_settings[filter_products][]" value="">
            <select name="ial_loyalty_settings[filter_products][]" id="ial_loyalty_filter_products" multiple
                class="wc-product-search" style="width:100%; max-width:520px;"
                data-placeholder="<?php esc_attr_e('Search for a product…', 'ial-reg'); ?>"
                data-action="woocommerce_json_search_products_and_variations">
                <?php
                foreach ($s['filter_products'] as $product_id) {
                    $product = wc_get_product($product_id);
                    if (!$product) {
                        continue;
                    }
                    printf(
                        '<option value="%1$s" selected>%2$s</option>',
                        esc_attr($product_id),
                        esc_html(wp_strip_all_tags($product->get_formatted_name()))
                    );
                }
                ?>
            </select>
        </p>
    </fieldset>

    <p class="description">
        <?php esc_html_e('Categories include everything filed underneath them, so picking a parent covers its subcategories. Categories and products add up: a product qualifies if it matches either list.', 'ial-reg'); ?>
    </p>
    <p class="description">
        <?php esc_html_e('Leave both lists empty to apply the discount to the whole catalogue. On "only on what I pick", empty lists mean nothing qualifies and no discount is ever applied.', 'ial-reg'); ?>
    </p>
    <?php
}

function ial_loyalty_render_field_display()
{
    $s = ial_loyalty_get_settings();
    ?>
    <label style="display:block; margin-bottom:6px;">
        <input type="checkbox" name="ial_loyalty_settings[show_notice]" value="1" <?php checked($s['show_notice'], 1); ?>>
        <?php esc_html_e('Show a notice on the product page ("you have X% off, applied in the cart")', 'ial-reg'); ?>
    </label>
    <label style="display:block;">
        <input type="checkbox" name="ial_loyalty_settings[show_collection]" value="1" <?php checked($s['show_collection'], 1); ?>>
        <?php esc_html_e('Show the collection and progress panel in My Account', 'ial-reg'); ?>
    </label>
    <p class="description">
        <?php esc_html_e('Catalogue prices are left untouched on purpose: the discount is applied in the cart, so page caching stays safe.', 'ial-reg'); ?>
    </p>
    <?php
}

/** Whole-option sanitizer. */
function ial_loyalty_sanitize_settings($input)
{
    $defaults = ial_loyalty_default_settings();
    if (!is_array($input)) {
        return $defaults;
    }

    $basis_options = ial_loyalty_get_basis_options();

    $out = array(
        'enabled'         => !empty($input['enabled']) ? 1 : 0,
        'basis'           => (isset($input['basis']) && array_key_exists($input['basis'], $basis_options))
            ? $input['basis']
            : $defaults['basis'],
        'tiers'           => ial_loyalty_normalize_tiers(isset($input['tiers']) ? $input['tiers'] : array()),
        'max_percent'     => ial_loyalty_clamp_percent(isset($input['max_percent']) ? $input['max_percent'] : $defaults['max_percent']),
        'filter_mode'     => (isset($input['filter_mode']) && 'include' === $input['filter_mode'])
            ? 'include'
            : 'exclude',
        'filter_cats'     => ial_loyalty_clean_id_list(isset($input['filter_cats']) ? $input['filter_cats'] : array()),
        'filter_products' => ial_loyalty_clean_id_list(isset($input['filter_products']) ? $input['filter_products'] : array()),
        'show_notice'     => !empty($input['show_notice']) ? 1 : 0,
        'show_collection' => !empty($input['show_collection']) ? 1 : 0,
    );

    if ($out['enabled'] && 'include' === $out['filter_mode']
        && empty($out['filter_cats']) && empty($out['filter_products'])) {
        add_settings_error(
            'ial_loyalty_settings',
            'ial_loyalty_empty_include',
            __('The discount is set to apply only to selected categories or products, but nothing is selected — so no product qualifies and no discount will be applied. Pick something, or switch back to "everywhere except".', 'ial-reg'),
            'warning'
        );
    }

    if ($out['enabled'] && empty($out['tiers'])) {
        add_settings_error(
            'ial_loyalty_settings',
            'ial_loyalty_no_tiers',
            __('The loyalty discount is enabled but has no valid tiers, so nothing will be applied. Add at least one tier with a threshold and a percentage.', 'ial-reg'),
            'warning'
        );
    }

    return $out;
}

/** Scripts and styles for the tier repeater. */
add_action('admin_enqueue_scripts', 'ial_loyalty_admin_assets', 20);
function ial_loyalty_admin_assets($hook_suffix)
{
    $screen = get_current_screen();
    if (!$screen || false === strpos((string) $screen->id, 'ial-reg-settings')) {
        return;
    }

    wp_enqueue_style(
        'ial-admin-loyalty',
        plugin_dir_url(__FILE__) . '../../assets/css/admin-loyalty.css',
        array(),
        IAL_REG_VERSION
    );
    wp_enqueue_script(
        'ial-admin-loyalty',
        plugin_dir_url(__FILE__) . '../../assets/js/admin-loyalty.js',
        array(),
        IAL_REG_VERSION,
        true
    );

    // WooCommerce's searchable multi-select, if it is around.
    if (class_exists('WooCommerce') && wp_script_is('wc-enhanced-select', 'registered')) {
        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('woocommerce_admin_styles');
    }
}
