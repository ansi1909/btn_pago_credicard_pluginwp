<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Credicard extends WC_Payment_Gateway {
    
    public $client_id;
    public $client_secret;
    public $api_url;

    public function __construct() {
        $this->id = 'credicard';
        $this->method_title = 'Botón de Pago Credicard';
        $this->method_description = 'Acepta pagos seguros mediante el botón de pago Credicard.';
        $this->has_fields = true;

        $this->init_form_fields();
        $this->init_settings();

        $this->title         = $this->get_option('title');
        $this->description   = $this->get_option('description');
        $this->enabled       = $this->get_option('enabled');
        $this->client_id     = $this->get_option('client_id');
        $this->client_secret = $this->get_option('client_secret');
        $this->api_url       = $this->get_option('api_url');
        
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Activar/Desactivar',
                'type'    => 'checkbox',
                'label'   => 'Habilitar método de pago Credicard',
                'default' => 'no'
            ],
            'title' => [
                'title'       => 'Título',
                'type'        => 'text',
                'default'     => 'Botón de Pago Credicard',
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => 'Descripción',
                'type'        => 'textarea',
                'default'     => 'Paga con tu tarjeta de débito o crédito a través de Credicard.',
            ],
            'api_url' => [
                'title'       => 'API URL',
                'type'        => 'text',
                'description' => 'Endpoint base de la API de Credicard.',
                'default'     => '',
            ],
            'client_id' => [
                'title'       => 'Client ID',
                'type'        => 'text',
                'description' => 'Identificador único del comercio.',
                'default'     => '',
            ],
            'client_secret' => [
                'title'       => 'Secret ID',
                'type'        => 'text',
                'description' => 'Clave secreta de autenticación.',
                'default'     => '',
            ],
            
        ];
    }

    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }

        echo '<div id="credicard-payment-button-wrapper" style="margin-top: 20px;">';
        echo '  <a id="pagar" data-order-id="" style="cursor: not-allowed; background: gray; color: lightgray; padding: 12px 24px; border-radius: 12px; text-decoration: none;">Pagar</a>';
        echo '</div>';
    }

    public function enqueue_scripts() {
        if (is_checkout() && $this->is_available()) {
            if (!wp_script_is('sweetalert2', 'enqueued')) {
                wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', array(), null);
            }

            wp_enqueue_script(
                'credicard-checkout-js', 
                plugin_dir_url(__FILE__) . '../assets/js/credicard-checkout.js', 
                ['jquery'],
                '1.0', 
                true
            );

            wp_enqueue_style(
                'credicard-checkout-css',
                plugin_dir_url(__FILE__) . '../assets/css/credicard-style.css',
                [],
                '1.0'
            );

            wp_enqueue_style(
                'voucher-credicard-css',
                plugin_dir_url(__FILE__) . '../assets/css/voucher-credicard.css',
                [],
                '1.0'
            );

            wp_localize_script('credicard-checkout-js', 'credicard_params', [
                'ajax_url'   => admin_url('admin-ajax.php'),
                'nonce_create'      => wp_create_nonce('credicard_create_payment'),
                'nonce_finalize'      => wp_create_nonce('credicard_finalize_order'),
            ]);
        }
    }

    public function validate_fields() {
        return true;
    }

    public function process_payment($order_id) {
        wc_add_notice(
            'Este método de pago no debe usarse directamente. Por favor usa el botón azul "Pagar" que aparece arriba.',
            'error'
        );

        return [
            'result'   => 'failure',
            'redirect' => wc_get_checkout_url(),
        ];
    }
}

add_action('wp_ajax_nopriv_credicard_finalize_order', 'credicard_finalize_order');
add_action('wp_ajax_credicard_finalize_order', 'credicard_finalize_order');

