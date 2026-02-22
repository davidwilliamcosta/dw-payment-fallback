<?php
/**
 * Página de configurações do DW Payment Fallback (WooCommerce > Configurações > Fallback de pagamento).
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Settings_Page' ) ) {
	return null;
}

class DW_Payment_Fallback_Settings extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'dw_fallback';
		$this->label = __( 'Fallback de pagamento', 'dw-payment-fallback' );
		parent::__construct();
	}

	/**
	 * Retorna os gateways de pagamento disponíveis (sem opção vazia, para multiselect).
	 *
	 * @return array [ 'id' => 'Nome do gateway' ]
	 */
	private function get_available_gateway_options() {
		$options  = array();
		$gateways = WC()->payment_gateways()->payment_gateways();
		foreach ( $gateways as $id => $gateway ) {
			$title = $gateway->get_title();
			if ( empty( $title ) ) {
				$title = $id;
			}
			$options[ $id ] = $title . ' (' . $id . ')';
		}
		return $options;
	}

	public function get_sections() {
		return array(
			'' => __( 'Geral', 'dw-payment-fallback' ),
		);
	}

	public function get_settings( $current_section = '' ) {
		$gateways = $this->get_available_gateway_options();
		return array(
			array(
				'title' => __( 'Fallback de pagamento', 'dw-payment-fallback' ),
				'type'  => 'title',
				'desc'  => __( 'Quando o pagamento falhar em algum dos gateways principais, o cliente poderá tentar com um dos gateways de fallback. Selecione quais formas de pagamento são "principais" (em caso de falha, oferecemos fallback) e quais são "fallback" (opções alternativas para o cliente).', 'dw-payment-fallback' ),
				'id'    => 'dw_fallback_options',
			),
			array(
				'title'   => __( 'Gateways principais', 'dw-payment-fallback' ),
				'desc'    => __( 'Formas de pagamento em que, ao falhar, o fallback será oferecido. Pode selecionar várias (ex.: Pagar.me, outro gateway).', 'dw-payment-fallback' ),
				'id'      => 'dw_fallback_primary_gateways',
				'type'    => 'multiselect',
				'options' => $gateways,
				'default' => array(),
				'class'   => 'wc-enhanced-select',
				'css'     => 'min-width: 300px;',
			),
			array(
				'title'   => __( 'Gateways de fallback', 'dw-payment-fallback' ),
				'desc'    => __( 'Formas de pagamento que o cliente poderá usar ao acessar o link de fallback. Pode selecionar várias (ex.: Mercado Pago, PIX).', 'dw-payment-fallback' ),
				'id'      => 'dw_fallback_alternate_gateways',
				'type'    => 'multiselect',
				'options' => $gateways,
				'default' => array(),
				'class'   => 'wc-enhanced-select',
				'css'     => 'min-width: 300px;',
			),
			array(
				'title'   => __( 'Colocar pedido em "Pendente"', 'dw-payment-fallback' ),
				'desc'    => __( 'Após falha em um gateway principal, mudar o status do pedido de "Falhou" para "Pendente de pagamento" para que o link de pagamento funcione.', 'dw-payment-fallback' ),
				'id'      => 'dw_fallback_set_pending',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Enviar e-mail com link', 'dw-payment-fallback' ),
				'desc'    => __( 'Enviar e-mail ao cliente com link para pagar via gateway alternativo.', 'dw-payment-fallback' ),
				'id'      => 'dw_fallback_send_email',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Apenas gateways de fallback na página "Pagar pedido"', 'dw-payment-fallback' ),
				'desc'    => __( 'Quando o cliente acessar o link de fallback, mostrar somente os gateways de fallback selecionados (recomendado).', 'dw-payment-fallback' ),
				'id'      => 'dw_fallback_only_alternate_on_pay',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Oferecer fallback em "Em espera"', 'dw-payment-fallback' ),
				'desc'    => __( 'Alguns gateways colocam o pedido em "Em espera" em caso de falha. Marque para oferecer fallback também nesse caso.', 'dw-payment-fallback' ),
				'id'      => 'dw_fallback_also_on_hold',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'dw_fallback_options',
			),
		);
	}
}

return new DW_Payment_Fallback_Settings();
