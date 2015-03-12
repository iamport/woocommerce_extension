<?php
/*
Plugin Name: WooCommerce Iamport Payment Gateway
Plugin URI: http://www.iamport.kr
Description: Extends WooCommerce with an Iamport Payment gateway.
Version: 1.0
Author: SIOT
Author URI: http://www.siot.do
*/

add_action('plugins_loaded', 'woocommerce_gateway_iamport_init', 0);

function woocommerce_gateway_iamport_init() {

	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

	/**
 	 * Localisation
	 */
	load_plugin_textdomain('wc-gateway-name', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');
    
	/**
 	 * Gateway class
 	 */
	class WC_Gateway_Iamport extends WC_Payment_Gateway {

		public function __construct() {
			$this->id = 'iamport';
			$this->method_title = '아임포트';
			$this->method_description = '아임포트 서비스를 이용해 결제모듈을 연동할 수 있습니다.';
			$this->has_fields = true;

			$this->init_form_fields();
			$this->init_settings();

			//settings
			$this->title = $this->settings['title'];
			$this->description = $this->settings['description'];
			$this->imp_user_code = $this->settings['imp_user_code'];
			$this->imp_rest_key = $this->settings['imp_rest_key'];
			$this->imp_rest_secret = $this->settings['imp_rest_secret'];

			if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			} else {
				add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
			}

			//actions
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_iamport_script') );
			add_action( 'woocommerce_receipt_iamport', array( $this, 'request_payment' ) );
			add_action( 'woocommerce_thankyou_iamport', array( $this, 'check_payment_status' ) );
			//add_action('woocommerce_after_checkout_form',array( $this, 'request_payment' ));
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'woocommerce' ),
					'type' => 'checkbox',
					'label' => __( '아임포트 결제모듈 사용', 'woocommerce' ),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'woocommerce' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
					'default' => __( '신용카드/실시간계좌이체/가상계좌', 'woocommerce' ),
					'desc_tip'      => true,
				),
				'description' => array(
					'title' => __( 'Customer Message', 'woocommerce' ),
					'type' => 'textarea',
					'default' => 'asdf'
				),
				'imp_user_code' => array(
					'title' => '[아임포트] 가맹점 식별코드',
					'type' => 'text',
					'label' => '[아임포트] 가맹점 식별코드'
				),
				'imp_rest_key' => array(
					'title' => '[아임포트] REST API 키',
					'type' => 'text',
					'label' => '[아임포트] REST API 키'
				),
				'imp_rest_secret' => array(
					'title' => '[아임포트] REST API Secret',
					'type' => 'text',
					'label' => '[아임포트] REST API Secret'
				)
			);
		}

		public function request_payment( $order ) {
			echo $this->generate_iamport_payment( $order );
		}

		public function process_payment( $order_id ) {
			global $woocommerce;
			$order = new WC_Order( $order_id );

			if ( $order->has_status(array('processing', 'completed')) ) {
				$redirect_url = $order->get_checkout_order_received_url();
			} else {
				$redirect_url = $order->get_checkout_payment_url( true );
			}

			return array(
				'result' => 'success',
				//'redirect' => $this->get_return_url( $order )
				'redirect'	=> $redirect_url
			);
		}

		public function generate_iamport_payment( $order_id ) {
			global $woocommerce;

			$order = new WC_Order( $order_id );
			$landing_url = $order->get_checkout_order_received_url();

			$html_template = '
			<p style="text-align:center">
				<select id="iamport_paymethod">
					<option value="card">신용카드</option>
					<option value="trans">실시간계좌이체</option>
					<option value="vbank">가상계좌</option>
				</select>
				<a class="iamport-payment button" onclick="iamport_payment(); return false;">결제하기</a>
				<script type="text/javascript">
					function iamport_payment() {
						var pay_method = document.getElementById("iamport_paymethod").value;
						var landing_url = "%s";

						var IMP = window.IMP;
						IMP.init("%s");

						IMP.request_pay({
						    pay_method : pay_method,
						    merchant_uid : "%s",
						    name : "%s",
						    amount : Number("%s"),
						    buyer_email : "%s",
						    buyer_name : "%s",
						    buyer_tel : "%s",
						    buyer_addr : "%s",
						    buyer_postcode : "%s",
						    vbank_due : "%s",
						    m_redirect_url : landing_url
						}, function(rsp) {
						    if ( rsp.success ) {
						        location.href = landing_url;
						    } else {
						    	alert(rsp.error_msg);
						    	location.reload();
						    }
						});
					}
				</script>
			</p>
			';

			$oid = $this->get_oid($order->get_order_number());
			$order_name = $this->get_order_name($order);

			$html_string = sprintf($html_template,
				$landing_url, //landing_url
				$this->imp_user_code, //user_code
				$oid, //merchant_uid
				$order_name, //name
				$order->order_total, //amount
				$order->billing_email, //email
				$order->billing_first_name . $order->billing_last_name, //name
				'010-1234-5678', //tel
				strip_tags($order->get_shipping_address()), //address
				$order->shipping_postcode,
				date('Ymd')

			);
			return $html_string;
		}

		public function enqueue_iamport_script() {
			wp_register_script( 'iamport_script', 'https://service.iamport.kr/js/iamport.payment.js');
			//wp_register_script( 'iamport_script', plugins_url( '/iamport.js',plugin_basename(__FILE__) ));
   			wp_enqueue_script('iamport_script');
		}

		public function check_payment_status( $order_id ) {
			require_once('lib/iamport.php');

			$order = new WC_Order( $order_id );
			$order_uid = $this->get_oid($order->get_order_number());

			$iamport = new Iamport($this->imp_rest_key, $this->imp_rest_secret);
			$iamport_payment = $iamport->findByMerchantUID($order_uid);

			if ( !empty($iamport_payment) ) {
				if ( $iamport_payment->status == 'paid' ) {
					if ( $order->order_total == $iamport_payment->amount ) {
						if ( !$order->has_status(array('processing', 'completed')) ) {
							//$order->update_status( 'completed' );
							$order->payment_complete( $iamport_payment->pg_tid );
						}
						$message = '결제가 완료되었습니다.';
					} else {
						$order->add_order_note('요청하신 결제금액이 다릅니다.');
						$message = '요청하신 결제금액이 다릅니다.';
					}
				} else if ( $iamport_payment->status == 'failed' ) {
					$order->add_order_note( $iamport_payment->fail_reason );
					$message = '결제실패 : ' . $iamport_payment->fail_reason;
				}
			}

			echo "<p>$message</p>";
		}

		private function get_oid($order_id) {
			return 'woo_iamport_' . $order_id;
		}

		private function get_order_name($order) {
			$order_name = "#" . $order->get_order_number() . "번 주문";

			$cart_items = $order->get_items();
			$cnt = count($cart_items);

			if (!empty($cart_items)) {
				$index = 0;
				foreach ($cart_items as $item) {
					if ( $index == 0 ) {
						$order_name = $item['name'];
					} else if ( $index > 0 ) {
						
						$order_name .= ' 외 ' . ($cnt-1);
					}

					$index++;
				}
			} 

			return $order_name;
		}
	}
	
	/**
 	* Add the Gateway to WooCommerce
 	**/
	function woocommerce_add_gateway_iamport_gateway($methods) {
		$methods[] = 'WC_Gateway_Iamport';
		return $methods;
	}

	//default	
	add_filter('woocommerce_payment_gateways', 'woocommerce_add_gateway_iamport_gateway' );
}