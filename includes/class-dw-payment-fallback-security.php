<?php
/**
 * Camada de segurança e autorização do fallback.
 */

defined( 'ABSPATH' ) || exit;

class DW_Payment_Fallback_Security {

	/**
	 * Verifica se a requisição atual pode ativar o modo fallback no checkout.
	 *
	 * @return bool
	 */
	public static function is_valid_fallback_checkout_request() {
		$nonce = isset( $_GET['dw_fallback_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['dw_fallback_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'dw_fallback_checkout' ) ) {
			return false;
		}

		$order_id = isset( $_GET['dw_fallback_order'] ) ? absint( $_GET['dw_fallback_order'] ) : 0;
		if ( ! $order_id ) {
			return true;
		}
		$order = wc_get_order( $order_id );
		if ( ! ( $order instanceof WC_Order ) ) {
			return false;
		}

		return self::can_current_visitor_pay_order( $order );
	}

	/**
	 * Verifica se o checkout está em modo fallback.
	 *
	 * @return bool
	 */
	public static function is_checkout_fallback_mode_active() {
		if ( isset( $_GET['dw_fallback_checkout'] ) && self::is_valid_fallback_checkout_request() ) {
			return true;
		}
		if ( WC()->session && WC()->session->get( 'dw_fallback_checkout_mode', 'no' ) === 'yes' ) {
			// Restringe ao contexto de checkout / ajax do checkout.
			if ( is_checkout() ) {
				return true;
			}
			if ( wp_doing_ajax() ) {
				$action = isset( $_REQUEST['wc-ajax'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wc-ajax'] ) ) : '';
				return in_array( $action, array( 'update_order_review', 'checkout' ), true );
			}
		}
		return false;
	}

	/**
	 * Valida se o visitante atual pode acessar/pagar o pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function can_current_visitor_pay_order( WC_Order $order ) {
		$current_user_id = get_current_user_id();
		if ( $current_user_id > 0 && (int) $order->get_user_id() === $current_user_id ) {
			return true;
		}
		if ( self::has_valid_order_key_in_request( $order ) ) {
			return true;
		}
		if ( WC()->session && absint( WC()->session->get( 'order_awaiting_payment', 0 ) ) === (int) $order->get_id() ) {
			return true;
		}
		return false;
	}

	/**
	 * Valida a key do pedido na URL.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	private static function has_valid_order_key_in_request( WC_Order $order ) {
		$request_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( empty( $request_key ) ) {
			return false;
		}
		return hash_equals( (string) $order->get_order_key(), (string) $request_key );
	}
}
