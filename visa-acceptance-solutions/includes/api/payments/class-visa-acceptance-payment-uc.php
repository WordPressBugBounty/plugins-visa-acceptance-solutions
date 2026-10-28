<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Include all the necessary dependencies.
 */
require_once __DIR__ . '/../class-visa-acceptance-request.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-authorization-request.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';
require_once __DIR__ . '/../response/payments/class-visa-acceptance-authorization-response.php';
require_once __DIR__ . '/class-visa-acceptance-auth-reversal.php';
require_once __DIR__ . '/class-visa-acceptance-refund.php';
require_once __DIR__ . '/../../class-visa-acceptance-payment-gateway-subscriptions.php';
require_once plugin_dir_path( __DIR__ ) . '/../../public/class-visa-acceptance-payment-gateway-unified-checkout-public.php';
require_once plugin_dir_path( __DIR__ ) . '/../../public/class-visa-acceptance-payment-gateway-expresspay-public.php';
use Pymt_Vas\Dependencies\CyberSource\Api\TransientTokenDataV2Api;

/**
 * Visa Acceptance Unified Checkout Authorization Class
 *
 * Handles Unified Checkout Authorization requests
 */
class Visa_Acceptance_Payment_UC extends Visa_Acceptance_Request {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;

	/**
	 * Gateway object
	 *
	 * @var object $gateway */
	public $gateway;

	/**
	 * PaymentUC constructor.
	 *
	 * @param object $gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Returns true if the transient token JWT indicates an eCheck payment type.
	 *
	 * @param string $transient_token The transient token JWT to check.
	 * @return bool True if the token represents an eCheck payment, false otherwise.
	 */
	public function is_echeck_from_transient_token( string $transient_token ): bool {
		$is_echeck = false;
        $parts = explode( VISA_ACCEPTANCE_FULL_STOP, $transient_token );
        if ( isset( $parts[VISA_ACCEPTANCE_VAL_ONE] ) ) {
            $json = base64_decode( $parts[VISA_ACCEPTANCE_VAL_ONE] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding JWT payload.
            $obj = json_decode( $json, true );
            if ( is_array( $obj ) ) {
                $name = $obj['content']['paymentInformation']['paymentType']['name']['value'] ?? $obj['content']['paymentInformation']['paymentType']['name'] ?? $obj['metadata']['paymentType'] ?? null;
                $is_echeck = is_string( $name ) && VISA_ACCEPTANCE_CHECK === strtoupper( $name );
            }
        }

		return $is_echeck;
	}

	/**
	 * Update WooCommerce order billing and shipping addresses from payment details API 	response.
	 *
	 * @param \WC_Order $order           WooCommerce order to update.
	 * @param object    $payment_details Response body object returned by the Transient Token Data API.
	 * @return void
	 */
	public function update_order_addresses_from_payment_details( $order, $payment_details ) {
		if ( empty( $payment_details ) || ! is_object( $payment_details ) ) {
			return;
		}

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- External API uses camelCase.
		$bill_map = array(
			'firstName'          => array( 'set_billing_first_name',  'sanitize_text_field' ),
			'lastName'           => array( 'set_billing_last_name',   'sanitize_text_field' ),
			'address1'           => array( 'set_billing_address_1',   'sanitize_text_field' ),
			'address2'           => array( 'set_billing_address_2',   'sanitize_text_field' ),
			'locality'           => array( 'set_billing_city',        'sanitize_text_field' ),
			'administrativeArea' => array( 'set_billing_state',       'sanitize_text_field' ),
			'postalCode'         => array( 'set_billing_postcode',    'sanitize_text_field' ),
			'country'            => array( 'set_billing_country',     'sanitize_text_field' ),
			'email'              => array( 'set_billing_email',       'sanitize_email' ),
			'phoneNumber'        => array( 'set_billing_phone',       'sanitize_text_field' ),
		);
		$ship_map = array(
			'firstName'          => array( 'set_shipping_first_name',  'sanitize_text_field' ),
			'lastName'           => array( 'set_shipping_last_name',   'sanitize_text_field' ),
			'address1'           => array( 'set_shipping_address_1',   'sanitize_text_field' ),
			'address2'           => array( 'set_shipping_address_2',   'sanitize_text_field' ),
			'locality'           => array( 'set_shipping_city',        'sanitize_text_field' ),
			'administrativeArea' => array( 'set_shipping_state',       'sanitize_text_field' ),
			'postalCode'         => array( 'set_shipping_postcode',    'sanitize_text_field' ),
			'country'            => array( 'set_shipping_country',     'sanitize_text_field' ),
			'phoneNumber'        => array( 'set_shipping_phone',       'sanitize_text_field' ),
		);
		if ( isset( $payment_details->orderInformation->billTo ) ) {
			$bill_to = $payment_details->orderInformation->billTo;
			foreach ( $bill_map as $field => $cb ) {
				if ( ! empty( $bill_to->$field ) ) { $order->{$cb[0]}( $cb[1]( $bill_to->$field ) ); }
			}
		}
		if ( isset( $payment_details->orderInformation->shipTo ) ) {
			$ship_to = $payment_details->orderInformation->shipTo;
			foreach ( $ship_map as $field => $cb ) {
				if ( ! empty( $ship_to->$field ) ) { $order->{$cb[0]}( $cb[1]( $ship_to->$field ) ); }
			}
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$order->save();
	}

	/**
	 * Retrieve payment details including address from transient token
	 *
	 * @param string $transient_token Transient Token.
	 * @return array|null Payment details or null on failure.
	 */
	public function get_payment_details_from_transient_token( $transient_token ) {
		try {
			$request    = new Visa_Acceptance_Payment_Adapter( $this->gateway );
			$api_client = $request->get_api_client(true);
			$transient_token_api = new TransientTokenDataV2Api( $api_client );

			// Call the API to get transient token data.
			$api_response = $transient_token_api->getTransactionForTransientToken( $transient_token );

			// Return the response body which contains orderInformation with billTo and shipTo.
			return array(
				'http_code' => $api_response[VISA_ACCEPTANCE_VAL_ONE],
				'body'      => $api_response[VISA_ACCEPTANCE_VAL_ZERO],
			);

		} catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
			$this->gateway->add_logs_header_response( 
				wp_json_encode( array( VISA_ACCEPTANCE_ERROR => $e->getMessage() ) ), 
				false, 'Get Payment Details Error' 
			);
			return null;
		}
	}
}
