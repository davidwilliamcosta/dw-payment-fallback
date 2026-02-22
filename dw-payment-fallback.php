<?php
/**
 * Plugin Name: DW Payment Fallback
 * Plugin URI: https://github.com/agenciadw/dw-payment-fallback
 * Description: Quando o pagamento falhar no gateway principal (ex: Pagar.me), permite tentar cobrar via gateway alternativo (ex: Mercado Pago).
 * Version: 0.0.1
 * Author: David William da Costa
 * Author URI: https://github.com/agenciadw
 * Text Domain: dw-payment-fallback
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.x
 */

defined( 'ABSPATH' ) || exit;

define( 'DW_PAYMENT_FALLBACK_VERSION', '0.0.1' );
define( 'DW_PAYMENT_FALLBACK_PATH', plugin_dir_path( __FILE__ ) );
define( 'DW_PAYMENT_FALLBACK_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declara compatibilidade com HPOS (High-Performance Order Storage).
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Verifica se o WooCommerce está ativo.
 */
function dw_payment_fallback_check_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="error"><p><strong>DW Payment Fallback</strong> requer o WooCommerce ativo.</p></div>';
		} );
		return false;
	}
	return true;
}

add_action( 'plugins_loaded', function () {
	if ( ! dw_payment_fallback_check_woocommerce() ) {
		return;
	}
	require_once DW_PAYMENT_FALLBACK_PATH . 'includes/class-dw-payment-fallback-security.php';
	require_once DW_PAYMENT_FALLBACK_PATH . 'includes/class-dw-payment-fallback.php';
	require_once DW_PAYMENT_FALLBACK_PATH . 'includes/class-dw-payment-fallback-admin.php';
	DW_Payment_Fallback::instance();
	DW_Payment_Fallback_Admin::instance();
}, 20 );
