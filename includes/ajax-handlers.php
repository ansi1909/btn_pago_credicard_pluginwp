<?php

add_action('wp_ajax_credicard_create_payment', 'credicard_create_payment_handler');
add_action('wp_ajax_nopriv_credicard_create_payment', 'credicard_create_payment_handler');

function credicard_create_payment_handler() {
    check_ajax_referer('credicard_create_payment', 'security');

    $gateway = new WC_Gateway_Credicard();
    $api_url = trailingslashit($gateway->api_url) . 'v1/api/commerce/paymentOrder/clientCredentials';

    $client_id    = $gateway->client_id;
    $client_secret= $gateway->client_secret;

    // Obtener email del cliente o fallback al correo del admin
    $email = WC()->customer ? WC()->customer->get_email() : '';
    if (empty($email)) {
        $email = get_option('admin_email');
    }

    // Obtener monto total del carrito
    $amount = WC()->cart->get_total('edit');
    $amount = floatval(preg_replace('/[^0-9.]/', '', $amount));
    $amount = round($amount, 2);

    if ($amount <= 0) {
        wp_send_json_error(['message' => 'El total del carrito es inválido.']);
    }

    $body = [
        'email'   => $email,
        'amount'  => $amount,
        'concept' => 'Pago con Credicard',
    ];

    $response = wp_remote_post($api_url, [
        'headers' => [
            'client-id'     => $client_id,
            'client-secret' => $client_secret,
            'Content-Type'  => 'application/json',
        ],
        'body'        => json_encode($body),
        'timeout'     => 15,
        'data_format' => 'body',
    ]);

    error_log("[Credicard] EL REPONSE CREACION TRAE: " . print_r($response, true));

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Error al conectar con Credicard.']);
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 201 && !empty($data['data']['id']) && !empty($data['data']['paymentUrl'])) {
        
        $payment_id = $data['data']['id'];
        //Guardar billing en transient 
        $billing_data = [
            'billing_first_name' => sanitize_text_field($_POST['billing_first_name'] ?? ''),
            'billing_last_name'  => sanitize_text_field($_POST['billing_last_name'] ?? ''),
            'billing_email'      => sanitize_email($_POST['billing_email'] ?? ''),
            'billing_phone'      => sanitize_text_field($_POST['billing_phone'] ?? ''),
            'fecha_asistencia'   => sanitize_text_field($_POST['fecha_asistencia'] ?? ''),
            'cart_items'         => [],
        ];   
        
        foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
            $product_id = $values['product_id'];
            $quantity   = $values['quantity'];
            $item_data  = [
                'product_id' => $product_id,
                'quantity'   => $quantity,
            ];

            // También guardamos la fecha de asistencia por ítem
            if (!empty($billing_data['fecha_asistencia'])) {
                $item_data['fecha_asistencia'] = $billing_data['fecha_asistencia'];
            }

            $billing_data['cart_items'][] = $item_data;
        }
        
        set_transient("credicard_billing_$payment_id", $billing_data, 3600);

        error_log("[Credicard] BILLING DATA GUARDADO EN TRANSIENT: " . print_r($billing_data, true));

        wp_send_json_success([
            'id' => $payment_id,
            'paymentUrl' => $data['data']['paymentUrl'],
        ]);
    } else {
        wp_send_json_error(['message' => 'No se pudo generar el pago con Credicard.']);
    }
}
