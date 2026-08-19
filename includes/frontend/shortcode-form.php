<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get the real client IP address, accounting for proxies and load balancers.
function ial_get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'];

    if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
        $forwarded_ip = trim( reset( $ips ) );
        if ( filter_var( $forwarded_ip, FILTER_VALIDATE_IP ) ) {
            return $forwarded_ip;
        }
    }

    return filter_var( $ip, FILTER_VALIDATE_IP );
}

// Product a serial belongs to, resolved through its production batch.
function ial_get_serial_product_id( $serial_id ) {
    $production_id = get_post_meta( $serial_id, 'production', true );
    // Handle legacy ACF post object return if mixed data
    if ( is_object( $production_id ) ) {
        $production_id = $production_id->ID;
    }
    if ( ! $production_id ) {
        return 0;
    }

    $product_id = get_post_meta( $production_id, 'product', true );
    if ( is_object( $product_id ) ) {
        $product_id = $product_id->ID;
    }

    return (int) $product_id;
}

/**
 * Find a post of type 'ial_serial' by its exact title (the serial number).
 *
 * Serials are meant to be globally unique — batch creation enforces it — but
 * imported or hand-made data can still hold duplicates. When several posts
 * share a title, prefer the one that belongs to the product the customer
 * selected and is not registered yet, so a stale duplicate never makes a
 * legitimate registration look like it was already taken.
 */
function ial_get_serial_post_by_title( $title, $product_id = 0 ) {
    global $wpdb;

    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'ial_serial' AND post_status = 'publish' ORDER BY ID ASC",
        $title
    ) );

    if ( empty( $ids ) ) {
        return null;
    }

    if ( count( $ids ) === 1 || ! $product_id ) {
        return get_post( (int) $ids[0] );
    }

    $same_product = 0;
    foreach ( $ids as $id ) {
        $id = (int) $id;
        if ( ial_get_serial_product_id( $id ) !== (int) $product_id ) {
            continue;
        }
        if ( ! get_post_meta( $id, 'uid', true ) ) {
            return get_post( $id ); // Right product and still free: best match.
        }
        if ( ! $same_product ) {
            $same_product = $id;
        }
    }

    return get_post( $same_product ? $same_product : (int) $ids[0] );
}

// URL of the page listing the customer's registered products, if there is one.
function ial_registration_my_products_url() {
    if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
        return wc_get_account_endpoint_url( 'productos-registrados' );
    }

    return '';
}

// Where someone without a session goes to get one.
function ial_registration_login_url() {
    if ( function_exists( 'wc_get_page_permalink' ) ) {
        $account = wc_get_page_permalink( 'myaccount' );
        if ( $account ) {
            return $account;
        }
    }

    return wp_login_url( ial_registration_current_url() );
}

// Current frontend URL, used for the post/redirect/get round trip.
function ial_registration_current_url() {
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

    return remove_query_arg( 'ial_reg', $request_uri );
}

// Per-IP rate limit context, shared by the handler and the rendered form.
function ial_registration_rate_limit() {
    $ip_address = ial_get_client_ip();

    return array(
        'count_key'    => $ip_address ? 'ial_count_' . md5( $ip_address ) : '',
        'block_key'    => $ip_address ? 'ial_block_' . md5( $ip_address ) : '',
        'max_attempts' => 5,   // Failed submissions allowed per minute.
        'lockout_time' => 600, // 10 minutes.
    );
}

function ial_registration_blocked_message( $rate ) {
    return sprintf(
        __( 'Error: Has superado el número máximo de intentos. Por favor, espera %d minutos.', 'ial-reg' ),
        round( $rate['lockout_time'] / 60 )
    );
}

// Build one outcome of a submission: type is 'success', 'notice' or 'error'.
function ial_registration_result( $type, $message, $fields = array(), $link = array() ) {
    return array(
        'type'    => $type,
        'message' => $message,
        'fields'  => $fields,
        'link'    => $link,
    );
}

/**
 * Error result for a lookup failure, counted against the per-IP rate limit.
 *
 * Only failures that look like serial guessing are counted. Validation errors
 * and re-submissions of the customer's own serial are not, so registering
 * several products in a row can never lock anyone out.
 */
