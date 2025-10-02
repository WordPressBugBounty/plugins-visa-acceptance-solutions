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
require_once __DIR__ . '/../class-visa-acceptance-api-client.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';

/**
 * Visa Acceptance Reporting Class
 *
 * Handles conversion and transaction detail reports
 */
class Visa_Acceptance_Reporting extends Visa_Acceptance_Request {

	/**
	 * Gateway object
	 *
	 * @var object $gateway */
	public $gateway;

	/**
	 * Key_Generation constructor.
	 *
	 * @param object $gateway gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}
	/**
	 * Fetches order based on request id from db.
	 *
	 * @param string $request_id request id.
	 * @return \WC_Order|null
	 */
	public function get_order( string $request_id ) {
		try {
			$return_order = null;
			$orders       = wc_get_orders(
				array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_TRANSACTION_ID,
							'value' => $request_id,
						),
					),
				)
			);
			foreach ( $orders as $order ) {
				if ( $order->get_payment_method( VISA_ACCEPTANCE_EDIT ) === $this->gateway->get_id() && strval( $request_id ) === strval( $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID ) ) ) {
					$return_order = $order;
					break;
				}
			}
			return $return_order;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to fetches order based on request id from db.', true );
		}
	}

	/**
	 * Create conversion detail report and update transaction status.
	 *
	 * @param string $merchant_id merchant id value.
	 */
	public function get_conversion_details( $merchant_id ) {
		$end_time             = gmdate( VISA_ACCEPTANCE_DATE_Y_M_D_TH_I_S, strtotime( VISA_ACCEPTANCE_REPORT_END_TIME ) ) . 'Z';
		$start_time           = gmdate( VISA_ACCEPTANCE_DATE_Y_M_D_TH_I_S, strtotime( VISA_ACCEPTANCE_REPORT_START_TIME ) ) . 'Z';
		$request  			  = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$report_data_response = $this->getCDReportData(
			$start_time,
			$end_time
		);
		if ( VISA_ACCEPTANCE_TWO_ZERO_ZERO === (int) $report_data_response['http_code'] ) {
			$rows = json_decode( $report_data_response['body'], true );
			foreach ( $this->get_conversion_detail_reports( $rows ) as $conversion_detail ) {
				try {
					$order = $this->get_order( $conversion_detail['requestId'] );
					if ( ! empty( $order ) && $order instanceof \WC_Order ) {
						$payment_gateway_id         = $order->get_payment_method( VISA_ACCEPTANCE_EDIT );
						$payment_acceptance_service = $this->get_order_meta( $order, VISA_ACCEPTANCE_PAYMENT_ACCEPTANCE_SERVICE );

						if ( ( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING === $order->get_status() ) && ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_PENDING_REVIEW === $this->get_order_meta( $order, VISA_ACCEPTANCE_UNDERSCORE_PAYMENT_STATUS ) ) ) {
							if ( VISA_ACCEPTANCE_ACCEPT === $conversion_detail['newDecision'] ) {
								$message  = sprintf(
									__( 'Order Accepted in', 'visa-acceptance-solutions' ) . VISA_ACCEPTANCE_SPACE . $this->gateway->get_title() . VISA_ACCEPTANCE_SPACE . __( 'Case Management System.', 'visa-acceptance-solutions' )// phpcs:ignore WordPress.Security.NonceVerification
								);
								$settings = $this->gateway->get_config_settings();
								if ( $this->check_order_is_settled( $conversion_detail ) ) {
									$this->update_captured_order_status( $order, $conversion_detail, $payment_gateway_id );
									$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, $message );
								} elseif ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $payment_acceptance_service || $request->check_virtual_order_enabled( $settings, $order ) ) {
									$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, $message );
								} elseif ( VISA_ACCEPTANCE_STRING_EMPTY !== $payment_acceptance_service ) {
									$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD, $message );
								}
							} else {
								$message = sprintf(
									__( 'Order Rejected in', 'visa-acceptance-solutions' ) . VISA_ACCEPTANCE_SPACE . $this->gateway->get_title() . VISA_ACCEPTANCE_SPACE . __( 'Case Management System.', 'visa-acceptance-solutions' )
								);
								$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED, $message );
							}
						}
					}
				} catch ( \Exception $e ) {
					$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Scheduler functionality', true );
				}
			}
		}
	}

	/**
	 * Retrieves conversion detail report from an API
	 *
	 * @param string $start_time start time for reporting.
	 * @param string $end_time end time for reporting.
	 *
	 * @return array
	 */
	private function getCDReportData( $start_time, $end_time ) {
		$api = new Visa_Acceptance_Api_Client( $this->gateway );
		try {
			$resource = VISA_ACCEPTANCE_REPORTING_RESOURCE . $start_time . '&endTime=' . $end_time;
			return $api->processor( null, $resource, true, null, $this->gateway->get_config_settings() );
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to retrieves conversion detail report from an API.', true );
		}
	}

	/**
	 * Retrieves conversion detail report using getCDReportData function
	 *
	 * @param array $rows rows in CDReportData.
	 *
	 * @return array
	 */
	public function get_conversion_detail_reports( $rows ) {
		try {
			return $rows['conversionDetails'];
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to retrieves conversion detail report using getCDReportData function.', true );
		}
	}

	/**
	 * Check order settlement based on given conversion report
	 *
	 * @param array $conversion_detail conversion detail row.
	 *
	 * @return boolean
	 */
	public function check_order_is_settled( $conversion_detail ) {
		try {
			$is_settled = false;
			$notes      = $this->get_order_notes( $conversion_detail );
			if ( is_array( $notes ) ) {
				foreach ( $notes as $note ) {
					if ( ! empty( $note['comments'] ) && strpos( $note['comments'], VISA_ACCEPTANCE_CARD_SETTLEMENT_SUCCEEDED ) ) {
						$is_settled = true;
						break;
					}
				}
			}
			return $is_settled;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to check order settlement based on given conversion report.', true );
		}
	}

	/**
	 * Check order notes key in conversion detail report
	 *
	 * @param array $conversion_detail conversion detail row.
	 *
	 * @return array
	 */
	public function get_order_notes( $conversion_detail ) {
		try {
			return ! empty( $conversion_detail['notes'] ) ? $conversion_detail['notes'] : null;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to check order notes key in conversion report.', true );
		}
	}

	/**
	 * Retrieves transaction data from conversion detail report and updates order status
	 *
	 * @param object $order order object.
	 * @param array  $conversion_detail conversion detail row.
	 * @param string $payment_gateway_id Payment Gateway ID.
	 */
	public function update_captured_order_status( $order, $conversion_detail, $payment_gateway_id ) {
		$captured_data = $this->get_notes_transaction_data( $conversion_detail );
		// add capture transaction ID.
		if ( $captured_data ) {
			$this->update_order_meta_updated( $order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID, $captured_data, $payment_gateway_id );
		}
		// update capture related data.
		$this->update_order_meta_updated( $order, VISA_ACCEPTANCE_CAPTURE_TOTAL, $order->get_total(), $payment_gateway_id );
		$this->update_order_meta_updated( $order, VISA_ACCEPTANCE_CHARGE_CAPTURED, VISA_ACCEPTANCE_YES, $payment_gateway_id );
	}

	/**
	 * Updates order meta.
	 *
	 * @param \WC_Order $order order details.
	 * @param string    $key array key.
	 * @param string    $value value.
	 * @param string    $payment_gateway_id Payment Gateway ID.
	 *
	 * @return array
	 */
	public function update_order_meta_updated( $order, $key, $value, $payment_gateway_id ) {
		try {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}
			if ( $order instanceof \WC_Order ) {
				if ( handle_hpos_compatibility() ) {
					$order->update_meta_data( $this->get_order_meta_prefix_includes_updated( $payment_gateway_id ) . $key, $value );
					$order->save_meta_data();
				} else {
					update_post_meta( $order->get_id(), $this->get_order_meta_prefix_includes_updated( $payment_gateway_id ) . $key, $value );
				}
			}
			return $order instanceof \WC_Order;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to update order meta.', true );
		}
	}

	/**
	 * Gets the order meta prefix used for order meta
	 *
	 * @param string $payment_gateway_id Payment Gateway ID.
	 *
	 * @return string
	 */
	public function get_order_meta_prefix_includes_updated( $payment_gateway_id ) {
		return VISA_ACCEPTANCE_UNDERSCORE . VISA_ACCEPTANCE_WC_UNDERSCORE . $payment_gateway_id . VISA_ACCEPTANCE_UNDERSCORE;
	}

	/**
	 * Retrieves transaction data related to notes and comments
	 *
	 * @param array $conversion_detail conversion detail row.
	 *
	 * @return array
	 */
	public function get_notes_transaction_data( $conversion_detail ) {
		try {
			$captured_data = null;
			$notes         = $this->get_order_notes( $conversion_detail );
			if ( is_array( $notes ) ) {
				foreach ( $notes as $note ) {
					if ( ! empty( $note['comments'] ) && strpos( $note['comments'], VISA_ACCEPTANCE_CARD_SETTLEMENT_SUCCEEDED ) ) {
						if ( ! empty( $note['requestId'] ) ) {
							return $note['requestId'];
						}
						$substring_after_is = strstr( $note['comments'], VISA_ACCEPTANCE_IS );
						return trim( substr( $substring_after_is, 2 ) );
					}
				}
			}
			return $captured_data;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to retrieves transaction data related to notes & comments.', true );
		}
	}
}
