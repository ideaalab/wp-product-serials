<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loyalty discount: users get a percentage discount in WooCommerce based on
 * how many products they have registered.
 *
 * Design notes:
 *
 * - The discount is applied by overriding the price of each cart line in
 *   `woocommerce_before_calculate_totals`. Taxes are therefore computed by
 *   WooCommerce from the line price using each product's own tax class — no
 *   proration, no approximation.
 * - The line price is ALWAYS derived from the product's regular price, never
 *   from its current price, which makes the whole thing idempotent: the hook
 *   can fire any number of times per request without compounding.
 * - It never stacks. Per line, the bigger of (existing offer, loyalty) wins.
 *   Against manual coupons, the bigger of (coupon, loyalty) wins.
 * - It runs last on that hook (priority 9999) so the comparison also covers
 *   discounts applied by other plugins, which would otherwise multiply.
 */

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

function ial_loyalty_default_settings()
{
    return array(
        'enabled'         => 0,
        'basis'           => 'distinct_products',
        'tiers'           => array(),
        'max_percent'     => 20,
        'filter_mode'     => 'exclude',
        'filter_cats'     => array(),
        'filter_products' => array(),
        'show_notice'     => 1,
        // Opt-in: updating the plugin must not change what a live My Account
        // page shows until the shop decides to turn it on.
        'show_collection' => 0,
    );
}

/**
 * Available "what makes you level up" criteria. Filterable so more can be
 * added later without touching stored data.
 */
function ial_loyalty_get_basis_options()
{
    return apply_filters('ial_loyalty_basis_options', array(
        'distinct_products' => __('Different products registered', 'ial-reg'),
        'serials'           => __('Serials registered (units)', 'ial-reg'),
    ));
}

function ial_loyalty_get_settings($force_refresh = false)
{
    static $cache = null;
    if (null !== $cache && !$force_refresh) {
        return $cache;
    }

    $saved = get_option('ial_loyalty_settings', array());
    if (!is_array($saved)) {
        $saved = array();
    }

    $s = wp_parse_args($saved, ial_loyalty_default_settings());

    $s['enabled']         = !empty($s['enabled']) ? 1 : 0;
    $s['show_notice']     = !empty($s['show_notice']) ? 1 : 0;
    $s['show_collection'] = !empty($s['show_collection']) ? 1 : 0;
    $s['basis']           = array_key_exists($s['basis'], ial_loyalty_get_basis_options())
        ? $s['basis']
        : 'distinct_products';
    $s['max_percent']     = ial_loyalty_clamp_percent($s['max_percent']);
    $s['tiers']           = ial_loyalty_normalize_tiers($s['tiers']);

    // 3.7.x stored a single "excluded categories" list with no mode. Read it
    // as an exclude filter so upgrading changes nothing; the next save writes
    // the current shape.
    if (!isset($saved['filter_mode']) && !empty($saved['excluded_cats'])) {
        $s['filter_mode'] = 'exclude';
        $s['filter_cats'] = (array) $saved['excluded_cats'];
    }

    $s['filter_mode']     = in_array($s['filter_mode'], array('exclude', 'include'), true)
        ? $s['filter_mode']
        : 'exclude';
    $s['filter_cats']     = ial_loyalty_clean_id_list($s['filter_cats']);
    $s['filter_products'] = ial_loyalty_clean_id_list($s['filter_products']);
    unset($s['excluded_cats']);

    $cache = $s;
    return $cache;
}

// Saving the settings mid-request must not leave a stale copy behind.
add_action('update_option_ial_loyalty_settings', 'ial_loyalty_flush_settings_cache');
add_action('add_option_ial_loyalty_settings', 'ial_loyalty_flush_settings_cache');
function ial_loyalty_flush_settings_cache()
{
    ial_loyalty_get_settings(true);
}

/** Unique, positive integer IDs from whatever the settings hold. */
function ial_loyalty_clean_id_list($value)
{
    $ids = array_map('intval', (array) $value);
    $ids = array_filter($ids, function ($id) {
        return $id > 0;
    });
    return array_values(array_unique($ids));
}