function credicard_finalize_order() {
    if (!function_exists('wc_get_order')) {
        include_once WC_ABSPATH . 'includes/wc-order-functions.php';
    }

    error_log("[Credicard] Entró en finalize_order");
    error_log("[Credicard] payment_id: " . $_POST['payment_id']);

    $gateway = new WC_Gateway_Credicard();

    if (!isset($_POST['payment_id']) || !isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'credicard_finalize_order')) {
        wp_send_json_error(['message' => 'Petición no autorizada.']);
    }

    $payment_id = sanitize_text_field($_POST['payment_id'] ?? '');
    if (empty($payment_id)) {
        wp_send_json_error(['message' => 'Falta el payment_id.']);
    }

    // Validar si ya existe una orden con este payment_id
    $orders = wc_get_orders([
        'meta_key'   => '_credicard_payment_id',
        'meta_value' => $payment_id,
        'limit'      => 1,
    ]);

    if (empty($orders)) {
        wp_send_json_error(['message' => 'No se encontró una orden con este payment_id.']);
    }

    $order = $orders[0];

    // Validar si la orden ya ha sido completada
    if ($order->has_status(['processing', 'completed'])) {
        wp_send_json_error(['message' => 'La orden ya fue procesada.']);
    }

    $client_id = $gateway->client_id;
    $client_secret = $gateway->client_secret;
    $api_url = trailingslashit($gateway->api_url) . 'v1/api/commerce/paymentOrder/clientCredentials/';

    $response = wp_remote_get($api_url . $payment_id, [
        'headers' => [
            'client-id' => $client_id,
            'client-secret' => $client_secret
        ]
    ]);

    error_log("[Credicard] EL REPONSE VALIDACION TRAE: " . print_r($response, true));

    if (is_wp_error($response)) {
        error_log("[Credicard] ERROR Credicard API: " . $response->get_error_message());
        wp_send_json_error(['message' => 'Error de conexión con Credicard']);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($body['data']['status']) || strtolower($body['data']['status']) !== 'paid') {
        error_log("[Credicard] Estado del pago no válido o aún pendiente.");
        wp_send_json_error(['message' => 'Pago no válido o aún pendiente.']);
    }

    try {

        $voucher =  '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">    
        </head>
        <body class="voucher-cuerpo">
            <div class="voucher-container">
                <div class="voucher-header">
                    Comprobante de Pago
                </div>
                
                <div class="voucher-body">            
                    <div class="voucher-success">
                        OPERACIÓN EXITOSA
                    </div>
                    
                    <table class="voucher-details">
                        <tr>
                            <td>Monto:</td>
                            <td>'.$body['data']['transaction']['receipt']['result']['monto'].'</td>
                        </tr>
                        <tr>
                            <td>Referencia:</td>
                            <td>'.$body['data']['transaction']['receipt']['result']['ref'].'</td>
                        </tr>
                        <tr>
                            <td>Fecha y Hora:</td>
                            <td>'.date('d/m/Y H:i A', strtotime($order->order_date)).'</td>
                        </tr>
                        <tr>
                            <td>Estado:</td>
                            <td><span style="color: #27ae60; font-weight: bold;">'.$body['data']['transaction']['receipt']['result']['message'].'</span></td>
                        </tr>
                        <tr>
                            <td>Aprob:</td>
                            <td>'.$body['data']['transaction']['receipt']['result']['codigo'].'</td>
                        </tr>
                        <tr>
                            <td>Terminal:</td>
                            <td>'.$body['data']['transaction']['terminal'].'</td>
                        </tr>
                        <tr>
                            <td>Teléfono del cliente:</td>
                            <td>'.$billing_phone.'</td>
                        </tr>
                        
                        <tr>
                            <td>Tipo de Operación:</td>
                            <td>Botón de Pago Credicard</td>
                        </tr>
                    </table>
                    
                </div>
                
            </div>
        </body>
        </html>
        ';
        
        $order->update_meta_data('credicard_voucher', $voucher);
        $order->update_meta_data('_credicard_ref', $body['data']['transaction']['receipt']['result']['ref'] ?? '');
        $order->set_payment_method_title('Pago vía Credicard');
        $order->set_status('completed', 'Pago Credicard validado');
        $order->save();

        WC()->cart->empty_cart();

        error_log("[Credicard] Redirigiendo a: " . $order->get_checkout_order_received_url());
        wp_send_json_success([
            'redirect_url' => $order->get_checkout_order_received_url(),
            'order_id' => $order->get_id()
        ]);
        
    } catch (Exception $e) {
        error_log("[Credicard] Error al crear la orden: " . $e->getMessage());
        wp_send_json_error(['message' => 'No se pudo procesar la orden: ' . $e->getMessage()]);
    }
}
