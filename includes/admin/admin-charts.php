<?php

if (!defined('ABSPATH')) {
    exit;
}

// 1. Load Assets (Chart.js and Custom JS/CSS)
add_action('admin_enqueue_scripts', 'ial_load_chart_assets');
function ial_load_chart_assets($hook)
{
    $screen = get_current_screen();
    // Load only on Serial list page
    if ('edit.php' !== $hook || 'ial_serial' !== $screen->post_type)
        return;

    // Load Chart.js from CDN
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);

    // Load local admin charts script
    wp_enqueue_script('ial-admin-charts', plugin_dir_url(__FILE__) . '../../assets/js/admin-charts.js', array('chart-js', 'jquery'), '1.7.0', true);

    // Inline CSS for dashboard widget
    wp_add_inline_style('common', ial_get_dashboard_css());
}

// 2. Helper: Get Global Registration Stats. Respects current admin filters (search, meta_query).
function ial_get_serial_stats()
{
    global $wp_query, $wpdb;

    $args = array(
        'post_type' => 'ial_serial',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'update_post_meta_cache' => false,
    );

    // Copy filters from main query (e.g. if user filtered by Production)
    if (!empty($wp_query->get('meta_query')))
        $args['meta_query'] = $wp_query->get('meta_query');
    if (!empty($wp_query->get('s')))
        $args['s'] = $wp_query->get('s');

    $ids = get_posts($args);
    $total = count($ids);
    $activated = 0;

    if (!empty($ids)) {
        // Efficiently count how many of these IDs have a 'uid' meta key
        $ids_sql = implode(',', array_map('intval', $ids));
        $sql = "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE post_id IN ($ids_sql) AND meta_key = 'uid' AND meta_value != ''";
        $activated = (int) $wpdb->get_var($sql);
    }

    $non_activated = $total - $activated;

    return array(
        'total' => $total,
        'activated' => $activated,
        'non_activated' => $non_activated,
        'percentage' => $total > 0 ? round(($activated / $total) * 100, 1) : 0,
        'non_active_percentage' => $total > 0 ? round(($non_activated / $total) * 100, 1) : 0
    );
}