function ial_loyalty_clamp_percent($value)
{
    $value = (float) str_replace(',', '.', (string) $value);
    if ($value < 0) {
        $value = 0;
    }
    if ($value > 100) {
        $value = 100;
    }
    return round($value, 2);
}

/**
 * Clean up a tier list: valid rows only, one row per threshold, sorted by
 * threshold ascending. The sort is what lets `ial_loyalty_percent_for_count()`
 * simply keep the last match.
 */
function ial_loyalty_normalize_tiers($tiers)
{
    if (!is_array($tiers)) {
        return array();
    }

    $clean = array();
    foreach ($tiers as $tier) {
        if (!is_array($tier)) {
            continue;
        }
        $threshold = isset($tier['threshold']) ? (int) $tier['threshold'] : 0;
        $percent   = isset($tier['percent']) ? ial_loyalty_clamp_percent($tier['percent']) : 0;
        if ($threshold < 1 || $percent <= 0) {
            continue;
        }
        $clean[$threshold] = array(
            'threshold' => $threshold,
            'percent'   => $percent,
            'label'     => isset($tier['label']) ? sanitize_text_field($tier['label']) : '',
        );
    }

    ksort($clean, SORT_NUMERIC);
    return array_values($clean);
}

/** The feature only does anything with WooCommerce active and tiers defined. */
function ial_loyalty_is_active()
{
    if (!class_exists('WooCommerce')) {
        return false;
    }
    $s = ial_loyalty_get_settings();
    return !empty($s['enabled']) && !empty($s['tiers']);
}

/**
 * Fingerprint of the settings that affect a user's level. Stored alongside the
 * cached level so changing the tiers in the admin invalidates every user's
 * cached value without needing a batch job.
 */
function ial_loyalty_settings_signature()
{
    $s = ial_loyalty_get_settings();
    return md5(wp_json_encode(array($s['basis'], $s['tiers'], $s['max_percent'])));
}

/* -------------------------------------------------------------------------
 * Counting what the user has registered
 * ---------------------------------------------------------------------- */

/**
 * Distinct `ial_product` IDs the user currently has a registered serial of.
 *
 * @return int[]
 */
function ial_loyalty_user_product_ids($user_id)
{
    global $wpdb;
    $user_id = (int) $user_id;
    if (!$user_id) {
        return array();
    }

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT pm_prod.meta_value
           FROM {$wpdb->posts} s
           JOIN {$wpdb->postmeta} sm_uid  ON sm_uid.post_id  = s.ID AND sm_uid.meta_key  = 'uid' AND sm_uid.meta_value = %d
           JOIN {$wpdb->postmeta} sm_prod ON sm_prod.post_id = s.ID AND sm_prod.meta_key = 'production'
           JOIN {$wpdb->postmeta} pm_prod ON pm_prod.post_id = sm_prod.meta_value AND pm_prod.meta_key = 'product'
          WHERE s.post_type   = 'ial_serial'
            AND s.post_status = 'publish'",
        $user_id
    ));

    return array_values(array_filter(array_map('intval', (array) $ids)));
}

/** Number of registered serials (units) belonging to the user. */
function ial_loyalty_user_serial_count($user_id)
{
    global $wpdb;
    $user_id = (int) $user_id;
    if (!$user_id) {
        return 0;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*)
           FROM {$wpdb->posts} s
           JOIN {$wpdb->postmeta} sm_uid  ON sm_uid.post_id  = s.ID AND sm_uid.meta_key  = 'uid' AND sm_uid.meta_value = %d
           JOIN {$wpdb->postmeta} sm_prod ON sm_prod.post_id = s.ID AND sm_prod.meta_key = 'production'
           JOIN {$wpdb->postmeta} pm_prod ON pm_prod.post_id = sm_prod.meta_value AND pm_prod.meta_key = 'product'
          WHERE s.post_type   = 'ial_serial'
            AND s.post_status = 'publish'",
        $user_id
    ));
}

/** Count that feeds the tier ladder, according to the configured basis. */
function ial_loyalty_count_for_user($user_id)
{
    $s = ial_loyalty_get_settings();

    if ('serials' === $s['basis']) {
        return ial_loyalty_user_serial_count($user_id);
    }

    return count(ial_loyalty_user_product_ids($user_id));
}

