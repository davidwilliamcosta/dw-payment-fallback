<?php
/**
 * Lógica principal do fallback de pagamento.
 *
 * Quando um pedido falha com o gateway principal, marca o pedido para
 * permitir pagamento via gateway alternativo e oferece link para o cliente.
 */

defined( 'ABSPATH' ) || exit;

class DW_Payment_Fallback {

	const META_FALLBACK_OFFERED = '_dw_fallback_offered';
	const META_PRIMARY_GATEWAY  = '_dw_primary_gateway_used';

	/** @var self */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Quando o pedido falha (status = failed), oferecer fallback se for o gateway principal.
		add_action( 'woocommerce_order_status_failed', array( $this, 'on_order_failed' ), 10, 2 );
		// Opcional: também quando fica "on-hold" por falha de pagamento (alguns gateways usam isso).
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'on_order_on_hold' ), 10, 2 );

		// No checkout: exibir apenas principais e ocultar secundários. No fluxo de fallback: exibir apenas secundários.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_available_payment_gateways' ), 20, 1 );

		// Assets e dados do fallback no checkout/order-pay.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_inject_fallback_message' ), 20 );

		// Parâmetro na URL da página de pagar pedido para forçar gateway de fallback.
		add_filter( 'woocommerce_get_checkout_payment_url', array( $this, 'maybe_append_fallback_param' ), 10, 2 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'maybe_set_fallback_payment_method' ), 5 );
		add_action( 'template_redirect', array( $this, 'maybe_prepare_order_for_fallback' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_prepare_checkout_fallback_mode' ), 1 );

		// AJAX: retorna URL de retry para o checkout exibir botão "Tentar com ...".
		add_action( 'wp_ajax_dw_fallback_get_retry_url', array( $this, 'ajax_get_retry_url' ) );
		add_action( 'wp_ajax_nopriv_dw_fallback_get_retry_url', array( $this, 'ajax_get_retry_url' ) );
	}

	/**
	 * Retorna os IDs dos gateways principais configurados.
	 *
	 * @return array Lista de IDs.
	 */
	public static function get_primary_gateway_ids() {
		$ids = get_option( 'dw_fallback_primary_gateways', array() );
		if ( ! is_array( $ids ) ) {
			$ids = array_filter( array_map( 'trim', explode( ',', (string) $ids ) ) );
		}
		// Compatibilidade: opções antigas em singular.
		if ( empty( $ids ) ) {
			$legacy = get_option( 'dw_fallback_primary_gateway', '' );
			if ( (string) $legacy !== '' ) {
				$ids = array( (string) $legacy );
			}
		}
		return array_values( array_filter( $ids ) );
	}

	/**
	 * Retorna os IDs dos gateways de fallback configurados.
	 *
	 * @return array Lista de IDs.
	 */
	public static function get_fallback_gateway_ids() {
		$ids = get_option( 'dw_fallback_alternate_gateways', array() );
		if ( ! is_array( $ids ) ) {
			$ids = array_filter( array_map( 'trim', explode( ',', (string) $ids ) ) );
		}
		// Compatibilidade: opções antigas em singular.
		if ( empty( $ids ) ) {
			$legacy = get_option( 'dw_fallback_alternate_gateway', '' );
			if ( (string) $legacy !== '' ) {
				$ids = array( (string) $legacy );
			}
		}
		return array_values( array_filter( $ids ) );
	}

	/**
	 * Verifica se o fallback está configurado e válido.
	 * Usa payment_gateways() (não get_available) para evitar recursão no filtro woocommerce_available_payment_gateways.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$primaries = self::get_primary_gateway_ids();
		$fallbacks = self::get_fallback_gateway_ids();
		if ( empty( $primaries ) || empty( $fallbacks ) ) {
			return false;
		}
		$gateways = array();
		if ( WC()->payment_gateways ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			$gateways = is_array( $gateways ) ? $gateways : array();
		}
		$keys      = array_keys( $gateways );
		$has_primary  = ! empty( array_intersect( $primaries, $keys ) );
		$has_fallback = ! empty( array_intersect( $fallbacks, $keys ) );
		return $has_primary && $has_fallback;
	}

	/**
	 * Chamado quando o status do pedido passa para 'failed'.
	 *
	 * @param int      $order_id ID do pedido.
	 * @param WC_Order $order    Objeto do pedido (em versões que passam).
	 */
	public function on_order_failed( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$this->offer_fallback_if_primary_failed( $order );
	}

	/**
	 * Chamado quando o status do pedido passa para 'on-hold'.
	 * Alguns gateways colocam em on-hold em caso de falha para revisão.
	 *
	 * @param int      $order_id ID do pedido.
	 * @param WC_Order $order    Objeto do pedido.
	 */
	public function on_order_on_hold( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Só oferecer fallback em on-hold se a opção estiver ativa (evitar conflito com outros fluxos).
		if ( get_option( 'dw_fallback_also_on_hold', 'no' ) !== 'yes' ) {
			return;
		}
		$this->offer_fallback_if_primary_failed( $order );
	}

	/**
	 * Se o pedido foi pago com o gateway principal e falhou, marca para fallback e opcionalmente envia link.
	 *
	 * @param WC_Order $order
	 */
	private function offer_fallback_if_primary_failed( WC_Order $order ) {
		if ( ! self::is_configured() ) {
			return;
		}
		$primary_ids = self::get_primary_gateway_ids();
		$current     = $order->get_payment_method();
		if ( ! in_array( $current, $primary_ids, true ) ) {
			return;
		}
		if ( $order->get_meta( self::META_FALLBACK_OFFERED ) ) {
			return;
		}
		$order->update_meta_data( self::META_FALLBACK_OFFERED, 'yes' );
		$order->update_meta_data( self::META_PRIMARY_GATEWAY, $current );
		$order->save();

		// Colocar pedido em "pending" para que o cliente possa pagar novamente (link "Pagar pedido").
		if ( $order->get_status() === 'failed' && get_option( 'dw_fallback_set_pending', 'yes' ) === 'yes' ) {
			$order->update_status( 'pending', __( 'Pagamento principal falhou. Cliente pode tentar com o gateway alternativo (fallback).', 'dw-payment-fallback' ) );
		}

		$pay_url = $this->get_fallback_pay_url( $order );
		$order->add_order_note(
			sprintf(
				/* translators: 1: URL para pagar com gateway alternativo */
				__( 'Fallback de pagamento: o cliente pode tentar pagar com o gateway alternativo. Link: %s', 'dw-payment-fallback' ),
				$pay_url
			)
		);

		if ( get_option( 'dw_fallback_send_email', 'yes' ) === 'yes' ) {
			$this->send_fallback_email( $order, $pay_url );
		}

		// Guarda dados de fallback em sessão para uso no checkout (botão "Tentar com ...").
		if ( WC()->session ) {
			WC()->session->set( 'dw_fallback_last_order_id', (int) $order->get_id() );
			WC()->session->set( 'dw_fallback_last_pay_url', $pay_url );
		}
	}

	/**
	 * Gera a URL para o cliente pagar o pedido (com fallback).
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public function get_fallback_pay_url( WC_Order $order ) {
		$pay_url = $order->get_checkout_payment_url( true );
		return add_query_arg( 'dw_fallback', '1', $pay_url );
	}

	/**
	 * Envia e-mail ao cliente com link para pagar via gateway alternativo.
	 *
	 * @param WC_Order $order
	 * @param string   $pay_url
	 */
	private function send_fallback_email( WC_Order $order, $pay_url ) {
		$mailer    = WC()->mailer();
		$fallbacks = self::get_fallback_gateway_ids();
		$gateways  = WC()->payment_gateways ? WC()->payment_gateways()->payment_gateways() : array();
		$names     = array();
		foreach ( $fallbacks as $fid ) {
			if ( isset( $gateways[ $fid ] ) ) {
				$names[] = $gateways[ $fid ]->get_title();
			}
		}
		$name = ! empty( $names ) ? implode( ', ', $names ) : __( 'outro método de pagamento', 'dw-payment-fallback' );

		$subject = sprintf(
			/* translators: %s: número do pedido */
			__( 'Tente novamente: pagar seu pedido #%s', 'dw-payment-fallback' ),
			$order->get_order_number()
		);
		$message = sprintf(
			/* translators: 1: número do pedido, 2: nome(s) do(s) gateway(s) alternativo(s), 3: link */
			__( 'O pagamento do pedido #%1$s não pôde ser processado. Você pode tentar novamente usando %2$s pelo link abaixo:', 'dw-payment-fallback' ) . "\n\n%3$s",
			$order->get_order_number(),
			$name,
			$pay_url
		);

		$sent = $mailer->send(
			$order->get_billing_email(),
			$subject,
			$mailer->wrap_message( $subject, $message ),
			array( 'Content-Type: text/plain; charset=UTF-8' ),
			null
		);
		if ( $sent ) {
			$order->add_order_note( __( 'E-mail de fallback enviado ao cliente com link para pagamento alternativo.', 'dw-payment-fallback' ) );
		}
	}

	/**
	 * Filtra os gateways de pagamento disponíveis:
	 * - No checkout normal: exibe apenas os principais, oculta sempre os secundários (fallback).
	 * - No fluxo de fallback (página "Pagar pedido" após falha): exibe apenas os secundários.
	 *
	 * @param array $gateways
	 * @return array
	 */
	public function filter_available_payment_gateways( $gateways ) {
		if ( ! is_array( $gateways ) ) {
			return $gateways;
		}
		if ( empty( $gateways ) || ! self::is_configured() ) {
			return $gateways;
		}
		$primary_ids  = self::get_primary_gateway_ids();
		$fallback_ids = self::get_fallback_gateway_ids();

		$is_checkout_fallback_mode = $this->is_checkout_fallback_mode_active();

		// Checkout em modo fallback (compatível com FunnelKit): oculta principais e exibe fallback + não relacionados.
		if ( $is_checkout_fallback_mode ) {
			foreach ( $primary_ids as $pid ) {
				if ( ! in_array( $pid, $fallback_ids, true ) ) {
					unset( $gateways[ $pid ] );
				}
			}
			return $gateways;
		}

		// Fluxo de fallback: página "Pagar pedido" com pedido que falhou (meta ou dw_fallback=1 na URL).
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			$is_fallback_flow = $order && ( $order->get_meta( self::META_FALLBACK_OFFERED ) || isset( $_GET['dw_fallback'] ) );

			if ( $is_fallback_flow && ! empty( $fallback_ids ) ) {
				$filtered = array();
				foreach ( $fallback_ids as $fid ) {
					if ( isset( $gateways[ $fid ] ) ) {
						$filtered[ $fid ] = $gateways[ $fid ];
					}
				}
				return ! empty( $filtered ) ? $filtered : $gateways;
			}
		}

		// Checkout normal: ocultar apenas gateways de fallback que não forem principais.
		// Gateways não relacionados ao fallback permanecem visíveis.
		if ( is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
			foreach ( $fallback_ids as $fid ) {
				if ( ! in_array( $fid, $primary_ids, true ) ) {
					unset( $gateways[ $fid ] );
				}
			}
			return $gateways;
		}

		return $gateways;
	}

	/**
	 * Se a URL de pagamento for para um pedido com fallback, adicionar parâmetro para pré-selecionar o gateway.
	 *
	 * @param string   $url
	 * @param WC_Order $order
	 * @return string
	 */
	public function maybe_append_fallback_param( $url, $order ) {
		if ( $order && $order->get_meta( self::META_FALLBACK_OFFERED ) ) {
			// FunnelKit + alguns gateways (ex.: Mercado Pago custom) não renderizam bem no order-pay.
			// Nesses casos, direcionar retry para o checkout em modo fallback.
			if ( $this->is_funnelkit_checkout_active() ) {
				return $this->get_checkout_fallback_url( $order );
			}
			$url = add_query_arg( 'dw_fallback', '1', $url );
		}
		return $url;
	}

	/**
	 * Na ação "pay order", se vier dw_fallback=1, definir o método de pagamento como o de fallback.
	 *
	 * Assim o checkout já abre com Mercado Pago (ou o alternativo) selecionado.
	 */
	public function maybe_set_fallback_payment_method() {
		if ( ! isset( $_GET['dw_fallback'] ) || ! self::is_configured() ) {
			return;
		}
		if ( isset( $_POST['payment_method'] ) ) {
			return;
		}
		$fallback_ids = self::get_fallback_gateway_ids();
		$first        = reset( $fallback_ids );
		if ( $first && WC()->session ) {
			WC()->session->set( 'chosen_payment_method', $first );
		}
	}

	/**
	 * Antes de renderizar o order-pay, prepara o pedido para fallback:
	 * troca o método de pagamento do pedido para o gateway alternativo.
	 *
	 * Isso melhora compatibilidade com gateways que só carregam campos
	 * quando o payment_method do pedido coincide com o próprio gateway.
	 */
	public function maybe_prepare_order_for_fallback() {
		if ( ! is_wc_endpoint_url( 'order-pay' ) || ! self::is_configured() ) {
			return;
		}
		// Em FunnelKit, preferimos retry no checkout; não forçar manipulação no order-pay.
		if ( $this->is_funnelkit_checkout_active() ) {
			return;
		}
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! ( $order instanceof WC_Order ) || ! $order->needs_payment() ) {
			return;
		}
		if ( ! $this->can_current_visitor_pay_order( $order ) ) {
			return;
		}

		$is_fallback_flow = isset( $_GET['dw_fallback'] ) || (bool) $order->get_meta( self::META_FALLBACK_OFFERED );
		if ( ! $is_fallback_flow ) {
			return;
		}

		$this->apply_first_fallback_gateway_to_order( $order );
	}

	/**
	 * Aplica o primeiro gateway de fallback configurado ao pedido.
	 *
	 * @param WC_Order $order Pedido.
	 */
	private function apply_first_fallback_gateway_to_order( WC_Order $order ) {
		$fallback_ids = self::get_fallback_gateway_ids();
		if ( empty( $fallback_ids ) ) {
			return;
		}
		$all_gateways = WC()->payment_gateways ? WC()->payment_gateways()->payment_gateways() : array();
		$all_gateways = is_array( $all_gateways ) ? $all_gateways : array();

		$target_id = '';
		foreach ( $fallback_ids as $fid ) {
			if ( isset( $all_gateways[ $fid ] ) ) {
				$target_id = $fid;
				break;
			}
		}
		if ( '' === $target_id ) {
			return;
		}
		if ( $order->get_payment_method() === $target_id ) {
			return;
		}

		$order->set_payment_method( $target_id );
		$order->set_payment_method_title( $all_gateways[ $target_id ]->get_title() );
		$order->update_meta_data( self::META_FALLBACK_OFFERED, 'yes' );
		$order->save();
	}

	/**
	 * No footer do checkout, se houver mensagem de falha e for o gateway principal,
	 * injetar aviso de que pode tentar com o gateway alternativo (e link quando houver order-pay).
	 */
	public function maybe_inject_fallback_message() {
		if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		if ( ! self::is_configured() ) {
			return;
		}
		$order_id     = 0;
		$fallback_url = '';
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$fallback_url = add_query_arg( 'dw_fallback', '1', $order->get_checkout_payment_url( true ) );
			}
		}
		$gateways     = WC()->payment_gateways ? WC()->payment_gateways()->payment_gateways() : array();
		$gateways     = is_array( $gateways ) ? $gateways : array();
		$fallback_ids = self::get_fallback_gateway_ids();
		$names        = array();
		foreach ( $fallback_ids as $fid ) {
			if ( isset( $gateways[ $fid ] ) ) {
				$names[] = $gateways[ $fid ]->get_title();
			}
		}
		$name = ! empty( $names ) ? implode( ', ', $names ) : __( 'outro método', 'dw-payment-fallback' );

		wp_enqueue_style(
			'dw-payment-fallback-frontend',
			DW_PAYMENT_FALLBACK_URL . 'assets/css/dw-payment-fallback-frontend.css',
			array(),
			DW_PAYMENT_FALLBACK_VERSION
		);
		wp_enqueue_script(
			'dw-payment-fallback-frontend',
			DW_PAYMENT_FALLBACK_URL . 'assets/js/dw-payment-fallback-frontend.js',
			array(),
			DW_PAYMENT_FALLBACK_VERSION,
			true
		);
		wp_add_inline_script(
			'dw-payment-fallback-frontend',
			'window.dwPaymentFallbackData = ' . wp_json_encode(
				array(
					'staticFallbackUrl' => $fallback_url,
					'fallbackName'      => $name,
					'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
					'nonce'             => wp_create_nonce( 'dw_fallback_retry' ),
					'retryPrefixText'   => __( 'Pagamento falhou. Você pode tentar com:', 'dw-payment-fallback' ),
					'retryButtonText'   => __( 'Tentar com outro meio', 'dw-payment-fallback' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * AJAX: retorna URL de retry para o último pedido com fallback detectado na sessão.
	 */
	public function ajax_get_retry_url() {
		check_ajax_referer( 'dw_fallback_retry', 'nonce' );

		if ( ! WC()->session ) {
			wp_send_json_error();
		}

		$pay_url  = (string) WC()->session->get( 'dw_fallback_last_pay_url', '' );
		$order_id = absint( WC()->session->get( 'dw_fallback_last_order_id', 0 ) );

		// Compatibilidade: em alguns gateways/checkout builders o pedido falho fica como "aguardando pagamento"
		// e é rastreado no order_awaiting_payment, sem disparar status "failed".
		if ( ! $order_id ) {
			$order_id = absint( WC()->session->get( 'order_awaiting_payment', 0 ) );
		}

		if ( empty( $pay_url ) && $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				if ( ! $this->can_current_visitor_pay_order( $order ) ) {
					wp_send_json_error();
				}
				// Se ainda não marcou fallback, tentar marcar aqui (fluxos que não disparam "failed"/"on-hold").
				if ( ! $order->get_meta( self::META_FALLBACK_OFFERED ) ) {
					$primary_ids = self::get_primary_gateway_ids();
					if ( in_array( $order->get_payment_method(), $primary_ids, true ) ) {
						$order->update_meta_data( self::META_FALLBACK_OFFERED, 'yes' );
						$order->update_meta_data( self::META_PRIMARY_GATEWAY, $order->get_payment_method() );
						$order->save();
					}
				}
				if ( $order->get_meta( self::META_FALLBACK_OFFERED ) ) {
					$pay_url = $this->get_fallback_pay_url( $order );
				}
			}
		}

		if ( empty( $pay_url ) ) {
			wp_send_json_error();
		}

		// Compatibilidade FunnelKit: usar checkout em modo fallback no lugar de order-pay.
		if ( $this->is_funnelkit_checkout_active() ) {
			$order = $order_id ? wc_get_order( $order_id ) : null;
			$pay_url = $this->get_checkout_fallback_url( $order instanceof WC_Order ? $order : null );
		}

		wp_send_json_success(
			array(
				'url' => $pay_url,
			)
		);
	}

	/**
	 * Persiste modo fallback no checkout (importante para AJAX do FunnelKit,
	 * que não mantém query params em update_order_review/checkout).
	 */
	public function maybe_prepare_checkout_fallback_mode() {
		if ( ! WC()->session ) {
			return;
		}

		if ( isset( $_GET['dw_fallback_checkout'] ) ) {
			if ( $this->is_valid_fallback_checkout_request() ) {
				WC()->session->set( 'dw_fallback_checkout_mode', 'yes' );
			} else {
				WC()->session->set( 'dw_fallback_checkout_mode', 'no' );
			}
		}

		// Se o modo estiver ativo no checkout, já pré-seleciona o primeiro fallback.
		if ( $this->is_checkout_fallback_mode_active() && is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
			$fallback_ids = self::get_fallback_gateway_ids();
			$first        = reset( $fallback_ids );
			if ( $first ) {
				WC()->session->set( 'chosen_payment_method', $first );
			}
		}

		// Limpa o modo fallback ao finalizar pedido para não vazar para próximos checkouts.
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			WC()->session->set( 'dw_fallback_checkout_mode', 'no' );
		}
	}

	/**
	 * Verifica se o checkout está em modo fallback.
	 *
	 * @return bool
	 */
	private function is_checkout_fallback_mode_active() {
		return DW_Payment_Fallback_Security::is_checkout_fallback_mode_active();
	}

	/**
	 * URL do checkout com fallback habilitado para o próximo retry.
	 *
	 * @param WC_Order|null $order Pedido opcional para gerar nonce vinculado.
	 * @return string
	 */
	private function get_checkout_fallback_url( $order = null ) {
		$args = array(
			'dw_fallback_checkout' => '1',
			'dw_fallback_nonce'    => wp_create_nonce( 'dw_fallback_checkout' ),
		);
		if ( $order instanceof WC_Order ) {
			$args['dw_fallback_order'] = (string) $order->get_id();
			$args['key']               = $order->get_order_key();
		}
		return add_query_arg( $args, wc_get_checkout_url() );
	}

	/**
	 * Verifica se a requisição atual pode ativar o modo fallback no checkout.
	 *
	 * @return bool
	 */
	private function is_valid_fallback_checkout_request() {
		return DW_Payment_Fallback_Security::is_valid_fallback_checkout_request();
	}

	/**
	 * Valida se o visitante atual pode acessar/pagar o pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	private function can_current_visitor_pay_order( WC_Order $order ) {
		return DW_Payment_Fallback_Security::can_current_visitor_pay_order( $order );
	}

	/**
	 * Detecta FunnelKit Checkout ativo (nomes antigos e novos).
	 *
	 * @return bool
	 */
	private function is_funnelkit_checkout_active() {
		return defined( 'WFACP_VERSION' )
			|| class_exists( 'WFACP_Common' )
			|| class_exists( 'FKWCS' )
			|| class_exists( 'FKCart' );
	}
}
