<?php

if (!defined('ABSPATH')) {
    exit;
}

// Helper to get child post count efficiently.
function ial_get_child_post_count($parent_id, $meta_key, $child_type)
{
    $query = new WP_Query(array(
        'post_type' => $child_type,
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => $meta_key,
                'value' => $parent_id,
                'compare' => '='
            )
        ),
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ));
    return $query->found_posts;
}

add_filter('manage_ial_product_posts_columns', function ($columns) {
    $new = array();
    $new['cb'] = $columns['cb'];
    $new['product_image'] = __('Image', 'ial-reg');
    $new['title'] = 'Product';
    $new['frontend_enable'] = 'Frontend?';
    $new['acym_list'] = __('AcyMailing List', 'ial-reg');
    $new['assign_roles'] = __('Assigned Roles', 'ial-reg');
    $new['productions_count'] = 'Productions';
    $new['serials_count'] = 'Total Serials';
    $new['notes'] = __('Notes', 'ial-reg');
    $new['internal_notes'] = __('Internal Notes', 'ial-reg');
    $new['date'] = $columns['date'];
    return $new;
});

add_filter('manage_edit-ial_product_sortable_columns', function ($columns) {
    $columns['acym_list'] = 'acymailing_list_id';
    return $columns;
});

add_action('manage_ial_product_posts_custom_column', function ($column, $post_id) {
    static $acym_list_names = array();

    switch ($column) {
        case 'product_image':
            $img_id = get_post_meta($post_id, 'product_image', true);
            if ($img_id) {
                echo wp_get_attachment_image($img_id, array(60, 60), false, array(
                    'style' => 'width:60px;height:60px;object-fit:cover;border-radius:4px;'
                ));
            } else {
                echo '<span style="color:#ccc;">—</span>';
            }
            break;

        case 'frontend_enable':
            echo get_post_meta($post_id, 'frontend_enable', true) ? '<span style="color:green;">Yes</span>' : '<span style="color:red;">No</span>';
            break;

        case 'acym_list':
            $list_id = get_post_meta($post_id, 'acymailing_list_id', true);

            if ($list_id) {
                if (!isset($acym_list_names[$list_id])) {
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'acym_list';
                    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
                        $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM $table_name WHERE id = %d", $list_id));
                        $acym_list_names[$list_id] = $name ? $name : __('Unknown List', 'ial-reg');
                    } else {
                        $acym_list_names[$list_id] = __('AcyMailing N/A', 'ial-reg');
                    }
                }

                printf(
                    '<span title="ID: %d"><strong>%s</strong></span>',
                    esc_attr($list_id),
                    esc_html($acym_list_names[$list_id])
                );
            } else {
                echo '<span style="color:#ccc;">—</span>';
            }
            break;

        case 'assign_roles':
            $roles = get_post_meta($post_id, 'assign_roles', true);
            if (!is_array($roles) || empty($roles)) {
                echo '<span style="color:#ccc;">—</span>';
                break;
            }
            $all_roles = wp_roles()->get_names();
            $chips = array();
            foreach ($roles as $role_key) {
                if (!isset($all_roles[$role_key])) {
                    continue;
                }
                $chips[] = sprintf(
                    '<span class="ial-role-chip" title="%1$s" style="display:inline-block; background:#f0f0f1; border:1px solid #c3c4c7; border-radius:10px; padding:1px 8px; margin:1px 2px; font-size:11px; line-height:18px;">%2$s</span>',
                    esc_attr($role_key),
                    esc_html(translate_user_role($all_roles[$role_key]))
                );
            }
            echo $chips ? implode('', $chips) : '<span style="color:#ccc;">—</span>';
            break;

        case 'productions_count':
            echo ial_get_child_post_count($post_id, 'product', 'ial_production');
            break;

        case 'serials_count':
            $key = 'ial_prod_serial_count_' . $post_id;
            $count = get_transient($key);

            if (false === $count) {
                $prod_ids = get_posts(array(
                    'post_type' => 'ial_production',
                    'fields' => 'ids',
                    'posts_per_page' => -1,
                    'meta_key' => 'product',
                    'meta_value' => $post_id
                ));

                if (empty($prod_ids)) {
                    $count = 0;
                } else {
                    $q = new WP_Query(array(
                        'post_type' => 'ial_serial',
                        'fields' => 'ids',
                        'meta_query' => array(
                            array(
                                'key' => 'production',
                                'value' => $prod_ids,
                                'compare' => 'IN'
                            )
                        )
                    ));
                    $count = $q->found_posts;
                }
                set_transient($key, $count, 12 * HOUR_IN_SECONDS);
            }
            echo intval($count);
            break;

        case 'notes':
            $val = get_post_meta($post_id, 'product_notes', true);
            echo $val ? '<div style="max-height: 80px; overflow-y: auto;">' . esc_html($val) . '</div>' : '—';
            break;

        case 'internal_notes':
            $val = get_post_meta($post_id, 'notes', true);
            echo $val ? '<div style="max-height: 80px; overflow-y: auto;">' . esc_html($val) . '</div>' : '—';
            break;
    }
}, 10, 2);