/** Highest tier reached by $count, capped by the global maximum. */
function ial_loyalty_percent_for_count($count)
{
    $s       = ial_loyalty_get_settings();
    $count   = (int) $count;
    $percent = 0.0;

    // Tiers are sorted ascending, so the last one that matches is the highest.
    foreach ($s['tiers'] as $tier) {
        if ($count >= $tier['threshold']) {
            $percent = (float) $tier['percent'];
        }
    }

    return min($percent, (float) $s['max_percent']);
}

/** The highest tier the user has reached, or null if they are below the first. */
function ial_loyalty_current_tier($count)
{
    $s       = ial_loyalty_get_settings();
    $count   = (int) $count;
    $current = null;

    foreach ($s['tiers'] as $tier) {
        if ($count >= $tier['threshold']) {
            $current = $tier;
        }
    }

    return $current;
}

/** The next tier the user has not reached yet, or null if maxed out. */
function ial_loyalty_next_tier($count)
{
    $s     = ial_loyalty_get_settings();
    $count = (int) $count;

    foreach ($s['tiers'] as $tier) {
        if ($count < $tier['threshold']) {
            return $tier;
        }
    }
    return null;
}

/* -------------------------------------------------------------------------
 * Cached level per user
 * ---------------------------------------------------------------------- */

/**
 * Recompute and store the user's count and percentage. Called whenever a
 * product is registered or unbound, and lazily when the settings change.
 */
function ial_loyalty_refresh_user($user_id)
{
    $user_id = (int) $user_id;
    if (!$user_id) {
        return 0.0;
    }

    $count   = ial_loyalty_count_for_user($user_id);
    $percent = ial_loyalty_percent_for_count($count);

    update_user_meta($user_id, '_ial_loyalty_count', $count);
    update_user_meta($user_id, '_ial_loyalty_percent', $percent);
    update_user_meta($user_id, '_ial_loyalty_sig', ial_loyalty_settings_signature());

    return $percent;
}

/** Read the cached level, recomputing it if the settings changed since. */
function ial_loyalty_get_user_state($user_id)
{
    $user_id = (int) $user_id;
    if (!$user_id) {
        return array('count' => 0, 'percent' => 0.0);
    }

    $sig = get_user_meta($user_id, '_ial_loyalty_sig', true);
    if ($sig !== ial_loyalty_settings_signature()) {
        ial_loyalty_refresh_user($user_id);
    }

    return array(
        'count'   => (int) get_user_meta($user_id, '_ial_loyalty_count', true),
        'percent' => (float) get_user_meta($user_id, '_ial_loyalty_percent', true),
    );
}

/** Discount percentage the user is entitled to right now. */
function ial_loyalty_get_user_percent($user_id)
{
    if (!ial_loyalty_is_active()) {
        return 0.0;
    }
    $state = ial_loyalty_get_user_state($user_id);
    return (float) $state['percent'];
}

add_action('ial_user_product_registered', 'ial_loyalty_on_registration', 20, 2);
function ial_loyalty_on_registration($user_id, $product_id)
{
    ial_loyalty_refresh_user($user_id);
}

add_action('ial_user_unbound_product', 'ial_loyalty_on_unbind', 20, 4);
function ial_loyalty_on_unbind($serial_id, $user_id, $product_id, $motivo)
{
    ial_loyalty_refresh_user($user_id);
}

/* -------------------------------------------------------------------------
 * Cart: per-line price override
 * ---------------------------------------------------------------------- */

/**
 * Price of a cart line before we touch it, snapshotted once per request.
 *
 * Because we run last, this is the price every other discount in the shop has
 * already settled on — which is exactly what the loyalty discount has to beat.
 * `woocommerce_before_calculate_totals` fires several times per request and we
 * overwrite prices, so re-reading `get_price()` on a later pass would give us
 * our own discounted price back; the snapshot keeps the comparison and the
 * recorded saving honest.
 */
function ial_loyalty_baseline_price($cart_item_key, $product)
{
    static $baseline = array();

    if (!isset($baseline[$cart_item_key])) {
        $baseline[$cart_item_key] = (float) $product->get_price();
    }

    return $baseline[$cart_item_key];
}

