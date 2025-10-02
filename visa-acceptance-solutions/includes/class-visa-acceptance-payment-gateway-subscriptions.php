<?php
/**
 * The file that defines the subscriptions functionality
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

require_once plugin_dir_path( __DIR__ ) . 'includes/class-visa-acceptance-payment-gateway-unified-checkout.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/class-visa-acceptance-payment-gateway-activator.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-authorization-saved-card.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-payment-methods.php';

/**
 * The subscriptions plugin class.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Payment_Gateway_Subscriptions extends Visa_Acceptance_Payment_Gateway_Unified_Checkout {
	use Visa_Acceptance_Payment_Gateway_Includes_Trait;

	/**
	 * Gateway Object
	 *
	 * @var object
	 */
	public $gateway;

	/**
	 * Subscription constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->gateway = $this;
	}

	/**
	 * Add subscription actions.
	 */
	public function add_subscription_actions() {
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );
		add_filter( 'woocommerce_my_subscriptions_payment_method', array( $this, 'render_payment_method_for_subscriptions' ), VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );
		add_filter( 'woocommerce_account_payment_methods_column_subscriptions', array( $this, 'add_payment_method_subscriptions' ) );
		add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'admin_add_payment_meta' ), 9, VISA_ACCEPTANCE_VAL_TWO );
		add_filter( 'woocommerce_payment_gateways_renewal_support_status_html', array( $this, 'subscriptions_maybe_edit_renewal_support_status' ), VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );
		add_filter( 'woocommerce_subscriptions_process_payment_for_change_method_via_pay_shortcode', array( $this, 'remove_order_meta_from_change_payment' ), VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );
		add_action( 'woocommerce_update_options_checkout_' . $this->get_id(), array( $this, 'visa_acceptance_solutions_subscription_notice' ) );
		add_action( 'woocommerce_subscription_validate_payment_meta_' . $this->get_id(), array( $this, 'admin_validate_payment_meta' ), 9 );
	}

	/**
	 * Adds order meta data
	 *
	 * @param object $subscription order subscription.
	 * @param string $key meta key.
	 * @param string $value meta value.
	 * @param bool   $unique indicates whether the meta value should be unique.
	 */
	public function add_subscription_token_meta_for_migration( $subscription, $key, $value, $unique = false ) {
		if ( $subscription instanceof \WC_Subscription ) {
			if ( handle_hpos_compatibility() ) {
				$subscription->add_meta_data( VISA_ACCEPTANCE_WC_UC_ID . $key, $value, $unique );
				$subscription->save_meta_data();
			} else {
				add_post_meta( $subscription->get_id(), VISA_ACCEPTANCE_WC_UC_ID . $key, $value );
			}
		}
	}

	/**
	 * Save the setting for subscription notice.
	 */
	public function visa_acceptance_solutions_subscription_notice() {
		$wc_payment_gateway_activator = new Visa_Acceptance_Payment_Gateway_Activator();
		if ( VISA_ACCEPTANCE_YES === $this->enabled && ! ( VISA_ACCEPTANCE_YES === $this->get_option( 'tokenization' ) ) ) {
			/* translators: %s - payment method name */
			$message = sprintf( __( 'To use WooCommerce subscriptions with %s, please enable Tokenization.', 'visa-acceptance-solutions' ), $this->method_title );
			wp_admin_notice(
				$message,
				array(
					'id'                 => 'message',
					'additional_classes' => array( 'error' ),
					'dismissible'        => true,
				)
			);
		} else {
			global $wpdb;
			$payment_method = 'cybersource_credit_card';
			$type           = 'shop_subscription';
			if ( handle_hpos_compatibility() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$sv_subscriptions = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}wc_orders WHERE payment_method = %s AND type = %s",
						$payment_method,
						$type
					),
					ARRAY_N
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$sv_subscriptions = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.ID as id FROM {$wpdb->prefix}posts p INNER JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id WHERE pm.meta_value = %s AND p.post_type = %s",
						$payment_method,
						$type
					),
					ARRAY_N
				);
			}
			foreach ( $sv_subscriptions as $sv_subsription_id ) {
				$sv_subsription_id = (array) $sv_subsription_id;
				$sv_subscription   = wcs_get_subscription( $sv_subsription_id[0] );
				if ( VISA_ACCEPTANCE_SV_GATEWAY_ID === $sv_subscription->get_payment_method( VISA_ACCEPTANCE_EDIT ) ) {
					$sv_payment_token         = $this->get_order_meta( $sv_subscription, 'payment_token' );
					$sv_customer_id           = $this->get_order_meta( $sv_subscription, 'customer_id' );
					$tokens                   = WC_Payment_Tokens::get_tokens(
						array(
							'user_id'    => $sv_subscription->get_user_id(),
							'gateway_id' => VISA_ACCEPTANCE_SV_GATEWAY_ID,
						)
					);
					$instrument_identifier_id = VISA_ACCEPTANCE_STRING_EMPTY;
					foreach ( $tokens as $token ) {
						$payment_method = new Visa_Acceptance_Payment_Methods( $this );
						$token_data     = $payment_method->build_token_data( $token );
						if ( $token_data['token'] === $sv_payment_token && get_user_meta( $token_data['user_id'], 'wc_cybersource_customer_id_' . $token_data['environment'], true ) === $sv_customer_id ) {
							$instrument_identifier_id = $token_data['instrument_identifier']['id'];
							break;
						}
					}
					if ( ! empty( $instrument_identifier_id ) ) {
						$sv_subscription->set_payment_method( VISA_ACCEPTANCE_UC_ID );
						$this->add_subscription_token_meta_for_migration( $sv_subscription, 'subscription_token', $instrument_identifier_id );
						$sv_subscription->save();
						$wc_payment_gateway_activator->add_logs_data( 'Success: Subscription Order ' . $sv_subsription_id[0] . ' migrated.' );
					} else {
						$wc_payment_gateway_activator->add_logs_data( 'Failed: Subscription Order ' . $sv_subsription_id[0] . ' migration failed.' );
					}
				}
			}
		}
	}

	/**
	 * Subscription maybe Edit renewal support status.
	 *
	 * @param string $html html.
	 * @param object $gateway The current payment gateways object.
	 */
	public function subscriptions_maybe_edit_renewal_support_status( $html, $gateway ) {
		if ( ( $gateway->id === $this->id ) && ( VISA_ACCEPTANCE_YES === $this->enabled && ! ( VISA_ACCEPTANCE_YES === $this->get_option( 'tokenization' ) ) ) ) {
				$tool_tip = esc_attr__( 'You must enable tokenization for this gateway in order to support automatic renewal payments with the WooCommerce Subscriptions extension.', 'visa-acceptance-solutions' );
				$status   = esc_html__( 'Inactive', 'visa-acceptance-solutions' );

				$html = sprintf(
					'<a href="%1$s"><span class="visa-acceptance-solutions-wc-payment-gateway-renewal-status-inactive tips" data-tip="%2$s">%3$s</span></a>',
					esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $this->id ) ),
					$tool_tip,
					$status
				);
		}

		return $html;
	}

	/**
	 * Scheduled subscription payment.
	 *
	 * @param int    $amount_to_charge Amount to charge.
	 * @param object $order order details.
	 *
	 * @return array $result
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $order ) {
		$result = array( 'success' => false );
		if ( $order->get_payment_method( 'edit' ) === $this->get_id() ) {
			try {
				$token           = null;
				$token           = $this->get_order_meta( $order, 'subscription_token' );
				$current_user_id = $order->get_user_id();

				if ( ! empty( $token ) ) {
					$authorization_saved_card = new Visa_Acceptance_Authorization_Saved_Card( $this );
					$token                    = $this->get_wc_token( $token, $current_user_id );
				}

				if ( ! empty( $token ) && $token instanceof \WC_Payment_Token ) {
					$result = $authorization_saved_card->do_transaction( $order, $token, null, wcs_order_contains_early_renewal( $order ) ? false : true );
				} else {
					$subscriptions = wcs_get_subscriptions_for_order(
						$order,
						array(
							'order_type'  => array( 'any' ),
							'customer_id' => $current_user_id,
						)
					);
					$this->add_logs_data( 'Payment token not found for autorenewal transaction for order ' . $order->get_id() . ' of subscription ' . wp_json_encode( $subscriptions ), true, 'Auto renewal subscription order payment' );
				}

				if ( $result[ VISA_ACCEPTANCE_SUCCESS ] ) {
					$this->add_payment_data_to_subscription( $order );
				}
				return $result;
			} catch ( Exception $e ) {
				$this->add_logs_data( __CLASS__ . __FUNCTION__ . VISA_ACCEPTANCE_SPACE . $e->getMessage() . VISA_ACCEPTANCE_SPACE . 'for the auto renewal order ' . $order->get_id(), true, 'Auto renewal subscription order payment' );
				WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order );
			}
		}
	}

	/**
	 * Render payment method for subscriptions.
	 *
	 * @param string $payment_method_to_display Display method name.
	 * @param object $subscription subscription details.
	 *
	 * @return string @payment_method_to_display
	 */
	public function render_payment_method_for_subscriptions( $payment_method_to_display, $subscription ) {

		// bail for other payment methods.
		if ( $subscription->get_payment_method( 'edit' ) === $this->get_id() && ! is_add_payment_method_page() ) {

			$token = $this->get_order_meta( $subscription, 'subscription_token' );
			$token = $this->get_wc_token( $token, get_current_user_id() );
			if ( $token instanceof \WC_Payment_Token ) {
				$payment_method = new Visa_Acceptance_Payment_Methods( $this );
				$token_data     = $payment_method->build_token_data( $token );
				/* translators: %1$s - payment method name, %2$s - last four digits of the card. */
				$payment_method_to_display = sprintf( __( '%1$s card ending with %2$s', 'visa-acceptance-solutions' ), $payment_method_to_display, $token_data['last_four'] );
			}
		}

		return $payment_method_to_display;
	}

	/**
	 * Add payment method subscriptions.
	 *
	 * @param array $token token.
	 */
	public function add_payment_method_subscriptions( $token ) {

		if ( isset( $token['token'] ) && $token['method']['gateway'] === $this->get_id() ) {
			$subscription_ids = array();
			$subscriptions    = $this->get_payment_token_subscriptions( $token );
			// build a link for each subscription.
			foreach ( $subscriptions as $subscription ) {
				/* translators: %1$s -subscription order number, %2$s - subscription order number. */
				$subscription_ids[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $subscription->get_view_order_url() ), sprintf( __( '#%s', 'visa-acceptance-solutions' ), $subscription->get_order_number() ) );
			}

			if ( ! empty( $subscription_ids ) ) {
				$html = implode( ', ', $subscription_ids );
			} else {
				$html = wp_kses_post( 'N/A', 'visa-acceptance-solutions' );
			}
			echo wp_kses_post( $html );
		}
	}

	/**
	 * Get payment token subscriptions.
	 *
	 * @param array $token token.
	 *
	 * @return object $subscriptions
	 */
	protected function get_payment_token_subscriptions( $token ) {

		$subscriptions = wcs_get_users_subscriptions( get_current_user_id() );
		foreach ( $subscriptions as $key => $subscription ) {
			$payment_method  = $subscription->get_payment_method( 'edit' );
			$stored_token_id = $this->get_order_meta( $subscription, 'subscription_token' );
			if ( $stored_token_id !== (string) $token['token'] || $payment_method !== $this->get_id() ) {
				unset( $subscriptions[ $key ] );
			}
		}

		return $subscriptions;
	}

	/**
	 * Saved token subscriptions payload.
	 *
	 * @param object  $order order details.
	 * @param array   $payload payload.
	 * @param boolean $merchant_initiated merchant initiated transaction.
	 *
	 * @return array $payload
	 */
	public function saved_token_subscriptions_payload( $order, $payload, $merchant_initiated ) {
		if ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) ) {
			if ( wcs_order_contains_renewal( $order ) ) {
				if ( $merchant_initiated ) {
					$payload['authorizationOptions']['initiator']['type'] = 'merchant';
					if ( ! in_array( VISA_ACCEPTANCE_DECISION_SKIP, $payload['actionList'], true ) ) {
						array_push( $payload['actionList'], VISA_ACCEPTANCE_DECISION_SKIP );
					}
				}
			}
			if ( ! empty( $this->get_order_meta( $order, 'subscription_token' ) ) && wcs_order_contains_renewal( $order ) ) {
				$payload['commerceIndicator'] = VISA_ACCEPTANCE_RECURRING;
			} else {
				$payload['recurringOptions']['firstRecurringPayment'] = true;
			}
		}

		return $payload;
	}

	/**
	 * Customer subscription payload
	 *
	 * @param object $order order details.
	 * @param array  $payload payload.
	 *
	 * @return array $payload
	 */
	public function customer_subscription_payload( $order, $payload ) {
		if ( ! empty( $this->get_order_meta( $order, 'subscription_token' ) ) && wcs_order_contains_renewal( $order ) ) {
			$payload['commerceIndicator'] = VISA_ACCEPTANCE_RECURRING;
		} elseif ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) ) {
			$payload['recurringOptions']['firstRecurringPayment'] = true;
		}

		return $payload;
	}

	/**
	 * Admin add payment meta.
	 *
	 * @param array  $meta meta.
	 * @param object $subscription subscription details.
	 *
	 * @return array $meta
	 */
	public function admin_add_payment_meta( $meta, $subscription ) {
		$meta[ $this->get_id() ] = array(
			'post_meta' => array(
				VISA_ACCEPTANCE_WC_UC_ID . 'subscription_token' => array(
					'value' => $this->get_order_meta( $subscription, 'subscription_token' ),
					'label' => __( 'Payment Token', 'visa-acceptance-solutions' ),

				),
			),
		);
		return $meta;
	}

	/**
	 * Add payment data to subscription.
	 *
	 * @param object $order order details.
	 */
	public function add_payment_data_to_subscription( $order ) {
		$subscriptions = wcs_get_subscriptions_for_order(
			$order,
			array(
				'order_type'  => array( 'any' ),
				'customer_id' => $order->get_user_id(),
			)
		);

		foreach ( $subscriptions as $subscription ) {
			$updated = false;
			if ( ! empty( $this->get_order_meta( $order, 'subscription_token' ) ) ) {
				if ( ! empty( $this->get_order_meta( $subscription, 'subscription_token' ) ) ) {
					$subscription->update_meta_data( VISA_ACCEPTANCE_WC_UC_ID . 'subscription_token', $this->get_order_meta( $order, 'subscription_token' ) );
				} elseif ( VISA_ACCEPTANCE_ZERO_AMOUNT === $order->get_total() ) {
						$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, VISA_ACCEPTANCE_STRING_EMPTY );
						$subscription->add_meta_data( VISA_ACCEPTANCE_WC_UC_ID . 'subscription_token', $this->get_order_meta( $order, 'subscription_token' ) );
				} else {
					$subscription->add_meta_data( VISA_ACCEPTANCE_WC_UC_ID . 'subscription_token', $this->get_order_meta( $order, 'subscription_token' ) );
				}
				$updated = true;
			}
			if ( $updated ) {
				$subscription->save_meta_data();
			}
		}
	}

	/**
	 * Update order subscription token
	 *
	 * @param object $order order details.
	 * @param string $instrument_identifier instrument identifier.
	 */
	public function update_order_subscription_token( $order, $instrument_identifier ) {
		if ( ! empty( $this->get_order_meta( $order, 'subscription_token' ) ) ) {
			$this->update_order_meta( $order, 'subscription_token', $instrument_identifier );
		} else {
			$authorization_saved_card = new Visa_Acceptance_Authorization_Saved_Card( $this );
			$authorization_saved_card->add_order_meta( $order, 'subscription_token', $instrument_identifier );
		}
	}

	/**
	 * Change payment method.
	 *
	 * @param string $token_id token_id.
	 * @param object $order order details.
	 * @param array  $transient_token $transient_token.
	 *
	 * @return array $response_array
	 */
	public function change_payment_method( $token_id, $order, $transient_token ) {
		$payment_method_updated = false;
		$instrument_identifier  = null;
		$subscriptions          = array();
		$response_array         = array(
			'result' => VISA_ACCEPTANCE_FAILURE,
		);
		try {
			if ( WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) {
				$error_message = esc_html__( 'An error occurred, please try again or try an alternate form of payment.', 'visa-acceptance-solutions' );
				if ( $token_id ) {
					$token = $this->plugin_public->get_meta_data_token( $token_id );
					if ( $token instanceof WC_Payment_Token ) {
						$payment_method_updated = true;
						$instrument_identifier  = $token->get_token();
					}
				} elseif ( ! empty( $transient_token ) ) {
					$payment_method = new Visa_Acceptance_Payment_Methods( $this );
					$result         = $payment_method->create_token( $transient_token, $order );
					if ( isset( $result['status'] ) && $result['status'] && isset( $result['token'] ) ) {
						$payment_method_updated = true;
						$instrument_identifier  = $result['token'];
					} elseif ( ! empty( $result['message'] ) ) {
						$error_message = $result['message'];
					}
				}
				$subscriptions = wcs_get_subscriptions_for_order(
					$order->get_parent_id(),
					array(
						'order_type'  => array( 'any' ),
						'customer_id' => $order->get_user_id(),
					)
				);
				if ( $payment_method_updated && ! empty( $instrument_identifier ) ) {
					$this->update_order_subscription_token( $order, $instrument_identifier );
					$parent_order = wc_get_order( $order->get_parent_id() );
					if ( ! empty( $parent_order ) ) {
						$this->update_order_subscription_token( $parent_order, $instrument_identifier );
						$this->add_payment_data_to_subscription( $parent_order );
					}
					$this->add_logs_data( 'Payment method updated for order' . VISA_ACCEPTANCE_SPACE . $order->get_id() . ' of subscription' . VISA_ACCEPTANCE_SPACE . wp_json_encode( $subscriptions ), true, 'Change payment method for subscription' );
					$response_array = array(
						'result'   => VISA_ACCEPTANCE_SUCCESS,
						'redirect' => $this->get_return_url( $order ),
					);
				} else {
					$this->add_logs_data( 'Payment method update failed for order' . VISA_ACCEPTANCE_SPACE . $order->get_id() . ' of subscription' . VISA_ACCEPTANCE_SPACE . wp_json_encode( $subscriptions ), true, 'Change payment method for subscription' );
					$this->mark_order_failed( $error_message );
				}
			}
		} catch ( Exception $e ) {
			$this->add_logs_data( __CLASS__ . __FUNCTION__ . VISA_ACCEPTANCE_SPACE . $e->getMessage() . VISA_ACCEPTANCE_SPACE . 'Payment method update failed for order' . VISA_ACCEPTANCE_SPACE . $order->get_id(), true, 'Change payment method for subscription' );
		}
		return $response_array;
	}

	/**
	 * Remove order meta from change payment
	 *
	 * @param boolean $result result.
	 * @param object  $subscription subscription details.
	 *
	 * @return boolean $result
	 */
	public function remove_order_meta_from_change_payment( $result, $subscription ) {

		$subscription = is_numeric( $subscription ) ? wcs_get_subscription( $subscription ) : $subscription;

		if ( ! $subscription instanceof \WC_Subscription ) {
			return $result;
		}
		$old_payment_method = $subscription->get_meta( '_old_payment_method', true, 'edit' );
		$new_payment_method = $subscription->get_payment_method( 'edit' );
		$gateway_id         = $this->get_id();

		// if the payment method has been changed to another gateway, additionally remove the old payment token and customer ID meta.
		if ( $new_payment_method !== $gateway_id && $old_payment_method === $gateway_id ) {

			$subscription->delete_meta_data( VISA_ACCEPTANCE_WC_UC_ID . 'subscription_token' );
			$subscription->save_meta_data();
		}

		return $result;
	}

	/**
	 * Admin validate payment meta.
	 *
	 * @param array $meta meta.
	 * @throws \Exception If the subscription token is missing.
	 */
	public function admin_validate_payment_meta( $meta ) {
		if ( empty( $meta['post_meta'][ VISA_ACCEPTANCE_WC_UC_ID . 'subscription_token' ]['value'] ) ) {
			/* translators: %s - label of the required field. */
			throw new \Exception(
				sprintf(
					esc_html__( '%s is required.', 'visa-acceptance-solutions' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
					esc_html( $meta['post_meta'][ VISA_ACCEPTANCE_WC_UC_ID . 'subscription_token' ]['label'] )
				)
			);
		}
	}
}
