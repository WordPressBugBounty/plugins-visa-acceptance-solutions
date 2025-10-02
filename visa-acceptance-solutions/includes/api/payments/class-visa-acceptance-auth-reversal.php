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

use Automattic\WooCommerce\Caches\OrderCache;

/**
 * Include all the necessary dependencies.
 */
require_once __DIR__ . '/../class-visa-acceptance-request.php';
require_once __DIR__ . '/../class-visa-acceptance-api-client.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-authorization-reversal-request.php';
require_once __DIR__ . '/../response/payments/class-visa-acceptance-authorization-reversal-response.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';

/**
 * Visa Acceptance Authorization Reversal Class
 *
 * Handles Authorization Reversal requests
 */
class Visa_Acceptance_Auth_Reversal extends Visa_Acceptance_Request {

	/**
	 * Gateway object.
	 *
	 * @var object $gateway */
	public $gateway;

	/**
	 * Handles Authorization Reversal requests
	 *
	 * @param object $gateway gateway.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Handles Void Transaction
	 *
	 * @param \WC_Order $order order object.
	 * @param string    $amount void amount.
	 * @param string    $reason void reason.
	 *
	 * @return any
	 */
	public function process_void( $order, $amount, $reason ) {
		$auth_rev_response = new Visa_Acceptance_Authorization_Reversal_Response( $this->gateway );
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		try {
			if ( VISA_ACCEPTANCE_VAL_ZERO != $order->get_total() && $order->get_total() == $amount ) { //phpcs:ignore
				$transaction_id          = $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID );
				$reversal_response       = $this->get_reversal_response( $order, $amount, $reason, $transaction_id );
				$http_code               = $reversal_response['http_code'];
				$reversal_body = $reversal_response['body'];
				if(VISA_ACCEPTANCE_FOUR_ZERO_ONE === $http_code) {
					$reversal_body = wp_json_encode($reversal_response['body']);
				}
				$reversal_response_array = $this->get_payment_response_array(
					$http_code,
					$reversal_body,
					VISA_ACCEPTANCE_AUTH_REVERSAL
				);
				$status                  = $reversal_response_array['status'];
				if ( $auth_rev_response->is_transaction_approved( $reversal_response, $status ) ) {
					$this->add_void_data( $order, $reversal_response_array );
					$this->mark_order_as_voided( $order, $reason, $reversal_response_array );
					$response = true;
				} else {
					$response = $this->get_void_failed_wp_error( $http_code, $reversal_response_array['reason'] );
					$order->add_order_note( $response->get_error_message() );
				}
			} else {
				$response = $this->get_void_failed_wp_error( VISA_ACCEPTANCE_INVALID_AMOUNT, VISA_ACCEPTANCE_INVALID_AMOUNT_ERROR );
				$order->add_order_note( $response->get_error_message() );
			}
			return $response;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to handles Void transaction', true );
		}
	}

	/**
	 * Updates order notes if void fails
	 *
	 * @param string $error_code error code.
	 * @param string $error_message error message.
	 *
	 * @return object
	 */
	protected function get_void_failed_wp_error( $error_code, $error_message ) {
		try {
			if ( $error_code ) {
				$message = sprintf(
				/* translators: Placeholders: %1$s - payment gateway title, %2$s - error code, %3$s - error message. Void as in to void an order. */
					esc_html__( '%1$s Void Failed: %2$s - %3$s', 'visa-acceptance-solutions' ),
					$this->gateway->get_title(),
					$error_code,
					$error_message
				);
			} else {
				$message = sprintf(
				/* translators: Placeholders: %1$s - payment gateway title, %2$s - error message. Void as in to void an order. */
					esc_html__( '%1$s Void Failed: %2$s', 'visa-acceptance-solutions' ),
					$this->gateway->get_title(),
					$error_message
				);
			}
			return new \WP_Error( VISA_ACCEPTANCE_WC_UNDERSCORE . $this->gateway->get_id() . VISA_ACCEPTANCE_UNDERSCORE_VOID_FAILED, $message );
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to updates order notes if void fails', true );
		}
	}

	/**
	 * Generate auth-reversal response payload
	 *
	 * @param \WC_Order $order order.
	 * @param string    $amount auth reversal amount.
	 * @param string    $reason auth reversal reason.
	 * @param string    $transaction_id transaction id.
	 *
	 * @return array
	 */
	public function get_reversal_response( $order, $amount, $reason, $transaction_id ) {
		$settings     = $this->gateway->get_config_settings();
		$request      = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client   = $request->get_api_client();
		$reversal_api = new \CyberSource\Api\ReversalApi( $api_client );

		// Build the payload for the reversal request.
		$client_reference_information_partner = new \CyberSource\Model\Ptsv2paymentsidreversalsClientReferenceInformationPartner(
			array(
				'developerId' => VISA_ACCEPTANCE_DEVELOPER_ID,
				'solutionId'  => VISA_ACCEPTANCE_SOLUTION_ID,
			)
		);

		$client_reference_information = new \CyberSource\Model\Ptsv2paymentsidreversalsClientReferenceInformation(
			array(
				'code'               => $order->get_id(),
				'partner'            => $client_reference_information_partner,
				'applicationName'    => VISA_ACCEPTANCE_PLUGIN_APPLICATION_NAME . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_PLUGIN_API_TYPE,
				'applicationVersion' => VISA_ACCEPTANCE_PLUGIN_VERSION,
			)
		);

		$reversal_information_amount_details = new \CyberSource\Model\Ptsv2paymentsidreversalsReversalInformationAmountDetails(
			array(
				'totalAmount' => (string) $amount,
			)
		);

		$reversal_information = new \CyberSource\Model\Ptsv2paymentsidreversalsReversalInformation(
			array(
				'amountDetails' => $reversal_information_amount_details,
				'reason'        => $reason,
			)
		);

		$processing_information = new \CyberSource\Model\Ptsv2paymentsidreversalsProcessingInformation(
			array(
				'paymentSolution' => $request->get_payment_solution( $order ),
			)
		);

		$order_information = new \CyberSource\Model\Ptsv2paymentsidreversalsOrderInformation(
			array(
				'lineItems' => $request->get_line_items_information( $order ),
			)
		);

		if ( VISA_ACCEPTANCE_UC_ID === $order->get_payment_method( VISA_ACCEPTANCE_EDIT ) ) {
			$payment_solution = $this->get_order_meta( $order, 'payment_solution' );
			$reversal_request = array(
				'clientReferenceInformation' => $client_reference_information,
				'reversalInformation'        => $reversal_information,
				'orderInformation'           => $order_information,
			);

			if ( ! empty( $payment_solution ) ) {
				$reversal_request['processingInformation'] = $processing_information;
			}

			$reversal_request_array = new \CyberSource\Model\AuthReversalRequest( $reversal_request );
			if ( ! empty( $reversal_request_array ) ) {
				$this->gateway->add_logs_data( $reversal_request_array, true, VISA_ACCEPTANCE_AUTHORIZATION_REVERSAL );
				try {
					$api_response = $reversal_api->authReversal( $transaction_id, $reversal_request_array );
					$this->gateway->add_logs_service_response( $api_response[0],$api_response[2]['v-c-correlation-id'], true, VISA_ACCEPTANCE_AUTHORIZATION_REVERSAL );
					$return_array = array(
						'http_code' => $api_response[1],
						'body'      => $api_response[0],
					);
					return $return_array;
				} catch ( \CyberSource\ApiException $e ) {
					$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, VISA_ACCEPTANCE_AUTHORIZATION_REVERSAL );
				}
			}
		}
	}

	/**
	 * Updates order status based on Authorization Response.
	 *
	 * @param \WC_Order $order order object.
	 * @param string    $reason reason for auth reversal.
	 * @param array     $reversal_response_array reversal response array.
	 *
	 * @return void
	 */
	public function mark_order_as_voided( $order, $reason, $reversal_response_array ) {

		$message = sprintf(
		/* translators: Placeholders: %1$s - payment gateway title, %2$s - a monetary amount. Void as in to void an order. */
			esc_html__( 'Void amount of %1$s approved.', 'visa-acceptance-solutions' ),
			wc_price(
				$reversal_response_array['amount'],
				array(
					'currency' => $order->get_currency(),
				)
			)
		);
		if ( ! empty( $reason ) ) {
			$reason_message = sprintf(
			/* translators: %1$s - reason */
				esc_html__( ' Voided because of reason - %1$s.', 'visa-acceptance-solutions' ),
				$reason,
			);
			$message = $message . $reason_message;
		}

		// adds the transaction id (if any) to the order note.
		if ( $reversal_response_array['transaction_id'] ) {
			$message .= VISA_ACCEPTANCE_SPACE . sprintf(
				/* translators: Placeholders: %1$s - payment gateway title, %2$s - error code, %3$s - error message. Void as in to void an order. */
				esc_html__( '(Transaction ID %s)', 'visa-acceptance-solutions' ),
				$reversal_response_array['transaction_id']
			);
		}
		// mark order as cancelled, since no money was actually transferred.
		if ( ! $order->has_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED ) ) {
			add_filter( 'woocommerce_order_fully_refunded_status', array( $this, 'cancel_voided_order' ), 10, 2 );
		}
		$order->add_order_note( $message );
	}

	/**
	 * Checks whether the order status is already cancelled.
	 *
	 * @param array $order_status order status.
	 * @param int   $order_id order id.
	 *
	 * @return string|void
	 */
	public function cancel_voided_order( $order_status, $order_id ) {
		$transaction_id = null;
		$order          = wc_get_order( $order_id );
		try {
			if ( in_array( $order->get_payment_method(), array( VISA_ACCEPTANCE_UC_ID ), true ) ) {
				if ( class_exists( OrderCache::class ) ) {
					$order_cache = wc_get_container()->get( OrderCache::class );
					$order_cache->remove( $order_id );
					$order = wc_get_order( $order_id );
				}
				$transaction_id = $this->get_order_meta( $order, VISA_ACCEPTANCE_VOID_TRANSACTION_ID );
				return empty( $transaction_id ) ? $order_status : VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED;
			}
			return; // phpcs:ignore WordPress.Security.NonceVerification
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to check order status is already cancelled.', true );
		}
	}

	/**
	 * Add voided data to the transaction details
	 *
	 * @param \WC_Order $order order.
	 * @param array     $reversal_response_array reversal response array.
	 */
	protected function add_void_data( \WC_Order $order, $reversal_response_array ) {
		try {
			// indicate the order was voided along with the amount.
			$this->update_order_meta( $order, VISA_ACCEPTANCE_VOID_AMOUNT, $reversal_response_array['amount'] );

			// add refund transaction ID.
			if ( $reversal_response_array && $reversal_response_array['transaction_id'] ) {
				$this->add_order_meta( $order, VISA_ACCEPTANCE_VOID_TRANSACTION_ID, $reversal_response_array['transaction_id'] );
			}
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to add voided data to transaction details', true );
		}
	}
}