/** Categories (and anything hooked in) that never receive the discount. */
/**
 * Selected categories plus every category underneath them.
 *
 * `has_term()` only matches terms assigned directly to the product, and shops
 * normally file a product under its leaf category alone. Without walking the
 * tree, picking a parent category in the settings would silently match nothing.
 *
 * @param int[] $term_ids
 * @return int[]
 */
function ial_loyalty_expand_categories($term_ids)
{
    static $cache = array();

    $term_ids = ial_loyalty_clean_id_list($term_ids);
    if (empty($term_ids)) {
        return array();
    }

    $key = implode(',', $term_ids);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $all = $term_ids;
    foreach ($term_ids as $term_id) {
        $children = get_term_children($term_id, 'product_cat');
        if (!is_wp_error($children)) {
            $all = array_merge($all, array_map('intval', $children));
        }
    }

    $cache[$key] = array_values(array_unique($all));
    return $cache[$key];
}

/** Is this product named by the configured categories or products? */
function ial_loyalty_filter_matches($product)
{
    $s      = ial_loyalty_get_settings();
    $own_id = (int) $product->get_id();
    $parent = $product->is_type('variation') ? (int) $product->get_parent_id() : 0;

    // A variation can be picked directly in the product selector, so a match on
    // either the variation or its parent counts.
    if (!empty($s['filter_products'])) {
        if (in_array($own_id, $s['filter_products'], true)) {
            return true;
        }
        if ($parent && in_array($parent, $s['filter_products'], true)) {
            return true;
        }
    }

    if (!empty($s['filter_cats'])) {
        // Categories live on the parent, never on the variation.
        $cat_owner = $parent ? $parent : $own_id;
        if (has_term(ial_loyalty_expand_categories($s['filter_cats']), 'product_cat', $cat_owner)) {
            return true;
        }
    }

    return false;
}

/** Does the loyalty discount apply to this product at all? */
function ial_loyalty_product_qualifies($product)
{
    if (!$product instanceof WC_Product) {
        return false;
    }

    $s       = ial_loyalty_get_settings();
    $has_list = !empty($s['filter_cats']) || !empty($s['filter_products']);

    if ('include' === $s['filter_mode']) {
        // An empty allow-list means exactly what it says: nothing qualifies.
        // The settings screen warns about that combination when it is saved.
        $qualifies = $has_list && ial_loyalty_filter_matches($product);
    } else {
        $qualifies = !($has_list && ial_loyalty_filter_matches($product));
    }

    // Kept from 3.7.0: a veto that can only ever take the discount away.
    if (apply_filters('ial_loyalty_exclude_product', false, $product)) {
        $qualifies = false;
    }

    return (bool) apply_filters('ial_loyalty_product_qualifies', $qualifies, $product);
}

/**
 * What the discount would do to this cart, without touching anything.
 * Returns array('total' => float, 'lines' => array(key => array(price, saving))).
 *
 * $use_snapshot is only true when called from the totals hook, where prices may
 * already have been overwritten by a previous pass. Callers that run earlier
 * (coupon arbitration) read the live price so they never seed the snapshot with
 * a value taken before other pricing hooks had their turn.
 */
function ial_loyalty_build_plan($cart, $percent, $use_snapshot = true)
{
    $plan = array('total' => 0.0, 'lines' => array());

    if (!$cart instanceof WC_Cart || $percent <= 0) {
        return $plan;
    }

    $decimals = wc_get_price_decimals();

    foreach ($cart->get_cart() as $key => $item) {
        $product = isset($item['data']) ? $item['data'] : null;
        if (!$product instanceof WC_Product) {
            continue;
        }
        if (!ial_loyalty_product_qualifies($product)) {
            continue;
        }

        // No regular price to work from: grouped products, bundles, price on
        // request. Leave them alone rather than guess.
        $regular = (float) $product->get_regular_price();
        if ($regular <= 0) {
            continue;
        }

        $baseline = $use_snapshot
            ? ial_loyalty_baseline_price($key, $product)
            : (float) $product->get_price();
        if ($baseline <= 0) {
            continue;
        }

        $target = round($regular * (1 - ($percent / 100)), $decimals);

        // The offer already on the product is as good or better: it wins.
        if ($target >= $baseline) {
            continue;
        }

        $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 1;
        $saving   = ($baseline - $target) * max(1, $quantity);

        $plan['lines'][$key] = array('price' => $target, 'saving' => $saving);
        $plan['total']      += $saving;
    }

    return $plan;
}

