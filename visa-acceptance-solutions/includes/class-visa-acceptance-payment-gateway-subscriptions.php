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
		add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'admin_add_payment_meta' ), VISA_ACCEPTANCE_VAL_NINE, VISA_ACCEPTANCE_VAL_TWO );
		add_filter( 'woocommerce_payment_gateways_renewal_support_status_html', array( $this, 'subscriptions_maybe_edit_renewal_support_status' ), VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );
		add_filter( 'woocommerce_subscriptions_process_payment_for_change_method_via_pay_shortcode', array( $this, 'remove_order_meta_from_change_payment' ), VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );
		add_action( 'admin_notices', array( $this, 'visa_acceptance_solutions_subscription_notice' ) );
		add_action( 'woocommerce_subscription_validate_payment_meta_' . $this->get_id(), array( $this, 'admin_validate_payment_meta' ), VISA_ACCEPTANCE_VAL_NINE );
		add_filter( 'woocommerce_order_needs_payment', array( $this, 'dm_review_early_renewal_needs_payment' ), VISA_ACCEPTANCE_VAL_TEN, VISA_ACCEPTANCE_VAL_TWO );
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
			if ( visa_acceptance_handle_hpos_compatibility() ) {
				$subscription->add_meta_data( VISA_ACCEPTANCE_WC_UC_ID . $key, $value, $unique );
				$subscription->save_meta_data();
			} else {
				add_post_meta( $subscription->get_id(), VISA_ACCEPTANCE_WC_UC_ID . $key, $value );
			}
		}
	}

	/**
	 * Display admin notice when WooCommerce Subscriptions is active but Tokenization is disabled.
	 */
	public function visa_acceptance_solutions_subscription_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading URL parameters for conditional notice display only.
		$page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : VISA_ACCEPTANCE_STRING_EMPTY;
		$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : VISA_ACCEPTANCE_STRING_EMPTY;
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : VISA_ACCEPTANCE_STRING_EMPTY;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'wc-settings' !== $page || 'checkout' !== $tab || $this->get_id() !== $section ) {
			return;
		}
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['save'] ) ) {
			$enabled_key      = $this->get_field_key( 'enabled' );
			$tokenization_key = $this->get_field_key( 'tokenization' );
			$enabled          = isset( $_POST[ $enabled_key ] ) ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO;
			$tokenization     = isset( $_POST[ $tokenization_key ] ) ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO;
		} else {
			$enabled      = $this->get_option( 'enabled' );
			$tokenization = $this->get_option( 'tokenization' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( VISA_ACCEPTANCE_YES === $enabled && VISA_ACCEPTANCE_YES !== $tokenization ) {
			/* translators: %s - payment method name */
			$message = sprintf( __( 'To use WooCommerce subscriptions with %s, please enable Tokenization.', 'visa-acceptance-solutions' ), $this->method_title );
			wp_admin_notice(
				$message,
				array(
					'id'          => 'visa-acceptance-subscription-tokenization-notice',
					'type'        => VISA_ACCEPTANCE_WARNING,
					'dismissible' => true,
				)
			);
		} else {
			$wc_payment_gateway_activator = new Visa_Acceptance_Payment_Gateway_Activator();
			global $wpdb;
			$payment_method = 'cybersource_credit_card';
			$type           = 'shop_subscription';
			if ( visa_acceptance_handle_hpos_compatibility() ) {
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
				$sv_subscription   = wcs_get_subscription( $sv_subsription_id[VISA_ACCEPTANCE_VAL_ZERO] );
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
						if ( $token_data['token'] === $sv_payment_token && get_user_meta( $token_data['user_id'], 'wc_cybersource_customer_id_' . $token_data[VISA_ACCEPTANCE_ENVIRONMENT], true ) === $sv_customer_id ) {
							$instrument_identifier_id = $token_data['instrument_identifier']['id'];
							break;
						}
					}
					if ( ! empty( $instrument_identifier_id ) ) {
						$sv_subscription->set_payment_method( VISA_ACCEPTANCE_UC_ID );
						$this->add_subscription_token_meta_for_migration( $sv_subscription, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN, $instrument_identifier_id );
						$sv_subscription->save();
						$wc_payment_gateway_activator->add_logs_data( 'Success: Subscription Order ' . $sv_subsription_id[VISA_ACCEPTANCE_VAL_ZERO] . ' migrated.' );
					} else {
						$wc_payment_gateway_activator->add_logs_data( 'Failed: Subscription Order ' . $sv_subsription_id[VISA_ACCEPTANCE_VAL_ZERO] . ' migration failed.' );
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
        $result = array( VISA_ACCEPTANCE_SUCCESS => false );
        if ( $order->get_payment_method( 'edit' ) === $this->get_id() ) {
            try {
                // Get token from subscription, not from renewal order.
                $token           = null;
                $current_user_id = $order->get_user_id();
                $subscriptions = wcs_get_subscriptions_for_order(
                    $order,
                    array(
                        'order_type'  => array( 'any' ),
                        'customer_id' => $current_user_id,
                    )
                );
               
                // Try to get token from the first subscription.
                if ( ! empty( $subscriptions ) ) {
                    foreach ( $subscriptions as $subscription ) {
                        $token = $this->get_order_meta( $subscription, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN );
                        if ( ! empty( $token ) ) {
                            break;
                        }
                    }
                }
                // Fallback: try to get token from renewal order itself (legacy support).
                if ( empty( $token ) ) {
                    $token = $this->get_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN );
                }
               
                $is_early_renewal = false;
                $accepted_via_modal = false;
 
                if ( ! empty( $token ) ) {
                    $authorization_saved_card = new Visa_Acceptance_Authorization_Saved_Card( $this );
                    $token                    = $this->get_wc_token( $token, $current_user_id );
                }
 
                if ( ! empty($order)) {
                    if ( method_exists( $order, 'get_meta' ) ) {
                        $is_early_renewal = (bool) $order->get_meta( '_subscription_renewal_early' );
                    }
                }
 
                if ( ! $accepted_via_modal ) {
                    $accepted_via_modal = ( VISA_ACCEPTANCE_YES === get_option( 'woocommerce_subscriptions_enable_early_renewal_via_modal', VISA_ACCEPTANCE_NO ) ) ? true : false ;
                }
 
                if ( ! empty( $token ) && $token instanceof \WC_Payment_Token ) {
                    $result = $authorization_saved_card->do_transaction( $order, $token, wcs_order_contains_early_renewal( $order ) ? false : true );
                } else {
                    $this->add_logs_data( 'Payment token not found for autorenewal transaction for order ' . $order->get_id() . ' of subscription ' . wp_json_encode( $subscriptions ), true, 'Auto renewal subscription order payment' );
                }
 
                if ( $result[ VISA_ACCEPTANCE_SUCCESS ] ) {
                    // Refresh order to get latest status after transaction.
                    $order = wc_get_order( $order->get_id() );
                    
                    // Check if order is in Decision Manager review — DM review sets order to PENDING.
                    $is_dm_review = $order->has_status( array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING ) );
                    
                    // For early renewal orders in DM review, add a flag.
                    if ( $is_dm_review && $is_early_renewal && $accepted_via_modal ) {
                        $order->update_meta_data( '_visa_dm_review_early_renewal', VISA_ACCEPTANCE_YES );
                        $order->save();
                    
                        // Add success message for DM review orders.
                        if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( __( 'Your early renewal order was successful.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_SUCCESS );
                        }
                    }
                    
                    // Only update subscription payment data if NOT in DM review.
                    // DM review orders will update subscription when approved via reporting cron.
                    if ( ! $is_dm_review ) {
                        $this->add_payment_data_to_subscription( $order );
                    }
                } else {
                    $is_dm_reject = $order->has_status( array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED ) );
 
                    // For early renewal orders in DM review, add a flag.
                    if ( $is_dm_reject && $is_early_renewal && $accepted_via_modal ) {
                        $order->update_meta_data( '_visa_dm_reject_early_renewal', VISA_ACCEPTANCE_NO );
                        $order->save();
                    
                        // Add success message for DM review orders.
                        if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_ERROR );
                        }
                    }
                }
                return $result;
            } catch ( Exception $e ) {
                $this->add_logs_data( __CLASS__ . __FUNCTION__ . VISA_ACCEPTANCE_SPACE . $e->getMessage() . VISA_ACCEPTANCE_SPACE . 'for the auto renewal order ' . $order->get_id(), true, 'Auto renewal subscription order payment' );
                WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order );
            }
        }
    }

	/**
	 * Prevent DM review early renewal orders from being considered as "needs payment"
	 * This prevents the WooCommerce Subscriptions modal handler from deleting them
	 *
	 * @param bool $needs_payment Whether the order needs payment.
	 * @param WC_Order $order The order object.
	 * @return bool
	 */
	public function dm_review_early_renewal_needs_payment( $needs_payment, $order ) {
		$result = $needs_payment;
        // Only apply to our payment gateway.
        if ( $order->get_payment_method() === $this->id ) {
            // Check if this is an early renewal order in DM review.
            $is_early_renewal = $order->get_meta( '_subscription_renewal_early' );
 
            if ( $is_early_renewal ) {
                $has_dm_flag = VISA_ACCEPTANCE_YES === $order->get_meta( '_visa_dm_review_early_renewal' );
                $has_dm_flag_reject = VISA_ACCEPTANCE_YES === $order->get_meta( '_visa_dm_reject_early_renewal' );
 
                if ( $has_dm_flag || $has_dm_flag_reject ) {
                    $result = false; // Tell WooCommerce this order doesn't need payment (even though it's pending).
                }
            }
        }
        return $result;
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

			$token = $this->get_order_meta( $subscription, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN );
			$token = $this->get_wc_token( $token, get_current_user_id() );
			if ( $token instanceof \WC_Payment_Token ) {
				$payment_method = new Visa_Acceptance_Payment_Methods( $this );
				$token_data     = $payment_method->build_token_data( $token );
				$last4          = isset( $token_data['last4'] ) ? $token_data['last4'] : ( isset( $token_data['last_four'] ) ? $token_data['last_four'] : VISA_ACCEPTANCE_STRING_EMPTY );
				$is_echeck      = ! empty( $token_data['is_echeck'] );
				if ( $is_echeck ) {
					/* translators: %1$s - payment method name, %2$s - last four digits of the account. */
					$payment_method_to_display = sprintf( __( '%1$s eCheck ending with %2$s', 'visa-acceptance-solutions' ), $payment_method_to_display, $last4 );
				} else {
					/* translators: %1$s - payment method name, %2$s - last four digits of the card. */
					$payment_method_to_display = sprintf( __( '%1$s card ending with %2$s', 'visa-acceptance-solutions' ), $payment_method_to_display, $last4 );
				}
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
				$html = wp_kses_post( VISA_ACCEPTANCE_NOT_APPLICABLE, 'visa-acceptance-solutions' );
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
			$stored_token_id = $this->get_order_meta( $subscription, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN );
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
		$contain_token = $this->has_subscription_token ( $order );
		$is_echeck     = $this->is_echeck_transaction( $order );
		if ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) ) {
			if ( wcs_order_contains_renewal( $order ) && $merchant_initiated ) {
				$payload['authorizationOptions']['initiator']['type'] = 'merchant';
				$payload['authorizationOptions']['initiator']['storedCredentialUsed'] = true;

				// merchantInitiatedTransaction is a card-only field, not supported for eCheck/bank transfer.
				if ( ! $is_echeck ) {
					$previous_txn_id = $this->get_previous_transaction_id( $order );
					$is_mastercard = $this->is_mastercard_transaction( $order );
					$is_discover = $this->is_discover_transaction( $order );

					$payload['authorizationOptions']['initiator']['merchantInitiatedTransaction'] = array();

					if ( $is_mastercard ) {
						$payload['authorizationOptions']['initiator']['merchantInitiatedTransaction']['reason'] = '7';
					}

					if ( ! empty( $previous_txn_id ) ) {
						$payload['authorizationOptions']['initiator']['merchantInitiatedTransaction']['previousTransactionId'] = $previous_txn_id;
					}

					// Discover requires original authorized amount.
					if ( $is_discover ) {
						$original_amount = $this->get_original_authorized_amount( $order );
						if ( ! empty( $original_amount ) ) {
							$payload['authorizationOptions']['initiator']['merchantInitiatedTransaction']['originalAuthorizedAmount'] = $original_amount;
						}
					}
				}
                $payload['commerceIndicator'] = VISA_ACCEPTANCE_RECURRING;
				if ( ! in_array( VISA_ACCEPTANCE_DECISION_SKIP, $payload['actionList'], true ) ) {
					array_push( $payload['actionList'], VISA_ACCEPTANCE_DECISION_SKIP );
				}
			} elseif ( wcs_order_contains_renewal( $order ) && ! $merchant_initiated ) {
				// Customer-initiated renewal (from My Account section).
                $is_mastercard = ! $is_echeck && $this->is_mastercard_transaction( $order );
                $is_discover = ! $is_echeck && $this->is_discover_transaction( $order );
                $previous_txn_id = $is_echeck ? null : $this->get_previous_transaction_id( $order );
                
                // Customer-initiated renewals use internet.
                $payload['commerceIndicator'] = VISA_ACCEPTANCE_INTERNET;
                
                // Add merchantInitiatedTransaction for Mastercard.
                if ( ! $is_echeck && ( $is_mastercard || $is_discover || ! empty( $previous_txn_id ) ) ) {
                    if ( ! isset( $payload['authorizationOptions']['initiator']['merchantInitiatedTransaction'] ) ) {
                        $payload['authorizationOptions']['initiator']['merchantInitiatedTransaction'] = array();
                    }
                    
                    if ( $is_mastercard ) {
                        $payload['authorizationOptions']['initiator']['merchantInitiatedTransaction']['reason'] = '7';
                    }
                    
                    if ( ! empty( $previous_txn_id ) ) {
                        $payload['authorizationOptions']['initiator']['merchantInitiatedTransaction']['previousTransactionId'] = $previous_txn_id;
                    }
                    
                    // Discover requires original authorized amount.
                    if ( $is_discover ) {
                        $original_amount = $this->get_original_authorized_amount( $order );
                        if ( ! empty( $original_amount ) ) {
                            $payload['authorizationOptions']['initiator']['merchantInitiatedTransaction']['originalAuthorizedAmount'] = $original_amount;
                        }
                    }
                }
			}
			// Fallback: for other renewal scenarios.
			if ( ( ! empty( $this->get_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ) ) || $contain_token ) && wcs_order_contains_renewal( $order ) && ! isset( $payload['commerceIndicator'] ) ) { 
			$payload['commerceIndicator'] = VISA_ACCEPTANCE_INTERNET;
		} elseif ( wcs_order_contains_subscription( $order ) && ! wcs_order_contains_renewal( $order ) ) {
			// Initial subscription with saved card.
			$is_mastercard = $this->is_mastercard_transaction( $order );
			$is_discover = $this->is_discover_transaction( $order );
			$payload['commerceIndicator'] = VISA_ACCEPTANCE_INTERNET;
            $payload['recurringOptions']['firstRecurringPayment'] = true;
			
			// Add merchantInitiatedTransaction for Mastercard on initial subscription with saved card.
            if ( $is_mastercard || $is_discover ) {
                // Ensure authorizationOptions structure exists.
                if ( ! isset( $payload['authorizationOptions'] ) ) {
                    $payload['authorizationOptions'] = array();
                }
                if ( ! isset( $payload['authorizationOptions']['initiator'] ) ) {
                    $payload['authorizationOptions']['initiator'] = array();
                }
                if ( ! isset( $payload['authorizationOptions']['initiator']['merchantInitiatedTransaction'] ) ) {
                    $payload['authorizationOptions']['initiator']['merchantInitiatedTransaction'] = array();
                }
                
                if ( $is_mastercard ) {
                    $payload['authorizationOptions']['initiator']['merchantInitiatedTransaction']['reason'] = '7';
                }
                
                // Discover requires original authorized amount for initial transactions.
                if ( $is_discover ) {
                    $original_amount = $this->get_original_authorized_amount( $order );
                    if ( ! empty( $original_amount ) ) {
                        $payload['authorizationOptions']['initiator']['merchantInitiatedTransaction']['originalAuthorizedAmount'] = $original_amount;
                    }
                }
            }
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
		$contain_token = $this->has_subscription_token ( $order );
		$is_echeck     = $this->is_echeck_transaction( $order );
		if ( ( ! empty( $this->get_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ) ) || $contain_token ) && wcs_order_contains_renewal( $order ) ) { 
			// Customer-initiated renewals (from checkout/My Account) use internet.
			$is_mastercard = ! $is_echeck && $this->is_mastercard_transaction( $order );
			$is_discover = ! $is_echeck && $this->is_discover_transaction( $order );
			$previous_txn_id = $is_echeck ? null : $this->get_previous_transaction_id( $order );
			
			$payload['commerceIndicator'] = VISA_ACCEPTANCE_INTERNET;
			
			// merchantInitiatedTransaction is a card-only field, not supported for eCheck/bank transfer.
			if ( ! $is_echeck && ( $is_mastercard || $is_discover || ! empty( $previous_txn_id ) ) ) {
				if ( ! isset( $payload['authorizationOptions'] ) ) {
					$payload['authorizationOptions'] = array();
				}
				if ( ! isset( $payload['authorizationOptions']['initiator'] ) ) {
					$payload['authorizationOptions']['initiator'] = array();
				}
				if ( ! isset( $payload['authorizationOptions']['initiator']['merchantInitiatedTransaction'] ) ) {
					$payload['authorizationOptions']['initiator']['merchantInitiatedTransaction'] = array();
				}
				
				if ( $is_mastercard ) {
					$payload['authorizationOptions']['initiator']['merchantInitiatedTransaction']['reason'] = '7';
				}
				
				if ( ! empty( $previous_txn_id ) ) {
					$payload['authorizationOptions']['initiator']['merchantInitiatedTransaction']['previousTransactionId'] = $previous_txn_id;
				}
				
				// Discover requires original authorized amount.
				if ( $is_discover ) {
					$original_amount = $this->get_original_authorized_amount( $order );
					if ( ! empty( $original_amount ) ) {
						$payload['authorizationOptions']['initiator']['merchantInitiatedTransaction']['originalAuthorizedAmount'] = $original_amount;
					}
				}
			}
		} elseif ( wcs_order_contains_subscription( $order ) && ! wcs_order_contains_renewal( $order ) ) {
			$is_mastercard = $this->is_mastercard_transaction( $order );
 
            if ( $is_mastercard ) {
            $payload['commerceIndicator'] = VISA_ACCEPTANCE_INTERNET;
            $payload['recurringOptions']   = array(
                'firstRecurringPayment' => true,
            );
 
            if ( ! isset( $payload['authorizationOptions'] ) ) {
                $payload['authorizationOptions'] = array();
            }
            if ( ! isset( $payload['authorizationOptions']['initiator'] ) ) {
                $payload['authorizationOptions']['initiator'] = array();
            }
 
            $payload['authorizationOptions']['initiator'] = array_merge(
                $payload['authorizationOptions']['initiator'],
                array(
                    'credentialStoredOnFile'       => true,
                    'type'                         => 'customer',
                    'merchantInitiatedTransaction' => array(
                        'reason' => '7',
                    ),
                )
            );
        } else {
            $payload['commerceIndicator']  = VISA_ACCEPTANCE_INTERNET;
            $payload['recurringOptions']   = array(
                'firstRecurringPayment' => true,
            );
			if ( ! isset( $payload['authorizationOptions'] ) ) {
                $payload['authorizationOptions'] = array();
            }
            if ( ! isset( $payload['authorizationOptions']['initiator'] ) ) {
                $payload['authorizationOptions']['initiator'] = array();
            }
 
            $payload['authorizationOptions']['initiator'] = array_merge(
                $payload['authorizationOptions']['initiator'],
                array(
                    'credentialStoredOnFile' => true,
                    'type'                   => 'customer',
                )
            );
        }
		}
		return $payload;
	}

	/**
     * Check if the transaction uses an eCheck (bank transfer) payment method.
     *
     * @param WC_Order $order The order object.
     * @return bool True if eCheck, false otherwise.
     */
    public function is_echeck_transaction( $order ) {
        $card_type = $this->get_order_meta( $order, 'card_type' );

        if ( is_array( $card_type ) ) {
            $card_type = reset( $card_type );
        }

        if ( ! empty( $card_type ) && VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $card_type ) {
            return true;
        }

        $token_id = $this->get_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN );

        if ( ! empty( $token_id ) ) {
            $user_id = $order->get_user_id();

            if ( $user_id ) {
                $token = $this->get_wc_token( $token_id, $user_id );

                if ( $token instanceof \WC_Payment_Token && VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $token->get_type() ) {
                    return true;
                }
            }
        }

        return false;
    }

	/**
	 * Check if the subscription token associated with renewal order.
	 *
	 * @param order $order The WooCommerce order object.
	 * @return bool True if subscription token exists, otherwise false.
	 */
	private function has_subscription_token ( $order ) {
		$token = false;
		if ( empty( $this->get_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ) ) && function_exists( 'wcs_get_subscriptions_for_renewal_order' ) && wcs_order_contains_early_renewal( $order ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
			foreach ( $subscriptions as $subscription ) {
				$subscription_token = $this->get_order_meta( $subscription, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN );
				if ( $subscription_token ) {
					$token = true;
				}
			}
		}
		return $token;
	}
	/**
     * Generic method to check card type for a transaction.
     *
     * @param WC_Order $order The order object.
     * @param array    $card_names Array of card type names to match (e.g., ['mastercard', 'master']).
     * @param string   $cybersource_code CyberSource card type code (e.g., '002' for Mastercard).
     * @return bool True if card type matches, false otherwise.
     */
    private function is_card_type( $order, $card_names, $cybersource_code ) {
        // Check card_type order meta.
        $card_type = $this->get_order_meta( $order, 'card_type' );
 
        if ( ! empty( $card_type ) ) {
            if ( is_array( $card_type ) ) {
                $card_type = reset( $card_type );
            }
 
            if ( is_string( $card_type ) ) {
                $card_type = strtolower( $card_type );
                if ( in_array( $card_type, $card_names, true ) ) {
                    return true;
                }
            }
        }
 
        // Check WooCommerce payment token.
        $token_id = $this->get_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN );
 
        if ( ! empty( $token_id ) ) {
            $user_id = $order->get_user_id();
 
            if ( $user_id ) {
                $token = $this->get_wc_token( $token_id, $user_id );
 
                if ( $token instanceof \WC_Payment_Token_CC ) {
                    $card_type = strtolower( $token->get_card_type() );
                    if ( in_array( $card_type, $card_names, true ) ) {
                        return true;
                    }
                }
            }
        }
 
        // Check JWT transient token.
        $jwt = $this->get_order_meta( $order, 'transient_token_jwt' );
 
        if ( ! empty( $jwt ) ) {
            $payment_method = new Visa_Acceptance_Payment_Methods( $this );
            $card_type_code = $payment_method->get_card_type_from_token( $jwt );
 
            if ( $card_type_code === $cybersource_code ) {
                return true;
            }
        }
 
        return false;
    }
 
    /**
     * Check if the transaction is using a Mastercard.
     *
     * @param WC_Order $order The order object.
     * @return bool True if Mastercard, false otherwise.
     */
    public function is_mastercard_transaction( $order ) {
        return $this->is_card_type( $order, array( 'mastercard', 'master' ), '002' );
    }
 
    /**
     * Check if the transaction is a Discover card.
     *
     * @param WC_Order $order The order object.
     * @return bool True if Discover, false otherwise.
     */
    public function is_discover_transaction( $order ) {
        return $this->is_card_type( $order, array( 'discover' ), '004' );
    }
 
    /**
     * Get the original authorized amount from the parent subscription order.
     *
     * @param WC_Order $order The renewal order.
     * @return string|null The original authorized amount or null if not found.
     */
    private function get_original_authorized_amount( $order ) {
        $original_amount = null;
       
        if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
            return $original_amount;
        }
       
        // Get the parent subscription(s) for this renewal order.
        $subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
       
        if ( empty( $subscriptions ) ) {
            return $original_amount;
        }
       
        // Get the first subscription.
        $subscription = reset( $subscriptions );
       
        if ( ! $subscription instanceof \WC_Subscription ) {
            return $original_amount;
        }
       
        // Get the parent order (initial signup order).
        $parent_order = $subscription->get_parent();
       
        if ( $parent_order && $parent_order instanceof \WC_Order ) {
            $original_amount = $parent_order->get_total();
        }
       
        return $original_amount;
    }
 
    /**
     * Get the previous transaction ID from the parent subscription's last successful payment.
     *
     * @param WC_Order $order The renewal order object.
     * @return string|null The previous transaction ID or null if not found.
     */
    private function get_previous_transaction_id( $order ) {
        $previous_transaction_id = null;
       
        if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
            return $previous_transaction_id;
        }
       
        // Get the parent subscription(s) for this renewal order.
        $subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
       
        if ( empty( $subscriptions ) ) {
            return $previous_transaction_id;
        }
       
        // Get the first subscription (usually only one).
        $subscription = reset( $subscriptions );
       
        if ( ! $subscription instanceof \WC_Subscription ) {
            return $previous_transaction_id;
        }
        $parent_order = $subscription->get_parent();
        if ( $parent_order && $parent_order instanceof \WC_Order ) {
            $parent_network_txn_id = $this->get_order_meta( $parent_order, VISA_ACCEPTANCE_NETWORK_TRANSACTION_ID );
            if ( ! empty( $parent_network_txn_id ) ) {
                $previous_transaction_id = $parent_network_txn_id;
            } else {
                // Fall back to CYBS transaction ID if network ID not stored.
                $parent_txn_id = $this->get_order_meta( $parent_order, VISA_ACCEPTANCE_TRANSACTION_ID );
                if ( ! empty( $parent_txn_id ) ) {
                    $previous_transaction_id = $parent_txn_id;
                }
            }
        }
       
        // If not found in parent, check the subscription's related renewal orders.
        if ( empty( $previous_transaction_id ) ) {
            $related_orders = $subscription->get_related_orders( 'all', array( 'renewal' ) );
           
            if ( ! empty( $related_orders ) ) {
                // Sort by date descending to get the most recent.
                usort( $related_orders, function( $a, $b ) {
                    $order_a = wc_get_order( $a );
                    $order_b = wc_get_order( $b );
                    if ( ! $order_a || ! $order_b ) {
                        return 0;
                    }
                    return $order_b->get_date_created()->getTimestamp() - $order_a->get_date_created()->getTimestamp();
                });
               
                // Find the most recent successful renewal order (excluding current order).
                foreach ( $related_orders as $related_order_id ) {
                    if ( $related_order_id === $order->get_id() ) {
                        continue; // Skip the current order.
                    }
                   
                    $related_order = wc_get_order( $related_order_id );
                    if ( ! $related_order ) {
                        continue;
                    }
                   
                    // Check if the order was successful (completed or processing).
                    if ( $related_order->has_status( array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING ) ) ) {
                        $network_txn_id = $this->get_order_meta( $related_order, VISA_ACCEPTANCE_NETWORK_TRANSACTION_ID );
                        if ( ! empty( $network_txn_id ) ) {
                            $previous_transaction_id = $network_txn_id;
                            break;
                        }
                        $related_txn_id = $this->get_order_meta( $related_order, VISA_ACCEPTANCE_TRANSACTION_ID );
                        if ( ! empty( $related_txn_id ) ) {
                            $previous_transaction_id = $related_txn_id;
                            break;
                        }
                    }
                }
            }
        }
       
        return $previous_transaction_id;
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
				VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN => array(
					'value' => $this->get_order_meta( $subscription, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ),
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
			if ( ! empty( $this->get_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ) ) ) {
				if ( ! empty( $this->get_order_meta( $subscription, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ) ) ) {
					$subscription->update_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN, $this->get_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ) );
				} elseif ( VISA_ACCEPTANCE_ZERO_AMOUNT === $order->get_total() ) {
						$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, VISA_ACCEPTANCE_STRING_EMPTY );
						$subscription->add_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN, $this->get_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ) );
				} else {
					$subscription->add_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN, $this->get_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ) );
				}
				$updated = true;
			}
			// Copy WooCommerce payment token ID from order to subscription to link subscription to payment method.
            $payment_token_id = $order->get_meta( '_payment_token_id', true );
            if ( ! empty( $payment_token_id ) ) {
                if ( ! empty( $subscription->get_meta( '_payment_token_id', true ) ) ) {
                    $subscription->update_meta_data( '_payment_token_id', $payment_token_id );
                } else {
                    $subscription->add_meta_data( '_payment_token_id', $payment_token_id );
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
		if ( ! empty( $this->get_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ) ) ) {
			$this->update_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN, $instrument_identifier );
		} else {
			$authorization_saved_card = new Visa_Acceptance_Authorization_Saved_Card( $this );
			$authorization_saved_card->add_order_meta( $order, VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN, $instrument_identifier );
			// Save order to commit meta data immediately.
        	$order->save();
		}
	}

	/**
	 * Change payment method.
	 *
	 * @param string $token_id token_id.
	 * @param object $order order details.
	 * @param array  $transient_token $transient_token.
	 * @param string $complete_response_raw EBC complete response JSON.
	 *
	 * @return array $response_array
	 */
	public function change_payment_method( $token_id, $order, $transient_token, $complete_response_raw = VISA_ACCEPTANCE_STRING_EMPTY ) {
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
                    // Detect if this is an eCheck token.
                    $is_echeck = false;
                    $decoded_transient_token = json_decode( base64_decode( explode( VISA_ACCEPTANCE_FULL_STOP, $transient_token )[VISA_ACCEPTANCE_VAL_ONE] ), true );// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
                    if ( isset( $decoded_transient_token['metadata']['paymentType'] ) && VISA_ACCEPTANCE_CHECK === $decoded_transient_token['metadata']['paymentType'] ) {
                        $is_echeck = true;
                    }
                    $complete_payload = ! empty( $complete_response_raw ) ? json_decode( $complete_response_raw, true ) : null;
                    if ( is_string( $complete_payload ) ) {
                        $complete_payload = json_decode( $complete_payload, true );
                    }
 
                    $token_info_from_complete = ( is_array( $complete_payload ) && isset( $complete_payload['details']['tokenInformation'] ) )
                        ? $complete_payload['details']['tokenInformation']
                        : null;
 
                    if ( ! empty( $token_info_from_complete ) ) {
                        $ebc_status = $is_echeck ? VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS : ( $complete_payload['status'] ?? VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED );
                        $synth_body = array_merge(
                            isset( $complete_payload['details'] ) ? $complete_payload['details'] : array(),
                            array(
                                'id'     => $complete_payload['id'] ?? VISA_ACCEPTANCE_STRING_EMPTY,
                                'status' => $ebc_status,
                            )
                        );
                        $response_for_save = array(
                            'body'      => wp_json_encode( $synth_body ),
                            'http_code' => VISA_ACCEPTANCE_TWO_ZERO_ONE,
                        );
                        $save_result = $this->save_payment_method( $response_for_save, $order, $is_echeck );
                        if ( is_array( $save_result ) && ! empty( $save_result['status'] ) && ! empty( $save_result['token'] ) ) {
                            $payment_method_updated = true;
                            $instrument_identifier  = $save_result['token'];
                        } elseif ( is_array( $save_result ) && ! empty( $save_result['message'] ) ) {
                            $error_message = $save_result['message'];
                        }
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

			$subscription->delete_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN );
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
		if ( empty( $meta['post_meta'][ VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ]['value'] ) ) {
			/* translators: %s - label of the required field. */
			throw new \Exception(
				sprintf(
					esc_html__( '%s is required.', 'visa-acceptance-solutions' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
					esc_html( $meta['post_meta'][ VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN ]['label'] )
				)
			);
		}
	}
}
