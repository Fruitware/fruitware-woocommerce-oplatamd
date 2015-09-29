<?php
/*
  Plugin Name: OPLATA.MD Payment Gateway for WooCommerce
  Plugin URI: https://github.com/Fruitware/fruitware-woocommerce-oplatamd
  Description: Allows you to use Oplata.md payment gateway with the WooCommerce plugin.
  Version: 0.1.1
  Author: Coroliov Oleg, Fruitware SRL
  Author URI: http://fruitware.ru
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/* Add a MDL currency to WooCommerce currencies
  ------------------------------------------------------------ */
add_filter( 'woocommerce_currencies', 'add_mdl_currency' );
if ( ! function_exists( 'add_mdl_currency' ) ) {
	function add_mdl_currency( $currencies ) {
		if ( ! isset( $currencies['MDL'] ) ) {
			$currencies['MDL'] = __( 'Leu moldovenesc', 'woocommerce' );
		}

		return $currencies;
	}
}

add_filter( 'woocommerce_currency_symbol', 'add_mdl_currency_symbol', 10, 2 );
if ( ! function_exists( 'add_mdl_currency_symbol' ) ) {
	function add_mdl_currency_symbol( $currency_symbol, $currency ) {
		switch ( $currency ) {
			case 'MDL':
				$currency_symbol = 'MDL';
				break;
		}

		return $currency_symbol;
	}
}

/* Add a custom payment class to WC
  ------------------------------------------------------------ */
