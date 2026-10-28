<?php
/**
 * WooCommerce Visa Acceptance Solutions
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@visa-acceptance-solutions.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade WooCommerce Visa Acceptance Solutions to newer
 * versions in the future.
 *
 * @package    Visa_Acceptance_Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Include all the necessary dependencies.
 */
require_once __DIR__ . '/../../class-visa-acceptance-request.php';

use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsCaptureMandate;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsCompleteMandate;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsData;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsDataOrderInformation;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsDataOrderInformationAmountDetails;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsDataOrderInformationBillTo;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsDataConsumerAuthenticationInformation;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsTransientTokenResponseOptions;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsDataPaymentInformation;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsDataPaymentInformationCard;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsPaymentConfigurations;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsPaymentConfigurationsTMSTOKEN;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsPaymentConfigurationsTMSTOKENPaymentInstruments;
use Pymt_Vas\Dependencies\CyberSource\Model\Upv1capturecontextsDataClientReferenceInformationPartner;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsDataClientReferenceInformation;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsDataDeviceInformation;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsPaymentConfigurationsTMSTOKENInstrumentIdentifiers;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsDataProcessingInformation;
use Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsDataProcessingInformationBankTransferOptions;

/**
 * Visa Acceptance Credit Card API Key Generation Request Class.
 *
 * Handles key generation requests.
 */
class Visa_Acceptance_Key_Generation_Request extends Visa_Acceptance_Request {

	/**
	 * The gateway object of this plugin.
	 *
	 * @var      object    $gateway    The current payment gateways object.
	 */
	public $gateway;

	/**
	 * Key_Generation_Request constructor.
	 *
	 * @param object $gateway Gateway Variable.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Gives admin orders checkout total amount
	 *
	 * @return array
	 */
	public function get_admin_checkout_total_amount() {
		$get_data             = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$is_admin_order_pay   = isset( $get_data['pay_for_order'], $get_data['key'] );
		$response             = array(
			'total_amount'            => VISA_ACCEPTANCE_ZERO_AMOUNT,
			'is_admin_order_pay_page' => $is_admin_order_pay,
		);
		$total_amount = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$order_id     = null;

		if ( $is_admin_order_pay ) {
			// Normal page request: order-pay query var is available.
			$order_id = null !== get_query_var( 'order-pay' ) ? get_query_var( 'order-pay' ) : get_query_var( get_option( 'woocommerce_checkout_pay_endpoint', 'order-pay' ) );
		} elseif ( isset( WC()->session ) ) {
			// AJAX context (admin-ajax.php): $_GET has no page URL params; fall back to session-stored order ID.
			$session_order_id = WC()->session->get( 'vas_order_pay_id' );
			if ( ! empty( $session_order_id ) ) {
				$order_id = absint( $session_order_id );
				$response['is_admin_order_pay_page'] = true;
			}
		}

		if ( ! empty( $order_id ) ) {
			$order        = wc_get_order( $order_id );
			$total_amount = ( $order instanceof \WC_Order ) ? $order->get_total() : VISA_ACCEPTANCE_ZERO_AMOUNT;
		}

		$response['total_amount'] = $total_amount;
		return $response;
	}

	/**
	 * Calculate the total amount for a product including subscription features, shipping, and taxes.
	 *
	 * @param int $product_id The ID of the product.
	 * @param int $quantity The quantity of the product.
	 * @param array $address The shipping address.
	 * @return array An associative array containing breakdown of costs and total amount.
	 */
	public function get_product_page_total_amount( $product_id, $quantity, $address = array() ) {
		$signup_fee  	= VISA_ACCEPTANCE_VAL_ZERO;
		$amount       	= VISA_ACCEPTANCE_VAL_ZERO;
		$tax_total		= VISA_ACCEPTANCE_VAL_ZERO;
		$skip_shipping_tax = false;
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return number_format( VISA_ACCEPTANCE_VAL_ZERO, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY );
		}
		if (null === $quantity) {
			$quantity = VISA_ACCEPTANCE_VAL_ONE;
		}
		// Base product price multiplied by quantity — also serves as the amount for simple products.
        $amount = $product ? ( $product->get_price() * $quantity ) : VISA_ACCEPTANCE_ZERO_AMOUNT;

		// Subscription features with synchronization/proration support.
		if ( class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
			$signup_fee   = (float) get_post_meta( $product->get_id(), '_subscription_sign_up_fee', true );
			$signup_fee_total = $signup_fee * $quantity;
			$trial_length = WC_Subscriptions_Product::get_trial_length( $product_id );

			if ( $trial_length > VISA_ACCEPTANCE_VAL_ZERO ) {
				$total = ($signup_fee_total > VISA_ACCEPTANCE_VAL_ZERO) ? $signup_fee_total : VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
				return number_format( (float) $total, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY );
			}
			$pricing_result = $this->process_subscription_sync_pricing( $product, $product_id, $quantity, $amount );
            $amount = $pricing_result['amount'];
            $skip_shipping_tax = $pricing_result['skip_shipping_tax'];
 
				
		}
		// Calculate shipping if shipping is enabled and not skipped.
		if ( wc_shipping_enabled() && !$skip_shipping_tax && !$product->is_virtual()) {
			$amount = $this->calculate_product_page_shipping($amount, $product_id, $quantity, $address);
		}
		
		// Calculate product tax if taxes are enabled and not skipped.
        if ( wc_tax_enabled() && $product->is_taxable() && !$skip_shipping_tax ) {
			$tax_total = $this->calculate_product_page_taxes($product, $address, $amount);
        }
		