add_filter('manage_ial_production_posts_columns', function ($columns) {
    return array(
        'cb' => $columns['cb'],
        'title' => 'Production',
        'parent_product' => 'Parent Product',
        'serials_count' => 'Serials',
        'hardware_version' => 'HW Version',
        'software_version' => 'SW Version',
        'production_date' => 'Date',
        'notes' => 'Notes',
    );
});

// Make production date sortable
add_filter('manage_edit-ial_production_sortable_columns', function ($columns) {
    $columns['production_date'] = 'production_date';
    $columns['parent_product'] = 'parent_product';
    return $columns;
});

add_action('manage_ial_production_posts_custom_column', function ($column, $post_id) {
    switch ($column) {
        case 'parent_product':
            $pid = get_post_meta($post_id, 'product', true);
            if ($pid) {
                echo '<a href="' . esc_url(get_edit_post_link($pid)) . '">' . esc_html(get_the_title($pid)) . '</a>';
            } else {
                echo '—';
            }
            break;
        case 'serials_count':
            echo ial_get_child_post_count($post_id, 'production', 'ial_serial');
            break;
        case 'hardware_version':
            echo esc_html(get_post_meta($post_id, 'hardware_version', true)) ?: '—';
            break;
        case 'software_version':
            echo esc_html(get_post_meta($post_id, 'software_version', true)) ?: '—';
            break;
        case 'production_date':
            echo esc_html(get_post_meta($post_id, 'production_date', true)) ?: '—';
            break;
        case 'notes':
            $val = get_post_meta($post_id, 'notes', true);
            echo $val ? '<div style="max-height: 80px; overflow-y: auto;">' . esc_html($val) . '</div>' : '—';
            break;
    }
}, 10, 2);

add_filter('manage_ial_serial_posts_columns', function ($columns) {
    return array(
        'cb' => $columns['cb'],
        'title' => 'Serials',
        'u_name' => 'Registered Name',
        'parent_product' => 'Product',
        'parent_production' => 'Production',
        'purchase' => 'Purchase Date',
        'seller' => 'Seller',
        'distributor' => 'Distributor',
        'uid' => 'User (UID)',
        'notes' => 'Notes',
    );
});

add_filter('manage_edit-ial_serial_sortable_columns', function ($columns) {
    $columns['u_name'] = 'u_name';
    $columns['purchase'] = 'purchase';
    return $columns;
});