/** What the currently applied coupons would save on undiscounted prices. */
function ial_loyalty_coupon_savings($cart)
{
    if (!$cart instanceof WC_Cart || !class_exists('WC_Discounts')) {
        return 0.0;
    }

    $applied = $cart->get_applied_coupons();
    if (empty($applied)) {
        return 0.0;
    }

    $discounts = new WC_Discounts($cart);

    foreach ($applied as $code) {
        $coupon = new WC_Coupon($code);
        $valid  = $discounts->is_coupon_valid($coupon);
        if (is_wp_error($valid) || !$valid) {
            continue;
        }
        $discounts->apply_coupon($coupon);
    }

    return (float) array_sum($discounts->get_discounts_by_coupon());
}

/**
 * Runs deliberately late on `woocommerce_before_calculate_totals`.
 *
 * "The bigger discount wins" only holds if we are the last thing to touch the
 * price: at an early priority a quantity-discount or dynamic-pricing plugin
 * running after us takes OUR discounted price as its base and the two end up
 * multiplied. Running last means we compare against whatever everyone else
 * settled on and simply step aside when their discount is better.
 *
 * Filter `ial_loyalty_price_priority` if something in the shop needs to run
 * even later.
 */
add_action('init', 'ial_loyalty_register_price_hook', 20);
function ial_loyalty_register_price_hook()
{
    $priority = (int) apply_filters('ial_loyalty_price_priority', 9999);
    add_action('woocommerce_before_calculate_totals', 'ial_loyalty_apply_cart_prices', $priority);
}

function ial_loyalty_apply_cart_prices($cart)
{
    if (is_admin() && !wp_doing_ajax()) {
        return;
    }
    if (!$cart instanceof WC_Cart || !ial_loyalty_is_active()) {
        return;
    }

    // Undo only what WE changed on an earlier pass of this request, so every
    // pass starts from the same place. Lines we never touched are left exactly
    // as the rest of the shop's pricing left them.
    foreach ($cart->get_cart() as $key => $item) {
        if (!empty($item['ial_loyalty']) && isset($item['data']) && $item['data'] instanceof WC_Product) {
            $item['data']->set_price(ial_loyalty_baseline_price($key, $item['data']));
        }
        unset($cart->cart_contents[$key]['ial_loyalty']);
    }

    $state   = ial_loyalty_get_user_state(get_current_user_id());
    $percent = (float) $state['percent'];
    if ($percent <= 0) {
        return;
    }

    $plan = ial_loyalty_build_plan($cart, $percent);
    if ($plan['total'] <= 0) {
        return;
    }

    // Tier name, so the cart line can say "Iniciado − 5% aplicado".
    $tier      = ial_loyalty_current_tier($state['count']);
    $tier_name = ($tier && '' !== $tier['label']) ? $tier['label'] : '';

    // A coupon is still applied at this point only if it beat the loyalty
    // discount during arbitration. The bigger one wins, they never stack.
    if (!empty($cart->get_applied_coupons())) {
        return;
    }

    foreach ($plan['lines'] as $key => $line) {
        $cart->cart_contents[$key]['data']->set_price($line['price']);
        $cart->cart_contents[$key]['ial_loyalty'] = array(
            'percent' => $percent,
            'saving'  => $line['saving'],
            'tier'    => $tier_name,
        );
    }
}

/* -------------------------------------------------------------------------
 * Coupons: the bigger discount wins, never both
 * ---------------------------------------------------------------------- */