// 3. Helper: Get Breakdown by Product
function ial_get_product_breakdown()
{
    global $wpdb;

    // Get all Products
    $products = get_posts(array('post_type' => 'ial_product', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
    $data = array();

    foreach ($products as $prod) {
        // Find Productions linked to this Product
        $prod_ids = get_posts(array(
            'post_type' => 'ial_production',
            'fields' => 'ids',
            'meta_key' => 'product',
            'meta_value' => $prod->ID,
            'numberposts' => -1
        ));

        if (empty($prod_ids)) {
            $data[] = array('obj' => $prod, 'total' => 0, 'active' => 0, 'perc' => 0);
            continue;
        }

        $prod_ids_str = implode(',', array_map('intval', $prod_ids));

        // Count Total Serials for these Productions
        $sql_total = "SELECT COUNT(ID) FROM $wpdb->posts p
                      INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id
                      WHERE p.post_type = 'ial_serial' AND p.post_status = 'publish'
                      AND pm.meta_key = 'production' AND pm.meta_value IN ($prod_ids_str)";
        $total = (int) $wpdb->get_var($sql_total);

        // Count Active Serials (with UID)
        $sql_active = "SELECT COUNT(DISTINCT p.ID) FROM $wpdb->posts p
                       INNER JOIN $wpdb->postmeta pm_prod ON (p.ID = pm_prod.post_id AND pm_prod.meta_key = 'production')
                       INNER JOIN $wpdb->postmeta pm_uid ON (p.ID = pm_uid.post_id AND pm_uid.meta_key = 'uid')
                       WHERE p.post_type = 'ial_serial' AND p.post_status = 'publish'
                       AND pm_prod.meta_value IN ($prod_ids_str)
                       AND pm_uid.meta_value != ''";
        $active = (int) $wpdb->get_var($sql_active);

        $data[] = array(
            'obj' => $prod,
            'total' => $total,
            'active' => $active,
            'perc' => $total > 0 ? round(($active / $total) * 100) : 0
        );
    }
    return $data;
}

// 4. Render Dashboard Widget
add_action('all_admin_notices', 'ial_render_dashboard_widget');
function ial_render_dashboard_widget()
{
    $screen = get_current_screen();
    if ('ial_serial' !== $screen->post_type || 'edit' !== $screen->base)
        return;

    $stats = ial_get_serial_stats();
    $breakdown = ial_get_product_breakdown();

    // Pass data to JS
    $js_data = array(
        'activated' => $stats['activated'],
        'non_activated' => $stats['non_activated'],
        'total' => $stats['total'],
        'text_activated' => __('Registered', 'ial-reg'),
        'text_not_active' => __('Not Registered', 'ial-reg'),
    );
    echo '<script>var ialChartData = ' . wp_json_encode($js_data) . ';</script>';

    ?>
    <div class="ial-dashboard-wrapper">

        <div class="ial-col-left ial-card">
            <div class="ial-kpi-header">
                <div class="ial-kpi-item">
                    <span class="ial-label"><?php esc_html_e('Total', 'ial-reg'); ?></span>
                    <strong class="ial-val"><?php echo esc_html($stats['total']); ?></strong>
                </div>
                <div class="ial-kpi-item highlight-blue">
                    <span class="ial-label"><?php esc_html_e('Registered', 'ial-reg'); ?></span>
                    <strong class="ial-val"><?php echo esc_html($stats['activated']); ?></strong>
                </div>
                <div class="ial-kpi-item highlight-red">
                    <span class="ial-label"><?php esc_html_e('Not Registered', 'ial-reg'); ?></span>
                    <strong class="ial-val"><?php echo esc_html($stats['non_activated']); ?></strong>
                </div>
            </div>

            <div class="ial-chart-wrapper">
                <canvas id="ialSerialChart"></canvas>
            </div>
        </div>

        <div class="ial-col-right ial-card">
            <h3><?php esc_html_e('Performance by Product', 'ial-reg'); ?></h3>

            <div class="ial-table-scroll">
                <table class="ial-mini-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Product', 'ial-reg'); ?></th>
                            <th class="text-center"><?php esc_html_e('Total', 'ial-reg'); ?></th>
                            <th class="text-center"><?php esc_html_e('Registered', 'ial-reg'); ?></th>
                            <th class="text-center"><?php esc_html_e('Conversion', 'ial-reg'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($breakdown as $row):
                            $img_id = get_post_meta($row['obj']->ID, 'product_image', true);
                            $img_url = $img_id ? wp_get_attachment_image_url($img_id, 'thumbnail') : '';
                            ?>
                            <tr>
                                <td class="td-product">
                                    <div class="ial-prod-cell">
                                        <?php if ($img_url): ?>
                                            <img src="<?php echo esc_url($img_url); ?>" class="ial-row-img" alt="">
                                        <?php endif; ?>
                                        <span><?php echo esc_html($row['obj']->post_title); ?></span>
                                    </div>
                                </td>
                                <td class="text-center"><?php echo esc_html($row['total']); ?></td>
                                <td class="text-center"><strong><?php echo esc_html($row['active']); ?></strong></td>
                                <td class="text-right td-conv">
                                    <div class="ial-conv-wrapper">
                                        <div class="ial-bar-container">
                                            <div class="ial-bar" style="width: <?php echo $row['perc']; ?>%;"></div>
                                        </div>
                                        <span class="ial-small-perc"><?php echo $row['perc']; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
    <?php
}

// 5. CSS Generator
function ial_get_dashboard_css()
{
    return "
        .ial-dashboard-wrapper { display: grid; grid-template-columns: 340px 1fr; gap: 20px; margin: 40px 20px 20px 0; width: auto; box-sizing: border-box; align-items: start; }
        .ial-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04); display: flex; flex-direction: column; }
        .ial-col-left { padding: 15px; box-sizing: border-box; }
        .ial-kpi-header { display: flex; justify-content: space-between; margin-bottom: 10px; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px; }
        .ial-kpi-item { display: flex; flex-direction: column; text-align: center; flex: 1; }
        .ial-kpi-item:not(:last-child) { border-right: 1px solid #eee; }
        .ial-label { font-size: 11px; text-transform: uppercase; color: #646970; font-weight: 600; margin-bottom: 5px; letter-spacing: 0.5px; }
        .ial-val { font-size: 22px; color: #1d2327; line-height: 1; font-weight: 700; }
        .ial-kpi-item.highlight-blue .ial-val { color: #2271b1; }
        .ial-kpi-item.highlight-red .ial-val { color: #d63638; }
        .ial-chart-wrapper { flex-grow: 1; display: flex; justify-content: center; align-items: center; position: relative; min-height: 140px; padding-top: 5px; }
        #ialSerialChart { max-height: 160px !important; width: 100% !important; height: auto !important; }
        .ial-col-right { min-width: 0; }
        .ial-col-right h3 { margin: 0; padding: 12px 15px; border-bottom: 1px solid #eee; font-size: 14px; text-transform: uppercase; color: #50575e; font-weight: 600; background: #f8f9fa; }
        .ial-table-scroll { height: 215px; overflow-y: auto; margin: 0; padding: 0; border-top: 1px solid #eee; }
        .ial-mini-table { width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 0; }
        .ial-mini-table th { position: sticky; top: 0; background: #fff; z-index: 10; text-align: left; padding: 10px 15px; color: #646970; font-weight: 600; border-bottom: 2px solid #f0f0f1; font-size: 12px; }
        .ial-mini-table th.text-center { text-align: center; }
        .ial-mini-table th.text-right { text-align: right; }
        .ial-mini-table td { padding: 10px 15px; border-bottom: 1px solid #f6f7f7; vertical-align: middle; }
        .ial-mini-table tr:last-child td { border-bottom: none; }
        .ial-prod-cell { display: flex; align-items: center; gap: 12px; font-weight: 500; color: #1d2327; }
        .ial-row-img { width: 36px; height: 36px; border-radius: 4px; background: #f0f0f1; object-fit: cover; border: 1px solid #e0e0e0; flex-shrink: 0; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .td-product { width: 28%; }
        .td-conv { width: 30%; }
        .ial-conv-wrapper { display: flex; align-items: center; width: 100%; }
        .ial-bar-container { flex-grow: 1; height: 8px; background: #f0f0f1; border-radius: 4px; overflow: hidden; margin-right: 10px; }
        .ial-bar { height: 100%; background: #2271b1; border-radius: 4px; }
        .ial-small-perc { font-size: 12px; color: #646970; min-width: 35px; text-align: right; font-weight: 600; }
        @media (max-width: 960px) { .ial-dashboard-wrapper { display: flex; flex-direction: column; width: auto; margin-right: 0; } .ial-col-left, .ial-col-right { width: 100%; } .ial-table-scroll { height: auto; max-height: 300px; } }
    ";
}
