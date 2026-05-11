<?php

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Quick Edit — custom box on the ial_product list.
// ---------------------------------------------------------------------------

add_action('quick_edit_custom_box', 'ial_render_product_quick_edit_box', 10, 2);
function ial_render_product_quick_edit_box($column_name, $post_type)
{
    if ($post_type !== 'ial_product') {
        return;
    }
    // Render only once — the hook fires for every custom column. Pin it to one.
    if ($column_name !== 'frontend_enable') {
        return;
    }

    $acym_options = ial_inline_edit_acym_options();
    $roles        = wp_roles()->get_names();
    ?>
    <fieldset class="inline-edit-col-right inline-edit-ial-product">
        <div class="inline-edit-col">
            <h4><?php esc_html_e('Product Serials', 'ial-reg'); ?></h4>

            <?php wp_nonce_field('ial_inline_edit_product', 'ial_inline_edit_nonce'); ?>

            <label class="alignleft">
                <input type="checkbox" name="frontend_enable" value="1">
                <span class="checkbox-title"><?php esc_html_e('Show on Frontend', 'ial-reg'); ?></span>
            </label>

            <br class="clear">

            <label>
                <span class="title"><?php esc_html_e('AcyMailing List', 'ial-reg'); ?></span>
                <select name="acymailing_list_id">
                    <option value=""><?php esc_html_e('— None —', 'ial-reg'); ?></option>
                    <?php foreach ($acym_options as $id => $name): ?>
                        <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span class="title"><?php esc_html_e('Assigned Roles', 'ial-reg'); ?></span>
                <select name="assign_roles[]" multiple size="4" style="height:auto; min-height:60px;">
                    <?php foreach ($roles as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html(translate_user_role($label)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </fieldset>
    <?php
}

// ---------------------------------------------------------------------------
// Bulk Edit — custom box on the ial_product list (subset of fields).
// ---------------------------------------------------------------------------

add_action('bulk_edit_custom_box', 'ial_render_product_bulk_edit_box', 10, 2);
function ial_render_product_bulk_edit_box($column_name, $post_type)
{
    if ($post_type !== 'ial_product') {
        return;
    }
    if ($column_name !== 'frontend_enable') {
        return;
    }

    $acym_options = ial_inline_edit_acym_options();
    ?>
    <fieldset class="inline-edit-col-right inline-edit-ial-product">
        <div class="inline-edit-col">
            <h4><?php esc_html_e('Product Serials', 'ial-reg'); ?></h4>

            <?php wp_nonce_field('ial_inline_edit_product', 'ial_inline_edit_nonce'); ?>

            <label>
                <span class="title"><?php esc_html_e('Show on Frontend', 'ial-reg'); ?></span>
                <select name="ial_bulk_frontend_enable">
                    <option value="-1"><?php esc_html_e('— No change —', 'ial-reg'); ?></option>
                    <option value="1"><?php esc_html_e('Yes', 'ial-reg'); ?></option>
                    <option value="0"><?php esc_html_e('No', 'ial-reg'); ?></option>
                </select>
            </label>

            <label>
                <span class="title"><?php esc_html_e('AcyMailing List', 'ial-reg'); ?></span>
                <select name="ial_bulk_acymailing_list_id">
                    <option value="-1"><?php esc_html_e('— No change —', 'ial-reg'); ?></option>
                    <option value=""><?php esc_html_e('None', 'ial-reg'); ?></option>
                    <?php foreach ($acym_options as $id => $name): ?>
                        <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </fieldset>
    <?php
}

// ---------------------------------------------------------------------------
// Save Quick Edit + Bulk Edit submissions.
// ---------------------------------------------------------------------------

add_action('save_post_ial_product', 'ial_save_product_inline_edit', 20, 2);
function ial_save_product_inline_edit($post_id, $post)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id) || $post->post_type !== 'ial_product') {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Quick Edit goes through admin-ajax with action=inline-save and the
    // 'inlineeditnonce' nonce. Bulk Edit posts to edit.php with bulk_edit set
    // and the 'bulk-posts' nonce verified by the bulk handler itself.
    $is_quick_edit = isset($_REQUEST['action'], $_REQUEST['_inline_edit'])
        && $_REQUEST['action'] === 'inline-save'
        && wp_verify_nonce($_REQUEST['_inline_edit'], 'inlineeditnonce');

    $is_bulk_edit = isset($_REQUEST['bulk_edit']) || isset($_REQUEST['ial_bulk_frontend_enable']) || isset($_REQUEST['ial_bulk_acymailing_list_id']);

    if (!$is_quick_edit && !$is_bulk_edit) {
        return;
    }

    // Defense in depth: confirm our own nonce is present.
    if (empty($_REQUEST['ial_inline_edit_nonce']) || !wp_verify_nonce($_REQUEST['ial_inline_edit_nonce'], 'ial_inline_edit_product')) {
        return;
    }

    if ($is_quick_edit) {
        // Checkbox: missing means unchecked.
        $enable = isset($_REQUEST['frontend_enable']) ? 1 : 0;
        update_post_meta($post_id, 'frontend_enable', $enable);

        if (isset($_REQUEST['acymailing_list_id'])) {
            update_post_meta($post_id, 'acymailing_list_id', sanitize_text_field($_REQUEST['acymailing_list_id']));
        }

        $valid_roles = array_keys(wp_roles()->get_names());
        $submitted   = isset($_REQUEST['assign_roles']) && is_array($_REQUEST['assign_roles'])
            ? array_map('sanitize_key', $_REQUEST['assign_roles'])
            : array();
        $clean_roles = array_values(array_unique(array_intersect($submitted, $valid_roles)));
        update_post_meta($post_id, 'assign_roles', $clean_roles);
        return;
    }

    // Bulk Edit: only touch a field if its value isn't the "no change" sentinel.
    if (isset($_REQUEST['ial_bulk_frontend_enable']) && $_REQUEST['ial_bulk_frontend_enable'] !== '-1') {
        update_post_meta($post_id, 'frontend_enable', $_REQUEST['ial_bulk_frontend_enable'] === '1' ? 1 : 0);
    }
    if (isset($_REQUEST['ial_bulk_acymailing_list_id']) && $_REQUEST['ial_bulk_acymailing_list_id'] !== '-1') {
        update_post_meta($post_id, 'acymailing_list_id', sanitize_text_field($_REQUEST['ial_bulk_acymailing_list_id']));
    }
}

// ---------------------------------------------------------------------------
// Shared AcyMailing list options for the inline edit selectors.
// ---------------------------------------------------------------------------

function ial_inline_edit_acym_options()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = array();
    global $wpdb;
    $table = $wpdb->prefix . 'acym_list';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
        $rows = $wpdb->get_results("SELECT id, name FROM $table WHERE active = 1 ORDER BY name ASC");
        foreach ((array) $rows as $r) {
            $cache[(int) $r->id] = $r->name . ' (ID: ' . $r->id . ')';
        }
    }
    return $cache;
}