/**
 * Compare the loyalty discount against the applied coupons and drop the loser.
 *
 * Runs when a coupon is applied and again when the cart is loaded from the
 * session, so a coupon that stopped being the better deal (because the cart
 * changed) is cleaned up on the next page load. Never runs during totals
 * calculation, where removing a coupon would re-enter the calculation.
 *
 * KNOWN LIMITATION (not hit by the shop as configured today, revisit if it is).
 * This runs before `woocommerce_before_calculate_totals`, so the prices it
 * reads are the ones WooCommerce itself knows about — a quantity-discount or
 * dynamic-pricing plugin has not run yet. On a cart where such a plugin is
 * active, the loyalty saving estimated here is too high, and a coupon could be
 * removed for "losing" when it was actually the better deal. Only matters when
 * manual coupons and third-party price discounts meet on the same products.
 * The fix is to arbitrate after totals are calculated, where prices are final;
 * it changes when coupons get removed, so it needs deciding, not just doing.
 */
function ial_loyalty_arbitrate_coupons($context = '')
{
    static $running = false;

    if ($running || (is_admin() && !wp_doing_ajax()) || !ial_loyalty_is_active()) {
        return;
    }

    // `woocommerce_cart_loaded_from_session` hands us the cart itself, which is
    // more reliable than WC()->cart that early in the request.
    // `woocommerce_applied_coupon` hands us the coupon code instead.
    $applied_code = '';
    if ($context instanceof WC_Cart) {
        $cart = $context;
    } else {
        $applied_code = (string) $context;
        $cart         = (function_exists('WC') && WC()->cart) ? WC()->cart : null;
    }

    if (!$cart instanceof WC_Cart || $cart->is_empty() || empty($cart->get_applied_coupons())) {
        return;
    }

    $percent = ial_loyalty_get_user_percent(get_current_user_id());
    if ($percent <= 0) {
        return;
    }

    $running = true;

    $plan            = ial_loyalty_build_plan($cart, $percent, false);
    $coupon_savings  = ial_loyalty_coupon_savings($cart);
    $loyalty_savings = $plan['total'];

    if ($loyalty_savings > $coupon_savings) {
        $removed = $cart->get_applied_coupons();
        foreach ($removed as $code) {
            $cart->remove_coupon($code);
        }
        if (function_exists('wc_add_notice')) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: loyalty discount amount, 2: percentage, 3: coupon codes, 4: coupon discount amount */
                    __('Tu descuento por productos registrados (%1$s, un %2$s%%) es mayor que el del cupón %3$s (%4$s), así que hemos mantenido el mayor de los dos. No son acumulables.', 'ial-reg'),
                    wp_strip_all_tags(wc_price($loyalty_savings)),
                    ial_loyalty_format_percent($percent),
                    implode(', ', array_map('strtoupper', $removed)),
                    wp_strip_all_tags(wc_price($coupon_savings))
                ),
                'notice'
            );
        }
    } elseif ($applied_code && $loyalty_savings > 0 && function_exists('wc_add_notice')) {
        wc_add_notice(
            sprintf(
                /* translators: 1: coupon code, 2: loyalty percentage */
                __('El cupón %1$s mejora tu descuento del %2$s%% por productos registrados, así que aplicamos el cupón. No son acumulables.', 'ial-reg'),
                strtoupper($applied_code),
                ial_loyalty_format_percent($percent)
            ),
            'notice'
        );
    }

    $running = false;
}
add_action('woocommerce_applied_coupon', 'ial_loyalty_arbitrate_coupons', 20, 1);
add_action('woocommerce_cart_loaded_from_session', 'ial_loyalty_arbitrate_coupons', 20, 1);

/* -------------------------------------------------------------------------
 * Display: cart lines, order records, product page notice
 * ---------------------------------------------------------------------- */

/** 5.00 -> "5", 7.50 -> "7,5" (locale aware, no trailing zeros). */
function ial_loyalty_format_percent($percent)
{
    $percent = (float) $percent;

    if (floor($percent) == $percent) {
        return number_format_i18n($percent, 0);
    }

    $separator = isset($GLOBALS['wp_locale']->number_format['decimal_point'])
        ? $GLOBALS['wp_locale']->number_format['decimal_point']
        : '.';

    return rtrim(rtrim(number_format_i18n($percent, 2), '0'), $separator);
}

/**
 * "Iniciado − 5% aplicado", or just "−5% aplicado" when the tier has no name.
 */