add_action('manage_ial_serial_posts_custom_column', function ($column, $post_id) {
    switch ($column) {
        case 'u_name':
            echo esc_html(get_post_meta($post_id, 'u_name', true)) ?: '—';
            break;
        case 'purchase':
            echo esc_html(get_post_meta($post_id, 'purchase', true)) ?: '—';
            break;
        case 'seller':
            echo esc_html(get_post_meta($post_id, 'seller', true)) ?: '—';
            break;
        case 'distributor':
            echo esc_html(get_post_meta($post_id, 'distributor', true)) ?: '—';
            break;
        case 'notes':
            $val = get_post_meta($post_id, 'notes', true);
            echo $val ? '<div style="max-height: 80px; overflow-y: auto;">' . esc_html($val) . '</div>' : '—';
            break;

        case 'parent_production':
            $pid = get_post_meta($post_id, 'production', true);
            if ($pid) {
                echo '<a href="' . esc_url(get_edit_post_link($pid)) . '">' . esc_html(get_the_title($pid)) . '</a>';
            } else {
                echo '—';
            }
            break;

        case 'parent_product':
            $key = 'ial_serial_prod_name_' . $post_id;
            $name = get_transient($key);
            if (false === $name) {
                $prod_id = get_post_meta($post_id, 'production', true);
                if (is_object($prod_id))
                    $prod_id = $prod_id->ID;

                $p_id = $prod_id ? get_post_meta($prod_id, 'product', true) : false;
                if (is_object($p_id))
                    $p_id = $p_id->ID;

                $name = $p_id ? get_the_title($p_id) : '—';
                set_transient($key, $name, 12 * HOUR_IN_SECONDS);
            }
            echo esc_html($name);
            break;

        case 'uid':
            $u = get_post_meta($post_id, 'uid', true);
            if ($u) {
                $user = get_userdata($u);
                if ($user) {
                    echo '<a href="' . esc_url(get_edit_user_link($user->ID)) . '"><strong>' . esc_html($user->display_name) . '</strong></a>';
                } else {
                    echo 'User ' . esc_html($u);
                }
            } else {
                echo '—';
            }
            break;
    }
}, 10, 2);

add_action('pre_get_posts', 'ial_registrations_handle_sorting');
function ial_registrations_handle_sorting($query)
{
    if (!is_admin() || !$query->is_main_query())
        return;

    $orderby = $query->get('orderby');

    // Products
    if ('ial_product' === $query->get('post_type') && 'acymailing_list_id' === $orderby) {
        $query->set('meta_key', 'acymailing_list_id');
        $query->set('orderby', 'meta_value_num');
    }

    // Productions
    if ('ial_production' === $query->get('post_type')) {
        if ('production_date' === $orderby) {
            $query->set('meta_key', 'production_date');
            $query->set('orderby', 'meta_value');
        } elseif ('parent_product' === $orderby) {
            $query->set('meta_key', 'product');
            $query->set('orderby', 'meta_value_num');
        }
    }

    // Serials
    if ('ial_serial' === $query->get('post_type')) {
        if ('u_name' === $orderby) {
            $query->set('meta_key', 'u_name');
            $query->set('orderby', 'meta_value');
        } elseif ('purchase' === $orderby) {
            $query->set('meta_key', 'purchase');
            $query->set('orderby', 'meta_value');
        }
    }
}

