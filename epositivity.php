<?php
/*
 Plugin Name: BNL E-positivity WooCommerce Extension
Plugin URI: http://developers.overplace.com
Description: BNL E-Positivity gateway for WooCommerce.
Version: 1.0.0
Author: Francesco Marchesini
Author URI: http://www.overplace.com
*/

if(in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

	add_action('plugins_loaded', 'init_epositivity_gateway_class');
	
	/**
	 * Inizializzo il gateway per epositivity
	 * @return void|string|multitype:string NULL Ambigous <string, string, mixed> |multitype:string |Ambigous <boolean, unknown>|boolean
	 */	
	function init_epositivity_gateway_class() {
		add_filter('woocommerce_payment_gateways', 'add_this_class_to_gateway_class');
		
		/**
		 * Aggiungo il gateway ai methods di Woocommerce
		 * @param array $methods
		 * @return string
		 */
		function add_this_class_to_gateway_class($methods){
			$methods[] = 'WC_Epositivity_Gateway';
			return $methods;
		}
		
		/**
		 * Gateway BNL E-positivity per WooCommerce
		 * @author Francesco
		 *
		 */
		class WC_Epositivity_Gateway extends WC_Payment_Gateway{
		
			/**
			 * Id dello store / storename
			 * @var int
			 */
			private $storeId = "";
			
			/**
			 * Key per l'utilizzo di Connect
			 * @var string
			 */
			private $sharedSecret = "";
			
			/**
			 * Url gateway
			 * @var string
			 */
			private $epos_url = "";
			
			/**
			 * Timezone
			 * @var string
			 */
			private $default_timezone = "";
			
			/**
			 * Codice numerico valuta
			 * @var int
			 */
			private $default_currency_code = "";
			
			/**
			 * Modalità Connect (Payonly, Payplus o Fullpay)
			 * @var string
			 */
			private $default_mode = "";
			
			/**
			 * Datetime in formato Y:m:d-H:i:s
			 * @var unknown
			 */
			private $dt;
	
			/**
			 * Costruttore, aggiunge le action per il funzionamento del gateway
			 */
			function __construct(){
				$this->id = 'epositivity';
				$this->has_fields = false;
				$this->enabled = true;
				$this->method_title = __( 'E-Positivity BNL', 'woocommerce' );
				$this->method_description = __( 'Paga con la tua carta di credito con il circuito BNL e-positivity', 'woocommerce' );
				$this->init_form_fields();
				$this->init_settings();
				$this->pop_class_vars();
				add_action('woocommerce_api_wc_epos_gateway', array($this, 'check_ipn_response' ));
				add_action('valid_epos_ipn_request', array($this, 'successful_epos_request'));
				add_action('woocommerce_receipt_epositivity', array( $this, 'epos_receipt_page' ));
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			}
			
			/**
			 * Popola le variabili di classe
			 */
			private function pop_class_vars(){
				$this->title = $this->get_option('title');
				$this->storeId = $this->get_option('store-id');
				$this->sharedSecret = $this->get_option('shared-secret');
				$this->epos_url = $this->get_option('epos-url');
				$this->default_timezone = $this->get_option('default-timezone');
				$this->default_currency_code = $this->get_option('default-currency-code');
				$this->default_mode = $this->get_option('default-mode');
				$this->dt = $this->get_epos_date_time();
			}
		
			/**
			 * Costruisce il menu di amministrazione del Gateway all'interno del pannello Woocommerce
			 * (non-PHPdoc)
			 * @see WC_Settings_API::init_form_fields()
			 */
			public function init_form_fields(){
				$this->form_fields = array(
						'enabled' => array(
								'title' => __( 'Enable/Disable', 'woocommerce' ),
								'type' => 'checkbox',
								'label' => __( 'Abilita E-positivity BNL', 'woocommerce' ),
								'default' => 'yes'
						),
						'title' => array(
								'title' => __( 'Title', 'woocommerce' ),
								'type' => 'text',
								'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
								'default' => __( 'E-positivity', 'woocommerce' ),
								'desc_tip'      => true,
						),
						'description' => array(
								'title' => __( 'Description', 'woocommerce' ),
								'type' => 'textarea',
								'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
								'default' => __( 'Pagamento tramite BNL E-Positivity, con o senza carta di credito e account Paypal', 'woocommerce' )
						),
						'store-id' => array(
								'title' => __( 'Store Id', 'woocommerce' ),
								'type' 			=> 'text',
								'description' => __( 'Store Id fornito da BNL.', 'woocommerce' ),
								'desc_tip'      => true
						),
						'shared-secret' => array(
								'title' => __( 'Shared Secret Key', 'woocommerce' ),
								'type' 			=> 'text',
								'description' => __( 'Chiave di accesso fornita da BNL.', 'woocommerce' ),
								'desc_tip'      => true
						),
						'epos-url' => array(
								'title' => __( 'Url Gateway', 'woocommerce' ),
								'type' 			=> 'text',
								'description' => __( 'Gateway Url', 'woocommerce' ),
								'default' => 'https://ipg-online.com/connect/gateway/processing',
								'desc_tip'      => true
						),
						'default-timezone' => array(
								'title' => __( 'Fuso Orario', 'woocommerce' ),
								'type' 			=> 'text',
								'description' => __( 'Fuso Orario Shop', 'woocommerce' ),
								'default' => 'CET',
								'desc_tip'      => true,
								'placeholder'	=> 'CET per l&apos;Italia'
						),
						'default-currency-code' => array(
								'title' => __( 'Codice Valuta', 'woocommerce' ),
								'type' 			=> 'text',
								'description' => __( 'Codice numerico valuta - per "&euro;" 978', 'woocommerce' ),
								'default' => '987',
								'desc_tip'      => true,
								'placeholder'	=> 'CET per l&apos;Italia'
						),
						'default-mode' => array(
								'title' => __( 'Tipologia Gateway', 'woocommerce' ),
								'type' 			=> 'select',
								'description' => __( 'Modalit&agrave; di checkout all&apos;interno del gateway', 'woocommerce' ),
								'options' => array(
										'payonly' => 'PayOnly',
										'payplus' => 'PayPlus',
										'fullpay' => 'FullPay',
								),
								'default' => 'payplus'
						)
						
				);
			}
		
			/**
			 * Crea l'hash da inviare come chiave crittografica al memonto della request verso
			 * il gateway.
			 * @param float $total
			 * @param int $currency
			 * @return string
			 */
			private function createHash($total, $currency) {
				$stringToHash = $this->storeId . $this->dt . $total . $currency . $this->sharedSecret;
				$ascii = bin2hex($stringToHash);
				return sha1($ascii);
			}
			
			/**
			 * Genera la pagina per procedere con il pagamento
			 * @param unknown $order
			 */
			public function epos_receipt_page($order){
				echo '<p>'.__( 'Grazie per il tuo ordine, clicca qui sotto per pagare con il circuito E-Positivity.', 'woocommerce' ).'</p>';
				echo $this->generate_epos_form($order);
			}
			
			/**
			 * Prepara i campi hidden del form per passare i dati al gateway
			 * @param unknown $order_id
			 * @return string
			 */
			public function generate_epos_form($order_id){
				global $woocommerce;
				$order = new WC_Order($order_id);
				$epos_args = $this->get_epos_args($order);
				$epos_args_array = array();
				
				foreach ($epos_args as $key => $value) {
					$epos_args_array[] = '<input type="hidden" name="'.esc_attr( $key ).'" value="'.esc_attr( $value ).'" />';
				}
				/*
				 * Javascript per l'inizializzazione automatica del forma,
				 * per evitare che avvenga l'autsubmit commentare fino a linea 246
				 */
				/*$woocommerce->add_inline_js( '
				jQuery("body").block({
						message: "<img src=\"' . esc_url( apply_filters( 'woocommerce_ajax_loader_url', $woocommerce->plugin_url() . '/assets/images/ajax-loader.gif' ) ) . '\" alt=\"Redirecting&hellip;\" style=\"float:left; margin-right: 10px;\" />'.__( 'Grazie per il tuo Ordine. Ti stiamo reindirizzando su E-Positivity per effettuare il pagamento.', 'woocommerce' ).'",
						overlayCSS:
						{
							background: "#fff",
							opacity: 0.6
						},
						css: {
					        padding:        20,
					        textAlign:      "center",
					        color:          "#555",
					        border:         "3px solid #aaa",
					        backgroundColor:"#fff",
					        cursor:         "wait",
					        lineHeight:		"32px"
					    }
					});*/
				$form = '<form action="'.esc_url($this->epos_url).'" method="post" id="epos_payment_form" target="_top">
							' . implode( '', $epos_args_array) . '
							<input type="submit" class="button-alt" id="submit_epos_payment_form" value="'.__( 'Paga con E-Positivity', 'woocommerce' ).'" /> <a class="button cancel" href="'.esc_url( $order->get_cancel_order_url() ).'">'.__( 'Cancel order &amp; restore cart', 'woocommerce' ).'</a>
						</form>';
				$form.= '<script>jQuery("#submit_epos_payment_form").click();</script>';
				return $form;
					
			}
			
			/**
			 * Ritorna il datetime nel formato richiesto da E-Positivity
			 * @return DateTime
			 */
			private function get_epos_date_time(){
				return date("Y:m:d-H:i:s", strtotime(date('Y-m-d H:i:s').'+2 hour'));
			}
			
			/**
			 * Estrae un array con i dati necessari al form di E-positivity dall'oggetto Order di Woocommerce
			 * @param WC_Order $order
			 * @return Array
			 */
			private function get_epos_args(WC_Order $order){
				$rurl = $this->get_return_url($order);
				
				$args = array(
							'txntype' =>  'sale',
							'timezone' => $this->default_timezone,
							'txndatetime' => $this->dt,
							'hash' => $this->createHash($order->order_total,$this->default_currency_code),
							'storename' => $this->storeId,
							'mode' => $this->default_mode,
							'chargetotal' => $order->order_total,
							'currency' => $this->default_currency_code,
							'oid' => $order->id,
							'responseSuccessURL' => esc_url($this->get_return_url($order)),
							'responseFailURL' => esc_url($this->get_return_url($order)),
							'transactionNotificationURL' => esc_url($this->get_return_url($order)),
							'data_ora' => $this->dt,
							'bcompany' => $order->billing_company
						);
				return $args;
			}
			
			/**
			 * Setta l'ordine in status complete in caso di pagamento avvenuto con successo
			 * @param unknown $posted
			 */
			public function successful_epos_request($posted){
				$order = new WC_Order(esc_attr($posted['oid']));
				$order->update_status('completed');
				$order->payment_complete();
			}
			
			/**
			 * Inizializza il process payment per il gateway
			 * (non-PHPdoc)
			 * @see WC_Payment_Gateway::process_payment()
			 */
			function process_payment($order_id){
				$order = new WC_Order( $order_id );
				return array(
					'result' 	=> 'success',
					'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay' ))))
				);
			}
			
			/**
			 * Controlla la response proveniente dal Gateway e aggiorna l'ordine
			 */
			public function check_ipn_response() {
				@ob_clean();
				$data = $this->check_ipn_request_is_valid( $_POST );
				if($data){
					header( 'HTTP/1.0 200 OK' );
					do_action( 'valid_epos_ipn_request', $data );
				} else {
					global $woocommerce;
					$order = new WC_Order(esc_attr($_POST['oid']));
					$order->update_status('failed', 'Pagamento Fallito');
					$woocommerce->add_error(__('Pagamento Fallito', 'woocommerce') . $error_message);
					return;
				}
			}
			
			/**
			 * Controlla se la request in entrata è completa e valida
			 * @param array $data
			 * @return Ambigous <boolean, array>|boolean
			 */
			public function check_ipn_request_is_valid( $data ) {
				if(($data['status'] == 'APPROVATO' || $data['status'] == 'APPROVED' || $data['status'] == 'GENEHMIGT') && isset($data['approval_code']) && isset($data['data_ora'])){
					return $this->check_response_content($data) ? $data : false;
				} else {
					return false;
				}
			}
			
			/**
			 * Verifica la chiave crittografica della response proveniente dal gateway in caso di pagamento
			 * approvato.
			 * @param array $data
			 * @return boolean
			 */
			private function check_response_content($data){
				if(isset($data['response_hash'])){
					$h = $this->sharedSecret.$data['approval_code'].$data['chargetotal'].$data['currency'].$data['data_ora'].$this->storeId;
					$ascii = bin2hex($h);
					if(sha1($ascii) == $data['response_hash']){
						return true;
					} else {
						return false;
					}
				} elseif(isset($data['notification_hash'])){
					$h = $data['chargetotal'].$this->sharedSecret.$data['currency'].$data['data_ora'].$this->storeId.$data['approval_code'];
					$ascii = bin2hex($h);
					if(sha1($ascii) == $data['notification_hash']){
						return true;
					} else {
						return false;
					}
				} else {
					return false;
				}
			}
		}
	}
	
	/**
	 * Chiamata api Woocommerce per inizializzare gateway in caso di risposta in seguito a processo
	 * di pagamento.
	 */
	function epos_legacy_ipn() {
		if ( isset( $_POST['status'] ) && isset($_POST['response_hash']) && isset($_POST['approval_code'])) {
			
			global $woocommerce;
	
			$woocommerce->payment_gateways();
			do_action( 'woocommerce_api_wc_epos_gateway' );
		}
	}
	
	add_action( 'init', 'epos_legacy_ipn' );
}