function ial_loyalty_line_label($loyalty)
{
    $percent = ial_loyalty_format_percent(isset($loyalty['percent']) ? $loyalty['percent'] : 0);
    $tier    = isset($loyalty['tier']) ? (string) $loyalty['tier'] : '';

    if ('' !== $tier) {
        return sprintf(
            /* translators: 1: tier name, 2: discount percentage */
            __('%1$s − %2$s%% aplicado', 'ial-reg'),
            $tier,
            $percent
        );
    }

    /* translators: %s: discount percentage */
    return sprintf(__('−%s%% aplicado', 'ial-reg'), $percent);
}

/**
 * Printed under the product name rather than through `woocommerce_get_item_data`,
 * because that one always renders as "Key: value" and we want the bare text.
 */
add_filter('woocommerce_cart_item_name', 'ial_loyalty_cart_item_name', 10, 3);
function ial_loyalty_cart_item_name($name, $cart_item, $cart_item_key)
{
    if (empty($cart_item['ial_loyalty'])) {
        return $name;
    }

    // At checkout the quantity is printed right after the name, so an inline
    // span keeps that line readable; everywhere else it gets its own line.
    $inline = (function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url('order-received'));

    return $name . sprintf(
        '<span class="ial-loyalty-line%1$s">%2$s</span>',
        $inline ? ' ial-loyalty-line-inline' : '',
        esc_html(ial_loyalty_line_label($cart_item['ial_loyalty']))
    );
}

add_action('woocommerce_checkout_create_order_line_item', 'ial_loyalty_save_line_item_meta', 10, 4);
function ial_loyalty_save_line_item_meta($item, $cart_item_key, $values, $order)
{
    if (empty($values['ial_loyalty'])) {
        return;
    }

    $percent = (float) $values['ial_loyalty']['percent'];
    $saving  = (float) $values['ial_loyalty']['saving'];
    $tier    = isset($values['ial_loyalty']['tier']) ? (string) $values['ial_loyalty']['tier'] : '';

    // Visible on the order and in emails, so the customer sees why the price
    // is what it is. Order item meta always renders as "label: value", so the
    // tier name doubles as the label — "Iniciado: −5% aplicado".
    $item->add_meta_data(
        '' !== $tier ? $tier : __('Descuento', 'ial-reg'),
        sprintf(
            /* translators: %s: discount percentage */
            __('−%s%% aplicado', 'ial-reg'),
            ial_loyalty_format_percent($percent)
        ),
        true
    );
    $item->add_meta_data('_ial_loyalty_percent', $percent, true);
    $item->add_meta_data('_ial_loyalty_saved', wc_format_decimal($saving, wc_get_price_decimals()), true);
    if ('' !== $tier) {
        $item->add_meta_data('_ial_loyalty_tier', $tier, true);
    }
}

add_action('woocommerce_checkout_create_order', 'ial_loyalty_save_order_meta', 10, 2);
function ial_loyalty_save_order_meta($order, $data)
{
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }

    $total   = 0.0;
    $percent = 0.0;

    foreach (WC()->cart->get_cart() as $item) {
        if (empty($item['ial_loyalty'])) {
            continue;
        }
        $total  += (float) $item['ial_loyalty']['saving'];
        $percent = (float) $item['ial_loyalty']['percent'];
    }

    if ($total <= 0) {
        return;
    }

    // The discount lives in the line prices, so it never shows up as a
    // discount in WooCommerce reports. Recording it here keeps the programme
    // auditable: how much it cost, and at what level.
    $order->update_meta_data('_ial_loyalty_percent', $percent);
    $order->update_meta_data('_ial_loyalty_discount_total', wc_format_decimal($total, wc_get_price_decimals()));
}

add_action('woocommerce_admin_order_data_after_order_details', 'ial_loyalty_admin_order_summary');
function ial_loyalty_admin_order_summary($order)
{
    if (!$order instanceof WC_Order) {
        return;
    }

    $percent = (float) $order->get_meta('_ial_loyalty_percent');
    $total   = (float) $order->get_meta('_ial_loyalty_discount_total');
    if ($total <= 0) {
        return;
    }

    echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Loyalty discount', 'ial-reg') . ':</strong> ';
    printf(
        /* translators: 1: percentage, 2: amount */
        esc_html__('%1$s%% off for registered products (%2$s).', 'ial-reg'),
        esc_html(ial_loyalty_format_percent($percent)),
        wp_kses_post(wc_price($total))
    );
    echo '</p>';
}