function ial_registration_fail( $message, $fields, $rate ) {
    if ( ! empty( $rate['count_key'] ) ) {
        $attempts = (int) get_transient( $rate['count_key'] ) + 1;
        set_transient( $rate['count_key'], $attempts, 60 ); // Failures within a 60-second window.

        if ( $attempts > $rate['max_attempts'] ) {
            set_transient( $rate['block_key'], true, $rate['lockout_time'] );
            $message = ial_registration_blocked_message( $rate );
        }
    }

    return ial_registration_result( 'error', $message, $fields );
}

/**
 * Fire the post-registration listeners after the response has been sent.
 *
 * Registering a serial drags in slow, external work: the AcyMailing
 * subscription, whatever the role plugins do when a role is added, any mail
 * that goes out over SMTP. The customer used to wait for all of it with the
 * button still live, which is precisely what produced the double clicks. The
 * serial is already claimed by the time this runs, so none of it needs to
 * happen before the browser gets its answer.
 *
 * Falls back to running everything inline when the SAPI cannot close the
 * connection early (mod_php), which is the behaviour this replaces.
 */
function ial_registration_dispatch_side_effects( $serial_id, $user_id, $product_id ) {
    $can_close = function_exists( 'litespeed_finish_request' ) || function_exists( 'fastcgi_finish_request' );

    /**
     * Whether to run the registration side effects after the response.
     *
     * @param bool $deferred   True when the connection can be closed early.
     * @param int  $serial_id  Serial that was just registered.
     * @param int  $user_id    User it was registered to.
     * @param int  $product_id Product the serial belongs to.
     */
    $deferred = apply_filters( 'ial_defer_registration_side_effects', $can_close, $serial_id, $user_id, $product_id );

    if ( ! $deferred ) {
        do_action( 'ial_user_registered_product', $serial_id, $user_id, $product_id );
        return;
    }

    register_shutdown_function( function () use ( $serial_id, $user_id, $product_id ) {
        // Keep going once the browser is gone: from here on nobody is waiting.
        ignore_user_abort( true );

        if ( function_exists( 'litespeed_finish_request' ) ) {
            litespeed_finish_request();
        } elseif ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        }

        do_action( 'ial_user_registered_product', $serial_id, $user_id, $product_id );
    } );
}

/**
 * Validate and store one submission. Always returns a result array.
 */