add_action('restrict_manage_posts', function ($post_type) {
    if ('ial_serial' !== $post_type)
        return;

    $sel_prod = isset($_GET['filter_by_production']) ? (int) $_GET['filter_by_production'] : 0;
    echo '<select name="filter_by_production"><option value="0">' . __('Filter by Production', 'ial-reg') . '</option>';
    $prods = get_posts(array('post_type' => 'ial_production', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
    foreach ($prods as $p)
        printf('<option value="%d" %s>%s</option>', $p->ID, selected($sel_prod, $p->ID, false), esc_html($p->post_title));
    echo '</select>';

    $sel_product = isset($_GET['filter_by_product']) ? (int) $_GET['filter_by_product'] : 0;
    echo '<select name="filter_by_product"><option value="0">' . __('Filter by Product', 'ial-reg') . '</option>';
    $products = get_posts(array('post_type' => 'ial_product', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
    foreach ($products as $p)
        printf('<option value="%d" %s>%s</option>', $p->ID, selected($sel_product, $p->ID, false), esc_html($p->post_title));
    echo '</select>';
});

add_action('parse_query', function ($query) {
    global $pagenow;
    if (is_admin() && $query->is_main_query() && $pagenow == 'edit.php' && $query->get('post_type') == 'ial_serial') {
        $mq = $query->get('meta_query') ?: array();

        if (!empty($_GET['filter_by_production'])) {
            $mq[] = array('key' => 'production', 'value' => (int) $_GET['filter_by_production'], 'compare' => '=');
        }

        if (!empty($_GET['filter_by_product'])) {
            $pids = get_posts(array(
                'post_type' => 'ial_production',
                'fields' => 'ids',
                'numberposts' => -1,
                'meta_query' => array(
                    array('key' => 'product', 'value' => (int) $_GET['filter_by_product'], 'compare' => '=')
                )
            ));
            $mq[] = array(
                'key' => 'production',
                'value' => empty($pids) ? array('invalid_id') : $pids,
                'compare' => 'IN'
            );
        }

        if (!empty($mq))
            $query->set('meta_query', $mq);
    }
});


// Hide the default "All / Mine / Published / ..." views above the list tables
// for the plugin CPTs. They aren't useful here (products are organizational
// data, not user-authored content), and "Mine" can also trip security plugins
// that block ?author=N user-enumeration patterns.
add_filter('views_edit-ial_product', '__return_empty_array');
add_filter('views_edit-ial_production', '__return_empty_array');
add_filter('views_edit-ial_serial', '__return_empty_array');


add_filter('manage_ial_email_campaign_posts_columns', function ($columns) {
    $new = array();
    $new['cb'] = $columns['cb'];
    $new['title'] = __('Campaign Name', 'ial-reg');
    $new['subject'] = __('Email Subject', 'ial-reg');
    $new['target'] = __('Target', 'ial-reg');
    $new['stats'] = __('Stats', 'ial-reg');
    $new['status'] = __('Status', 'ial-reg');
    $new['date'] = $columns['date'];
    return $new;
});

add_action('manage_ial_email_campaign_posts_custom_column', function ($column, $post_id) {
    switch ($column) {
        case 'subject':
            echo esc_html(get_post_meta($post_id, 'email_subject', true));
            break;

        case 'target':
            $type = get_post_meta($post_id, 'target_type', true);
            if ('product' === $type) {
                $pid = get_post_meta($post_id, 'target_product', true);
                echo '<span class="dashicons dashicons-archive" title="Product"></span> ' . ($pid ? esc_html(get_the_title($pid)) : '—');
            } elseif ('production' === $type) {
                $pid = get_post_meta($post_id, 'target_production', true);
                echo '<span class="dashicons dashicons-tickets-alt" title="Production"></span> ' . ($pid ? esc_html(get_the_title($pid)) : '—');
            }
            break;

        case 'stats':
            $emails = (int) get_post_meta($post_id, '_ial_campaign_total_recipients', true);
            $serials = (int) get_post_meta($post_id, '_ial_campaign_total_serials', true);

            $text = '<strong>' . $emails . '</strong> ' . __('Users', 'ial-reg');
            if ($serials > 0) {
                $text .= ' <small style="color:#666;">(' . $serials . ' Serials)</small>';
            }

            if ($emails > 0) {
                echo '<a href="#" class="ial-view-recipients" data-id="' . esc_attr($post_id) . '" style="text-decoration:none; border-bottom:1px dashed;">';
                echo $text . ' <span class="dashicons dashicons-visibility" style="font-size:14px; vertical-align:middle;"></span>';
                echo '</a>';
            } else {
                echo $text;
            }
            break;

        case 'status':
            $last_sent = get_post_meta($post_id, '_ial_campaign_last_sent_date', true);
            if ($last_sent) {
                echo '<span style="color:green; font-weight:bold;">' . __('Active', 'ial-reg') . '</span><br>';
                echo '<small>' . esc_html($last_sent) . '</small>';
            } else {
                echo '<span style="color:#888;">' . __('Draft', 'ial-reg') . '</span>';
            }
            break;
    }
}, 10, 2);