add_action( 'plugins_loaded', 'fruitware_woocommerce_oplatamd', 0 );
function fruitware_woocommerce_oplatamd() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	} // if the WC payment gateway class is not available, do nothing

	if ( class_exists( 'WC_Fruitware_Oplatamd_Gateway' ) ) {
		return;
	}

	class WC_Fruitware_Oplatamd_Gateway extends WC_Payment_Gateway {

		/**
		 * @var integer
		 */
		protected $transaction_id;

		/**
		 * @var WC_Logger
		 */
		public $log;

		public function __construct() {
			$plugin_dir = plugin_dir_url( __FILE__ );

			$this->supports   = array( 'products', 'default_credit_card_form' );
			$this->id         = 'oplatamd';
			$this->icon       = apply_filters( 'fruitware_woocommerce_oplatamd_icon', '' . $plugin_dir . 'oplatamd.png' );
			$this->has_fields = false;

			$this->liveurl = 'https://oplata.md';
			$this->testurl = 'https://dev.oplata.md';

			// Load the settings
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title             = $this->get_option( 'title' );
			$this->oplatamd_merchant = $this->get_option( 'oplatamd_merchant' );
			$this->secret_key        = $this->get_option( 'secret_key' );
			$this->testmode          = 'yes' === $this->get_option( 'testmode', 'no' );
			$this->debug             = 'yes' === $this->get_option( 'debug', 'no' );
			$this->sslverify         = 'yes' === $this->get_option( 'sslverify', 'yes' );
			$this->description       = $this->get_option( 'description' );
			$this->instructions      = $this->get_option( 'instructions' );

			$this->view_transaction_url = $this->get_gateway_url( '/' . $this->get_lang() . '/invoice/%s' );

			// Actions
			add_action( 'woocommerce_receipt_' . strtolower( $this->id ), array( $this, 'receipt_page' ) );
			// Save options
			add_action( 'woocommerce_update_options_payment_gateways_' . strtolower( $this->id ), array( $this, 'process_admin_options' ) );

			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_' . strtolower( $this->id ), array( $this, 'check_ipn_response' ) );

			// Add custom email fields
			add_filter('woocommerce_email_order_meta_fields', array( $this, 'email_order_meta_fields' ), 10, 3);

			if ( ! $this->is_valid_for_use() ) {
				$this->enabled = false;
			}
		}

		/**
		 * @param array    $fields
		 * @param          $sent_to_admin
		 * @param WC_Order $order
		 *
		 * @return array
		 */
		public function email_order_meta_fields( array $fields, $sent_to_admin, WC_Order $order ) {
			$transaction_url = $this->get_transaction_url($order);
			$fields['transaction_id'] = array(
				'label' => __( 'Oplata.md invoice', 'woocommerce' ),
				'value' => '<a href="'.$transaction_url.'">'.$transaction_url.'</a>'
			);

			return $fields;
		}

		/**
		 * Get a link to the transaction on the 3rd party gateway size (if applicable)
		 *
		 * @param  WC_Order $order the order object
		 * @return string transaction URL, or empty string
		 */
		public function get_transaction_url( $order ) {

			$return_url = '';
			$transaction_id = $order->get_transaction_id();

			if (!$transaction_id) {
				$transaction_id = get_post_meta( $order->id, 'oplatamd_transaction_id', true );
			}

			if ( ! empty( $this->view_transaction_url ) && ! empty( $transaction_id ) ) {
				$return_url = sprintf( $this->view_transaction_url, $transaction_id );
			}

			return apply_filters( 'woocommerce_get_transaction_url', $return_url, $order, $this );
		}

		/**
		 * Logging method
		 *
		 * @param  string $message
		 */
		public function log( $message ) {
			if ( ! $this->debug ) {
				return;
			}

			if ( ! $this->log ) {
				global $woocommerce;
				if ( version_compare( WOOCOMMERCE_VERSION, '2.1', '<' ) ) {
					$this->log = $woocommerce->logger();
				} else {
					$this->log = new WC_Logger();
				}
			}

			$this->log->add( 'oplatamd', $message );
		}

		/**
		 * Check if this gateway is enabled and available in the user's country
		 */
		function is_valid_for_use() {
			if ( ! in_array( get_option( 'woocommerce_currency' ), array( 'MDL' ) ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 *
		 * @since 0.1
		 **/
		public function admin_options() {
			?>
			<h3><?php _e( 'OPLATA.MD', 'woocommerce' ); ?></h3>
			<p><?php _e( 'Настройка приема электронных платежей через Merchant OPLATA.MD.', 'woocommerce' ); ?></p>

			<?php if ( $this->is_valid_for_use() ) : ?>

				<table class="form-table">

					<?php
					// Generate the HTML For the settings form.
					$this->generate_settings_html();
					?>
				</table><!--/.form-table-->

			<?php else : ?>
				<div class="inline error"><p><strong><?php _e( 'Шлюз отключен',
								'woocommerce' ); ?></strong>: <?php _e( 'OPLATA.MD не поддерживает валюты Вашего магазина.', 'woocommerce' ); ?></p>
				</div>
			<?php
			endif;

		} // End admin_options()

		/**
		 * Initialise Gateway Settings Form Fields
		 *
		 * @access public
		 * @return void
		 */
		function init_form_fields() {
			$debug = __( 'Включить логирование (<code>woocommerce/logs/' . $this->id . '.txt</code>)', 'woocommerce' );
			if ( ! version_compare( WOOCOMMERCE_VERSION, '2.0', '<' ) ) {
				if ( version_compare( WOOCOMMERCE_VERSION, '2.2.0', '<' ) ) {
					$debug = str_replace( $this->id, $this->id . '-' . sanitize_file_name( wp_hash( $this->id ) ), $debug );
				} elseif ( function_exists( 'wc_get_log_file_path' ) ) {
					$debug = str_replace( 'woocommerce/logs/' . $this->id . '.txt', wc_get_log_file_path( $this->id ), $debug );
				}
			}
			$this->form_fields = array(
				'enabled'           => array(
					'title'   => __( 'Включить/Выключить', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Включен', 'woocommerce' ),
					'default' => 'yes'
				),
				'title'             => array(
					'title'       => __( 'Название', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Это название, которое пользователь видит во время проверки.', 'woocommerce' ),
					'default'     => __( 'OPLATA.MD', 'woocommerce' )
				),
				'testmode'          => array(
					'title'       => __( 'Тест режим', 'woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Включен', 'woocommerce' ),
					'description' => __( 'В этом режиме плата за товар не снимается.<br>Для тестового режима: <label style="font-weight: 700;" for="fruitware_woocommerce_oplatamd_oplatamd_merchant">Название проекта</label>: "demoshop" и <label style="font-weight: 700;" for="fruitware_woocommerce_oplatamd_secret_key">Секретный ключ</label>: "950856916534772"',
						'woocommerce' ),
					'default'     => 'no'
				),
				'oplatamd_merchant' => array(
					'title'       => __( 'Название проекта', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Пожалуйста введите Название проекта (projectsTitle).', 'woocommerce' ),
					'default'     => 'demoshop'
				),
				'secret_key'        => array(
					'title'       => __( 'Секретный ключ', 'woocommerce' ),
					'type'        => 'password',
					'description' => __( 'Пожалуйста введите Секретный ключ (secretKey).<br />', 'woocommerce' ),
					'default'     => ''
				),
				'debug'             => array(
					'title'   => __( 'Debug', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => $debug,
					'default' => 'no'
				),
				'sslverify'             => array(
					'title'   => __( 'Verify ssl certificate', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'       => __( 'Включен', 'woocommerce' ),
					'description' => __( 'Выключать проверку, только если есть проблема с проверкой сертификатов', 'woocommerce' ),
					'default' => 'yes'
				),
				'description'       => array(
					'title'       => __( 'Description', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Описанием метода оплаты которое клиент будет видеть на вашем сайте.', 'woocommerce' ),
					'default'     => 'Оплата с помощью oplata.md.'
				),
				'instructions'      => array(
					'title'       => __( 'Instructions', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Инструкции, которые будут добавлены на страницу благодарностей.', 'woocommerce' ),
					'default'     => 'Оплата с помощью oplata.md.'
				),
			);
		}

		/**
		 * There are no payment fields for sprypay, but we want to show the description if set.
		 **/
		function payment_fields() {
			if ( $this->description ) {
				echo wpautop( wptexturize( $this->description ) );
			}
		}

		/**
		 * Generate the dibs button link
		 *
		 * @param WC_Order $order
		 *
		 * @return bool|string
		 */
		public function generate_form( WC_Order $order ) {
			$invoice = $this->create_invoice( $order );
//			$invoice = '2cd7eaf4-576b-7db4-dfe1-26005a951e81'; // test

			if ( is_wp_error( $invoice ) ) {
				return '<a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __( 'Отказаться от оплаты & вернуться в корзину',
					'woocommerce' ) . '</a>';
			}

			return
				'<a class="button alt" href="' . $this->get_gateway_url( '/' . $this->get_lang() . '/invoice/' . $invoice ) . '">' . __( 'Оплатить',
					'woocommerce' ) . '</a>
					<a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __( 'Отказаться от оплаты & вернуться в корзину',
					'woocommerce' ) . '</a>';
		}

		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 *
		 * @return array|bool
		 */
		public function process_payment( $order_id ) {
			if ( ! $order = $this->get_order_by_id( $order_id ) ) {
				return false;
			}

			return array(
				'result'   => 'success',
				'redirect' => add_query_arg( 'order',
					$order->id,
					add_query_arg( 'key', $order->order_key, $order->get_checkout_payment_url(true) ) )
			);
		}

		/**
		 * receipt_page
		 **/
		function receipt_page( $order_id ) {
			if ( ! $order = $this->get_order_by_id( $order_id ) ) {
				return false;
			}

			$transaction_id = get_post_meta( $order->id, 'oplatamd_transaction_id', true );
			if (!$transaction_id) {
				$transaction_id = $this->create_invoice( $order );
			}

			do_action( 'woocommerce_api_wc_' . strtolower( $this->id ), $order );

//			$transaction_id = '2cd7eaf4-576b-7db4-dfe1-26005a951e81'; // test

			if ( is_wp_error( $transaction_id ) ) {
				echo '<p>' . __( 'Произошка ошибка. Перезагрузите страницу что бы попробовать ещё раз.', 'woocommerce' ) . '</p>';
			}
			else {
				echo '<p>' . __( 'Спасибо за Ваш заказ, пожалуйста, нажмите кнопку ниже, чтобы заплатить.', 'woocommerce' ) . '</p>';
				echo ' <a class="button alt" href="' . $this->get_gateway_url( '/' . $this->get_lang() . '/invoice/' . $transaction_id ) . '">' . __( 'Оплатить', 'woocommerce' ) . '</a>';
			}

			echo
				' <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __( 'Отказаться от оплаты & вернуться в корзину', 'woocommerce' ) . '</a>';
		}

		/**
		 * Check Response
		 **/
		public function check_ipn_response( WC_Order $order ) {
			$invoice = $this->view_invoice( $order );
//			$invoice = json_decode('{"ordersId":"174","invoiceId":"33961","invoiceAmount":"150.00","invoiceEmail":"email@email.email","invoiceDate":null,"invoiceStatus":"10"}', true); // test

			if ( is_wp_error( $invoice ) ) {
				$this->log( 'View invoice failed: ' . $invoice->get_error_message() );

				return false;
			}

			switch ( $invoice['invoiceStatus'] ) {
				case '0': // Not paid
					return false;
					break;
				case '1': // Created
					return false;
					break;
				case '5': // Error
					$order->update_status( 'failed', __( 'Ошибка оплаты', 'woocommerce' ) );
					wp_redirect( $order->get_cancel_order_url() );
					exit;
					break;
				case '10': // Paid
					WC()->cart->empty_cart();
					$transaction_id = get_post_meta( $order->id, 'oplatamd_transaction_id', true );
					$order->payment_complete($transaction_id);
					delete_post_meta($order->id, 'oplatamd_transaction_id');
					wp_redirect( $this->get_return_url( $order ) );
					exit;
					break;
				case '20': // Money Return
					$order->update_status( 'refunded', __( 'Платеж возвращён', 'woocommerce' ) );
					wp_redirect( $order->get_cancel_order_url() );
					exit;
					break;
			}
		}

		/**
		 * Create an invoice for order via OPLATA.MD
		 *
		 * @param  WC_Order $order
		 *
		 * @return array|wp_error The parsed response from OPLATA.MD, or a WP_Error object
		 */
		protected function create_invoice( WC_Order $order ) {
			$url = $this->get_gateway_url( '/invoice/createInvoice' );

			$args             = $this->get_invoice_args_by_order( $order, 'create' );
			$args['checksum'] = $this->generate_checksum( $order, 'create' );

//var_dump(http_build_query($args));die;
			apply_filters( 'fruitware_woocommerce_oplatamd_args', $args );

			$response = wp_safe_remote_post(
				$url,
				array(
					'method'      => 'POST',
					'body'        => $args,
					'timeout'     => 30,
					'user-agent'  => 'WooCommerce',
					'httpversion' => '1.1',
					'sslverify'   => $this->sslverify,
				)
			);

			if ( is_wp_error( $response ) ) {
				$this->log( 'Create invoice request error >>>>>>: ' . json_encode( $args ) . ' <<<<<<< ' . $response->get_error_message() );

				return $response;
			}

			if ( empty( $response['body'] ) ) {
				$this->log( 'Create invoice request error >>>>>>: ' . json_encode( $args ) . ' <<<<<<< ' . json_encode( $response ) );

				return new WP_Error( 'oplatamd-create-invoice', 'Empty Response' );
			}
			$transaction_id = $response['body'];

			$this->log( 'Create invoice request success >>>>>>: ' . json_encode( $args ) . ' <<<<<<< ' . $transaction_id );

			update_post_meta( $order->id, 'oplatamd_transaction_id', sanitize_text_field( $transaction_id ) );

			return $transaction_id;
		}

		/**
		 * Create an invoice for order via OPLATA.MD
		 *
		 * @param  WC_Order $order
		 *
		 * @return array|wp_error The parsed response from OPLATA.MD, or a WP_Error object
		 */
		protected function view_invoice( WC_Order $order ) {
			$url = $this->get_gateway_url( '/invoice/viewInvoice' );

			$args             = $this->get_invoice_args_by_order( $order, 'view' );
			$args['checksum'] = $this->generate_checksum( $order, 'view' );

//var_dump(http_build_query($args));die;
			apply_filters( 'fruitware_woocommerce_oplatamd_args', $args );

			$response = wp_safe_remote_post(
				$url,
				array(
					'method'      => 'POST',
					'body'        => $args,
					'timeout'     => 30,
					'user-agent'  => 'WooCommerce',
					'httpversion' => '1.1',
					'sslverify'   => $this->sslverify,
				)
			);

			if ( is_wp_error( $response ) ) {
				$this->log( 'View invoice request error >>>>>>: ' . json_encode( $args ) . ' <<<<<<< ' . $response->get_error_message() );

				return $response;
			}

			if ( empty( $response['body'] ) ) {
				$this->log( 'View invoice request error >>>>>>: ' . json_encode( $args ) . ' <<<<<<< ' . json_encode( $response ) );

				return new WP_Error( 'oplatamd-view-invoice', 'Empty Response' );
			}

			$answer = json_decode( $response['body'], true );

			if ( ! isset( $answer['invoiceStatus'] ) ) {
				$this->log( 'View invoice request error >>>>>>: ' . json_encode( $args ) . ' <<<<<<< ' . json_encode( $answer ) );
				$data = array( 'request' => $args, 'response' => $answer );

				return new WP_Error( 'oplatamd-view-invoice', 'View invoice request error', json_encode( $data ) );
			}

			$this->log( 'View invoice request success >>>>>>: ' . json_encode( $args ) . ' <<<<<<< ' . json_encode( $answer ) );

			return $answer;
		}

		/**
		 * @param WC_Order $order
		 *
		 * @return string
		 */
		protected function generate_checksum( WC_Order $order, $type ) {
			$args              = $this->get_invoice_args_by_order( $order, $type );
			$args['secretKey'] = $this->secret_key;

			return md5( implode( '::', $args ) );
		}

		/**
		 * @param WC_Order $order
		 * @param string   $type
		 *
		 * @return string
		 */
		protected function get_invoice_args_by_order( WC_Order $order, $type ) {
			switch ( $type ) {
				case 'create':
//					checksum = md5(ordersId::projectsTitle::ordersAmount::ordersStatusLink::cartEmail::timestamp::secretKey)
					$args = array(
						'ordersId'         => $order->id,
						'projectsTitle'    => $this->oplatamd_merchant,
						'ordersAmount'     => $this->number_format( $order->order_total ),
						'ordersStatusLink' => $order->get_checkout_payment_url( true ),//get_permalink( woocommerce_get_page_id( 'thanks' ) ),
						'cartEmail'        => $order->billing_email,
						'timestamp'        => strtotime( $order->order_date ),
					);
					break;
				case 'view':
//					checksum = md5(ordersId::projectsTitle::ordersAmount::timestamp::secretKey)
					$args = array(
						'ordersId'      => $order->id,
						'projectsTitle' => $this->oplatamd_merchant,
						'ordersAmount'  => $this->number_format( $order->order_total ),
						'timestamp'     => strtotime( $order->order_date ),
					);
					break;
				default:
					throw new \RuntimeException( 'Invalid type' );
					break;
			}

			return $args;
		}

		/**
		 * Get current wp lang
		 *
		 * @return string
		 */
		protected function get_lang() {
			if ( function_exists( 'qtrans_getLanguage' ) ) {
				$lang = qtrans_getLanguage();
			} elseif ( function_exists( 'qtranxf_getLanguage' ) ) {
				$lang = qtranxf_getLanguage();
			} else {
				$locale = explode( '_', get_locale() );
				$lang   = @$locale[0];
			}

			return $lang == 'ru' ? 'ru' : 'ro';
		}

		/**
		 * @param integer $order_id
		 *
		 * @return bool|WC_Order
		 */
		protected function get_order_by_id( $order_id ) {
			$order = new WC_Order( $order_id );

			if ( ! $order ) {
				$this->log->add( 'oplatamd', 'Error: Order ID not found.' );

				return false;
			}

			return $order;
		}

		/**
		 * Format prices
		 *
		 * @param  float|int $price
		 *
		 * @return float|int
		 */
		protected function number_format( $price ) {
			$decimals = 2;

			return number_format( $price, $decimals, '.', '' );
		}

		/**
		 * @param string $path
		 *
		 * @return string
		 */
		protected function get_gateway_url( $path = '' ) {
			return ( $this->testmode ? $this->testurl : $this->liveurl ) . $path;
		}
	}

	/**
	 * Add the gateway to WooCommerce
	 *
	 * @param array $methods
	 *
	 * @return array
	 */
	function add_gateway( array $methods ) {
		$methods[] = 'WC_Fruitware_Oplatamd_Gateway';

		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'add_gateway' );
}