/**
 * Notice for the product page. The catalogue keeps showing the public price —
 * the discount is applied in the cart — so nothing here depends on the visitor
 * and page caching stays safe.
 *
 * @param WC_Product|null $product Product it refers to, if any. Without one the
 *                                 notice is generic (no "the offer is better"
 *                                 check and no category exclusion).
 * @return string HTML, or '' when there is nothing to say.
 */
function ial_loyalty_get_notice_html($product = null)
{
    $settings = ial_loyalty_get_settings();
    if (!ial_loyalty_is_active() || empty($settings['show_notice']) || !is_user_logged_in()) {
        return '';
    }

    if (!$product instanceof WC_Product) {
        $product = null;
    } elseif (!ial_loyalty_product_qualifies($product)) {
        return '';
    }

    $state   = ial_loyalty_get_user_state(get_current_user_id());
    $percent = (float) $state['percent'];
    $classes = 'ial-loyalty-notice';

    if ($percent > 0) {
        $beaten = false;
        if ($product) {
            $regular = (float) $product->get_regular_price();
            $current = (float) $product->get_price();
            if ($regular > 0 && $current < $regular) {
                $target = round($regular * (1 - $percent / 100), wc_get_price_decimals());
                $beaten = ($target >= $current);
            }
        }

        if ($beaten) {
            $text = __('Este producto ya tiene una oferta mejor que tu descuento permanente, así que se queda el precio de oferta.', 'ial-reg');
        } else {
            $tier = ial_loyalty_current_tier($state['count']);
            if ($tier && '' !== $tier['label']) {
                $text = sprintf(
                    /* translators: 1: tier name, 2: discount percentage */
                    __('Nivel %1$s: tienes un %2$s%% de descuento permanente. Se aplica automáticamente en el carrito.', 'ial-reg'),
                    $tier['label'],
                    ial_loyalty_format_percent($percent)
                );
            } else {
                $text = sprintf(
                    /* translators: %s: discount percentage */
                    __('Tienes un %s%% de descuento permanente por tus productos registrados. Se aplica automáticamente en el carrito.', 'ial-reg'),
                    ial_loyalty_format_percent($percent)
                );
            }
        }
    } else {
        // No level yet: tell them what the first one is worth.
        $next = ial_loyalty_next_tier($state['count']);
        if (!$next) {
            return '';
        }
        $classes .= ' ial-loyalty-notice-teaser';
        $text = sprintf(
            /* translators: 1: number of products, 2: discount percentage */
            _n(
                'Registra %1$s producto y consigue un %2$s%% de descuento permanente en tus compras.',
                'Registra %1$s productos y consigue un %2$s%% de descuento permanente en tus compras.',
                (int) $next['threshold'],
                'ial-reg'
            ),
            number_format_i18n($next['threshold']),
            ial_loyalty_format_percent($next['percent'])
        );
    }

    return sprintf(
        '<p class="%1$s"><span class="ial-loyalty-notice-icon" aria-hidden="true">★</span> %2$s</p>',
        esc_attr($classes),
        esc_html($text)
    );
}

function ial_loyalty_print_product_notice()
{
    global $product;
    echo ial_loyalty_get_notice_html($product);
}

/**
 * Placement is filterable because page builders and heavily customised themes
 * often replace the product template and never fire the standard hooks.
 * `[ial_loyalty_notice]` is the escape hatch when that happens.
 */
add_action('init', 'ial_loyalty_register_notice_placement', 20);
function ial_loyalty_register_notice_placement()
{
    $hook = (string) apply_filters('ial_loyalty_notice_hook', 'woocommerce_single_product_summary');
    if ('' === $hook) {
        return;
    }
    $priority = (int) apply_filters('ial_loyalty_notice_priority', 11);
    add_action($hook, 'ial_loyalty_print_product_notice', $priority);
}

add_shortcode('ial_loyalty_notice', 'ial_loyalty_notice_shortcode');
function ial_loyalty_notice_shortcode()
{
    global $product;
    return ial_loyalty_get_notice_html($product);
}