function ial_run_registration_submission() {
    $rate = ial_registration_rate_limit();

    // Sanitize input. Values are kept around to repopulate the form on error.
    $fields = array(
        'product_id'    => isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0,
        'serial_number' => isset( $_POST['serial_number'] ) ? sanitize_text_field( wp_unslash( $_POST['serial_number'] ) ) : '',
        'u_name'        => isset( $_POST['u_name'] ) ? sanitize_text_field( wp_unslash( $_POST['u_name'] ) ) : '',
        'purchase'      => isset( $_POST['purchase'] ) ? sanitize_text_field( wp_unslash( $_POST['purchase'] ) ) : '',
        'seller'        => isset( $_POST['seller'] ) ? sanitize_text_field( wp_unslash( $_POST['seller'] ) ) : '',
    );

    // Nonce verification for frontend
    if ( ! isset( $_POST['ial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ial_nonce'] ) ), 'ial_register_action' ) ) {
        return ial_registration_result( 'error', __( 'Error: La sesión ha caducado. Por favor, recarga la página.', 'ial-reg' ), $fields );
    }

    // Blocked by the rate limiter from previous failures.
    if ( ! empty( $rate['block_key'] ) && get_transient( $rate['block_key'] ) ) {
        return ial_registration_result( 'error', ial_registration_blocked_message( $rate ), $fields );
    }

    // A serial is linked to an account, so registering requires being logged in.
    if ( ! is_user_logged_in() ) {
        return ial_registration_result(
            'error',
            __( 'Error: Debes iniciar sesión con tu cuenta para registrar un producto.', 'ial-reg' ),
            $fields,
            array(
                'url'  => ial_registration_login_url(),
                'text' => __( 'Iniciar sesión', 'ial-reg' ),
            )
        );
    }

    // Check for required fields.
    if ( ! $fields['product_id'] || ! $fields['serial_number'] || ! $fields['u_name'] || ! $fields['purchase'] || ! $fields['seller'] ) {
        return ial_registration_result( 'error', __( 'Error: Por favor, rellena todos los campos obligatorios.', 'ial-reg' ), $fields );
    }

    // Find the serial number post.
    $serial_post = ial_get_serial_post_by_title( $fields['serial_number'], $fields['product_id'] );

    if ( ! $serial_post ) {
        return ial_registration_fail( __( 'Error: El número de serie no es válido.', 'ial-reg' ), $fields, $rate );
    }

    // Verify Product and Serial Relationship
    $actual_product_id = ial_get_serial_product_id( $serial_post->ID );

    if ( $actual_product_id !== (int) $fields['product_id'] ) {
        return ial_registration_fail( __( 'Error: Este número de serie no pertenece al producto seleccionado.', 'ial-reg' ), $fields, $rate );
    }

    $registered_uid = (int) get_post_meta( $serial_post->ID, 'uid', true );
    $current_user   = wp_get_current_user();

    if ( $registered_uid === (int) $current_user->ID ) {
        // Already registered by this very customer: a double click, a page
        // refresh or a second attempt. Nothing to do, and nothing is wrong.
        $my_products_url = ial_registration_my_products_url();

        return ial_registration_result(
            'notice',
            __( 'Este número de serie ya está registrado en tu cuenta, no hace falta que lo registres otra vez.', 'ial-reg' ),
            array(),
            $my_products_url ? array(
                'url'  => $my_products_url,
                'text' => __( 'Ver tus productos registrados', 'ial-reg' ),
            ) : array()
        );
    }

    if ( $registered_uid ) {
        return ial_registration_fail(
            __( 'Error: Este número de serie ya ha sido registrado con otra cuenta. Si crees que es un error, ponte en contacto con nosotros.', 'ial-reg' ),
            $fields,
            $rate
        );
    }

    // Success: Update Serial Post with Registration Data
    update_post_meta( $serial_post->ID, 'uid', $current_user->ID );
    update_post_meta( $serial_post->ID, 'u_name', $fields['u_name'] );
    update_post_meta( $serial_post->ID, 'purchase', $fields['purchase'] );
    update_post_meta( $serial_post->ID, 'seller', $fields['seller'] );

    // The discount level costs one query and the customer may look at it on
    // the very next page, so it is the one thing recalculated inline. The
    // deferred chain recomputes it too, harmlessly.
    if ( function_exists( 'ial_loyalty_refresh_user' ) ) {
        ial_loyalty_refresh_user( $current_user->ID );
    }

    ial_registration_dispatch_side_effects( $serial_post->ID, $current_user->ID, $actual_product_id );

    $my_products_url = ial_registration_my_products_url();

    return ial_registration_result(
        'success',
        __( '¡Registro completo! Gracias por registrar tu producto.', 'ial-reg' ),
        array(),
        $my_products_url ? array(
            'url'  => $my_products_url,
            'text' => __( 'Ver tus productos registrados', 'ial-reg' ),
        ) : array()
    );
}

/**
 * Process a submission at most once per request.
 *
 * The shortcode can be rendered more than once in a single page load (page
 * builders and themes that run `the_content` twice), and processing it inside
 * the render would then register the serial on the first pass and report it as
 * already registered on the second. Returns null when there is nothing to
 * process.
 */
function ial_process_registration_submission() {
    static $processed = false;
    static $result    = null;

    if ( $processed ) {
        return $result;
    }
    $processed = true;

    if ( empty( $_POST['ial_submit'] ) ) {
        return null;
    }

    $result = ial_run_registration_submission();

    return $result;
}

// Keep the outcome across the redirect below; the token travels in the URL.
function ial_registration_store_result( $result ) {
    $token = wp_generate_password( 20, false );
    set_transient( 'ial_reg_result_' . $token, $result, 5 * MINUTE_IN_SECONDS );

    return $token;
}

function ial_registration_stored_result() {
    if ( empty( $_GET['ial_reg'] ) ) {
        return null;
    }

    $token = preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_GET['ial_reg'] ) );
    if ( '' === $token ) {
        return null;
    }

    $result = get_transient( 'ial_reg_result_' . $token );

    return is_array( $result ) ? $result : null;
}

