<?php
// Webhook Credicard - Recepción de pagos exitosos

add_action('rest_api_init', function () {
    register_rest_route('credicard/v1', '/webhook/', [
        'methods'  => 'POST',
        'callback' => 'credicard_handle_webhook',
        'permission_callback' => '__return_true',
    ]);
});

function credicard_handle_webhook($request) {
    $params = $request->get_json_params();

    if (empty($params['payment_id']) || empty($params['status'])) {
        return new WP_REST_Response(['error' => 'Parámetros incompletos.'], 400);
    }

    if ($params['status'] !== 'payment-success-cc') {
        return new WP_REST_Response(['message' => 'Estado no procesado.'], 200);
    }

    $payment_id = sanitize_text_field($params['payment_id']);
    error_log("[Credicard - desde el webhook] PAYMENT ID: $payment_id");

    $orders = wc_get_orders([
        'meta_key' => '_credicard_payment_id',
        'meta_value' => $payment_id,
        'limit' => 1,
    ]);

    if (!empty($orders)) {
        return new WP_REST_Response(['message' => 'Orden ya existe.'], 200);
    }

    $billing_data = get_transient("credicard_billing_$payment_id");
    if (!$billing_data || !is_array($billing_data)) {
        return new WP_REST_Response(['error' => 'Datos no encontrados.'], 404);
    }

    // Crear usuario si no existe
    $user_id = null;
    $billing_email       = $billing_data['billing_email'];
    $billing_first_name  = $billing_data['billing_first_name'];
    $billing_last_name   = $billing_data['billing_last_name'];
    $billing_phone       = $billing_data['billing_phone'];

    $user_id = email_exists($billing_email);

    if($user_id){
        // Usuario existente
        $user = get_user_by('email', $billing_email);
        $user_id = $user->ID;
        error_log("[Credicard - desde el webhook] Usuario existente identificado con ID: $user_id");
    }
    else{
        $username = sanitize_user(current(explode('@', $billing_email)));
        $password = wp_generate_password();

        $user_id = wc_create_new_customer(
            $billing_email,
            $username,
            $password
        );
    }

    // Crear orden asociada al usuario
    $order = wc_create_order(['customer_id' => $user_id]);
    $order->set_payment_method('credicard');
    $order->set_payment_method_title('Credicard');
    $order->set_billing_first_name($billing_first_name);
    $order->set_billing_last_name($billing_last_name);
    $order->set_billing_email($billing_email);
    $order->set_billing_phone($billing_phone);
    // Agregar productos del carrito
    if (!empty($billing_data['cart_items']) && is_array($billing_data['cart_items'])) {
        foreach ($billing_data['cart_items'] as $item) {
            $product = wc_get_product($item['product_id']);
            if ($product) {
                $item_id = $order->add_product($product, $item['quantity']);

                if (!empty($item['fecha_asistencia'])) {
                    wc_add_order_item_meta($item_id, '_fecha_asistencia', $item['fecha_asistencia']);
                }
            }
        }
    }

    $order->add_meta_data('_credicard_payment_id', $payment_id);
    $order->calculate_totals();
    $order->update_status('processing');
    $order->save();
    delete_transient("credicard_billing_$payment_id");

    return new WP_REST_Response(['message' => 'Orden creada OK'], 200);
}
