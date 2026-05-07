<?php

if (!defined('ABSPATH')) {
    exit;
}

// Add Campaign Details Meta Boxes.
add_action('add_meta_boxes', 'ial_add_campaign_metaboxes');
function ial_add_campaign_metaboxes()
{
    add_meta_box(
        'ial_campaign_details',
        __('Campaign Details', 'ial-reg'),
        'ial_render_campaign_details_metabox',
        'ial_email_campaign',
        'normal',
        'high'
    );

    add_meta_box(
        'ial_campaign_actions',
        __('Campaign Actions', 'ial-reg'),
        'ial_render_campaign_actions',
        'ial_email_campaign',
        'side',
        'high'
    );
}

// Render Campaign Details Meta Box.
function ial_render_campaign_details_metabox($post)
{
    wp_nonce_field('ial_save_campaign_meta', 'ial_campaign_nonce');

    $target_type = get_post_meta($post->ID, 'target_type', true);
    $target_product = get_post_meta($post->ID, 'target_product', true);
    $target_production = get_post_meta($post->ID, 'target_production', true);
    $exclude_sent = get_post_meta($post->ID, 'exclude_previously_sent', true);
    $subject = get_post_meta($post->ID, 'email_subject', true);
    $body = get_post_meta($post->ID, 'email_body', true);

    // Default
    if (empty($exclude_sent) && $post->post_status == 'auto-draft')
        $exclude_sent = 1;

    $products = get_posts(array('post_type' => 'ial_product', 'numberposts' => -1, 'post_status' => 'publish'));
    $productions = get_posts(array('post_type' => 'ial_production', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC'));

    // Inline JS for Conditional Logic
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function ($) {
            function toggleTargetFields() {
                var type = $('#ial_target_type').val();
                if (type === 'product') {
                    $('.ial-target-product-row').show();
                    $('.ial-target-production-row').hide();
                } else {
                    $('.ial-target-product-row').hide();
                    $('.ial-target-production-row').show();
                }
            }
            $('#ial_target_type').on('change', toggleTargetFields);
            toggleTargetFields();
        });
    </script>

    <table class="form-table">
        <tr>
            <th><label for="ial_target_type"><?php esc_html_e('Target Audience', 'ial-reg'); ?></label></th>
            <td>
                <select name="target_type" id="ial_target_type" class="regular-text">
                    <option value="product" <?php selected($target_type, 'product'); ?>>
                        <?php esc_html_e('Product (All Productions)', 'ial-reg'); ?>
                    </option>
                    <option value="production" <?php selected($target_type, 'production'); ?>>
                        <?php esc_html_e('Specific Production', 'ial-reg'); ?>
                    </option>
                </select>
                <p class="description">
                    <?php esc_html_e('Choose whether to target all users of a specific Product or a specific Production batch.', 'ial-reg'); ?>
                </p>
            </td>
        </tr>

        <tr class="ial-target-product-row">
            <th><label for="ial_target_product"><?php esc_html_e('Select Product', 'ial-reg'); ?></label></th>
            <td>
                <select name="target_product" id="ial_target_product" class="regular-text">
                    <option value=""><?php esc_html_e('-- Select Product --', 'ial-reg'); ?></option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($target_product, $p->ID); ?>>
                            <?php echo esc_html($p->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <tr class="ial-target-production-row">
            <th><label for="ial_target_production"><?php esc_html_e('Select Production', 'ial-reg'); ?></label></th>
            <td>
                <select name="target_production" id="ial_target_production" class="regular-text">
                    <option value=""><?php esc_html_e('-- Select Production --', 'ial-reg'); ?></option>
                    <?php foreach ($productions as $p): ?>
                        <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($target_production, $p->ID); ?>>
                            <?php echo esc_html($p->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <tr>
            <th><label for="ial_exclude_sent"><?php esc_html_e('Incremental Sending', 'ial-reg'); ?></label></th>
            <td>
                <label>
                    <input type="checkbox" name="exclude_previously_sent" id="ial_exclude_sent" value="1" <?php checked($exclude_sent, 1); ?>>
                    <?php esc_html_e('Send ONLY to serials that have NOT received this email yet.', 'ial-reg'); ?>
                </label>
            </td>
        </tr>

        <tr>
            <th><label for="ial_email_subject"><?php esc_html_e('Email Subject', 'ial-reg'); ?></label></th>
            <td>
                <input type="text" name="email_subject" id="ial_email_subject" value="<?php echo esc_attr($subject); ?>"
                    class="large-text">
            </td>
        </tr>

        <tr>
            <th><label for="ial_email_body"><?php esc_html_e('Email Body', 'ial-reg'); ?></label></th>
            <td>
                <?php wp_editor($body, 'email_body', array('textarea_name' => 'email_body', 'media_buttons' => false, 'textarea_rows' => 10)); ?>
            </td>
        </tr>
    </table>
    <?php
}

// Save Campaign Meta.
add_action('save_post', 'ial_save_campaign_meta_data');
function ial_save_campaign_meta_data($post_id)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;
    if (!isset($_POST['ial_campaign_nonce']) || !wp_verify_nonce($_POST['ial_campaign_nonce'], 'ial_save_campaign_meta'))
        return;

    if (isset($_POST['target_type']))
        update_post_meta($post_id, 'target_type', sanitize_text_field($_POST['target_type']));
    if (isset($_POST['target_product']))
        update_post_meta($post_id, 'target_product', sanitize_text_field($_POST['target_product']));
    if (isset($_POST['target_production']))
        update_post_meta($post_id, 'target_production', sanitize_text_field($_POST['target_production']));

    $exclude = isset($_POST['exclude_previously_sent']) ? 1 : 0;
    update_post_meta($post_id, 'exclude_previously_sent', $exclude);

    if (isset($_POST['email_subject']))
        update_post_meta($post_id, 'email_subject', sanitize_text_field($_POST['email_subject']));
    if (isset($_POST['email_body']))
        update_post_meta($post_id, 'email_body', wp_kses_post($_POST['email_body']));
}

// Render Campaign Actions Meta Box.
function ial_render_campaign_actions($post)
{
    $last_sent_date = get_post_meta($post->ID, '_ial_campaign_last_sent_date', true);
    $total_emails = (int) get_post_meta($post->ID, '_ial_campaign_total_recipients', true);
    $total_serials = (int) get_post_meta($post->ID, '_ial_campaign_total_serials', true);

    $stats_label = $total_emails . ' ' . __('Users Emailed', 'ial-reg');
    if ($total_serials > 0) {
        $stats_label .= ' (' . $total_serials . ' ' . __('Serials', 'ial-reg') . ')';
    }
    ?>
    <div class="ial-campaign-actions-wrapper">
        <?php if ($last_sent_date): ?>
            <div class="notice notice-info inline" style="margin-bottom: 15px;">
                <p>
                    <strong><?php esc_html_e('Campaign History:', 'ial-reg'); ?></strong><br>
                    <?php printf(esc_html__('Last Run: %s', 'ial-reg'), esc_html($last_sent_date)); ?><br>

                    <?php if ($total_emails > 0): ?>
                        <a href="#" class="ial-view-recipients" data-id="<?php echo esc_attr($post->ID); ?>"
                            style="text-decoration:none; border-bottom:1px dashed;">
                            <strong><?php echo esc_html($stats_label); ?></strong>
                            <span class="dashicons dashicons-visibility" style="font-size:14px; vertical-align:middle;"></span>
                        </a>
                    <?php else: ?>
                        <?php echo esc_html($stats_label); ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <p class="description">
                <?php esc_html_e('This campaign has not been sent yet.', 'ial-reg'); ?>
            </p>
        <?php endif; ?>

        <button type="button" id="ial-send-campaign-btn" class="button button-primary button-large"
            data-id="<?php echo esc_attr($post->ID); ?>" style="width:100%;">
            <span class="dashicons dashicons-email-alt" style="margin-top:3px;"></span>
            <?php esc_html_e('Send Emails', 'ial-reg'); ?>
        </button>

        <div id="ial-sending-status" style="margin-top:10px; display:none;">
            <span class="spinner" style="float:none; margin:0 5px 0 0;"></span>
            <span class="status-text"><?php esc_html_e('Processing...', 'ial-reg'); ?></span>
        </div>
    </div>
    <?php
}

// Load Admin JS for Campaign Sending and Log Modal.
add_action('admin_enqueue_scripts', 'ial_campaign_admin_scripts');
function ial_campaign_admin_scripts($hook)
{
    global $post_type;

    if ('ial_email_campaign' !== $post_type) {
        return;
    }

    $nonce = wp_create_nonce('ial_send_campaign_nonce');

    $css = "
        .ial-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 100001;
            display: none;
            justify-content: center;
            align-items: center;
        }
        .ial-modal-content {
            background: #fff;
            width: 600px;
            max-width: 90%;
            max-height: 80vh;
            overflow: hidden;
            border-radius: 4px;
            box-shadow: 0 5px 30px rgba(0,0,0,0.4);
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .ial-modal-header { padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; background: #f9f9f9; }
        .ial-modal-header h3 { margin: 0; font-size: 16px; color: #23282d; }
        .ial-modal-close { background: none; border: none; cursor: pointer; font-size: 20px; color: #666; line-height: 1; }
        .ial-modal-body { padding: 0; overflow-y: auto; flex-grow: 1; }
        .ial-log-table { width: 100%; border-collapse: collapse; }
        .ial-log-table th, .ial-log-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
        .ial-log-table th { background: #fff; position: sticky; top: 0; font-weight: 600; color: #555; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .ial-log-table tr:hover { background: #fafafa; }
        .ial-log-summary { padding: 10px 15px; background: #e5f5fa; border-bottom: 1px solid #ccd0d4; color: #00506e; font-size: 13px; }
    ";
    wp_add_inline_style('common', $css);

    $script = "
    jQuery(document).ready(function($){

        // --- Modal Logic ---
        var modalHTML = '<div class=\"ial-modal-overlay\"><div class=\"ial-modal-content\"><div class=\"ial-modal-header\"><h3>" . esc_js(__('Campaign Recipients Log', 'ial-reg')) . "</h3><button class=\"ial-modal-close\">&times;</button></div><div class=\"ial-modal-body\"><div style=\"padding:20px; text-align:center;\"><span class=\"spinner is-active\" style=\"float:none;\"></span></div></div></div></div>';

        if ($('.ial-modal-overlay').length === 0) {
            $('body').append(modalHTML);
        }
        var modal = $('.ial-modal-overlay');

        $(document).on('click', '.ial-view-recipients', function(e){
            e.preventDefault();
            var campaignId = $(this).data('id');
            modal.css('display', 'flex').hide().fadeIn(200);

            $.post( ajaxurl, {
                action: 'ial_fetch_campaign_recipients',
                campaign_id: campaignId,
                nonce: '" . $nonce . "'
            }, function(response) {
                if(response.success) {
                    modal.find('.ial-modal-body').html(response.data.html);
                } else {
                    modal.find('.ial-modal-body').html('<p style=\"padding:20px; color:red;\">' + response.data.message + '</p>');
                }
            });
        });

        $(document).on('click', '.ial-modal-close, .ial-modal-overlay', function(e){
            if (e.target !== this) return;
            modal.fadeOut(200);
            setTimeout(function(){
                modal.find('.ial-modal-body').html('<div style=\"padding:20px; text-align:center;\"><span class=\"spinner is-active\" style=\"float:none;\"></span></div>');
            }, 200);
        });

        // --- Sending Logic ---
        $('#ial-send-campaign-btn').on('click', function(e){
            e.preventDefault();

            if ( ! confirm('" . esc_js(__('Are you sure you want to run this campaign?', 'ial-reg')) . "') ) {
                return;
            }

            var btn = $(this);
            var statusBox = $('#ial-sending-status');
            var statusText = statusBox.find('.status-text');
            var spinner = statusBox.find('.spinner');
            var postId = btn.data('id');

            btn.prop('disabled', true);
            statusBox.show();
            spinner.addClass('is-active');
            statusText.css('color', 'inherit').text('" . esc_js(__('Processing...', 'ial-reg')) . "');

            $.post( ajaxurl, {
                action: 'ial_process_email_campaign',
                campaign_id: postId,
                nonce: '" . $nonce . "'
            }, function( response ) {
                if ( response.success ) {
                    statusText.text( response.data.message );
                    alert( response.data.message );
                    location.reload();
                } else {
                    statusText.css('color', '#d63638').text( response.data.message || 'Error' );
                    spinner.removeClass('is-active');
                    btn.prop('disabled', false);
                }
            }).fail(function() {
                statusText.css('color', '#d63638').text('" . esc_js(__('Server Error or Timeout.', 'ial-reg')) . "');
                spinner.removeClass('is-active');
                btn.prop('disabled', false);
            });
        });
    });
    ";
    wp_add_inline_script('jquery-core', $script);
}

// AJAX Handler: Fetch Recipients Log for Modal.
add_action('wp_ajax_ial_fetch_campaign_recipients', 'ial_fetch_campaign_recipients_ajax');
function ial_fetch_campaign_recipients_ajax()
{
    check_ajax_referer('ial_send_campaign_nonce', 'nonce');

    $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
    if (!$campaign_id)
        wp_send_json_error(array('message' => 'Invalid ID'));

    $meta_key = '_ial_sent_campaign_' . $campaign_id;

    // Retrieve Serials
    $serials = get_posts(array(
        'post_type' => 'ial_serial',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => $meta_key,
                'compare' => 'EXISTS',
            ),
        ),
    ));

    if (empty($serials)) {
        wp_send_json_error(array('message' => __('No records found.', 'ial-reg')));
    }

    // Calculate unique users for summary
    $unique_users = array();
    foreach ($serials as $s) {
        $u = get_post_meta($s->ID, 'uid', true);
        if ($u)
            $unique_users[$u] = true;
    }
    $user_count = count($unique_users);
    $serial_count = count($serials);

    ob_start();
    ?>
    <div class="ial-log-summary">
        <?php printf(esc_html__('Campaign Summary: the email was sent to %d unique users, covering a total of %d serial numbers.', 'ial-reg'), $user_count, $serial_count); ?>
    </div>
    <table class="ial-log-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Serial Number', 'ial-reg'); ?></th>
                <th><?php esc_html_e('Registered User', 'ial-reg'); ?></th>
                <th><?php esc_html_e('Sent Date', 'ial-reg'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($serials as $serial):
                $uid = get_post_meta($serial->ID, 'uid', true);
                $user_info = get_userdata($uid);
                $user_name = $user_info ? $user_info->display_name : '<em>' . __('Deleted User', 'ial-reg') . '</em>';

                $sent_timestamp = get_post_meta($serial->ID, $meta_key, true);
                $date_fmt = $sent_timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $sent_timestamp) : '—';
                ?>
                <tr>
                    <td><a href="<?php echo get_edit_post_link($serial->ID); ?>"
                            target="_blank"><?php echo esc_html($serial->post_title); ?></a></td>
                    <td><?php echo esc_html($user_name); ?></td>
                    <td><?php echo esc_html($date_fmt); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    $html = ob_get_clean();

    wp_send_json_success(array('html' => $html));
}

// AJAX Handler: Process Campaign Sending
add_action('wp_ajax_ial_process_email_campaign', 'ial_process_email_campaign_ajax');
function ial_process_email_campaign_ajax()
{
    check_ajax_referer('ial_send_campaign_nonce', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => __('Unauthorized.', 'ial-reg')));
    }

    $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
    if (!$campaign_id)
        wp_send_json_error(array('message' => __('Invalid Campaign ID.', 'ial-reg')));

    // 1. Gather Data
    $target_type = get_post_meta($campaign_id, 'target_type', true);
    $exclude_sent = get_post_meta($campaign_id, 'exclude_previously_sent', true);
    $subject = get_post_meta($campaign_id, 'email_subject', true);
    $body = get_post_meta($campaign_id, 'email_body', true);

    $site_name = get_bloginfo('name');
    $full_subject = "[$site_name] " . $subject;

    if (empty($subject) || empty($body))
        wp_send_json_error(array('message' => __('Subject or Body is missing.', 'ial-reg')));

    // 2. Build Query
    $meta_query = array(
        array('key' => 'uid', 'value' => '', 'compare' => '!=')
    );

    if ('product' === $target_type) {
        $product_id = get_post_meta($campaign_id, 'target_product', true);
        if (!$product_id)
            wp_send_json_error(array('message' => __('Target Product not selected.', 'ial-reg')));

        $production_ids = get_posts(array(
            'post_type' => 'ial_production',
            'fields' => 'ids',
            'numberposts' => -1,
            'meta_key' => 'product',
            'meta_value' => $product_id
        ));

        if (empty($production_ids))
            wp_send_json_error(array('message' => __('No productions found.', 'ial-reg')));

        $meta_query[] = array('key' => 'production', 'value' => $production_ids, 'compare' => 'IN');

    } elseif ('production' === $target_type) {
        $production_id = get_post_meta($campaign_id, 'target_production', true);
        if (!$production_id)
            wp_send_json_error(array('message' => __('Target Production not selected.', 'ial-reg')));
        $meta_query[] = array('key' => 'production', 'value' => $production_id, 'compare' => '=');
    }

    // Exclude previously sent
    $sent_flag_key = '_ial_sent_campaign_' . $campaign_id;
    if ($exclude_sent) {
        $meta_query[] = array('key' => $sent_flag_key, 'compare' => 'NOT EXISTS');
    }

    $args = array(
        'post_type' => 'ial_serial',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => $meta_query,
    );
    $eligible_serial_ids = get_posts($args);

    if (empty($eligible_serial_ids)) {
        wp_send_json_error(array('message' => __('No matching recipients found (or all have already received this email).', 'ial-reg')));
    }

    // 3. Group by User
    $users_to_process = array();
    foreach ($eligible_serial_ids as $serial_id) {
        $user_id = get_post_meta($serial_id, 'uid', true);
        if ($user_id)
            $users_to_process[$user_id][] = $serial_id;
    }

    // 4. Send Emails
    set_time_limit(0);
    $sent_emails_count = 0;
    $sent_serials_count = 0;
    $headers = array('Content-Type: text/html; charset=UTF-8');

    foreach ($users_to_process as $user_id => $serial_ids) {
        $user_info = get_userdata($user_id);

        if ($user_info && !empty($user_info->user_email)) {
            $personalized_body = str_replace('{name}', $user_info->display_name, $body);
            $personalized_body = wpautop($personalized_body);

            if (wp_mail($user_info->user_email, $full_subject, $personalized_body, $headers)) {
                $sent_emails_count++;
                $sent_serials_count += count($serial_ids);

                foreach ($serial_ids as $sid) {
                    update_post_meta($sid, $sent_flag_key, current_time('timestamp'));
                }
            }
        }
    }

    // 5. Update Stats
    $curr_emails = (int) get_post_meta($campaign_id, '_ial_campaign_total_recipients', true);
    $curr_serials = (int) get_post_meta($campaign_id, '_ial_campaign_total_serials', true);

    update_post_meta($campaign_id, '_ial_campaign_total_recipients', $curr_emails + $sent_emails_count);
    update_post_meta($campaign_id, '_ial_campaign_total_serials', $curr_serials + $sent_serials_count);
    update_post_meta($campaign_id, '_ial_campaign_last_sent_date', current_time('mysql'));

    wp_send_json_success(array(
        'message' => sprintf(__('Success! %d emails sent covering %d serial numbers.', 'ial-reg'), $sent_emails_count, $sent_serials_count)
    ));
}