/**
 * Feedback banner for one submission outcome. Shared by the form and by the
 * customer's products panel, which is where a successful registration lands.
 */
function ial_registration_render_result( $result ) {
    if ( empty( $result['type'] ) ) {
        return '';
    }

    $classes = array(
        'success' => 'ial-form-success',
        'notice'  => 'ial-form-notice',
        'error'   => 'ial-form-error',
    );
    $class = isset( $classes[ $result['type'] ] ) ? $classes[ $result['type'] ] : 'ial-form-error';

    $html = '<p class="' . esc_attr( $class ) . '">' . esc_html( $result['message'] );
    if ( ! empty( $result['link']['url'] ) ) {
        $html .= ' <a href="' . esc_url( $result['link']['url'] ) . '">' . esc_html( $result['link']['text'] ) . '</a>';
    }
    $html .= '</p>';

    return $html;
}

/**
 * Where to send the customer once the serial is theirs: their own products
 * panel, so they can see what they just registered. Falls back to the form
 * page when there is no such panel (no WooCommerce).
 */
function ial_registration_success_redirect_url() {
    /**
     * Filter the landing page after a successful registration.
     *
     * Return an empty string to keep the customer on the form.
     *
     * @param string $url Products panel URL, empty when there is none.
     */
    return (string) apply_filters( 'ial_registration_success_redirect', ial_registration_my_products_url() );
}

/**
 * Post/redirect/get: handle the submission before anything is rendered and
 * bounce the browser to a plain GET, so reloading the page (or hitting back)
 * cannot send the same registration twice.
 */
add_action( 'template_redirect', 'ial_registration_handle_submit' );
function ial_registration_handle_submit() {
    if ( empty( $_POST['ial_submit'] ) ) {
        return;
    }

    $result = ial_process_registration_submission();

    // If the redirect cannot happen (output already started, or a page builder
    // posting over AJAX) the shortcode still renders the cached result.
    if ( ! $result || headers_sent() ) {
        return;
    }

    // A settled serial — just registered, or already theirs — goes to the
    // customer's products panel, where they can see it. Anything they have to
    // correct stays on the form, with the fields they typed.
    $settled = in_array( $result['type'], array( 'success', 'notice' ), true );
    $target  = $settled ? ial_registration_success_redirect_url() : '';

    if ( $target ) {
        // The panel is the page they are landing on, so the link to it goes.
        $result['link'] = array();
    } else {
        $target = ial_registration_current_url();
    }

    $url = add_query_arg( 'ial_reg', ial_registration_store_result( $result ), $target );

    wp_safe_redirect( $url, 303 );
    exit;
}

