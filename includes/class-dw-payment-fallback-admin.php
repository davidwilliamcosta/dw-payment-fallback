<?php
/**
 * Configurações do plugin no admin do WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

class DW_Payment_Fallback_Admin {

	/** @var self */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DW_PAYMENT_FALLBACK_PATH . 'dw-payment-fallback.php' ), array( $this, 'plugin_links' ) );
	}

	/**
	 * Adiciona a aba de configurações do plugin nas configurações do WooCommerce.
	 *
	 * @param array $settings
	 * @return array
	 */
	public function add_settings_page( $settings ) {
		$settings[] = include DW_PAYMENT_FALLBACK_PATH . 'includes/class-dw-payment-fallback-settings.php';
		return $settings;
	}

	/**
	 * Link "Configurações" na lista de plugins.
	 *
	 * @param array $links
	 * @return array
	 */
	public function plugin_links( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=dw_fallback' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . __( 'Configurações', 'dw-payment-fallback' ) . '</a>';
		return $links;
	}
}
