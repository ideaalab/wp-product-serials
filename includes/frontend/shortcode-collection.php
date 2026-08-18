<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Your collection" panel: tier progress plus every product in the collection,
 * with the ones the user has not registered dimmed and linked to the shop.
 */

/**
 * Image markup for an `ial_product`. The `product_image` meta holds either an
 * attachment ID or a plain URL, so both are handled.
 */
function ial_get_product_image_html($product_id, $size = 'large', $class = 'ial-product-img-tag')
{
    $value = get_post_meta($product_id, 'product_image', true);

    if (is_numeric($value)) {
        return wp_get_attachment_image($value, $size, false, array('class' => $class));
    }

    if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_URL)) {
        return sprintf(
            '<img src="%s" alt="%s" class="%s" />',
            esc_url($value),
            esc_attr(get_the_title($product_id)),
            esc_attr($class)
        );
    }

    return '';
}

/**
 * Products that make up the visible collection.
 *
 * `show_in_collection` is opt-out: products saved before this feature existed
 * have no value stored and are included, so the panel works out of the box.
 *
 * @return WP_Post[]
 */
function ial_collection_get_products()
{
    $products = get_posts(array(
        'post_type'      => 'ial_product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ));

    $visible = array();
    foreach ($products as $product) {
        if ('0' === (string) get_post_meta($product->ID, 'show_in_collection', true)) {
            continue;
        }
        $visible[] = $product;
    }

    return $visible;
}

/** Shop URL for an `ial_product`, or '' when it is not linked to a WC product. */
function ial_collection_shop_url($product_id)
{
    $wc_product_id = (int) get_post_meta($product_id, 'wc_product_id', true);
    if (!$wc_product_id) {
        return '';
    }

    $wc_post = get_post($wc_product_id);
    if (!$wc_post || 'publish' !== $wc_post->post_status) {
        return '';
    }

    return (string) get_permalink($wc_product_id);
}

/**
 * Progress bar across every configured tier. Each tier gets an equal segment,
 * so the milestones never collide however far apart the thresholds are.
 */
function ial_collection_render_progress($user_id)
{
    if (!ial_loyalty_is_active()) {
        return;
    }

    $settings = ial_loyalty_get_settings();
    $tiers    = $settings['tiers'];
    $state    = ial_loyalty_get_user_state($user_id);
    $count    = (int) $state['count'];
    $percent  = (float) $state['percent'];
    $next     = ial_loyalty_next_tier($count);

    // Wording depends on what the admin chose as the levelling criterion.
    $is_units       = ('serials' === $settings['basis']);
    $unit_singular  = $is_units ? __('unidad', 'ial-reg') : __('producto', 'ial-reg');
    $unit_plural    = $is_units ? __('unidades', 'ial-reg') : __('productos', 'ial-reg');

    // Fill: completed segments plus the fraction of the one in progress.
    $segments  = count($tiers);
    $completed = 0;
    $fraction  = 0.0;
    $previous  = 0;

    foreach ($tiers as $tier) {
        if ($count >= $tier['threshold']) {
            $completed++;
            $previous = $tier['threshold'];
            continue;
        }
        $span     = max(1, $tier['threshold'] - $previous);
        $fraction = min(1, max(0, ($count - $previous) / $span));
        break;
    }

    $fill = $segments > 0 ? ((($completed + $fraction) / $segments) * 100) : 0;
    $fill = max(0, min(100, $fill));
    ?>
    <h2 class="ial-section-title"><?php esc_html_e('Descuento permanente', 'ial-reg'); ?></h2>
    <section class="ial-loyalty-panel" data-celebrate="<?php echo $percent > 0 ? '1' : '0'; ?>">
        <div class="ial-loyalty-head">
            <div class="ial-loyalty-current">
                <span class="ial-loyalty-current-label"><?php esc_html_e('Tu descuento actual', 'ial-reg'); ?></span>
                <strong class="ial-loyalty-percent"><?php echo esc_html(ial_loyalty_format_percent($percent)); ?>%</strong>
            </div>
            <p class="ial-loyalty-status">
                <?php
                printf(
                    /* translators: 1: count, 2: "productos" or "unidades" */
                    esc_html__('Llevas %1$s %2$s.', 'ial-reg'),
                    '<strong>' . esc_html(number_format_i18n($count)) . '</strong>',
                    esc_html(1 === $count ? $unit_singular : $unit_plural)
                );

                if ($next) {
                    $missing = max(1, (int) $next['threshold'] - $count);
                    echo ' ';
                    printf(
                        /* translators: 1: how many more are needed, 2: unit word, 3: discount percentage */
                        esc_html__('Registra %1$s %2$s más y desbloqueas un %3$s%% de descuento.', 'ial-reg'),
                        '<strong>' . esc_html(number_format_i18n($missing)) . '</strong>',
                        esc_html(1 === $missing ? $unit_singular : $unit_plural),
                        esc_html(ial_loyalty_format_percent($next['percent']))
                    );
                } elseif ($percent > 0) {
                    echo ' ' . esc_html__('Has alcanzado el nivel máximo. ¡Gracias!', 'ial-reg');
                }
                ?>
            </p>
        </div>

        <div class="ial-loyalty-bar">
            <div class="ial-loyalty-track">
                <?php // The inline width is the real state; the script rewinds it to zero and replays it. ?>
            <div class="ial-loyalty-fill" style="width:<?php echo esc_attr(round($fill, 2)); ?>%"
                data-fill="<?php echo esc_attr(round($fill, 2)); ?>"></div>
            </div>
            <ol class="ial-loyalty-steps">
                <?php foreach ($tiers as $tier): ?>
                    <?php $reached = $count >= $tier['threshold']; ?>
                    <li class="ial-loyalty-step<?php echo $reached ? ' is-reached' : ''; ?>">
                        <span class="ial-loyalty-dot" aria-hidden="true"></span>
                        <span class="ial-loyalty-step-goal">
                            <?php
                            printf(
                                /* translators: 1: threshold, 2: unit word */
                                esc_html__('%1$s %2$s', 'ial-reg'),
                                esc_html(number_format_i18n($tier['threshold'])),
                                esc_html(1 === (int) $tier['threshold'] ? $unit_singular : $unit_plural)
                            );
                            ?>
                        </span>
                        <span class="ial-loyalty-step-pct">
                            <?php
                            printf(
                                /* translators: %s: discount percentage */
                                esc_html__('%s%% de descuento', 'ial-reg'),
                                esc_html(ial_loyalty_format_percent($tier['percent']))
                            );
                            ?>
                        </span>
                        <?php if (!empty($tier['label'])): ?>
                            <span class="ial-loyalty-step-name"><?php echo esc_html($tier['label']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>
    <?php
}

/** The collection grid: owned products first, then the ones still missing. */
function ial_collection_render_grid($user_id)
{
    $products = ial_collection_get_products();
    if (empty($products)) {
        return;
    }

    $owned_ids = ial_loyalty_user_product_ids($user_id);
    $owned     = array();
    $missing   = array();

    foreach ($products as $product) {
        if (in_array((int) $product->ID, $owned_ids, true)) {
            $owned[] = $product;
        } else {
            $missing[] = $product;
        }
    }

    $total = count($products);
    ?>
    <section class="ial-collection">
        <h2 class="ial-section-title"><?php esc_html_e('Colección', 'ial-reg'); ?></h2>

        <ul class="ial-collection-grid">
            <?php foreach (array_merge($owned, $missing) as $product): ?>
                <?php
                $is_owned  = in_array((int) $product->ID, $owned_ids, true);
                $image     = ial_get_product_image_html($product->ID, 'medium', 'ial-collection-img');
                $shop_url  = $is_owned ? '' : ial_collection_shop_url($product->ID);
                $tag       = $shop_url ? 'a' : 'div';
                ?>
                <li class="ial-collection-item<?php echo $is_owned ? ' is-owned' : ' is-missing'; ?>">
                    <<?php echo $tag; ?> class="ial-collection-inner"
                        <?php if ($shop_url): ?>href="<?php echo esc_url($shop_url); ?>"<?php endif; ?>>
                        <span class="ial-collection-thumb">
                            <?php if ($image): ?>
                                <?php echo $image; ?>
                            <?php else: ?>
                                <span class="ial-collection-thumb-empty" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?php if ($is_owned): ?>
                                <span class="ial-collection-check" aria-hidden="true">✓</span>
                            <?php endif; ?>
                        </span>
                        <span class="ial-collection-name"><?php echo esc_html($product->post_title); ?></span>
                        <span class="ial-collection-state">
                            <?php if ($is_owned): ?>
                                <?php esc_html_e('Registrado', 'ial-reg'); ?>
                            <?php elseif ($shop_url): ?>
                                <?php esc_html_e('Ver en la tienda', 'ial-reg'); ?>
                            <?php else: ?>
                                <?php esc_html_e('Aún no lo tienes', 'ial-reg'); ?>
                            <?php endif; ?>
                        </span>
                    </<?php echo $tag; ?>>
                </li>
            <?php endforeach; ?>
        </ul>

        <p class="ial-collection-count">
            <?php
            printf(
                /* translators: 1: products owned, 2: products in the collection */
                esc_html__('%1$s de %2$s', 'ial-reg'),
                esc_html(number_format_i18n(count($owned))),
                esc_html(number_format_i18n($total))
            );
            ?>
        </p>
    </section>
    <?php
}

function ial_product_collection_shortcode()
{
    $settings = ial_loyalty_get_settings();
    if (empty($settings['show_collection'])) {
        return '';
    }

    if (!is_user_logged_in()) {
        return '<p class="ial-my-products-error">'
            . esc_html__('Debes iniciar sesión para ver tu colección.', 'ial-reg')
            . '</p>';
    }

    $user_id = get_current_user_id();

    ob_start();
    ial_collection_render_progress($user_id);
    ial_collection_render_grid($user_id);
    return ob_get_clean();
}
add_shortcode('ial_product_collection', 'ial_product_collection_shortcode');
