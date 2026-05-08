<?php

if (!defined('ABSPATH')) {
    exit;
}


// Register Meta Boxes.

function ial_reg_add_meta_boxes()
{
    // 1. IAL PRODUCT
    add_meta_box(
        'ial_product_details',
        __('Product Details', 'ial-reg'),
        'ial_render_product_metabox',
        'ial_product',
        'normal',
        'high'
    );

    // 2. IAL PRODUCTION
    add_meta_box(
        'ial_production_details',
        __('Production Details', 'ial-reg'),
        'ial_render_production_metabox',
        'ial_production',
        'normal',
        'high'
    );

    // Batch Creation Field on Serial (Only on Add New)
    add_meta_box(
        'ial_production_batch',
        __('Batch Generation', 'ial-reg'),
        'ial_render_production_batch_metabox',
        'ial_serial',
        'normal',
        'high'
    );

    // 3. IAL SERIAL
    add_meta_box(
        'ial_serial_details',
        __('Serial Details', 'ial-reg'),
        'ial_render_serial_metabox',
        'ial_serial',
        'normal',
        'high'
    );

    // Hide Standard Custom Fields for all plugin CPTs
    remove_meta_box('postcustom', 'ial_product', 'normal');
    remove_meta_box('postcustom', 'ial_production', 'normal');
    remove_meta_box('postcustom', 'ial_serial', 'normal');
    remove_meta_box('postcustom', 'ial_email_campaign', 'normal');
}
add_action('add_meta_boxes', 'ial_reg_add_meta_boxes');

