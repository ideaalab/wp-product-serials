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

// Find a post of type 'ial_serial' by its exact title (the serial number).
function ial_get_serial_post_by_title( $title ) {
    global $wpdb;

    $query = $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'ial_serial' AND post_status = 'publish' LIMIT 1",
        $title
    );
    $post_id = $wpdb->get_var( $query );

    return $post_id ? get_post( $post_id ) : null;
}

// Register and render the product registration form shortcode.
function ial_registration_form_shortcode() {
    ob_start();

    $success_message = '';
    $error_message   = '';

    // Security: Rate Limiting Configuration
    $max_attempts = 5; // Max submissions per minute
    $lockout_time = 600; // 10 minutes

    $ip_address = ial_get_client_ip();
    $block_key  = '';
    $count_key  = '';

    if ( $ip_address ) {
        $block_key = 'ial_block_' . md5( $ip_address );
        $count_key = 'ial_count_' . md5( $ip_address );

        // 1. Check if the user is currently blocked.
        if ( get_transient( $block_key ) ) {
            $error_message = sprintf(
                __( 'Error: Has superado el número máximo de intentos. Por favor, espera %d minutos.', 'ial-reg' ),
                round( $lockout_time / 60 )
            );
        }
    }

    // 2. Process the form if it has been submitted.
    if ( empty( $error_message ) && isset( $_POST['ial_submit'] ) ) {
        
        // Nonce verification for frontend
        if ( ! isset( $_POST['ial_nonce'] ) || ! wp_verify_nonce( $_POST['ial_nonce'], 'ial_register_action' ) ) {
            $error_message = __( 'Error: La sesión ha caducado. Por favor, recarga la página.', 'ial-reg' );
        } else {
            // Rate Limiting Logic 
            if ( $ip_address ) {
                $attempts = (int) get_transient( $count_key ) + 1;
                set_transient( $count_key, $attempts, 60 ); // Count attempts within a 60-second window.

                if ( $attempts > $max_attempts ) {
                    set_transient( $block_key, true, $lockout_time );
                    $error_message = __( 'Error: Has superado el número máximo de intentos.', 'ial-reg' );
                }
            }

            if ( empty( $error_message ) ) {
                // Sanitize and Validate Input
                $product_id    = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
                $serial_number = isset( $_POST['serial_number'] ) ? sanitize_text_field( $_POST['serial_number'] ) : '';
                $user_name     = isset( $_POST['u_name'] ) ? sanitize_text_field( $_POST['u_name'] ) : '';
                $purchase_date = isset( $_POST['purchase'] ) ? sanitize_text_field( $_POST['purchase'] ) : '';
                $seller_name   = isset( $_POST['seller'] ) ? sanitize_text_field( $_POST['seller'] ) : '';
                $current_user  = wp_get_current_user();

                // Check for required fields.
                if ( ! $product_id || ! $serial_number || ! $user_name || ! $purchase_date || ! $seller_name ) {
                    $error_message = __( 'Error: Por favor, rellena todos los campos obligatorios.', 'ial-reg' );
                } else {
                    // Find the serial number post.
                    $serial_post = ial_get_serial_post_by_title( $serial_number );

                    if ( $serial_post ) {
                        // Verify Product and Serial Relationship
                        $production_id = get_post_meta( $serial_post->ID, 'production', true );
                        // Handle legacy ACF post object return if mixed data
                        if ( is_object($production_id) ) $production_id = $production_id->ID;

                        $actual_product_id = 0;
                        if ( $production_id ) {
                            $prod_id_meta = get_post_meta( $production_id, 'product', true );
                            $actual_product_id = is_object( $prod_id_meta ) ? $prod_id_meta->ID : $prod_id_meta;
                        }

                        if ( (int) $actual_product_id !== (int) $product_id ) {
                            $error_message = __( 'Error: Este número de serie no pertenece al producto seleccionado.', 'ial-reg' );
                        } elseif ( get_post_meta( $serial_post->ID, 'uid', true ) ) {
                            $error_message = __( 'Error: Este número de serie ya ha sido registrado.', 'ial-reg' );
                        } else {
                            // Success: Update Serial Post with Registration Data
                            update_post_meta( $serial_post->ID, 'u_name', $user_name );
                            update_post_meta( $serial_post->ID, 'purchase', $purchase_date );
                            update_post_meta( $serial_post->ID, 'seller', $seller_name );

                            if ( $current_user->ID ) {
                                update_post_meta( $serial_post->ID, 'uid', $current_user->ID );
                            }

                            do_action( 'ial_user_registered_product', $serial_post->ID, $current_user->ID, (int)$actual_product_id );

                            $success_message = __( '¡Registro completo! Gracias por registrar tu producto.', 'ial-reg' );
                        }
                    } else {
                        $error_message = __( 'Error: El número de serie no es válido.', 'ial-reg' );
                    }
                }
            }
        }
    }

    // Render the form and feedback messages.
    echo '<div id="ial-registration-wrapper" class="ial-registration-container">';
    echo '<h3>' . esc_html__( 'Registra tu producto', 'ial-reg' ) . '</h3>';

    if ( $success_message ) {
        echo '<p class="ial-form-success">' . esc_html( $success_message ) . '</p>';
    }
    if ( $error_message ) {
        echo '<p class="ial-form-error">' . esc_html( $error_message ) . '</p>';
    }

    // Add JavaScript to scroll to the form messages after submission.
    if ( $success_message || $error_message ) {
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
    $posted_data = array();
    if ( $error_message ) {
        $posted_data['product_id']    = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $posted_data['serial_number'] = isset( $_POST['serial_number'] ) ? esc_attr( $_POST['serial_number'] ) : '';
        $posted_data['u_name']        = isset( $_POST['u_name'] ) ? esc_attr( $_POST['u_name'] ) : '';
        $posted_data['purchase']      = isset( $_POST['purchase'] ) ? esc_attr( $_POST['purchase'] ) : '';
        $posted_data['seller']        = isset( $_POST['seller'] ) ? esc_attr( $_POST['seller'] ) : '';
    }

    // Render Form HTML
    ?>
    <form action="" method="POST" class="ial-registration-form">
        <?php wp_nonce_field( 'ial_register_action', 'ial_nonce' ); ?>

        <p>
            <label for="u_name"><?php esc_html_e( 'Nombre', 'ial-reg' ); ?> *</label><br>
            <input type="text" id="u_name" name="u_name" class="ial-input" value="<?php echo $posted_data['u_name'] ?? ''; ?>" required>
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
            <input type="text" id="serial_number" name="serial_number" class="ial-input" value="<?php echo $posted_data['serial_number'] ?? ''; ?>" required>
        </p>

        <p>
            <label for="purchase_date"><?php esc_html_e( 'Fecha de Compra', 'ial-reg' ); ?> *</label><br>
            <input type="date" id="purchase_date" name="purchase" class="ial-input" value="<?php echo $posted_data['purchase'] ?? ''; ?>" required>
        </p>

        <p>
            <label for="seller"><?php esc_html_e( '¿Dónde lo compraste? (Vendedor)', 'ial-reg' ); ?> *</label><br>
            <input type="text" id="seller" name="seller" class="ial-input" value="<?php echo $posted_data['seller'] ?? ''; ?>" required>
        </p>

        <p>
            <input type="submit" name="ial_submit" class="ial-submit" value="<?php esc_attr_e( 'Registrar Producto', 'ial-reg' ); ?>">
        </p>
    </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'ial_registration_form', 'ial_registration_form_shortcode' );