		$total = $amount + round($tax_total, VISA_ACCEPTANCE_VAL_TWO);
		return number_format( (float) $total, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY );
	}

	/**
	 * Return next synchronised renewal DateTimeImmutable for a product.
	 * Adjust logic to match your store rule (first of month, specific weekday, etc).
	 *
	 * @param int $product_id The product ID.
	 * @return DateTimeImmutable
	 */
	public function get_next_synchronised_date_for_product( $product_id ) {
		// Use WooCommerce Subscriptions API to get the correct sync date.
		if ( method_exists('WC_Subscriptions_Product', 'get_first_renewal_payment_date') ) {
			$timestamp = WC_Subscriptions_Product::get_first_renewal_payment_date( $product_id, time() );
			$timestamp = is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp);
			$wc_timezone = new DateTimeZone( wc_timezone_string() );
			return (new DateTimeImmutable())->setTimestamp($timestamp)->setTimezone($wc_timezone);		
		}
	}

	/**
	 * Return approximate number of days in a billing period.
	 * Uses calendar-aware math for months/years.
	 *
	 * @param string $period 'day','week','month','year'.
	 * @param int $interval The billing interval.
	 * @param DateTimeImmutable|null $from_date The starting date for calculation (optional).
	 * @return int Number of days in the period.
	 */
	private function period_days( $period, $interval = VISA_ACCEPTANCE_VAL_ONE, $from_date = null ) {
		if ( ! $from_date ) {
			$wc_timezone = new DateTimeZone( wc_timezone_string() );
			$from_date = new DateTimeImmutable( 'now', $wc_timezone );
		}

		switch ( $period ) {
			case 'day':
				return max(VISA_ACCEPTANCE_VAL_ONE, $interval);
			case 'week':
				return 7 * max(VISA_ACCEPTANCE_VAL_ONE, $interval);
			case 'month':
				$end = $from_date->modify( '+' . intval($interval) . ' month' );
				$diff = $end->diff( $from_date );
				return (int) $diff->days;
			case 'year':
				$end = $from_date->modify( '+' . intval($interval) . ' year' );
				$diff = $end->diff( $from_date );
				return (int) $diff->days;
			default:
				return 30 * max(VISA_ACCEPTANCE_VAL_ONE, $interval);
		}
	}

	/**
	 * Calculate prorated amount for a subscription product until next sync date.
	 *
	 * @param float $unit_price Price per period (not including signup fee).
	 * @param int $quantity The quantity of the product.
	 * @param string $period The billing period ('day', 'week', 'month', 'year').
	 * @param int $interval The billing interval.
	 * @param DateTimeImmutable $next_sync The next synchronization date.
	 * @return float The prorated amount.
	 */
	public function calculate_prorated_amount( $unit_price, $quantity, $period, $interval, DateTimeImmutable $next_sync ) {
		$wc_timezone = new DateTimeZone( wc_timezone_string() );
		$current_time = new DateTimeImmutable( 'now', $wc_timezone );
		if ( $next_sync <= $current_time ) {
			return round( $unit_price * $quantity, VISA_ACCEPTANCE_VAL_TWO );
		}
        $diff = $current_time->diff( $next_sync );
        if ( ( (int) $diff->d || VISA_ACCEPTANCE_VAL_ZERO === $diff->d) && ( (int) $diff->h + (int) $diff->i + (int) $diff->s ) > VISA_ACCEPTANCE_VAL_ZERO ) {
            $diff_days = (int) $diff->days + VISA_ACCEPTANCE_VAL_ONE;
            if ( $diff_days > (int) $diff->days ) {
                $days_until_sync = $diff_days;
            }
        } else {
            $days_until_sync = (int) $diff->days;
        }
        if ( $days_until_sync < VISA_ACCEPTANCE_VAL_ZERO ) {
            $days_until_sync = abs( $days_until_sync );
        }
        
        $period_days = $this->period_days( $period, $interval, $current_time );
 
        if ( $period_days <= VISA_ACCEPTANCE_VAL_ZERO ) {
            return round( $unit_price * $quantity, VISA_ACCEPTANCE_VAL_TWO );
        }
 
        $prorate_factor = $days_until_sync / $period_days;
        $prorated = round( ($unit_price * $prorate_factor) * $quantity, VISA_ACCEPTANCE_VAL_TWO );
        if ( $prorated <= VISA_ACCEPTANCE_VAL_ZERO ) {
            $prorated = VISA_ACCEPTANCE_ZERO_AMOUNT;
        }
 
        return $prorated;
    }
 

	/**
	 * Get the shipping method from the last order of the current customer.
	 *
	 * @return string|null The shipping method ID and instance ID in the format 'method_id:instance_id', or null if not found.
	 */
	public function get_shipping_from_last_order() {
		// Get the current customer ID.
		$customer_id = get_current_user_id();
		$selected_shipping_method = null;
		// If customer is logged in, try to get their last order's shipping method.
		if ( $customer_id ) {
			$args = array(
				'customer_id' => $customer_id,
				'limit'       => VISA_ACCEPTANCE_VAL_ONE,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'status'      => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
			);
			$orders = wc_get_orders( $args );

			if ( ! empty( $orders ) ) {
				$last_order = $orders[VISA_ACCEPTANCE_VAL_ZERO];
				$shipping_items = $last_order->get_items( 'shipping' );
				if ( ! empty( $shipping_items ) ) {
					$shipping_item = reset( $shipping_items );
					$selected_shipping_method = $shipping_item->get_method_id() . ':' . $shipping_item->get_instance_id();
				}
			}
		}
		return $selected_shipping_method;
	}

	/**
	 * Calculate shipping cost for product page based on last order's shipping method or cheapest non-free rate.
	 *
	 * @param float $amount The current amount to which shipping will be added.
	 * @param int $product_id The product ID (or variation ID for variable products).
	 * @param int $quantity The quantity of the product.
	 * @param array $address The shipping address array.
	 * @return amount Updated amount including shipping cost.
	 */
	public function calculate_product_page_shipping($amount, $product_id = VISA_ACCEPTANCE_VAL_ZERO, $quantity = VISA_ACCEPTANCE_VAL_ONE, $address = array()) {
		$shipping_total = VISA_ACCEPTANCE_VAL_ZERO;

		$packages = WC()->shipping->calculate_shipping( WC()->cart->get_shipping_packages() );
		
		// For variable products or when cart is empty, build package manually.
		if ( empty( $packages ) && $product_id > VISA_ACCEPTANCE_VAL_ZERO ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$packages[] = array(
					'contents'        => array(
						'product_' . $product_id => array(
							'data'     => $product,
							'quantity' => $quantity,
						)
					),
					'contents_cost'   => $product->get_price() * $quantity,
					'applied_coupons' => array(),
					'destination'     => $this->build_shipping_destination( $address ),
 
				);
				$packages = WC()->shipping->calculate_shipping( $packages );
			}
		}
		$shipping_total = $this->calculate_shipping_cost_from_packages( $packages );
        $amount += $shipping_total;
		return $amount;
	}

	/**
	 * Calculate tax for product page based on provided address and product tax class.
	 *
	 * @param object $product The product object.
	 * @param array $address The address array containing country, state, postcode, city, etc.
	 * @param float $amount The current amount to which tax will be added.
	 * @return float tax_total Updated tax.
	 */
	public function calculate_product_page_taxes($product, $address, $amount){
		$tax_total    	= VISA_ACCEPTANCE_VAL_ZERO;
		$tax_rates = WC_Tax::find_rates( array(
			'country'  => $address['country'],
			'state'    => $address['state'],
			'city'     => $address['city'],
			'postcode' => $address['postcode'],
			'tax_class'=> $product->get_tax_class(),
		));
		$product_taxes = WC_Tax::calc_tax( $amount, $tax_rates, wc_prices_include_tax() );
		return $tax_total += array_sum( $product_taxes );
	}
	
	/**
	 * Calculate the total amount for grouped products including subscription features, shipping, and taxes.
	 * Supports combination of normal products and all types of subscription products.
	 *
	 * @param array $grouped_items Array of product IDs and quantities.
	 * @param array $address The shipping address.
	 * @return string Total amount formatted to 2 decimal places.
	 */
	public function get_grouped_product_total_amount( $grouped_items, $address = array() ) {
        $total_amount = VISA_ACCEPTANCE_VAL_ZERO;
        $tax_total = VISA_ACCEPTANCE_VAL_ZERO;
        $signup_fees_total = VISA_ACCEPTANCE_VAL_ZERO;
        $has_trial = false;
        $skip_shipping_tax = false;
        $shipping_products = array();
       
        // Calculate product amounts including subscription handling.
        foreach ( $grouped_items as $product_id => $quantity ) {
            $quantity = intval( $quantity );
            if ( $quantity <= VISA_ACCEPTANCE_VAL_ZERO ) {
                continue;
            }
           
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }
            if ( $product->needs_shipping() ) {
                $shipping_products[] = array(
                    'product' => $product,
                    'quantity' => $quantity,
                    'product_id' => $product_id,
                );
            }
            $is_subscription = class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product );
           
            if ( $is_subscription ) {
                $signup_fee   = (float) get_post_meta( $product->get_id(), '_subscription_sign_up_fee', true );
                $signup_fee_total = $signup_fee * $quantity;
                $trial_length = WC_Subscriptions_Product::get_trial_length( $product_id );
                if ( $trial_length > VISA_ACCEPTANCE_VAL_ZERO ) {
                    $total = ($signup_fee_total > VISA_ACCEPTANCE_VAL_ZERO) ? $signup_fee_total : VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
                    return number_format( (float) $total, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY );
                }
                $base_price = $product->get_price() * $quantity;
                $pricing_result = $this->process_subscription_sync_pricing( $product, $product_id, $quantity, $base_price );
                $total_amount += $pricing_result['amount'];
                $skip_shipping_tax = $pricing_result['skip_shipping_tax'];
            } else {
                $product_price = $product->get_price() * $quantity;
                $total_amount += $product_price;
            }
        }
        $total_amount += $signup_fees_total;
        if ( $has_trial && VISA_ACCEPTANCE_VAL_ZERO === $total_amount && VISA_ACCEPTANCE_VAL_ZERO === $signup_fees_total) {
            return number_format( (float) VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY );
        }
        if ( wc_shipping_enabled() && ! empty( $shipping_products ) && ! $skip_shipping_tax && !$product->is_virtual() ) {
            $total_amount = $this->calculate_grouped_shipping( $total_amount, $shipping_products, $address );
        }
        if ( wc_tax_enabled() && $product->is_taxable() && !$skip_shipping_tax ) {
            if ( $total_amount > VISA_ACCEPTANCE_VAL_ZERO ) {
                    $product_tax = $this->calculate_product_page_taxes( $product, $address, $total_amount );
                    $tax_total += $product_tax;
                }
        }
        $final_total = $total_amount + round( $tax_total, VISA_ACCEPTANCE_VAL_TWO );
        return number_format( (float) $final_total, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY );
    }
	
	/**
	 * Calculate shipping cost for grouped products.
	 *
	 * @param float $amount The current amount.
	 * @param array $shipping_products Array of products requiring shipping.
	 * @param array $address The shipping address.
	 * @return float Updated amount including shipping cost.
	 */
	private function calculate_grouped_shipping( $amount, $shipping_products, $address ) {
		$shipping_total = VISA_ACCEPTANCE_VAL_ZERO;
		
		// Build package for grouped products.
		$contents = array();
		$contents_cost = VISA_ACCEPTANCE_VAL_ZERO;
		
		foreach ( $shipping_products as $product_data ) {
			$product = $product_data['product'];
			$quantity = $product_data['quantity'];
			$product_id = $product_data['product_id'];
			
			$contents[ 'product_' . $product_id ] = array(
				'data' => $product,
				'quantity' => $quantity,
			);
			$contents_cost += $product->get_price() * $quantity;
		}
		
		$packages = array(
			array(
				'contents' => $contents,
				'contents_cost' => $contents_cost,
				'applied_coupons' => array(),
				'destination'     => $this->build_shipping_destination( $address ),
 
			)
		);
		
		$packages = WC()->shipping->calculate_shipping( $packages );
		$shipping_total = $this->calculate_shipping_cost_from_packages( $packages );
        $amount += $shipping_total;
 
		return $amount;
	}

	/**
	 * Builds request for Capture Context Generation in UC.
	 */
	public function get_uc_request() {
		$total_amount         = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$payment_method_array = array( VISA_ACCEPTANCE_PANENTRY );
		$uc_setting           = $this->get_uc_settings();
		// Use the new ISV Phase 1 method to get all card and other payment types.
        $payment_method_array = $this->get_card_and_other_payment_methods_array( $uc_setting );

		$total_amount = $this->get_cart_or_order_total( $total_amount );
		if ( $this->is_subscription_change_payment_request() ) {
			// New-card subscription payment method update — verify with $0.00, never the full order total.
			$total_amount = VISA_ACCEPTANCE_ZERO_AMOUNT;
		}
		$capture_context_payload = $this->get_capture_context_request( $payment_method_array, $total_amount );
		return $capture_context_payload;
	}

	/**
	 * Detects whether the current request is a subscription "change payment method" request.
	 *
	 * @return bool
	 */
	private function is_subscription_change_payment_request() {
		$payment_gateway_unified_checkout   = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
        $subscription_active                = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
		if ( is_add_payment_method_page() ) {
			return false;
		}
		if ( $subscription_active && class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) {
			return true;
		}
		// Fallback for AJAX: $is_request_to_change_payment is not set in AJAX context; detect via session order ID.
		if ( isset( WC()->session ) ) {
			$pay_order_id = (int) WC()->session->get( 'vas_order_pay_id', VISA_ACCEPTANCE_VAL_ZERO );
			if ( $pay_order_id > VISA_ACCEPTANCE_VAL_ZERO ) {
				$pay_order = wc_get_order( $pay_order_id );
				if ( $pay_order && 'shop_subscription' === $pay_order->get_type() ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Builds request for Digital Payment Capture Context Generation in UC.
	 *
	 * @param int        $product_id The product ID.
	 * @param int        $quantity The product quantity.
	 * @param array      $grouped_items Array of product IDs and quantities for grouped products.
	 * @param float|null $switch_amount Optional switch amount for subscription changes.
	 * @return array Capture context payload.
	 */
	public function get_digital_uc_request( $product_id, $quantity, $grouped_items = array(), $switch_amount = null ) {
		$total_amount         = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$payment_method_array = array();
		$uc_setting           = $this->get_uc_settings();
		// Check if Express Pay is enabled.
        if ( ! isset( $uc_setting['enable_express_pay'] ) || VISA_ACCEPTANCE_YES !== $uc_setting['enable_express_pay'] ) {
            // Return empty array if Express Pay is disabled.
            return array();
        }
		$payment_method_array = $this->get_digital_payment_methods_array( $uc_setting );
		$is_product_page_context = false;
		if ( null !== $switch_amount && $switch_amount > VISA_ACCEPTANCE_VAL_ZERO ) {
            $total_amount = (string) $switch_amount;
        }
		else {
			$checkout_total_amount = $this->get_admin_checkout_total_amount();
			if ( $checkout_total_amount['is_admin_order_pay_page'] ) {
				$total_amount = $checkout_total_amount['total_amount'];
			} 
			else if ( is_product() || $product_id ) {
				global $product;
				if ( null !== $product ) {
					$product_id = $product ? $product->get_id() : VISA_ACCEPTANCE_VAL_ZERO;
				}
				if ($product_id) {
					// Buy-Now/product-page flow: WC()->cart (if any) does not represent this purchase.
					$is_product_page_context = true;
					// Build address array using logged-in user's shipping info.
					$address = array(
					'country'   => WC()->customer->get_shipping_country(),
					'state'     => WC()->customer->get_shipping_state(),
					'postcode'  => WC()->customer->get_shipping_postcode(),
					'city'      => WC()->customer->get_shipping_city(),
					'address_1' => WC()->customer->get_shipping_address(),
					'address_2' => WC()->customer->get_shipping_address_2(),
					);
					
					if ( ! empty( $grouped_items ) ) {
						$total = $this->get_grouped_product_total_amount( $grouped_items, $address );
					} else {
						$total = $this->get_product_page_total_amount($product_id, $quantity, $address);
					}
					
					if ( null !== $product ) {
						$total_amount = $product ? $total : VISA_ACCEPTANCE_ZERO_AMOUNT;
					}
					else {
						$total_amount = $product_id ? $total : VISA_ACCEPTANCE_ZERO_AMOUNT;
					}
				}
			} else {
				$total_amount = isset( WC()->cart ) ? WC()->cart->get_totals()['total'] : $total_amount;
			}
		}
		WC()->session->set( "wc_{$this->gateway->id}_capture_context_total_amount", wc_clean( $total_amount ) );
		$capture_context_payload = $this->get_capture_context_request( $payment_method_array, $total_amount, $is_product_page_context );
		return $capture_context_payload;
	}

	/**
	 * Builds request for Zero Dollar Auth Capture Context Generation in UC.
	 *
	 * @return array $capture_context_payload payload.
	 */
	public function get_zero_uc_request() {
		$uc_setting           = $this->get_uc_settings();
		// Use the new ISV Phase 1 method to get all card and other payment types.
        $payment_method_array = $this->get_card_and_other_payment_methods_array( $uc_setting );
		$express_pay_enabled = isset( $uc_setting['enable_express_pay'] ) ? $uc_setting['enable_express_pay'] : VISA_ACCEPTANCE_YES;
        if ( (VISA_ACCEPTANCE_NO === $express_pay_enabled) && ! is_add_payment_method_page() ) {
            $digital_methods      = $this->get_digital_payment_methods_array( $uc_setting );
            $payment_method_array = array_merge( $payment_method_array, $digital_methods );
        }
		$total_amount            = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$capture_context_payload = $this->get_capture_context_request( $payment_method_array, $total_amount );
		return $capture_context_payload;
	}

	/**
	 * Builds request for Zero Dollar Auth Capture Context Generation in UC for Digital Payments.
	 *
	 * @return array $capture_context_payload payload.
	 */
	public function get_digital_zero_uc_request() {
		$payment_method_array = array();
		$total_amount  = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$total_amount         = WC()->cart->get_totals()['total'];
		$payment_gateway_unified_checkout   = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
        $subscription_active                = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
		if ( ! is_add_payment_method_page() && ( $subscription_active && (WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ||
		( VISA_ACCEPTANCE_ZERO_AMOUNT === $total_amount && WC_Subscriptions_Cart::cart_contains_subscription() ) ) ) ) {
			$uc_setting           = $this->get_uc_settings();
			// Check if Express Pay is enabled.
            if ( isset( $uc_setting['enable_express_pay'] ) && VISA_ACCEPTANCE_YES === $uc_setting['enable_express_pay'] ) {
                $payment_method_array = $this->get_digital_payment_methods_array( $uc_setting );
            }
		}
		$total_amount            = VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
		$capture_context_payload = $this->get_capture_context_request( $payment_method_array, $total_amount );
		return $capture_context_payload;
	}

	/**
     * Get an array of payment method constants for card and other payment types, including APMs and wallets, based on UC settings.
     *
     * @param array $uc_setting UC settings array.
     * @return array Array of payment method constants.
     */
    private function get_card_and_other_payment_methods_array( $uc_setting ) {
        // Base payment methods - always included.
        $payment_method_array = array( VISA_ACCEPTANCE_PANENTRY );
        $order_pay_total = $this->get_admin_checkout_total_amount();
        if ( $order_pay_total['is_admin_order_pay_page'] ) {
            $cart_total = $order_pay_total['total_amount'];
        } else {
            $cart_total = isset( WC()->cart ) ? WC()->cart->get_totals()['total'] : VISA_ACCEPTANCE_ZERO_AMOUNT;
        }
       $is_free_trial = ! is_add_payment_method_page() && ( $this->is_subscription_change_payment_request() || ( (float) VISA_ACCEPTANCE_ZERO_AMOUNT === (float) $cart_total ) );
		
		if(! is_add_payment_method_page()) {
			// $payment_method_array[] = VISA_ACCEPTANCE_CHECK;
			$payment_method_array[] = 
			VISA_ACCEPTANCE_CLICKTOPAY;
		}

		if ( ! $is_free_trial && ! is_add_payment_method_page() ) {
            $payment_method_array[] = VISA_ACCEPTANCE_CHECK;
        }
		/*$apm_methods = array(
			VISA_ACCEPTANCE_CLICKTOPAY,
            VISA_ACCEPTANCE_IDEAL,
            VISA_ACCEPTANCE_MULTIBANCO,
            VISA_ACCEPTANCE_PRZELEWY24,
            VISA_ACCEPTANCE_MYBANK,
            VISA_ACCEPTANCE_DRAGONPAY,
            VISA_ACCEPTANCE_BANCONTACT,
            VISA_ACCEPTANCE_TINKPAYBYBANK,
            VISA_ACCEPTANCE_KONBINI
        );*/
		
 
        if(! is_add_payment_method_page()) {
                //$payment_method_array = array_merge( $payment_method_array, $apm_methods );
                $payment_method_array = array_merge( $payment_method_array );
        }
 
        // If Express Pay is disabled, include wallet payment methods in the regular payment option.
        $enable_express_pay = isset( $uc_setting['enable_express_pay'] ) && VISA_ACCEPTANCE_YES === $uc_setting['enable_express_pay'];
        if ( ! $enable_express_pay && ! is_add_payment_method_page() ) {
            $wallet_methods = array(
                VISA_ACCEPTANCE_APPLEPAY,
                VISA_ACCEPTANCE_GOOGLEPAY,
                VISA_ACCEPTANCE_PAZE,
                VISA_ACCEPTANCE_PAYPAL,
                VISA_ACCEPTANCE_VENMO,
            );
            $payment_method_array = array_merge( $payment_method_array, $wallet_methods );
        }
        return $payment_method_array;
    }

	/**
	 * Builds request for TMS Token Capture Context Generation in UC.
	 *
	 * @param string $payment_instrument_id The CyberSource payment instrument ID of the saved card.
	 * @param bool     $is_echeck Whether the payment instrument is an eCheck/ACH type.
	 * @return array Capture context payload array.
	 */
	public function get_tms_token_uc_request( $payment_instrument_id, $is_echeck = false ) {
		$base_url     = $this->get_base_url();
		$total_amount = VISA_ACCEPTANCE_ZERO_AMOUNT;

		// Free trial ($0 total) uses a real $0.00 authorization (PREFER_AUTH supports zero amount).
		$total_amount = $this->get_cart_or_order_total( $total_amount );
		if ( VISA_ACCEPTANCE_VAL_ZERO > (float) $total_amount ) {
			$total_amount = VISA_ACCEPTANCE_ZERO_AMOUNT;
		}
		if ( $this->is_subscription_change_payment_request() ) {
			// Saved-card subscription payment method update — verify with $0.00, never the full order total.
			$total_amount = VISA_ACCEPTANCE_ZERO_AMOUNT;
		}
		$amount_details = new Ucv1sessionsDataOrderInformationAmountDetails( array(
			'totalAmount' => number_format( (float) $total_amount, 2, '.', '' ),
			'currency'    => get_woocommerce_currency(),
		) );

		$order_info_data = array( 'amountDetails' => $amount_details );

		$bill_to = $this->build_customer_bill_to();
		if ( $bill_to ) {
			// Create truncated copy for capture context API with smart overflow handling.
			$bill_to_array = (array) \Pymt_Vas\Dependencies\CyberSource\ObjectSerializer::sanitizeForSerialization( $bill_to );
			$truncated_bill_to = $this->apply_smart_address_truncation( $bill_to_array );
			$order_info_data['billTo'] = new Ucv1sessionsDataOrderInformationBillTo( $truncated_bill_to );
		}
		$ship_to = $this->build_customer_ship_to();
		if ( $ship_to ) {
			// Create truncated copy for capture context API with smart overflow handling.
			$ship_to_array = (array) \Pymt_Vas\Dependencies\CyberSource\ObjectSerializer::sanitizeForSerialization( $ship_to );
			$truncated_ship_to = $this->apply_smart_address_truncation( $ship_to_array );
			$order_info_data['shipTo'] = new \Pymt_Vas\Dependencies\CyberSource\Model\Upv1capturecontextsDataOrderInformationShipTo( $truncated_ship_to );
		}

		$line_items_result = $this->build_cart_line_items();
		if ( ! empty( $line_items_result['items'] ) ) {
			$order_info_data['lineItems'] = $line_items_result['items'];
		}
		if ( ! empty( $line_items_result['discountAmount'] ) ) {
            $amount_details->setDiscountAmount( $line_items_result['discountAmount'] );
        }

		$order_info  = new Ucv1sessionsDataOrderInformation( $order_info_data );
		$client_ref = $this->build_client_reference_info();

		$data_args = array(
            'orderInformation'           => $order_info,
            'clientReferenceInformation' => $client_ref,
            'deviceInformation'          => $this->build_device_info(),
        );
 
        if ( $is_echeck ) {
            // eCheck/ACH TMS: use instrumentIdentifiers and add bankTransferOptions.
            $instrument_identifier_obj = new Ucv1sessionsPaymentConfigurationsTMSTOKENInstrumentIdentifiers(
                array( 'id' => $payment_instrument_id )
            );
            $tms_token_config = new Ucv1sessionsPaymentConfigurationsTMSTOKEN(
                array( 'instrumentIdentifiers' => array( $instrument_identifier_obj ) )
            );
            $bank_transfer_options = new Ucv1sessionsDataProcessingInformationBankTransferOptions(
                array( 'secCode' => 'WEB' )
            );
            $data_args['processingInformation'] = new Ucv1sessionsDataProcessingInformation(
                array( 'bankTransferOptions' => $bank_transfer_options )
            );
        } else {
            // Credit card TMS: use paymentInstruments and include card typeSelectionIndicator.
            $pay_info_card = new Ucv1sessionsDataPaymentInformationCard( array( 'typeSelectionIndicator' => '1' ) );
            $data_args['paymentInformation'] = new Ucv1sessionsDataPaymentInformation( array( 'card' => $pay_info_card ) );
            $payment_instrument_obj = new Ucv1sessionsPaymentConfigurationsTMSTOKENPaymentInstruments(
                array( 'id' => $payment_instrument_id )
            );
            $tms_token_config = new Ucv1sessionsPaymentConfigurationsTMSTOKEN(
                array( 'paymentInstruments' => array( $payment_instrument_obj ) )
            );
        }

        $data_object = new Ucv1sessionsData( $data_args );
        $pay_configs = new Ucv1sessionsPaymentConfigurations(
            array( 'tMSTOKEN' => $tms_token_config )
        );
 
        // Transient token response options.
        $transient_token_response_options = new Ucv1sessionsTransientTokenResponseOptions(
            array( 'includeCardPrefix' => false )
        );

		// Format locale to UC v1 standard.
		$formatted_locale = str_replace( VISA_ACCEPTANCE_HYPHEN, VISA_ACCEPTANCE_UNDERSCORE, get_locale() );
		
		// ISV Guide Section 4.5: suppress billing/contact collection for saved-card flow.
		$capture_mandate = new Ucv1sessionsCaptureMandate( array(
			'billingType'              => 'NONE',
			'requestEmail'             => false,
			'requestPhone'             => false,
			'requestShipping'          => false,
			'showAcceptedNetworkIcons' => false,
		) );
        $complete_mandate = new Ucv1sessionsCompleteMandate();
        if ( 0.0 === (float) $total_amount || $this->is_subscription_change_payment_request() ) {
            $complete_mandate->setType( VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_PREFER_AUTH );
        } elseif ( ! is_add_payment_method_page() && isset( WC()->cart ) ) {
            $uc_setting = $this->get_uc_settings();
            if ( isset( $uc_setting['charge_virtual_orders'] ) && VISA_ACCEPTANCE_YES === $uc_setting['charge_virtual_orders'] ) {
                $cart_is_virtual = true;
                foreach ( WC()->cart->get_cart() as $cart_item ) {
                    $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
                    if ( ! $product || ! $product->is_virtual() ) {
                        $cart_is_virtual = false;
                        break;
                    }
                }
                if ( $cart_is_virtual ) {
                    $complete_mandate->setType( VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_CAPTURE );
                }
            }
        }
		// Step A-2 (TMS saved card): uses its own key so a concurrent CC SCA retry flag is never consumed.
		$use3ds_tms = isset( WC()->session ) ? WC()->session->get( VISA_ACCEPTANCE_COOKIE_USE_3DS_TMS_CHALLENGE, VISA_ACCEPTANCE_STRING_EMPTY ) : VISA_ACCEPTANCE_STRING_EMPTY;
		if ( '1' === $use3ds_tms ) {
			$consumer_auth_info = new Ucv1sessionsDataConsumerAuthenticationInformation(
				array( 'challengeCode' => VISA_ACCEPTANCE_SCA_CHALLANGE_CODE )
			);
			$data_object->setConsumerAuthenticationInformation( $consumer_auth_info );
			WC()->session->set( VISA_ACCEPTANCE_COOKIE_USE_3DS_TMS_CHALLENGE, '2' );
		} elseif ( VISA_ACCEPTANCE_STRING_EMPTY !== $use3ds_tms ) {
			WC()->session->set( VISA_ACCEPTANCE_COOKIE_USE_3DS_TMS_CHALLENGE, VISA_ACCEPTANCE_STRING_EMPTY );
		}

		// Add clientVersion only if specified - null means API will use latest UC v1 version.
        $client_version = $this->resolveClientVersion();
        $resolved_client_version = null;
        if ( null !== $client_version ) {
            // Validate format only - API validates version support.
            if ( $this->validate_uc_client_version( $client_version ) ) {
                $resolved_client_version = $client_version;
            }
            // If validation fails, omit clientVersion to let API use latest.
        }

		return array(
			'targetOrigins'                 => array( $base_url ),
			'country'                       => WC()->countries->get_base_country(),
			'locale'                        => $formatted_locale,
			'allowedPaymentTypes'           => array( 'TMS_TOKEN' ),
			'captureMandate'                => $capture_mandate,
			'completeMandate'               => $complete_mandate,
			'paymentConfigurations'         => $pay_configs,
			'data'                          => $data_object,
			'transientTokenResponseOptions' => $transient_token_response_options,
			'clientVersion'                 => $resolved_client_version,
			
		);
	}

	/**
	 * Generates capture context request.
	 *
	 * @param array $payment_method_array     payment method array.
	 * @param mixed $total_amount             total amount.
	 * @param bool  $is_product_page_context  whether it's a product page context.
	 * @return array $payload
	 */
	public function get_capture_context_request( $payment_method_array, $total_amount, $is_product_page_context = false ) {
		$base_url       = $this->get_base_url();
		$uc_setting     = $this->get_uc_settings();
		$payment_gateway_unified_checkout   = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
        $subscription_active                = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();

		// Build orderInformation for data object (UC v1 requirement).
		$order_information_data = array(
			'amountDetails' => array(
				'totalAmount' => number_format( (float) $total_amount, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
				'currency'    => get_woocommerce_currency(),
				'taxAmount'   => '0.00', // Will be calculated from line items for PayPal.
			)
		);
		
		// Add billing information with first name, last name, and email.
		$is_express_pay_data = ! in_array( VISA_ACCEPTANCE_PANENTRY, $payment_method_array, true );
        if ( ! $is_express_pay_data ) {
            $bill_to = $this->build_customer_bill_to();
            if ( $bill_to ) {
				// Create truncated copy for capture context API with smart overflow handling.
				$bill_to_array = (array) \Pymt_Vas\Dependencies\CyberSource\ObjectSerializer::sanitizeForSerialization( $bill_to );
				$truncated_bill_to = $this->apply_smart_address_truncation( $bill_to_array );
				$order_information_data['billTo'] = new Ucv1sessionsDataOrderInformationBillTo( $truncated_bill_to );
			}
        } elseif ( $is_express_pay_data && is_user_logged_in() ) {
            $bill_to_data = array();
            $customer = WC()->customer;
            if ( $customer ) {
                $email = $customer->get_billing_email();
                if ( empty( $email ) ) {
                    $email = $customer->get_email();
                }
                if ( ! empty( $email ) ) {
                    $bill_to_data['email'] = $email;
                }
            }
            if ( ! empty( $bill_to_data ) ) {
                $bill_to = new Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsDataOrderInformationBillTo( $bill_to_data );
                $order_information_data['billTo'] = $bill_to;
            }
        }
		if ( ! $is_express_pay_data && ! is_add_payment_method_page() ) {
			$ship_to = $this->build_customer_ship_to();
			if ( $ship_to ) {
				// Create truncated copy for capture context API with smart overflow handling.
				$ship_to_array = (array) \Pymt_Vas\Dependencies\CyberSource\ObjectSerializer::sanitizeForSerialization( $ship_to );
				$truncated_ship_to = $this->apply_smart_address_truncation( $ship_to_array );
				$order_information_data['shipTo'] = new \Pymt_Vas\Dependencies\CyberSource\Model\Upv1capturecontextsDataOrderInformationShipTo( $truncated_ship_to );
			}
		}

		$line_items_result = $this->build_cart_line_items();
		if ( ! empty( $line_items_result['items'] ) ) {
			$order_information_data['lineItems']              = $line_items_result['items'];
			$order_information_data['amountDetails']['taxAmount'] = $line_items_result['taxAmount'];
		}
		if ( ! empty( $line_items_result['discountAmount'] ) ) {
            $order_information_data['amountDetails']['discountAmount'] = $line_items_result['discountAmount'];
        }

		// Build data object for UC v1 request.
		$data_object = new Ucv1sessionsData();
		$order_information = new Ucv1sessionsDataOrderInformation($order_information_data);
		$data_object->setOrderInformation($order_information);
        $data_object->setClientReferenceInformation( $this->build_client_reference_info() );
		$data_object->setDeviceInformation( $this->build_device_info() );

		if ( in_array( VISA_ACCEPTANCE_CHECK, $payment_method_array, true ) ) {
			// Required by UC so the widget offers the "save this account" consent for eCheck/ACH entries.
			$bank_transfer_options = new Ucv1sessionsDataProcessingInformationBankTransferOptions(
				array( 'secCode' => 'WEB' )
			);
			$data_object->setProcessingInformation(
				new Ucv1sessionsDataProcessingInformation( array( 'bankTransferOptions' => $bank_transfer_options ) )
			);
		}

		$transient_token_response_options = new Ucv1sessionsTransientTokenResponseOptions(
			array(
				'includeCardPrefix' => false,
			)
		);

		$capture_mandate = new Ucv1sessionsCaptureMandate();

		// 3. Capture Context – Required Overrides.
        // captureMandate.billingType: PARTIAL for card payments, FULL for digital payment methods and APMs.
        $is_card_only = in_array( VISA_ACCEPTANCE_PANENTRY, $payment_method_array, true ) && ! $is_express_pay_data;
        $billing_type = $is_card_only ? ( ! is_add_payment_method_page() ? VISA_ACCEPTANCE_UC_BILLING_TYPE_PARTIAL : VISA_ACCEPTANCE_UC_BILLING_TYPE ) : VISA_ACCEPTANCE_UC_BILLING_TYPE_FULL;
        $capture_mandate->setBillingType( $billing_type );
       
        // captureMandate.requestEmail: false for card payments, true for digital payment methods and APMs.
        $capture_mandate->setRequestEmail( ! $is_card_only );
       
        // captureMandate.requestPhone: false for card payments, true for digital payment methods and APMs.
        $capture_mandate->setRequestPhone( ! $is_card_only );
       
        // captureMandate.requestShipping: false for cards, true for APMs and DPMs where applicable.
        $capture_mandate->setRequestShipping( ! $is_card_only );
        $capture_mandate->setShowAcceptedNetworkIcons( ! $is_express_pay_data );
		$is_subscription_cart = false;
		$is_change_payment    = false;
		if ( ! is_add_payment_method_page() && isset( WC()->cart ) ) {
			$payment_gateway_unified_checkout_sub = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
			if ( $payment_gateway_unified_checkout_sub->is_wc_subscriptions_activated() ) {
				$is_subscription_cart = WC_Subscriptions_Cart::cart_contains_subscription()
				|| wcs_cart_contains_renewal();
			}
			// Check if this is a change payment method request for a subscription.
			$is_change_payment = false;
			if ( $subscription_active && class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
				$is_change_payment = WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
			}
			// Fallback for AJAX: $is_request_to_change_payment is not set in AJAX context; detect via session order ID.
			if ( ! $is_change_payment && isset( WC()->session ) ) {
				$pay_order_id = (int) WC()->session->get( 'vas_order_pay_id', VISA_ACCEPTANCE_VAL_ZERO );
				if ( $pay_order_id > VISA_ACCEPTANCE_VAL_ZERO ) {
					$pay_order = wc_get_order( $pay_order_id );
					if ( $pay_order && 'shop_subscription' === $pay_order->get_type() ) {
						$is_change_payment = true;
					}
				}
			}
		}
		$tokenization_enabled = isset( $uc_setting['tokenization'] ) && VISA_ACCEPTANCE_YES === $uc_setting['tokenization'];
		$complete_mandate = new Ucv1sessionsCompleteMandate();
		if ( is_add_payment_method_page() ) {
            $complete_mandate->setType( 'PREFER_AUTH' );
        } elseif ( 0.0 === (float) $total_amount ) {
            // Must be outside is_checkout() — capture context is fetched via AJAX where is_checkout() = false.
            $complete_mandate->setType( 'PREFER_AUTH' );
        } elseif ( $is_change_payment ) {
            // Subscription payment method update — verify only, never charge the full subscription amount.
            $complete_mandate->setType( 'PREFER_AUTH' );
        } elseif ( ! $is_product_page_context && isset( WC()->cart ) ) {
            // Check if charge_virtual_orders is enabled and cart is virtual.
            if ( isset( $uc_setting['charge_virtual_orders'] ) && VISA_ACCEPTANCE_YES === $uc_setting['charge_virtual_orders'] ) {
                $cart_is_virtual = true;
                foreach ( WC()->cart->get_cart() as $cart_item ) {
                    $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
                    if ( ! $product || ! $product->is_virtual() ) {
                        $cart_is_virtual = false;
                        break;
                    }
                }
                if ( $cart_is_virtual ) {
                    $complete_mandate->setType( VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_CAPTURE );
                }
            }
        }

		// Configure TMS for both checkout and add payment method flows.
		$tms = new \Pymt_Vas\Dependencies\CyberSource\Model\Ucv1sessionsCompleteMandateTms();
		$tokenization_enabled = isset( $uc_setting['tokenization'] ) && VISA_ACCEPTANCE_YES === $uc_setting['tokenization'];
		$should_add_complete_mandate = ! $is_express_pay_data || ( $is_express_pay_data && ( $is_subscription_cart || $is_change_payment ) );
		if ( $should_add_complete_mandate ) {
			if ( is_add_payment_method_page() ) {
			// Add payment method page: always enable TMS token creation.
			$token_types = array( 'customer', 'paymentInstrument', 'instrumentIdentifier' );
			$tms->setTokenTypes( $token_types );
			$tms->setTokenCreate( true );
			$complete_mandate->setTms( $tms );
			$complete_mandate->setDecisionManager( false );
			$complete_mandate->setConsumerAuthentication( 'NONE' );
			} elseif ( $tokenization_enabled ) {
				// Checkout flow with tokenization enabled.
				$token_types = array( 'customer', 'paymentInstrument', 'instrumentIdentifier' );
				$tms->setTokenTypes( $token_types );
				if ( $is_subscription_cart || $is_change_payment ) 
				{
					$tms->setTokenCreate( true );
				}
				$complete_mandate->setTms( $tms );
			} else {
				// Checkout flow with tokenization disabled.
				$tms->setTokenTypes( array( 'customer', 'paymentInstrument', 'instrumentIdentifier' ) );
				$complete_mandate->setTms( $tms );
			}
		}
		
		if ( $is_subscription_cart || is_add_payment_method_page() || $is_change_payment ) {
            $capture_mandate->setRequestSaveCredentials( false );
        }
		// PayPal/Venmo cannot be used for subscription signup, renewal, or change-payment-method flows because they do not support CyberSource TMS tokenization required for recurring billing.
        $omit_wallets_for_subscriptions = false;
 
        if ( class_exists( 'WC_Subscriptions' ) ) {
 
            // Subscription product in cart.
            $has_subscription = isset( WC()->cart ) && (
                WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal()
            );
 
            // Change payment method request.
            $is_subscription_change_payment = $is_change_payment;
            $omit_wallets_for_subscriptions =
                $has_subscription || $is_subscription_change_payment;
        }
 
        if ( $omit_wallets_for_subscriptions ) {
            $payment_method_array = array_values(
                array_diff(
                    $payment_method_array,
                    array(
                        VISA_ACCEPTANCE_PAYPAL,
                        VISA_ACCEPTANCE_VENMO,
                    )
                )
            );
        }
		$wp_locale = get_locale();
		$formatted_locale = str_replace(VISA_ACCEPTANCE_HYPHEN, VISA_ACCEPTANCE_UNDERSCORE, $wp_locale);
		
		$payload = array(
			'targetOrigins'                 => array( $base_url ),
			'allowedPaymentTypes'           => $payment_method_array,
			'country'                       => WC()->countries->get_base_country(),
			'locale'                        => $formatted_locale,
			'captureMandate'                => $capture_mandate,
			'completeMandate'               => $complete_mandate,
			'data'                          => $data_object,
			'transientTokenResponseOptions' => $transient_token_response_options,
		);
		// appearance.buttonType set to ADD_CARD only when adding a card outside of a payment flow.
        if ( is_add_payment_method_page() ) {
            $payload['buttonType'] = VISA_ACCEPTANCE_ADD_CARD;
        }
		// Add clientVersion only if specified - null means API will use latest UC v1 version.
        $client_version = $this->resolveClientVersion();
        if ( null !== $client_version ) {
            // Validate format only - API validates version support.
            if ( $this->validate_uc_client_version( $client_version ) ) {
                $payload['clientVersion'] = $client_version;
            }
            // If validation fails, omit clientVersion to let API use latest.
        }
		// Step A-2 (CC): inject challengeCode:04 for card-entry SCA retry.
		if ( ! is_add_payment_method_page() && in_array( VISA_ACCEPTANCE_PANENTRY, $payment_method_array, true ) ) {
			$use3ds_challenge = isset( WC()->session ) ? WC()->session->get( VISA_ACCEPTANCE_COOKIE_USE_3DS_CHALLENGE, VISA_ACCEPTANCE_STRING_EMPTY ) : VISA_ACCEPTANCE_STRING_EMPTY;
			if ( '1' === $use3ds_challenge ) {
				// Inject challengeCode to force a 3DS challenge on this retry attempt.
				$consumer_auth_info = new Ucv1sessionsDataConsumerAuthenticationInformation(
					array( 'challengeCode' => VISA_ACCEPTANCE_SCA_CHALLANGE_CODE )
				);
				$data_object->setConsumerAuthenticationInformation( $consumer_auth_info );
				$payload['data'] = $data_object;
				// Advance session to '2' so that a second failure falls through to a normal error.
				WC()->session->set( VISA_ACCEPTANCE_COOKIE_USE_3DS_CHALLENGE, '2' );
			} elseif ( VISA_ACCEPTANCE_STRING_EMPTY !== $use3ds_challenge ) {
				// Value is stale (e.g. '2' from a previous session) — clear it.
				WC()->session->set( VISA_ACCEPTANCE_COOKIE_USE_3DS_CHALLENGE, VISA_ACCEPTANCE_STRING_EMPTY );
			}
		}

		// Step A-2 (Express Pay): same state machine as CC above, but uses a separate session.
		if ( ! is_add_payment_method_page() && $is_express_pay_data ) {
			$use3ds_ep = isset( WC()->session ) ? WC()->session->get( VISA_ACCEPTANCE_COOKIE_USE_3DS_EP_CHALLENGE, VISA_ACCEPTANCE_STRING_EMPTY ) : VISA_ACCEPTANCE_STRING_EMPTY;
			if ( '1' === $use3ds_ep ) {
				$consumer_auth_info_ep = new Ucv1sessionsDataConsumerAuthenticationInformation(
					array( 'challengeCode' => VISA_ACCEPTANCE_SCA_CHALLANGE_CODE )
				);
				$data_object->setConsumerAuthenticationInformation( $consumer_auth_info_ep );
				$payload['data'] = $data_object;
				WC()->session->set( VISA_ACCEPTANCE_COOKIE_USE_3DS_EP_CHALLENGE, '2' );
			} elseif ( VISA_ACCEPTANCE_STRING_EMPTY !== $use3ds_ep ) {
				WC()->session->set( VISA_ACCEPTANCE_COOKIE_USE_3DS_EP_CHALLENGE, VISA_ACCEPTANCE_STRING_EMPTY );
			}
                }
 
		return $payload;
	}

	/**
     * Helper method to get UC settings.
     *
     * @return array UC settings array.
     */
    private function get_uc_settings() {
        $payment_method = VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->id . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS;
        return get_option( $payment_method, array() );
    }

	/**
	 * Returns the configured Unified Checkout clientVersion or null.
	 *
	 * @return string|null Client version string (e.g., '1.5.0') or null to use latest.
	 */
	private function resolveClientVersion() {
		$uc_setting = $this->get_uc_settings();
		$version = isset( $uc_setting['uc_client_version'] ) ? trim( $uc_setting['uc_client_version'] ) : VISA_ACCEPTANCE_STRING_EMPTY;
		return VISA_ACCEPTANCE_STRING_EMPTY !== $version ? $version : null;
	}

	/**
	 * Validates UC client version format.
	 *
	 * @param string $version The version string to validate.
	 * @return bool True if valid format, false otherwise.
	 */
	private function validate_uc_client_version( $version ) {
		if ( empty( $version ) ) {
			return true; // Empty is valid (will use latest from API response).
		}
		return VISA_ACCEPTANCE_VAL_ONE === preg_match( '/^1\.[0-9]+(\.[0-9]+)?$/', $version );
	}
 
    /**
     * Helper method to get digital payment methods array based on UC settings.
     *
     * @param array $uc_setting UC settings array.
     * @return array Digital payment methods array.
     */
    private function get_digital_payment_methods_array( $uc_setting ) {
        $payment_method_array = array();
       
        // Check if Express Pay is enabled.
        if ( ! isset( $uc_setting['enable_express_pay'] ) || VISA_ACCEPTANCE_YES !== $uc_setting['enable_express_pay'] ) {
            return $payment_method_array; // Return empty array if Express Pay is disabled.
        }
       
        // When Express Pay is enabled, automatically include all wallet payment types.
        $payment_method_array = array(
            VISA_ACCEPTANCE_APPLEPAY,
            VISA_ACCEPTANCE_GOOGLEPAY,
            VISA_ACCEPTANCE_PAZE,
            VISA_ACCEPTANCE_PAYPAL,
            VISA_ACCEPTANCE_VENMO,
        );
       
        return $payment_method_array;
    }
 
    /**
     * Helper method to build shipping destination array.
     *
     * @param array $address The address array.
     * @return array Destination array.
     */
    private function build_shipping_destination( $address ) {
        return array(
            'country'   => ! empty( $address['country'] ) ? $address['country'] : WC()->customer->get_shipping_country(),
            'state'     => ! empty( $address['state'] ) ? $address['state'] : WC()->customer->get_shipping_state(),
            'postcode'  => ! empty( $address['postcode'] ) ? $address['postcode'] : WC()->customer->get_shipping_postcode(),
            'city'      => ! empty( $address['city'] ) ? $address['city'] : WC()->customer->get_shipping_city(),
            'address'   => ! empty( $address['address_1'] ) ? $address['address_1'] : WC()->customer->get_shipping_address(),
            'address_2' => ! empty( $address['address_2'] ) ? $address['address_2'] : WC()->customer->get_shipping_address_2(),
        );
    }
 
    /**
     * Helper method to calculate shipping cost from packages.
     *
     * @param array $packages Shipping packages array.
     * @return float Shipping total cost.
     */
    private function calculate_shipping_cost_from_packages( $packages ) {
        $shipping_total = VISA_ACCEPTANCE_VAL_ZERO;
       
        if ( ! empty( $packages ) ) {
            $selected_shipping_method = $this->get_shipping_from_last_order();
           
            foreach ( $packages as $package ) {
                if ( ! empty( $package['rates'] ) ) {
                    $min_cost = PHP_FLOAT_MAX;
                    $default_rate_cost = null;
                   
                    // Check if the last order's shipping method is available in the current package.
                    if ( $selected_shipping_method && isset( $package['rates'][ $selected_shipping_method ] ) ) {
                        $default_rate = $package['rates'][ $selected_shipping_method ];
                        $cost = (float) $default_rate->get_cost();
                        if ( $cost > VISA_ACCEPTANCE_VAL_ZERO ) {
                            $default_rate_cost = $cost;
                        }
                    }
                   
                    // If no valid default rate from last order, fall back to cheapest non-free rate.
                    if ( is_null( $default_rate_cost ) ) {
                        foreach ( $package['rates'] as $rate ) {
                            $cost = (float) $rate->get_cost();
                            // Only consider non-free shipping rates.
                            if ( $cost > VISA_ACCEPTANCE_VAL_ZERO && $cost < $min_cost ) {
                                $min_cost = $cost;
                            }
                        }
                        // Only add to total if we found a valid non-free shipping cost.
                        if ( PHP_FLOAT_MAX !== $min_cost ) {
                            $shipping_total += $min_cost;
                        }
                    } else {
                        $shipping_total += $default_rate_cost;
                    }
                }
            }
        }
       
        return $shipping_total;
    }
 
    /**
     * Helper method to handle subscription synchronization pricing logic.
     *
     * @param object $product The product object.
     * @param int $product_id The product ID.
     * @param int $quantity The quantity.
     * @param float $base_price The base price (product price * quantity).
     * @return array Array containing 'amount' and 'skip_shipping_tax' flag.
     */
    private function process_subscription_sync_pricing( $product, $product_id, $quantity, $base_price ) {
        $amount = $base_price;
        $skip_shipping_tax = false;
       
        $signup_fee = (float) get_post_meta( $product->get_id(), '_subscription_sign_up_fee', true );
        $signup_fee_total = $signup_fee * $quantity;
        $synchronise_renewal = get_option( 'woocommerce_subscriptions_sync_payments', VISA_ACCEPTANCE_NO );
        $prorate_synced_payments = get_option( 'woocommerce_subscriptions_prorate_synced_payments', VISA_ACCEPTANCE_NO );
       
        $product_sync_date = get_post_meta( $product_id, '_subscription_payment_sync_date', true );
        $product_do_not_sync = empty( $product_sync_date ) || '0' === $product_sync_date;
       
        if ( $product_do_not_sync ) {
            $amount += $signup_fee_total;
        } elseif ( VISA_ACCEPTANCE_YES === $synchronise_renewal ) {
            if ( VISA_ACCEPTANCE_NO === $prorate_synced_payments ) {
                // Never (do not charge any recurring amount) - only signup fee.
                if ( $signup_fee_total > VISA_ACCEPTANCE_ZERO_AMOUNT ) {
                    $amount = $signup_fee_total;
                } elseif ( $product->is_virtual() ) {
                    // For virtual products, charge full amount even with no signup fee.
                    $amount = $base_price;
                } else {
                    // For non-virtual products, use placeholder and skip shipping/tax.
                    $amount = VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
                    $skip_shipping_tax = true;
                }
            } elseif ( VISA_ACCEPTANCE_YES === $prorate_synced_payments || VISA_ACCEPTANCE_PRODUCT_TYPE_VIRTUAL === $prorate_synced_payments ) {
                // Prorate for all products or virtual products only.
                $should_prorate = ( VISA_ACCEPTANCE_YES === $prorate_synced_payments ) || ( VISA_ACCEPTANCE_PRODUCT_TYPE_VIRTUAL === $prorate_synced_payments && $product->is_virtual() );
               
                if ( $should_prorate ) {
                    $period = WC_Subscriptions_Product::get_period( $product );
                    $interval = WC_Subscriptions_Product::get_interval( $product );
                    $unit_price = $product->get_price();
                    $next_sync = $this->get_next_synchronised_date_for_product( $product_id );
                    $prorated_price = $this->calculate_prorated_amount( $unit_price, $quantity, $period, $interval, $next_sync );
                    $amount = $prorated_price + $signup_fee_total;
                } else {
                    // Virtual proration but product is not virtual - charge full amount.
                    $amount += $signup_fee_total;
                }
            } else {
                // 'no' - Never (charge the full recurring amount at sign-up).
                $amount += $signup_fee_total;
            }
        } else {
            // No synchronization - use regular price.
            $amount += $signup_fee_total;
        }
       
        return array(
            'amount'            => $amount,
            'skip_shipping_tax' => $skip_shipping_tax,
        );
    }
 

	/**
	 * Returns the cart total or the admin pay-for-order total.
	 *
	 * @param mixed $default_total Fallback value when cart is unavailable.
	 * @return mixed Total amount.
	 */
	private function get_cart_or_order_total( $default_total = VISA_ACCEPTANCE_ZERO_AMOUNT ) {
		$checkout_total_amount = $this->get_admin_checkout_total_amount();
		if ( $checkout_total_amount['is_admin_order_pay_page'] ) {
			return $checkout_total_amount['total_amount'];
		}
		return isset( WC()->cart ) ? WC()->cart->get_totals()['total'] : $default_total;
	}

	/**
	 * Builds the cart line items for the capture context request.
	 *
	 * @return array { items: Upv1capturecontextsDataOrderInformationLineItems[], taxAmount: string }
	 */
	private function build_cart_line_items() {
		$get_data         = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$is_pay_for_order = isset( $get_data['pay_for_order'] ) && isset( $get_data['key'] );
		// On order-pay page the cart is empty; build line items from the order instead.
		if ( $is_pay_for_order ) {
		return $this->build_order_line_items();
		}

		if ( is_add_payment_method_page() || ! isset( WC()->cart ) || WC()->cart->is_empty() ) {
		return array();
		}
		// Force product data hydration — guest sessions may have raw cart data without WC_Product objects.
        WC()->cart->calculate_totals();

		$uc_line_items    = array();
		$total_tax_amount = VISA_ACCEPTANCE_VAL_ZERO_DOT_ZERO_ZERO;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			// Fall back to a fresh product load if the cart item data object wasn't hydrated.
            if ( ! $product instanceof \WC_Product && ! empty( $cart_item['variation_id'] ) ) {
                $product = wc_get_product( $cart_item['variation_id'] );
            }
            if ( ! $product instanceof \WC_Product && ! empty( $cart_item['product_id'] ) ) {
                $product = wc_get_product( $cart_item['product_id'] );
            }
			if ( ! $product instanceof \WC_Product ) { continue; }
			$quantity   = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : VISA_ACCEPTANCE_VAL_ONE;
			$unit_price = $quantity > VISA_ACCEPTANCE_VAL_ZERO ? round( $cart_item['line_total'] / $quantity, VISA_ACCEPTANCE_VAL_TWO ) : VISA_ACCEPTANCE_VAL_ZERO;
			$tax_amount = isset( $cart_item['line_tax'] ) ? round( $cart_item['line_tax'], VISA_ACCEPTANCE_VAL_TWO ) : VISA_ACCEPTANCE_VAL_ZERO;
			$total_tax_amount += $tax_amount;
			$sku = $product->get_sku();
			$product_description = $product->get_short_description();
			$product_description = $product_description ? $product_description : $product->get_name();
			$uc_line_items[] = new \Pymt_Vas\Dependencies\CyberSource\Model\Upv1capturecontextsDataOrderInformationLineItems( array(
				'productName'        => $this->sanitize_item_name( $product->get_name() ),
				'productDescription' => $this->sanitize_item_name( wp_strip_all_tags( $product_description ) ),
				'productSku'         => $this->sanitize_item_name( ! empty( $sku ) ? $sku : (string) $product->get_id() ),
				'quantity'           => $quantity,
				'unitPrice'          => number_format( (float) $unit_price, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
				'taxAmount'          => number_format( (float) $tax_amount, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
				'typeOfSupply'       => $product->is_virtual() ? '01' : '00',
			) );
		}

		$shipping_total = (float) WC()->cart->get_shipping_total();
		if ( $shipping_total > VISA_ACCEPTANCE_VAL_ZERO ) {
			$shipping_tax    = (float) WC()->cart->get_shipping_tax();
			$total_tax_amount += $shipping_tax;
			$shipping_label  = VISA_ACCEPTANCE_STRING_EMPTY;
			foreach ( WC()->cart->get_shipping_packages() as $package ) {
				if ( isset( $package['rates'] ) && is_array( $package['rates'] ) ) {
					foreach ( $package['rates'] as $rate ) {
						$shipping_label = $rate->get_label();
						break 2;
					}
				}
			}
			if ( empty( $shipping_label ) ) {
				$shipping_label = __( 'Shipping', 'visa-acceptance-solutions' );
			}
			$uc_line_items[] = new \Pymt_Vas\Dependencies\CyberSource\Model\Upv1capturecontextsDataOrderInformationLineItems( array(
				'productCode'        => VISA_ACCEPTANCE_SHIPPING_AND_HANDELING,
				'productName'        => $this->sanitize_item_name( $shipping_label ),
				'productSku'         => 'shipping',
				'productDescription' => $shipping_label,
				'unitPrice'          => number_format( $shipping_total, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
				'quantity'           => VISA_ACCEPTANCE_VAL_ONE,
				'taxAmount'          => number_format( $shipping_tax, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
				'typeOfSupply'       => '01',
			) );
		}

		$discount_total = (float) WC()->cart->get_discount_total();
		$discount_amount = VISA_ACCEPTANCE_VAL_ZERO_DOT_ZERO_ZERO;
        if ( $discount_total > VISA_ACCEPTANCE_VAL_ZERO ) {
            $discount_tax     = (float) WC()->cart->get_discount_tax();
            $total_tax_amount -= $discount_tax;
            // CyberSource rejects a negative lineItems.unitPrice (enforced for eCheck), so surface the discount at order level instead.
            $discount_amount = number_format( $discount_total, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY );
        }
        return array(
            'items'          => $uc_line_items,
            'taxAmount'      => number_format( (float) $total_tax_amount, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
            'discountAmount' => $discount_amount,
        );
 
	}

	
	/**
	 * Builds line items from the WC_Order for the order-pay page capture context.
	 *
	 * @return array { items: Upv1capturecontextsDataOrderInformationLineItems[], taxAmount: string }
	 */
	private function build_order_line_items() {
		$order_id = get_query_var( 'order-pay' );
		if ( ! $order_id ) {
		return array();
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
		return array();
		}

		$uc_line_items    = array();
		$total_tax_amount = VISA_ACCEPTANCE_VAL_ZERO;

		foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		$quantity = $item->get_quantity();
		$line_total = (float) $item->get_total();
		$line_tax   = (float) $item->get_total_tax();
		$unit_price = $quantity > VISA_ACCEPTANCE_VAL_ZERO ? round( $line_total / $quantity, VISA_ACCEPTANCE_VAL_TWO ) : VISA_ACCEPTANCE_VAL_ZERO;
		$total_tax_amount += $line_tax;
		$name = $item->get_name();
		$sku  = $product ? $product->get_sku() : VISA_ACCEPTANCE_STRING_EMPTY;
		$desc = $product ? ( $product->get_short_description() ? $product->get_short_description() : $name ) : $name;
		$is_virtual = $product ? $product->is_virtual() : false;
		$uc_line_items[] = new \Pymt_Vas\Dependencies\CyberSource\Model\Upv1capturecontextsDataOrderInformationLineItems( array(
			'productName'        => $this->sanitize_item_name( $name ),
			'productDescription' => $this->sanitize_item_name( wp_strip_all_tags( $desc ) ),
			'productSku'         => $this->sanitize_item_name( ! empty( $sku ) ? $sku : (string) $item->get_product_id() ),
			'quantity'           => $quantity,
			'unitPrice'          => number_format( $unit_price, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
			'taxAmount'          => number_format( $line_tax, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
			'typeOfSupply'       => $is_virtual ? '01' : '00',
		) );
		}

		$shipping_total = (float) $order->get_shipping_total();
		if ( $shipping_total > VISA_ACCEPTANCE_VAL_ZERO ) {
		$shipping_tax     = (float) $order->get_shipping_tax();
		$total_tax_amount += $shipping_tax;
		$shipping_label   = __( 'Shipping', 'visa-acceptance-solutions' );
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			$shipping_label = $shipping_item->get_name();
			break;
		}
		$uc_line_items[] = new \Pymt_Vas\Dependencies\CyberSource\Model\Upv1capturecontextsDataOrderInformationLineItems( array(
			'productCode'        => VISA_ACCEPTANCE_SHIPPING_AND_HANDELING,
			'productName'        => $this->sanitize_item_name( $shipping_label ),
			'productSku'         => 'shipping',
			'productDescription' => $shipping_label,
			'unitPrice'          => number_format( $shipping_total, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
			'quantity'           => VISA_ACCEPTANCE_VAL_ONE,
			'taxAmount'          => number_format( $shipping_tax, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
			'typeOfSupply'       => '01',
		) );
		}

		$discount_total = (float) $order->get_discount_total();
		if ( $discount_total > VISA_ACCEPTANCE_VAL_ZERO ) {
		$discount_tax     = (float) $order->get_discount_tax();
		$total_tax_amount -= $discount_tax;
		$uc_line_items[] = new \Pymt_Vas\Dependencies\CyberSource\Model\Upv1capturecontextsDataOrderInformationLineItems( array(
			'productCode'        => 'coupon',
			'productName'        => 'Discount',
			'productSku'         => 'discount',
			'productDescription' => 'Coupon Discount',
			'unitPrice'          => number_format( -$discount_total, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
			'quantity'           => VISA_ACCEPTANCE_VAL_ONE,
			'taxAmount'          => number_format( -$discount_tax, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
			'typeOfSupply'       => '00',
		) );
		}

		return array(
		'items'     => $uc_line_items,
		'taxAmount' => number_format( (float) $total_tax_amount, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
		);
	}

	/**
	 * Applies smart truncation to address fields to ensure they fit within the maximum length constraints of the Unified Checkout API.
	 *
	 * @param array $address_array Address array with address1, address2, etc.
	 * @return array Address array with smart truncation applied.
	 */
	private function apply_smart_address_truncation( $address_array ) {
		$max_length = VISA_ACCEPTANCE_VAL_SIX_ZERO;
		
		// Get original values.
		$address1 = isset( $address_array['address1'] ) ? (string) $address_array['address1'] : '';
		$address2 = isset( $address_array['address2'] ) ? (string) $address_array['address2'] : '';
		
		// If address1 is within limit, no overflow needed.
		if ( strlen( $address1 ) <= $max_length ) {
			// Just truncate address2 if needed.
			if ( strlen( $address2 ) > $max_length ) {
				$address_array['address2'] = substr( $address2, VISA_ACCEPTANCE_VAL_ZERO, $max_length );
			}
			return $address_array;
		}
		
		// Address1 overflows - split it.
		$address1_keep = substr( $address1, VISA_ACCEPTANCE_VAL_ZERO, $max_length );
		$address1_overflow = substr( $address1, $max_length );
		
		// Combine overflow with original address2.
		$combined_address2 = $address1_overflow;
		if ( ! empty( $address2 ) ) {
			$combined_address2 .= '' . $address2;
		}
		$address_array['address1'] = $address1_keep;
		
		// If combined address2 fits, we're done.
		if ( strlen( $combined_address2 ) <= $max_length ) {
			$address_array['address2'] = $combined_address2;
			return $address_array;
		}
		
		// Address2 also overflows - split again.
		$address2_keep = substr( $combined_address2, VISA_ACCEPTANCE_VAL_ZERO, $max_length );
		$address2_overflow = substr( $combined_address2, $max_length );
		
		$address_array['address2'] = $address2_keep;
		
		// Store overflow in address3 if API supports it (truncate to 60 chars).
		if ( ! empty( $address2_overflow ) ) {
			$address_array['address3'] = substr( $address2_overflow, VISA_ACCEPTANCE_VAL_ZERO, $max_length );
		}
		return $address_array;
	}

	/**
	 * Builds billTo from the logged-in customer's billing address.
	 *
	 * @return Ucv1sessionsDataOrderInformationBillTo|null
	 */
	private function build_customer_bill_to() {
  $customer = WC()->customer;
  if ( ! $customer ) return null;

  // On order-pay page, order billing is the authoritative source; use it to fill gaps in the customer session.
  $ob = array();
  $order_pay = $this->get_admin_checkout_total_amount();
  if ( $order_pay['is_admin_order_pay_page'] ) {
   $order_id = get_query_var( 'order-pay' );
   if ( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( $order ) {
     $b = $order->get_data()['billing'];
     $ob = array(
      'firstName'          => $b['first_name'],
      'lastName'           => $b['last_name'],
      'email'              => $b['email'],
      'address1'           => $b['address_1'],
      'address2'           => $b['address_2'],
      'locality'           => $b['city'],
      'administrativeArea' => $b['state'],
      'postalCode'         => $b['postcode'],
      'country'            => $b['country'],
      'phoneNumber'        => $b['phone'],
     );
    }
   }
  }

  $map = array(
   'firstName'          => $customer->get_billing_first_name()  ?: ( $ob['firstName']          ?? '' ),
   'lastName'           => $customer->get_billing_last_name()   ?: ( $ob['lastName']           ?? '' ),
   'email'              => $customer->get_billing_email()       ?: ( $ob['email']              ?? '' ),
   'address1'           => $customer->get_billing_address_1()  ?: ( $ob['address1']           ?? '' ),
   'address2'           => $customer->get_billing_address_2()  ?: ( $ob['address2']           ?? '' ),
   'locality'           => $customer->get_billing_city()        ?: ( $ob['locality']           ?? '' ),
   'administrativeArea' => $customer->get_billing_state()       ?: ( $ob['administrativeArea'] ?? '' ),
   'postalCode'         => $customer->get_billing_postcode()   ?: ( $ob['postalCode']         ?? '' ),
   'country'            => $customer->get_billing_country()    ?: ( $ob['country']            ?? '' ),
   'phoneNumber'        => $customer->get_billing_phone()      ?: ( $ob['phoneNumber']        ?? '' ),
  );
  $data = array_filter( $map, function( $v ) { return ! empty( $v ); } );
  return ! empty( $data ) ? new Ucv1sessionsDataOrderInformationBillTo( $data ) : null;
 }

	/**
	 * Builds shipTo from the logged-in customer's shipping address.
	 *
	 * @return \Pymt_Vas\Dependencies\CyberSource\Model\Upv1capturecontextsDataOrderInformationShipTo|null
	 */
	private function build_customer_ship_to() {
		$customer = WC()->customer;
		if ( ! $customer ) return null;
		$map = array(
			'firstName'          => $customer->get_shipping_first_name(),
			'lastName'           => $customer->get_shipping_last_name(),
			'address1'           => $customer->get_shipping_address(),
			'address2'           => $customer->get_shipping_address_2(),
			'locality'           => $customer->get_shipping_city(),
			'administrativeArea' => $customer->get_shipping_state(),
			'postalCode'         => $customer->get_shipping_postcode(),
			'country'            => $customer->get_shipping_country(),
		);
		$data = array_filter( $map, function( $v ) { return ! empty( $v ); } );
		return ! empty( $data ) ? new \Pymt_Vas\Dependencies\CyberSource\Model\Upv1capturecontextsDataOrderInformationShipTo( $data ) : null;
	}

	/**
	 * Builds clientReferenceInformation with partner info and a unique reference code.
	 *
	 * Also reports applicationName/applicationVersion (our own plugin identity) so that transactions
	 * completed directly through the Unified Checkout widget show the same values in the Business
	 * Center as our server-to-server Payments API calls (see
	 * Visa_Acceptance_Payment_Adapter::client_reference_information()). Without these, CyberSource
	 * defaults them to "unifiedCheckout" / the actual UC library version on its own side.
	 *
	 * @return Ucv1sessionsDataClientReferenceInformation
	 */
	private function build_client_reference_info() {
        $partner = new Upv1capturecontextsDataClientReferenceInformationPartner( array(
            'solutionId'  => VISA_ACCEPTANCE_SOLUTION_ID,
        ) );
        $reference_code = strtoupper( wp_generate_password( VISA_ACCEPTANCE_VAL_FIVE, false, false ) );
        
        return new Ucv1sessionsDataClientReferenceInformation( array(
            'code'               => $reference_code,
            'applicationName'    => VISA_ACCEPTANCE_PLUGIN_APPLICATION_NAME . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_PLUGIN_API_TYPE,
            'applicationVersion' => VISA_ACCEPTANCE_PLUGIN_VERSION,
            'partner'            => $partner,
        ) );
    }
 

	/**
	 * Builds deviceInformation with the client IP address.
	 *
	 * @return Ucv1sessionsDataDeviceInformation
	 */
	private function build_device_info() {
		return new Ucv1sessionsDataDeviceInformation( array(
			'ipAddress' => $this->get_client_ip_address(),
		) );
	}

	/**
	 * Gets base url.
	 *
	 * @return string $base_url base url.
	 */
	private function get_base_url() {
		$complete_url = wp_parse_url( get_site_url() );
		if ( ! empty( $complete_url['port'] ) ) {
			$base_url = $complete_url['scheme'] . VISA_ACCEPTANCE_COLON_SLASH . $complete_url['host'] . VISA_ACCEPTANCE_COLON . $complete_url['port'];
		} else {
			$base_url = $complete_url['scheme'] . VISA_ACCEPTANCE_COLON_SLASH . $complete_url['host'];
		}
		return $base_url;
	}
}