// Render Product Meta Box.
function ial_render_product_metabox($post)
{
    wp_nonce_field('ial_save_product_meta', 'ial_product_nonce');

    $product_notes = get_post_meta($post->ID, 'product_notes', true);
    $notes = get_post_meta($post->ID, 'notes', true);
    $enable = get_post_meta($post->ID, 'frontend_enable', true);
    $mailing_list = get_post_meta($post->ID, 'acymailing_list_id', true);
    $image_id = get_post_meta($post->ID, 'product_image', true);
    $assign_roles = get_post_meta($post->ID, 'assign_roles', true);
    if (!is_array($assign_roles)) {
        $assign_roles = array();
    }

    // Default checked if new
    if ($post->post_status === 'auto-draft' && get_post_meta($post->ID, 'frontend_enable', true) === '') {
        $enable = 1;
    }

    // AcyMailing List Population Logic
    $acym_options = array('' => __('No active lists found / Not installed', 'ial-reg'));
    global $wpdb;
    $acym_table = $wpdb->prefix . 'acym_list';
    if ($wpdb->get_var("SHOW TABLES LIKE '$acym_table'") === $acym_table) {
        $lists = $wpdb->get_results("SELECT id, name FROM $acym_table WHERE active = 1 ORDER BY name ASC");
        if ($lists) {
            $acym_options = array('' => __('-- Select List --', 'ial-reg'));
            foreach ($lists as $list) {
                $acym_options[$list->id] = $list->name . ' (ID: ' . $list->id . ')';
            }
        }
    }

    // Image Logic
    $img_url = '';
    if ($image_id) {
        $img_url = wp_get_attachment_image_url($image_id, 'thumbnail');
    }
    ?>
    <table class="form-table">
        <tr>
            <th><label for="ial_product_notes"><?php esc_html_e('Public Notes', 'ial-reg'); ?></label></th>
            <td>
                <textarea name="product_notes" id="ial_product_notes" rows="3"
                    class="large-text"><?php echo esc_textarea($product_notes); ?></textarea>
                <p class="description"><?php esc_html_e('Notes visible to the customer.', 'ial-reg'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="ial_notes"><?php esc_html_e('Internal Notes', 'ial-reg'); ?></label></th>
            <td>
                <textarea name="notes" id="ial_notes" rows="3"
                    class="large-text"><?php echo esc_textarea($notes); ?></textarea>
                <p class="description"><?php esc_html_e('Internal use only.', 'ial-reg'); ?></p>
            </td>
        </tr>

        <tr>
            <th><label for="ial_frontend_enable"><?php esc_html_e('Show on Frontend?', 'ial-reg'); ?></label></th>
            <td>
                <input type="checkbox" name="frontend_enable" id="ial_frontend_enable" value="1" <?php checked($enable, 1); ?>>
                <span
                    class="description"><?php esc_html_e('If checked, this product appears in the user\'s "My Products" list.', 'ial-reg'); ?></span>
            </td>
        </tr>

        <tr>
            <th><label for="ial_acymailing_list_id"><?php esc_html_e('AcyMailing List', 'ial-reg'); ?></label></th>
            <td>
                <select name="acymailing_list_id" id="ial_acymailing_list_id" class="regular-text">
                    <?php foreach ($acym_options as $opt_val => $opt_label): ?>
                        <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($mailing_list, $opt_val); ?>>
                            <?php echo esc_html($opt_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php esc_html_e('Select the list to subscribe users to upon registration.', 'ial-reg'); ?></p>
            </td>
        </tr>

        <tr>
            <th><label><?php esc_html_e('Roles to assign on registration', 'ial-reg'); ?></label></th>
            <td>
                <?php
                $available_roles = wp_roles()->get_names();
                if (empty($available_roles)) {
                    echo '<p class="description">' . esc_html__('No roles available.', 'ial-reg') . '</p>';
                } else {
                    echo '<fieldset class="ial-assign-roles" style="max-height:180px; overflow-y:auto; border:1px solid #ddd; padding:8px; background:#fafafa; max-width:400px;">';
                    foreach ($available_roles as $role_key => $role_label) {
                        printf(
                            '<label style="display:block; margin-bottom:4px;"><input type="checkbox" name="assign_roles[]" value="%1$s" %2$s> %3$s <code>%1$s</code></label>',
                            esc_attr($role_key),
                            checked(in_array($role_key, $assign_roles, true), true, false),
                            esc_html(translate_user_role($role_label))
                        );
                    }
                    echo '</fieldset>';
                }
                ?>
                <p class="description">
                    <?php esc_html_e('When a user registers a serial of this product, the selected role(s) will be added to their account (additive — existing roles are kept).', 'ial-reg'); ?>
                </p>
                <p class="description" style="color:#b32d2e;">
                    <strong><?php esc_html_e('Note:', 'ial-reg'); ?></strong>
                    <?php esc_html_e('Already-registered users will not receive these roles automatically when this configuration changes. Only future registrations are affected.', 'ial-reg'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th><label for="ial_product_image"><?php esc_html_e('Product Image', 'ial-reg'); ?></label></th>
            <td>
                <div class="ial-image-preview-wrapper" style="margin-bottom:10px;">
                    <div class="ial-image-preview">
                        <?php if ($img_url): ?>
                            <img src="<?php echo esc_url($img_url); ?>"
                                style="max-width:100px; border:1px solid #ddd; padding:2px;">
                        <?php endif; ?>
                    </div>
                </div>

                <input type="hidden" name="product_image" id="ial_product_image" class="ial-image-id"
                    value="<?php echo esc_attr($image_id); ?>">

                <button type="button"
                    class="button ial-select-image-btn"><?php esc_html_e('Select Image', 'ial-reg'); ?></button>
                <button type="button" class="button ial-remove-image-btn"
                    style="<?php echo $image_id ? '' : 'display:none;'; ?>"><?php esc_html_e('Remove', 'ial-reg'); ?></button>

                <p class="description"><?php esc_html_e('Select an image from the Media Library.', 'ial-reg'); ?></p>
            </td>
        </tr>
    </table>
    <?php
}

// Render Production Meta Box
function ial_render_production_metabox($post)
{
    wp_nonce_field('ial_save_production_meta', 'ial_production_nonce');

    $product_id = get_post_meta($post->ID, 'product', true);
    $hardware_version = get_post_meta($post->ID, 'hardware_version', true);
    $software_version = get_post_meta($post->ID, 'software_version', true);
    $production_date = get_post_meta($post->ID, 'production_date', true);
    $notes = get_post_meta($post->ID, 'notes', true);

    // Get all products for dropdown
    $products = get_posts(array('post_type' => 'ial_product', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC'));
    ?>
    <table class="form-table">
        <tr>
            <th><label for="ial_prod_product"><?php esc_html_e('Product', 'ial-reg'); ?> *</label></th>
            <td>
                <select name="product" id="ial_prod_product" class="regular-text" required>
                    <option value=""><?php esc_html_e('-- Select Product --', 'ial-reg'); ?></option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($product_id, $p->ID); ?>>
                            <?php echo esc_html($p->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="ial_hardware_version"><?php esc_html_e('Hardware Version', 'ial-reg'); ?></label></th>
            <td><input type="text" name="hardware_version" id="ial_hardware_version"
                    value="<?php echo esc_attr($hardware_version); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="ial_software_version"><?php esc_html_e('Software Version', 'ial-reg'); ?></label></th>
            <td><input type="text" name="software_version" id="ial_software_version"
                    value="<?php echo esc_attr($software_version); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="ial_production_date"><?php esc_html_e('Production Date', 'ial-reg'); ?> *</label></th>
            <td>
                <input type="date" name="production_date" id="ial_production_date"
                    value="<?php echo esc_attr($production_date); ?>" class="regular-text" required>
            </td>
        </tr>
        <tr>
            <th><label for="ial_prod_notes"><?php esc_html_e('Notes', 'ial-reg'); ?></label></th>
            <td><textarea name="notes" id="ial_prod_notes" rows="3"
                    class="large-text"><?php echo esc_textarea($notes); ?></textarea></td>
        </tr>
    </table>
    <?php
}

// Render Serial Meta Box
function ial_render_serial_metabox($post)
{
    wp_nonce_field('ial_save_serial_meta', 'ial_serial_nonce');

    $production_id = get_post_meta($post->ID, 'production', true);
    $uid = get_post_meta($post->ID, 'uid', true);
    $a_uid = get_post_meta($post->ID, 'a_uid', true);
    $u_name = get_post_meta($post->ID, 'u_name', true);
    $purchase = get_post_meta($post->ID, 'purchase', true);
    $seller = get_post_meta($post->ID, 'seller', true);
    $distributor = get_post_meta($post->ID, 'distributor', true);
    $notes = get_post_meta($post->ID, 'notes', true);

    $productions = get_posts(array('post_type' => 'ial_production', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC'));

    // Determine User Display String
    $user_display = '';
    if ($uid) {
        $user_obj = get_userdata($uid);
        if ($user_obj) {
            $user_display = ' (User: ' . $user_obj->display_name . ')';
        } else {
            $user_display = ' (Invalid User ID)';
        }
    }

    // Logic from admin-batch.php: Hide user fields on creation
    $is_new = ($post->post_status == 'auto-draft');
    $user_fields_style = $is_new ? 'display:none;' : '';
    ?>
    <table class="form-table">
        <tr>
            <th><label for="ial_serial_production"><?php esc_html_e('Production Batch', 'ial-reg'); ?> *</label></th>
            <td>
                <select name="production" id="ial_serial_production" class="regular-text" required>
                    <option value=""><?php esc_html_e('-- Select Production --', 'ial-reg'); ?></option>
                    <?php foreach ($productions as $prod):
                        $prod_title = $prod->post_title;
                        if (empty($prod_title))
                            $prod_title = 'ID: ' . $prod->ID;
                        ?>
                        <option value="<?php echo esc_attr($prod->ID); ?>" <?php selected($production_id, $prod->ID); ?>>
                            <?php echo esc_html($prod_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <tr>
            <th><label for="ial_serial_distributor"><?php esc_html_e('Distributor', 'ial-reg'); ?></label></th>
            <td><input type="text" name="distributor" id="ial_serial_distributor"
                    value="<?php echo esc_attr($distributor); ?>" class="regular-text"></td>
        </tr>

        <tr>
            <th><label for="ial_serial_notes"><?php esc_html_e('Notes', 'ial-reg'); ?></label></th>
            <td><textarea name="notes" id="ial_serial_notes" rows="3"
                    class="large-text"><?php echo esc_textarea($notes); ?></textarea></td>
        </tr>

        <tr style="<?php echo esc_attr($user_fields_style); ?>">
            <th><label><?php esc_html_e('User Registration Data', 'ial-reg'); ?></label></th>
            <td>
                <p>
                    <label for="ial_u_name"><?php esc_html_e('Name:', 'ial-reg'); ?></label><br>
                    <input type="text" name="u_name" id="ial_u_name" value="<?php echo esc_attr($u_name); ?>"
                        class="regular-text">
                </p>
                <p>
                    <label for="ial_purchase"><?php esc_html_e('Purchase Date:', 'ial-reg'); ?></label><br>
                    <input type="text" name="purchase" id="ial_purchase" value="<?php echo esc_attr($purchase); ?>"
                        class="regular-text">
                </p>
                <p>
                    <label for="ial_seller"><?php esc_html_e('Seller:', 'ial-reg'); ?></label><br>
                    <input type="text" name="seller" id="ial_seller" value="<?php echo esc_attr($seller); ?>"
                        class="regular-text">
                </p>
                <p>
                    <label for="ial_uid"><?php esc_html_e('User ID:', 'ial-reg'); ?></label><br>
                    <input type="number" name="uid" id="ial_uid" value="<?php echo esc_attr($uid); ?>" class="small-text">
                    <span class="description"><?php echo esc_html($user_display); ?></span>
                </p>
                <p>
                    <label for="ial_a_uid"><?php esc_html_e('AcyMailing User ID:', 'ial-reg'); ?></label><br>
                    <input type="number" name="a_uid" id="ial_a_uid" value="<?php echo esc_attr($a_uid); ?>"
                        class="small-text">
                </p>
            </td>
        </tr>
    </table>
    <?php
}

// Render Batch Generation Meta Box (Only on Add New).
function ial_render_production_batch_metabox($post)
{
    $is_new = ($post->post_status == 'auto-draft' || empty($post->post_title));
    if (!$is_new && $post->post_status === 'publish') {
        echo '<p class="description">' . esc_html__('Batch generation is only available when creating new serials.', 'ial-reg') . '</p>';
        return;
    }

    ?>
    <p><strong><?php esc_html_e('Enter one serial number per line.', 'ial-reg'); ?></strong></p>
    <textarea name="add_serials" id="ial_add_serials" rows="10" class="large-text"
        placeholder="SN-001&#10;SN-002&#10;SN-003"></textarea>
    <p class="description">
        <?php esc_html_e('This will create multiple Serial posts at once. Ensure a Production Batch is selected above.', 'ial-reg'); ?>
    </p>
    <?php
}

// Save Meta Data
add_action('save_post', 'ial_save_plugin_meta_data');
function ial_save_plugin_meta_data($post_id)
{
    // Check if valid save
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;

    // 1. Save Product
    if (isset($_POST['ial_product_nonce']) && wp_verify_nonce($_POST['ial_product_nonce'], 'ial_save_product_meta')) {
        if (isset($_POST['product_notes']))
            update_post_meta($post_id, 'product_notes', sanitize_textarea_field($_POST['product_notes']));
        if (isset($_POST['notes']))
            update_post_meta($post_id, 'notes', sanitize_textarea_field($_POST['notes']));

        $enable = isset($_POST['frontend_enable']) ? 1 : 0;
        update_post_meta($post_id, 'frontend_enable', $enable);

        if (isset($_POST['acymailing_list_id']))
            update_post_meta($post_id, 'acymailing_list_id', sanitize_text_field($_POST['acymailing_list_id']));
        if (isset($_POST['product_image']))
            update_post_meta($post_id, 'product_image', sanitize_text_field($_POST['product_image']));

        // Roles to assign on registration. Filter against currently registered roles.
        $submitted_roles = isset($_POST['assign_roles']) && is_array($_POST['assign_roles'])
            ? array_map('sanitize_key', $_POST['assign_roles'])
            : array();
        $valid_role_keys = array_keys(wp_roles()->get_names());
        $clean_roles = array_values(array_unique(array_intersect($submitted_roles, $valid_role_keys)));
        update_post_meta($post_id, 'assign_roles', $clean_roles);
    }

    // 2. Save Production
    if (isset($_POST['ial_production_nonce']) && wp_verify_nonce($_POST['ial_production_nonce'], 'ial_save_production_meta')) {
        if (isset($_POST['product']))
            update_post_meta($post_id, 'product', sanitize_text_field($_POST['product']));
        if (isset($_POST['hardware_version']))
            update_post_meta($post_id, 'hardware_version', sanitize_text_field($_POST['hardware_version']));
        if (isset($_POST['software_version']))
            update_post_meta($post_id, 'software_version', sanitize_text_field($_POST['software_version']));
        if (isset($_POST['production_date']))
            update_post_meta($post_id, 'production_date', sanitize_text_field($_POST['production_date']));
        if (isset($_POST['notes']))
            update_post_meta($post_id, 'notes', sanitize_textarea_field($_POST['notes']));
    }

    // 3. Save Serial
    if (isset($_POST['ial_serial_nonce']) && wp_verify_nonce($_POST['ial_serial_nonce'], 'ial_save_serial_meta')) {
        if (isset($_POST['production']))
            update_post_meta($post_id, 'production', sanitize_text_field($_POST['production']));
        if (isset($_POST['distributor']))
            update_post_meta($post_id, 'distributor', sanitize_text_field($_POST['distributor']));
        if (isset($_POST['notes']))
            update_post_meta($post_id, 'notes', sanitize_textarea_field($_POST['notes']));

        // User fields
        if (isset($_POST['u_name']))
            update_post_meta($post_id, 'u_name', sanitize_text_field($_POST['u_name']));
        if (isset($_POST['purchase']))
            update_post_meta($post_id, 'purchase', sanitize_text_field($_POST['purchase']));
        if (isset($_POST['seller']))
            update_post_meta($post_id, 'seller', sanitize_text_field($_POST['seller']));
        if (isset($_POST['uid']))
            update_post_meta($post_id, 'uid', sanitize_text_field($_POST['uid']));
        if (isset($_POST['a_uid']))
            update_post_meta($post_id, 'a_uid', sanitize_text_field($_POST['a_uid']));

        // Handle Batch Serials (Special Logic)
        if (isset($_POST['add_serials']) && !empty($_POST['add_serials'])) {
        }
    }
}
