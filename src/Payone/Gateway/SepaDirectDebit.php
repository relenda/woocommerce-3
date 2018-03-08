<?php

namespace Payone\Gateway;

use Payone\Payone\Api\TransactionStatus;

class SepaDirectDebit extends GatewayBase {
	const GATEWAY_ID = 'bs_payone_sepa';

	public function __construct() {
		parent::__construct( self::GATEWAY_ID );

		$this->icon               = '';
		$this->method_title       = 'BS PAYONE Lastschrift (SEPA)';
		$this->method_description = 'method_description';
	}

	public function init_form_fields() {
		$this->init_common_form_fields( __( 'SEPA Direct Debit', 'payone-woocommerce-3' ) );
		$this->form_fields['sepa_check_bank_data'] = [
			'title'   => __( 'Check bank data', 'payone-woocommerce-3' ),
			'type'    => 'select',
			'options' => [
				'basic'    => __( 'Basic', 'payone-woocommerce-3' ),
				'blacklist' => __( 'Check POS black list', 'payone-woocommerce-3' ),
				'none' => __( 'None (only possible if PAYONE Mandate Management is inactive)', 'payone-woocommerce-3' ),
			],
			'default' => 'basic',
		];
		$this->form_fields['sepa_ask_account_number'] = [
			'title'   => __( 'Ask account number/bank code (for german accounts only)', 'payone-woocommerce-3' ),
			'type'    => 'select',
			'options' => [
				'0' => __( 'No', 'payone-woocommerce-3' ),
				'1' => __( 'Yes', 'payone-woocommerce-3' ),
			],
			'default' => '1',
		];
		$this->form_fields['sepa_use_mandate_management'] = [
			'title'   => __( 'Use PAYONE Mandate Management', 'payone-woocommerce-3' ),
			'type'    => 'select',
			'options' => [
				'0' => __( 'No', 'payone-woocommerce-3' ),
				'1' => __( 'Yes', 'payone-woocommerce-3' ),
			],
			'default' => '1',
		];
		$this->form_fields['sepa_pdf_download_mandate'] = [
			'title'   => __( 'Download mandate as PDF', 'payone-woocommerce-3' ),
			'type'    => 'select',
			'options' => [
				'0' => __( 'No', 'payone-woocommerce-3' ),
				'1' => __( 'Yes', 'payone-woocommerce-3' ),
			],
			'default' => '1',
		];
		$this->form_fields['sepa_countries'] = [
			'title'   => __( 'List of supported bank countries', 'payone-woocommerce-3' ),
			'type'    => 'multiselect',
			'options' => [
				'DE' => __( 'Germany', 'payone-woocommerce-3' ),
				'AT' => __( 'Austria', 'payone-woocommerce-3' ),
				'CH' => __( 'Switzerland', 'payone-woocommerce-3' ),
			],
			'default' => [ 'DE', 'AT', 'CH' ],
		];
	}

	public function payment_fields() {
		$options = get_option( \Payone\Admin\Option\Account::OPTION_NAME );

		include PAYONE_VIEW_PATH . '/gateway/sepa-direct-debit/payment-form.php';
	}

	public function process_payment( $order_id ) {
		global $woocommerce;
		$order = new \WC_Order( $order_id );

		// Mark as on-hold (we're awaiting the cheque)
		$order->update_status( 'on-hold', __( 'Awaiting cheque payment', 'woocommerce' ) );

		// Reduce stock levels
		wc_reduce_stock_levels( $order_id );

		// Remove cart
		$woocommerce->cart->empty_cart();

		// Return thankyou redirect
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * @param TransactionStatus $transaction_status
	 */
	public function process_transaction_status( TransactionStatus $transaction_status ) {

	}
}