// Register and render the product registration form shortcode.
function ial_registration_form_shortcode() {
    ob_start();

    // A serial is linked to an account, so there is nothing to fill in without
    // one. Where a page builder already gates the row by role this never runs
    // — the shortcode is not reached at all — so the two can never both show.
    if ( ! is_user_logged_in() ) {
        echo '<div id="ial-registration-wrapper" class="ial-registration-container">';
        echo '<h3>' . esc_html__( 'Registra tu producto', 'ial-reg' ) . '</h3>';
        echo '<p class="ial-form-notice">';
        echo esc_html__( 'Necesitas una cuenta para registrar un producto.', 'ial-reg' );
        echo ' <a href="' . esc_url( ial_registration_login_url() ) . '">';
        echo esc_html__( 'Accede o crea una', 'ial-reg' ) . '</a>';
        echo '</p>';
        echo '</div>';

        return ob_get_clean();
    }

    $result = ial_process_registration_submission();
    if ( null === $result ) {
        $result = ial_registration_stored_result();
    }

    // Still blocked by the rate limiter: say so before anything is retried.
    if ( null === $result ) {
        $rate = ial_registration_rate_limit();
        if ( ! empty( $rate['block_key'] ) && get_transient( $rate['block_key'] ) ) {
            $result = ial_registration_result( 'error', ial_registration_blocked_message( $rate ) );
        }
    }

    // Render the form and feedback messages.
    echo '<div id="ial-registration-wrapper" class="ial-registration-container">';
    echo '<h3>' . esc_html__( 'Registra tu producto', 'ial-reg' ) . '</h3>';

    if ( $result ) {
        echo ial_registration_render_result( $result );
    }

    // Add JavaScript to scroll to the form messages after submission.
    if ( $result ) {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var element = document.getElementById('ial-registration-wrapper');
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
        </script>
        <?php
    }

    // Prepare Form Data. Get products for the dropdown menu.
    $products = get_posts( array(
        'post_type'      => 'ial_product',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_key'       => 'frontend_enable',
        'meta_value'     => '1',
    ) );

    // Repopulate form fields on error to improve user experience.
    $posted_data = ( $result && ! empty( $result['fields'] ) ) ? $result['fields'] : array();

    // Render Form HTML
    ?>
    <form action="" method="POST" class="ial-registration-form">
        <?php wp_nonce_field( 'ial_register_action', 'ial_nonce' ); ?>

        <p>
            <label for="u_name"><?php esc_html_e( 'Nombre', 'ial-reg' ); ?> *</label><br>
            <input type="text" id="u_name" name="u_name" class="ial-input" value="<?php echo esc_attr( $posted_data['u_name'] ?? '' ); ?>" required>
        </p>

        <p>
            <label for="product_id"><?php esc_html_e( 'Producto', 'ial-reg' ); ?> *</label><br>
            <select id="product_id" name="product_id" class="ial-input" required>
                <option value=""><?php esc_html_e( '— Selecciona un Producto —', 'ial-reg' ); ?></option>
                <?php foreach ( $products as $p ) : ?>
                    <option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $posted_data['product_id'] ?? 0, $p->ID ); ?>>
                        <?php echo esc_html( $p->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label for="serial_number"><?php esc_html_e( 'Número de Serie', 'ial-reg' ); ?> *</label><br>
            <input type="text" id="serial_number" name="serial_number" class="ial-input" value="<?php echo esc_attr( $posted_data['serial_number'] ?? '' ); ?>" required>
        </p>

        <p>
            <label for="purchase_date"><?php esc_html_e( 'Fecha de Compra', 'ial-reg' ); ?> *</label><br>
            <input type="date" id="purchase_date" name="purchase" class="ial-input" value="<?php echo esc_attr( $posted_data['purchase'] ?? '' ); ?>" required>
        </p>

        <p>
            <label for="seller"><?php esc_html_e( '¿Dónde lo compraste? (Vendedor)', 'ial-reg' ); ?> *</label><br>
            <input type="text" id="seller" name="seller" class="ial-input" value="<?php echo esc_attr( $posted_data['seller'] ?? '' ); ?>" required>
        </p>

        <p>
            <input type="submit" name="ial_submit" class="ial-submit" value="<?php esc_attr_e( 'Registrar Producto', 'ial-reg' ); ?>">
        </p>
    </form>
    <script>
    // Block the second click: a registration can take a moment (mailing list,
    // roles) and an impatient double click used to come back as "ya registrado".
    (function() {
        if (window.ialRegFormBound) { return; }
        window.ialRegFormBound = true;

        var WORKING = <?php echo wp_json_encode( __( 'Registrando…', 'ial-reg' ) ); ?>;

        document.addEventListener('submit', function(event) {
            var form = event.target;
            if (!form || !form.classList || !form.classList.contains('ial-registration-form')) { return; }

            if (form.dataset.ialSubmitting === '1') {
                event.preventDefault(); // First registration still in flight.
                return;
            }
            form.dataset.ialSubmitting = '1';

            var button = form.querySelector('.ial-submit');
            if (!button) { return; }
            button.dataset.ialLabel = button.value;
            // Disabling the button right away would drop its value from the
            // POST, so wait until the browser has built the request.
            window.setTimeout(function() {
                button.disabled = true;
                button.value = WORKING;
            }, 0);
        });

        // Coming back through the browser history restores the page as it was
        // left, disabled button included.
        window.addEventListener('pageshow', function() {
            var forms = document.querySelectorAll('.ial-registration-form');
            for (var i = 0; i < forms.length; i++) {
                forms[i].dataset.ialSubmitting = '';
                var button = forms[i].querySelector('.ial-submit');
                if (button) {
                    button.disabled = false;
                    if (button.dataset.ialLabel) { button.value = button.dataset.ialLabel; }
                }
            }
        });
    })();
    </script>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'ial_registration_form', 'ial_registration_form_shortcode' );
