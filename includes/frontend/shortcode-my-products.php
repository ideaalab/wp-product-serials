<?php

if (!defined('ABSPATH')) {
    exit;
}

// Registers and renders the "My Registered Products" shortcode.
function ial_my_registered_products_shortcode()
{
    ob_start();

    // The user must be logged in to view their products.
    if (!is_user_logged_in()) {
        echo '<p class="ial-my-products-error">' . esc_html__('Debes iniciar sesión para ver tus productos registrados.', 'ial-reg') . '</p>';
        return ob_get_clean();
    }

    $current_user_id = get_current_user_id();

    // 1. Retrieve all registered serials for the current user.
    $serials_query_args = array(
        'post_type' => 'ial_serial',
        'posts_per_page' => -1,
        'meta_key' => 'uid',
        'meta_value' => $current_user_id,
        'orderby' => 'date',
        'order' => 'DESC',
    );
    $serials = get_posts($serials_query_args);

    // If the user has no registered products, display a notice.
    if (empty($serials)) {
        echo '<p class="ial-my-products-notice">' . esc_html__('Todavía no has registrado ningún producto.', 'ial-reg') . '</p>';
        return ob_get_clean();
    }

    // Cache Warming
    $production_ids = array();
    foreach ($serials as $serial) {
        $pid = get_post_meta($serial->ID, 'production', true);
        if (is_object($pid))
            $pid = $pid->ID;
        $production_ids[] = $pid;
    }
    $unique_production_ids = array_unique(array_filter($production_ids));

    if (!empty($unique_production_ids)) {
        // Pre-fetch of all associated production posts.
        $productions = get_posts(array(
            'post_type' => 'ial_production',
            'post__in' => $unique_production_ids,
            'posts_per_page' => -1,
        ));

        // Now get product IDs from productions.
        $product_ids = array();
        foreach ($productions as $production) {
            $pr_id = get_post_meta($production->ID, 'product', true);
            if (is_object($pr_id))
                $pr_id = $pr_id->ID;
            $product_ids[] = $pr_id;
        }
        $unique_product_ids = array_unique(array_filter($product_ids));

        if (!empty($unique_product_ids)) {
            // Pre-fetch of all associated product posts.
            get_posts(array(
                'post_type' => 'ial_product',
                'post__in' => $unique_product_ids,
                'posts_per_page' => -1,
            ));
        }
    }

    // 2. Render the list of registered products.
    echo '<div class="ial-my-products-list">';

    foreach ($serials as $serial_post) {
        $prod_id = get_post_meta($serial_post->ID, 'production', true);
        if (is_object($prod_id))
            $prod_id = $prod_id->ID;

        $production = get_post($prod_id);
        // Skip if production post is missing or deleted (data integrity).
        if (!$production instanceof WP_Post) {
            continue;
        }

        $p_id = get_post_meta($production->ID, 'product', true);
        if (is_object($p_id))
            $p_id = $p_id->ID;

        $product = get_post($p_id);
        // Skip if product post is missing or deleted.
        if (!$product instanceof WP_Post) {
            continue;
        }

        // Robust Image Handling
        $image_field_value = get_post_meta($product->ID, 'product_image', true);
        $image_html = '';

        if (is_numeric($image_field_value)) {
            $image_html = wp_get_attachment_image($image_field_value, 'large', false, array('class' => 'ial-product-img-tag'));
        } elseif (is_string($image_field_value) && !empty($image_field_value)) {
            if (filter_var($image_field_value, FILTER_VALIDATE_URL)) {
                $image_html = sprintf(
                    '<img src="%s" alt="%s" class="ial-product-img-tag" />',
                    esc_url($image_field_value),
                    esc_attr($product->post_title)
                );
            }
        }

        ?>
        <div class="ial-product-card">
            <div class="ial-product-image">
                <?php if ($image_html): ?>
                    <?php echo $image_html; ?>
                <?php else: ?>
                    <span class="ial-product-image-placeholder"><?php esc_html_e('Ninguna Imagen', 'ial-reg'); ?></span>
                <?php endif; ?>
            </div>

            <div class="ial-product-content-wrapper">
                <div class="ial-product-details">
                    <h3><?php echo esc_html($product->post_title); ?></h3>

                    <p class="ial-product-info">
                        <strong><?php esc_html_e('Número de Serie:', 'ial-reg'); ?></strong>
                        <?php echo esc_html($serial_post->post_title); ?>
                    </p>

                    <?php $production_date = get_post_meta($production->ID, 'production_date', true); ?>
                    <?php if ($production_date): ?>
                        <p class="ial-product-info">
                            <strong><?php esc_html_e('Fecha de Producción:', 'ial-reg'); ?></strong>
                            <?php echo esc_html($production_date); ?>
                        </p>
                    <?php endif; ?>

                    <?php $product_notes = get_post_meta($product->ID, 'product_notes', true); ?>
                    <?php if ($product_notes): ?>
                        <div class="ial-product-notes">
                            <?php echo wp_kses_post(wpautop($product_notes)); ?>
                        </div>
                    <?php endif; ?>

                    <div class="ial-product-actions">
                        <a href="#"
                            class="ial-unbind-link"
                            data-serial="<?php echo esc_attr($serial_post->ID); ?>"
                            data-product-name="<?php echo esc_attr($product->post_title); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('ial_unbind_serial_' . $serial_post->ID)); ?>">
                            <?php esc_html_e('Desvincular producto', 'ial-reg'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }


    echo '</div>';

    // Single shared modal at the end of the list. Populated dynamically by JS.
    ?>
    <div class="ial-modal" id="ial-unbind-modal" hidden>
        <div class="ial-modal-backdrop"></div>
        <div class="ial-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ial-unbind-title">
            <button type="button" class="ial-modal-close" aria-label="<?php esc_attr_e('Cerrar', 'ial-reg'); ?>">×</button>
            <h3 id="ial-unbind-title"><?php esc_html_e('Desvincular producto', 'ial-reg'); ?></h3>
            <p class="ial-modal-text">
                <?php
                printf(
                    /* translators: %s: product name (rendered in <strong>) */
                    esc_html__('Esta acción desvinculará %s de tu cuenta. El número de serie quedará disponible para que otra persona lo registre.', 'ial-reg'),
                    '<strong class="ial-modal-product-name"></strong>'
                );
                ?>
            </p>
            <label class="ial-modal-field">
                <span class="ial-modal-label">
                    <?php esc_html_e('Motivo', 'ial-reg'); ?> <span class="ial-required">*</span>
                </span>
                <textarea class="ial-modal-motivo" rows="3" required></textarea>
                <span class="ial-modal-hint"><?php esc_html_e('Ej.: vendido de segunda mano, regalo, etc.', 'ial-reg'); ?></span>
            </label>
            <p class="ial-modal-error" hidden></p>
            <div class="ial-modal-actions">
                <button type="button" class="ial-modal-cancel"><?php esc_html_e('Cancelar', 'ial-reg'); ?></button>
                <button type="button" class="ial-modal-confirm"><?php esc_html_e('Sí, desvincular', 'ial-reg'); ?></button>
            </div>
        </div>
    </div>
    <?php

    wp_reset_postdata();

    return ob_get_clean();
}
add_shortcode('ial_my_registered_products', 'ial_my_registered_products_shortcode');
