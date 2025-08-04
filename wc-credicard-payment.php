<?php
/**
 * Plugin Name: Botón de Pago Credicard
 * Description: Integra el botón de pago de Credicard con WooCommerce.
 * Version: 1.0
 * Author: Ansise Segovia
 * Text Domain: credicard
 * Domain Path: /languages
 * Requires at least: WooCommerce 3.0
 */

defined('ABSPATH') || exit;

define('CREDICARD_VERSION', '1.0');

add_action('plugins_loaded', 'credicard_init_plugin');

/**
 * Initialize the payment gateway
 * 
 * @return void
 */
function credicard_init_plugin() {
    // Check if WooCommerce is active
    if (!function_exists('WC') || !class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . 
                 esc_html__('El plugin Botón de Pago Credicard requiere WooCommerce activo.', 'credicard') . 
                 '</p></div>';
        });
        return;
    }

    // Check minimum WooCommerce version
    if (version_compare(WC_VERSION, '3.0', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' .
                 esc_html__('Botón de Pago Credicard requiere WooCommerce 3.0 o superior.', 'credicard') .
                 '</p></div>';
        });
        return;
    }

    // Load main gateway class
    $gateway_file = plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-credicard.php';
    $ajax_file = plugin_dir_path(__FILE__) . 'includes/ajax-handlers.php';
    $hooks_file = plugin_dir_path(__FILE__) . 'includes/checkout-hooks.php'; // ← nuevo archivo

    if (file_exists($gateway_file)) {
        require_once $gateway_file;
        // Register gateway
        add_filter('woocommerce_payment_gateways', 'credicard_add_gateway_class');
    } else {
        error_log('Credicard Payment Gateway: Missing main gateway class file');
        return;
    }

    if(file_exists($ajax_file)){
       require_once $ajax_file;
    }else{
        error_log('Hook Ajax: Missing callback ajax file');
        return;
    }

    if (file_exists($hooks_file)) {
        require_once $hooks_file;
    } else {
        error_log('Checkout Hooks: Missing file');
    }

}

/**
 * Add gateway to WooCommerce
 * 
 * @param array $gateways List of payment gateways
 * @return array
 */
function credicard_add_gateway_class($gateways) {
    $gateways[] = 'WC_Gateway_Credicard';
    return $gateways;
}
