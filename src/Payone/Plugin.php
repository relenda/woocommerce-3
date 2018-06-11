<?php

namespace Payone;

use Payone\Database\Migration;
use Payone\Gateway\GatewayBase;
use Payone\Gateway\SepaDirectDebit;
use Payone\Payone\Api\TransactionStatus;
use Payone\Transaction\Log;

class Plugin {
	const CALLBACK_SLUG = 'payone-callback';

	const PAYONE_IP_RANGES = [
		'213.178.72.196', '213.178.72.197', '217.70.200.0/24', '185.60.20.0/24'
	];

	public static $send_mail_after_capture = false;

	/**
	 * @todo Evtl. Zugriff über file_get_contents('php://input') realisieren, wenn der Server file_get_contents zulässt
	 *
	 * @return array
	 */
	public static function get_post_vars() {
		return $_POST;
	}

	public function init() {
		$migration = new Migration();
		$migration->run();

		if ( is_admin() ) {
			$settings = new \Payone\Admin\Settings();
			$settings->init();
		}

		$gateways = [
			\Payone\Gateway\CreditCard::GATEWAY_ID      => \Payone\Gateway\CreditCard::class,
			\Payone\Gateway\SepaDirectDebit::GATEWAY_ID => \Payone\Gateway\SepaDirectDebit::class,
			\Payone\Gateway\PrePayment::GATEWAY_ID      => \Payone\Gateway\PrePayment::class,
			\Payone\Gateway\Invoice::GATEWAY_ID         => \Payone\Gateway\Invoice::class,
			\Payone\Gateway\Sofort::GATEWAY_ID          => \Payone\Gateway\Sofort::class,
			\Payone\Gateway\Giropay::GATEWAY_ID         => \Payone\Gateway\Giropay::class,
			\Payone\Gateway\SafeInvoice::GATEWAY_ID     => \Payone\Gateway\SafeInvoice::class,
			\Payone\Gateway\PayPal::GATEWAY_ID          => \Payone\Gateway\PayPal::class,
			\Payone\Gateway\PayDirekt::GATEWAY_ID       => \Payone\Gateway\PayDirekt::class,
		];

		foreach ( $gateways as $gateway ) {
			add_filter( 'woocommerce_payment_gateways', [ $gateway, 'add' ] );
		}

		add_action( 'woocommerce_order_status_changed', [ $this, 'order_status_changed' ], 10, 3 );

		$plugin_rel_path = dirname( plugin_basename(__FILE__) ) . '/../../lang/';
		load_plugin_textdomain( 'payone-woocommerce-3', false, $plugin_rel_path);

		add_action( 'woocommerce_after_checkout_form', [ $this, 'add_javascript' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enque_javascript' ] );
		add_action( 'woocommerce_thankyou', [$this, 'add_content_to_thankyou_page'] );

		add_filter( 'woocommerce_email_enabled_customer_processing_order' , [ $this, 'disable_capture_mail_filter' ]);
	}

	public function disable_capture_mail_filter() {
		return self::$send_mail_after_capture;
	}

	/**
	 * @param string $type
	 *
	 * @return string
	 */
	public static function get_callback_url( $type = 'transaction' ) {
		$url = get_site_url( null, self::CALLBACK_SLUG . '/' );
		if ($type !== 'transaction') {
			$url .= '?type=' . $type;
		}

		return esc_url( $url );
	}

	/**
	 * https://gist.github.com/tott/7684443
	 * 
	 * @param string $ip_address
	 * @param string $range
	 *
	 * @return bool
	 */
	public static function ip_address_is_in_range( $ip_address, $range ) {
		if ( strpos( $range, '/' ) === false ) {
			$range .= '/32';
		}
		list( $range, $netmask ) = explode( '/', $range, 2 );
		$range_decimal    = ip2long( $range );
		$ip_decimal       = ip2long( $ip_address );
		$wildcard_decimal = pow( 2, ( 32 - $netmask ) ) - 1;
		$netmask_decimal  = ~$wildcard_decimal;

		return ( $ip_decimal & $netmask_decimal ) === ( $range_decimal & $netmask_decimal );
	}

	public function add_callback_url() {
		add_rewrite_rule( '^' . self::CALLBACK_SLUG . '/?$', 'index.php?' . self::CALLBACK_SLUG . '=true', 'top' );
		add_filter( 'query_vars', [ $this, 'add_rewrite_var' ] );
		add_action( 'template_redirect', [ $this, 'catch_payone_callback' ] );
	}

	public function add_rewrite_var( $vars ) {
		$vars[] = self::CALLBACK_SLUG;

		return $vars;
	}

	public function catch_payone_callback() {
		if ( get_query_var( self::CALLBACK_SLUG ) ) {

			if ( $this->is_callback_after_redirect() ) {
				return $this->process_callback_after_redirect();
			} elseif ( $this->is_manage_mandate_callback() ) {
				return $this->process_manage_mandate_callback();
			} elseif ( $this->is_manage_mandate_getfile() ) {
				return $this->process_manage_mandate_getfile();
			}

			$response = 'ERROR';
			if ( $this->request_is_from_payone() ) {
				do_action( 'payone_transaction_callback' );

				try {
					$response = $this->process_callback();
				} catch (\Exception $e) {
					$response .= ' (' . $e->getMessage() . ')';
				}

				if ( $response === 'TSOK' ) {
					Log::constructFromPostVars();
				}
			}

			echo $response;
			exit();
		}
	}

	/**
	 * @return string
	 */
	public function process_callback() {
		$transaction_status = TransactionStatus::construct_from_post_parameters();

		$do_process_callback = true;
		$do_process_callback = apply_filters( 'payone_do_process_callback', $do_process_callback, $transaction_status );

		if ( $do_process_callback ) {
			$gateway = $transaction_status->get_gateway();
			if ( $transaction_status->get( 'key' ) === hash( 'md5', $gateway->get_key() ) ) {
				$gateway->process_transaction_status( $transaction_status );
			} else {
				return 'ERROR: Wrong key';
			}
		}

		return 'TSOK';
	}

	public function order_status_changed( $id, $from_status, $to_status ) {
		$order   = new \WC_Order( $id );
		$gateway = $this->get_gateway_for_order( $order );

		if ( method_exists( $gateway, 'order_status_changed' ) ) {
			$gateway->order_status_changed( $order, $from_status, $to_status );
		}
	}

	/**
	 * @return bool
	 */
	private function request_is_from_payone()
	{
		$ip_address = $_SERVER['REMOTE_ADDR'];

		$result = false;

		foreach ( self::PAYONE_IP_RANGES as $range ) {
			if ( self::ip_address_is_in_range( $ip_address, $range ) ) {
				$result = true;
				break;
			}
		}

		return apply_filters( 'payone_request_is_from_payone',  $result );
	}

	/**
	 * @return bool
	 */
	private function is_callback_after_redirect() {
		$allowed_redirect_types = [ 'success', 'error', 'back' ];
		if ( isset( $_GET['type'] ) && in_array( $_GET['type'], $allowed_redirect_types, true)
		     && isset( $_GET['oid'] ) && (int)$_GET['oid']
		) {
			return true;
		}

		return false;
	}

	/**
	 * @return array
	 */
	private function process_callback_after_redirect() {
		$order_id = (int)$_GET['oid'];

		$order = new \WC_Order( $order_id );
		$gateway = self::get_gateway_for_order( $order );

		return $gateway->process_payment( $order_id );
	}

	/**
	 * @return bool
	 */
	private function is_manage_mandate_callback() {
		if ( isset( $_GET['type'] ) && $_GET['type'] === 'ajax-manage-mandate') {
			return true;
		}

		return false;
	}

	/**
	 * @return array
	 */
	private function process_manage_mandate_callback() {
		$gateway = self::find_gateway( SepaDirectDebit::GATEWAY_ID );

		return $gateway->process_manage_mandate( $_POST );
	}

	/**
	 * @return bool
	 */
	private function is_manage_mandate_getfile() {
		if ( isset( $_GET['type'] ) && $_GET['type'] === 'manage-mandate-getfile') {
			return true;
		}

		return false;
	}

	/**
	 * @return array
	 */
	private function process_manage_mandate_getfile() {
		$gateway = self::find_gateway( SepaDirectDebit::GATEWAY_ID );

		return $gateway->process_manage_mandate_getfile( $_GET );
	}

	/**
	 * @param \WC_Order $order
	 *
	 * @return null|GatewayBase
	 */
	public static function get_gateway_for_order( \WC_Order $order ) {
		// @todo Was tun, wenn es das Gateway nicht gibt?
		return self::find_gateway( $order->get_payment_method() );
	}

	/**
	 *
	 */
	public function add_javascript() {
		if ( is_checkout() ) {
			include PAYONE_VIEW_PATH . '/gateway/common/checkout.js.php';
		}
	}

	public function enque_javascript() {
		if ( is_checkout() ) {
			wp_enqueue_script( 'payone_hosted', 'https://secure.pay1.de/client-api/js/v1/payone_hosted_min.js' );
		}
	}

	public function add_content_to_thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$gateway = self::get_gateway_for_order( $order );
			$gateway->add_content_to_thankyou_page( $order );
		}
	}

	/**
	 * @param string $gateway_id
	 *
	 * @return null|GatewayBase
	 */
	private static function find_gateway( $gateway_id ) {
		$payment_gateways = WC()->payment_gateways->payment_gateways();
		foreach ( $payment_gateways as $payment_gateway_id => $payment_gateway ) {
			if ( $gateway_id === $payment_gateway_id ) {
				return $payment_gateway;
			}
		}

		return null;
	}
}