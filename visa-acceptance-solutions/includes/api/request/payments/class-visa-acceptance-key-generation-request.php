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

use CyberSource\Model\Upv1capturecontextsCaptureMandate;
use CyberSource\Model\Upv1capturecontextsOrderInformation;

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
		$get_data     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$response     = array(
			'total_amount'            => VISA_ACCEPTANCE_ZERO_AMOUNT,
			'is_admin_order_pay_page' => isset( $get_data['pay_for_order'], $get_data['key'] ),
		);
		$total_amount = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$base_url     = $this->get_base_url();
		// Logic for updating request for Pay_For_Order Page.
		if ( $response['is_admin_order_pay_page'] ) {
			$order_id     = null !== ( get_query_var( 'order-pay' ) ) ? get_query_var( 'order-pay' ) : get_query_var( get_option( 'woocommerce_checkout_pay_endpoint', 'order-pay' ) );
			$order        = isset( $order_id ) ? wc_get_order( $order_id ) : null;
			$total_amount = isset( $order ) ? $order->get_total() : VISA_ACCEPTANCE_ZERO_AMOUNT;
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
			return number_format( VISA_ACCEPTANCE_VAL_ZERO, VISA_ACCEPTANCE_VAL_TWO, '.', '' );
		}
		if (null === $quantity) {
			$quantity = VISA_ACCEPTANCE_ONE;
		}
		// Base product price multiplied by quantity.
		$amount = $product ? ( $product->get_price() * $quantity ) : VISA_ACCEPTANCE_ZERO_AMOUNT;

		// Subscription features with synchronization/proration support.
		if ( class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
			$signup_fee   = (float) get_post_meta( $product->get_id(), '_subscription_sign_up_fee', true );
			$signup_fee_total = $signup_fee * $quantity;
			$trial_length = WC_Subscriptions_Product::get_trial_length( $product_id );
			$synchronise_renewal = get_option('woocommerce_subscriptions_sync_payments', 'no');
			$prorate_synced_payments = get_option('woocommerce_subscriptions_prorate_synced_payments', 'no');

			if ( $trial_length > VISA_ACCEPTANCE_VAL_ZERO ) {
				$total = ($signup_fee_total > VISA_ACCEPTANCE_VAL_ZERO) ? $signup_fee_total : VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
				return number_format( (float) $total, VISA_ACCEPTANCE_VAL_TWO, '.', '' );
			}

			$product_sync_date = get_post_meta( $product_id, '_subscription_payment_sync_date', true );
			$product_do_not_sync = empty( $product_sync_date ) || '0' === $product_sync_date;
		
			if ( $product_do_not_sync ) {
				$amount += $signup_fee_total;
			} elseif ( 'yes' === $synchronise_renewal ) {
				if ( 'no' === $prorate_synced_payments ) {
					// Never (do not charge any recurring amount) - only signup fee.
					if ( $signup_fee_total > VISA_ACCEPTANCE_ZERO_AMOUNT ) {
						$amount = $signup_fee_total;
					} elseif ( $product->is_virtual() ) {
						// For virtual products, charge full amount even with no signup fee.
						$amount = $product->get_price() * $quantity;
					} else {
						// For non-virtual products, use placeholder and skip shipping/tax.
						$amount = VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
						$skip_shipping_tax = true;
					}
				} elseif ( 'yes' === $prorate_synced_payments || 'virtual' === $prorate_synced_payments ) {
						// Prorate for all products or virtual products only.
						$should_prorate = ( 'yes' === $prorate_synced_payments ) || ( 'virtual' === $prorate_synced_payments && $product->is_virtual() );
						
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
		return number_format( (float) $total, VISA_ACCEPTANCE_VAL_TWO, '.', '' );
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
            $diff_days = (int) $diff->days + VISA_ACCEPTANCE_ONE;
            if ( $diff_days > (int) $diff->days ) {
                $days_until_sync = $diff_days;
            }
        } else {
            $days_until_sync = (int) $diff->days;
        }
        if ( $days_until_sync < 0 ) {
            $days_until_sync = abs( $days_until_sync );
        }
        
        $period_days = $this->period_days( $period, $interval, $current_time );
 
        if ( $period_days <= 0 ) {
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
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'status'      => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
			);
			$orders = wc_get_orders( $args );

			if ( ! empty( $orders ) ) {
				$last_order = $orders[0];
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
		$shipping_total = 0;

		$packages = WC()->shipping->calculate_shipping( WC()->cart->get_shipping_packages() );
		
		// For variable products or when cart is empty, build package manually.
		if ( empty( $packages ) && $product_id > 0 ) {
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
					'destination'     => array(
						'country'   => ! empty( $address['country'] ) ? $address['country'] : WC()->customer->get_shipping_country(),
						'state'     => ! empty( $address['state'] ) ? $address['state'] : WC()->customer->get_shipping_state(),
						'postcode'  => ! empty( $address['postcode'] ) ? $address['postcode'] : WC()->customer->get_shipping_postcode(),
						'city'      => ! empty( $address['city'] ) ? $address['city'] : WC()->customer->get_shipping_city(),
						'address'   => ! empty( $address['address_1'] ) ? $address['address_1'] : WC()->customer->get_shipping_address(),
						'address_2' => ! empty( $address['address_2'] ) ? $address['address_2'] : WC()->customer->get_shipping_address_2(),
					),
				);
				$packages = WC()->shipping->calculate_shipping( $packages );
			}
		}
		
		if ( ! empty( $packages ) ) {
			
			$selected_shipping_method = $this->get_shipping_from_last_order();
			foreach ( $packages as $package ) {
				if ( ! empty( $package['rates'] ) ) {
					$min_cost = PHP_FLOAT_MAX; // Initialize with max float value.
					$default_rate_cost = null;

					// Check if the last order's shipping method is available in the current package.
					if ( $selected_shipping_method && isset( $package['rates'][ $selected_shipping_method ] ) ) {
						$default_rate = $package['rates'][ $selected_shipping_method ];
						$cost = (float) $default_rate->get_cost();
						if ( $cost > 0 ) {
							$default_rate_cost = $cost;
						}
					}

					// If no valid default rate from last order, fall back to cheapest non-free rate.
					if ( is_null( $default_rate_cost ) ) {
						foreach ( $package['rates'] as $rate ) {
							$cost = (float) $rate->get_cost();
							// Only consider non-free shipping rates.
							if ( $cost > 0 && $cost < $min_cost ) {
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
			$amount += $shipping_total;
		}
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
		$tax_total    	= 0;
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
        $total_amount = 0;
        $tax_total = 0;
        $signup_fees_total = 0;
        $has_trial = false;
        $skip_shipping_tax = false;
        $shipping_products = array();
       
        // Calculate product amounts including subscription handling.
        foreach ( $grouped_items as $product_id => $quantity ) {
            $quantity = intval( $quantity );
            if ( $quantity <= 0 ) {
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
                $synchronise_renewal = get_option('woocommerce_subscriptions_sync_payments', 'no');
                $prorate_synced_payments = get_option('woocommerce_subscriptions_prorate_synced_payments', 'no');
 
                if ( $trial_length > VISA_ACCEPTANCE_VAL_ZERO ) {
                    $total = ($signup_fee_total > VISA_ACCEPTANCE_VAL_ZERO) ? $signup_fee_total : VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
                    return number_format( (float) $total, VISA_ACCEPTANCE_VAL_TWO, '.', '' );
                }
 
                $product_sync_date = get_post_meta( $product_id, '_subscription_payment_sync_date', true );
                $product_do_not_sync = empty( $product_sync_date ) || '0' === $product_sync_date;
           
                if ( $product_do_not_sync ) {
                    $total_amount += ($product->get_price() * $quantity) + $signup_fee_total;
                } elseif ( 'yes' === $synchronise_renewal ) {
                    if ( 'no' === $prorate_synced_payments ) {
                        // Never (do not charge any recurring amount) - only signup fee.
                        if ( $signup_fee_total > VISA_ACCEPTANCE_ZERO_AMOUNT ) {
                            $total_amount += $signup_fee_total;
                        } elseif ( $product->is_virtual() ) {
                            // For virtual products, charge full amount even with no signup fee.
                            $total_amount += $product->get_price() * $quantity;
                        } else {
                            // For non-virtual products, use placeholder and skip shipping/tax.
                            $total_amount += VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
                            $skip_shipping_tax = true;
                        }
                    } elseif ( 'yes' === $prorate_synced_payments || 'virtual' === $prorate_synced_payments ) {
                            // Prorate for all products or virtual products only.
                            $should_prorate = ( 'yes' === $prorate_synced_payments ) || ( 'virtual' === $prorate_synced_payments && $product->is_virtual() );
                           
                            if ( $should_prorate ) {
                                $period = WC_Subscriptions_Product::get_period( $product );
                                $interval = WC_Subscriptions_Product::get_interval( $product );
                            $unit_price = $product->get_price();
                            $next_sync = $this->get_next_synchronised_date_for_product( $product_id );
                            $prorated_price = $this->calculate_prorated_amount( $unit_price, $quantity, $period, $interval, $next_sync );
                            $total_amount += $prorated_price + $signup_fee_total;
                        } else {
                            // Virtual proration but product is not virtual - charge full amount.
                            $total_amount += ($product->get_price() * $quantity) + $signup_fee_total;
                            }
                    } else {
                        // 'no' - Never (charge the full recurring amount at sign-up).
                        $total_amount += ($product->get_price() * $quantity) + $signup_fee_total;
                    }
                } else {
                    // No synchronization - use regular price.
                    $total_amount += ($product->get_price() * $quantity) + $signup_fee_total;
                }
            } else {
                $product_price = $product->get_price() * $quantity;
                $total_amount += $product_price;
            }
        }
        $total_amount += $signup_fees_total;
        if ( $has_trial && VISA_ACCEPTANCE_VAL_ZERO === $total_amount && VISA_ACCEPTANCE_VAL_ZERO === $signup_fees_total) {
            return number_format( (float) VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT, 2, '.', '' );
        }
        if ( wc_shipping_enabled() && ! empty( $shipping_products ) && ! $skip_shipping_tax && !$product->is_virtual() ) {
            $total_amount = $this->calculate_grouped_shipping( $total_amount, $shipping_products, $address );
        }
        if ( wc_tax_enabled() && $product->is_taxable() && !$skip_shipping_tax ) {
            if ( $total_amount > 0 ) {
                    $product_tax = $this->calculate_product_page_taxes( $product, $address, $total_amount );
                    $tax_total += $product_tax;
                }
        }
        $final_total = $total_amount + round( $tax_total, 2 );
        return number_format( (float) $final_total, 2, '.', '' );
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
		$shipping_total = 0;
		
		// Build package for grouped products.
		$contents = array();
		$contents_cost = 0;
		
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
				'destination' => array(
					'country' => ! empty( $address['country'] ) ? $address['country'] : WC()->customer->get_shipping_country(),
					'state' => ! empty( $address['state'] ) ? $address['state'] : WC()->customer->get_shipping_state(),
					'postcode' => ! empty( $address['postcode'] ) ? $address['postcode'] : WC()->customer->get_shipping_postcode(),
					'city' => ! empty( $address['city'] ) ? $address['city'] : WC()->customer->get_shipping_city(),
					'address' => ! empty( $address['address_1'] ) ? $address['address_1'] : WC()->customer->get_shipping_address(),
					'address_2' => ! empty( $address['address_2'] ) ? $address['address_2'] : WC()->customer->get_shipping_address_2(),
				),
			)
		);
		
		$packages = WC()->shipping->calculate_shipping( $packages );
		
		if ( ! empty( $packages ) ) {
			$selected_shipping_method = $this->get_shipping_from_last_order();
			
			foreach ( $packages as $package ) {
				if ( ! empty( $package['rates'] ) ) {
					$min_cost = PHP_FLOAT_MAX;
					$default_rate_cost = null;
					
					if ( $selected_shipping_method && isset( $package['rates'][ $selected_shipping_method ] ) ) {
						$default_rate = $package['rates'][ $selected_shipping_method ];
						$cost = (float) $default_rate->get_cost();
						if ( $cost > 0 ) {
							$default_rate_cost = $cost;
						}
					}
					
					if ( is_null( $default_rate_cost ) ) {
						foreach ( $package['rates'] as $rate ) {
							$cost = (float) $rate->get_cost();
							if ( $cost > 0 && $cost < $min_cost ) {
								$min_cost = $cost;
							}
						}
						if ( PHP_FLOAT_MAX !== $min_cost ) {
							$shipping_total += $min_cost;
						}
					} else {
						$shipping_total += $default_rate_cost;
					}
				}
			}
			$amount += $shipping_total;
		}
		
		return $amount;
	}

	/**
	 * Builds request for Capture Context Generation in UC.
	 */
	public function get_uc_request() {
		$total_amount         = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$payment_method_array = array( VISA_ACCEPTANCE_PANENTRY );
		$payment_method       = VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->id . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS;
		$uc_setting           = get_option( $payment_method, array() );

		if ( ! empty ($uc_setting['enabled_payment_methods'] ) ) {
                foreach($uc_setting['enabled_payment_methods'] as $digital_payment_method) {
                    if ('enable_vco' === $digital_payment_method) {
                        array_push( $payment_method_array, VISA_ACCEPTANCE_CLICKTOPAY );
                    }
                }
        }
		$checkout_total_amount = $this->get_admin_checkout_total_amount();
		if ( $checkout_total_amount['is_admin_order_pay_page'] ) {
			$total_amount = $checkout_total_amount['total_amount'];
		} else {
			$total_amount = isset( WC()->cart ) ? WC()->cart->get_totals()['total'] : $total_amount;
		}
		$capture_context_payload = $this->get_capture_context_request( $payment_method_array, $total_amount );
		return $capture_context_payload;
	}

	/**
	 * Builds request for Digital Payment Capture Context Generation in UC.
	 *
	 * @param int $product_id The product ID.
	 * @param int $quantity The product quantity.
	 * @param array $grouped_items Array of product IDs and quantities for grouped products.
	 * @return array Capture context payload.
	 */
	public function get_digital_uc_request( $product_id, $quantity, $grouped_items = array() ) {
		$total_amount         = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$payment_method_array = array();
		$payment_method       = VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->id . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS;
		$uc_setting           = get_option( $payment_method, array() );

		if ( ! empty ($uc_setting['enabled_payment_methods'] ) ) {
			$add_methods = array(
				'enable_gpay' => VISA_ACCEPTANCE_GOOGLEPAY,
				'enable_apay' => VISA_ACCEPTANCE_APPLEPAY,
				'enable_paze' => VISA_ACCEPTANCE_PAZE,
			);
			foreach ( $uc_setting['enabled_payment_methods'] as $digital_payment_method ) {
				if ( isset( $add_methods[ $digital_payment_method ] ) ) {
					$payment_method_array[] = $add_methods[ $digital_payment_method ];
				}
			}
		}
		$checkout_total_amount = $this->get_admin_checkout_total_amount();
		if ( $checkout_total_amount['is_admin_order_pay_page'] ) {
			$total_amount = $checkout_total_amount['total_amount'];
		} 
		else if ( is_product() || $product_id ) {
			global $product;
			if ( null !== $product ) {
				$product_id = $product ? $product->get_id() : 0;
			}
			if ($product_id) {
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
		$capture_context_payload = $this->get_capture_context_request( $payment_method_array, $total_amount );
		return $capture_context_payload;
	}

	/**
	 * Builds request for Zero Dollar Auth Capture Context Generation in UC.
	 *
	 * @return array $capture_context_payload payload.
	 */
	public function get_zero_uc_request() {

		$payment_method_array = array( VISA_ACCEPTANCE_PANENTRY );
		$total_amount         = WC()->cart->get_totals()['total'];
		if ( ! is_add_payment_method_page() && ( WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ||
		( VISA_ACCEPTANCE_ZERO_AMOUNT === $total_amount && WC_Subscriptions_Cart::cart_contains_subscription() ) ) ) {
			$payment_method = VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->id . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS;
			$uc_setting     = get_option( $payment_method, array() );

			if ( ! empty ($uc_setting['enabled_payment_methods'] ) ) {
					foreach($uc_setting['enabled_payment_methods'] as $digital_payment_method) {
						if ('enable_vco' === $digital_payment_method) {
							array_push( $payment_method_array, VISA_ACCEPTANCE_CLICKTOPAY );
						}
					}
			}
		}
		$total_amount            = VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
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
		$total_amount         = WC()->cart->get_totals()['total'];
		if ( ! is_add_payment_method_page() && ( WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ||
		( VISA_ACCEPTANCE_ZERO_AMOUNT === $total_amount && WC_Subscriptions_Cart::cart_contains_subscription() ) ) ) {
			$payment_method = VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->id . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS;
			$uc_setting     = get_option( $payment_method, array() );

			if ( ! empty ($uc_setting['enabled_payment_methods'] ) ) {
				$add_methods = array(
					'enable_gpay' => VISA_ACCEPTANCE_GOOGLEPAY,
					'enable_apay' => VISA_ACCEPTANCE_APPLEPAY,
					'enable_paze' => VISA_ACCEPTANCE_PAZE,
				);
				foreach ( $uc_setting['enabled_payment_methods'] as $digital_payment_method ) {
					if ( isset( $add_methods[ $digital_payment_method ] ) ) {
						$payment_method_array[] = $add_methods[ $digital_payment_method ];
					}
				}
			}
		}
		$total_amount            = VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
		$capture_context_payload = $this->get_capture_context_request( $payment_method_array, $total_amount );
		return $capture_context_payload;
	}

	/**
	 * Generates capture context request.
	 *
	 * @param array $payment_method_array   payment method array.
	 * @param mixed $total_amount total     amount.
	 * @return array $payload
	 */
	public function get_capture_context_request( $payment_method_array, $total_amount ) {
		$base_url       = $this->get_base_url();
		$payment_method = VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->id . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS;
		$uc_setting     = get_option( $payment_method, array() );
		$allowed_cards  = is_array( $uc_setting['card_types'] ) ? $uc_setting['card_types'] : VISA_ACCEPTANCE_DEFAULT_CARD_TYPES;
		
		// Get tokenization setting (default to false if not set).
		// Only enable save card checkbox for logged-in users.
        $enable_save_card = ( isset( $uc_setting['tokenization'] ) && VISA_ACCEPTANCE_YES === $uc_setting['tokenization'] && !is_add_payment_method_page() && is_user_logged_in());
		

		$order_information = new Upv1capturecontextsOrderInformation();
		$order_information->setAmountDetails(
			array(
				'totalAmount' => $total_amount,
				'currency'    => get_woocommerce_currency(),
			)
		);
		
		// Add billing information with first name, last name, and email.
		$is_express_pay_data = ! in_array( VISA_ACCEPTANCE_PANENTRY, $payment_method_array );
        if(! $is_express_pay_data) {
            // Add billing information with first name, last name, and email.
            $bill_to_data = array();
            if ( is_user_logged_in() ) {
                $customer = WC()->customer;
                if ( $customer ) {
                    $first_name     = $customer->get_billing_first_name();
                    $last_name      = $customer->get_billing_last_name();
                    $email          = $customer->get_billing_email();
                   
                    if ( ! empty( $first_name ) ) {
                        $bill_to_data['firstName']  = $first_name;
                    }
                    if ( ! empty( $last_name ) ) {
                        $bill_to_data['lastName']   = $last_name;
                    }
                    if ( ! empty( $email ) ) {
                        $bill_to_data['email']      = $email;
                    }
                }
            }
           
            if ( ! empty( $bill_to_data ) ) {
                $bill_to = new \CyberSource\Model\Upv1capturecontextsOrderInformationBillTo( $bill_to_data );
                $order_information->setBillTo( $bill_to );
            }
        }
 
		$transient_token_response_options = new \CyberSource\Model\Microformv2sessionsTransientTokenResponseOptions(
			array(
				'includeCardPrefix' => false,
			)
		);

		$capture_mandate = new Upv1capturecontextsCaptureMandate();
        $billing_type = $is_express_pay_data ? VISA_ACCEPTANCE_UC_BILLING_TYPE_FULL : VISA_ACCEPTANCE_UC_BILLING_TYPE;
        $capture_mandate->setBillingType( $billing_type);
        $is_express_pay = ! is_user_logged_in();
        $request_email = ( $is_express_pay && VISA_ACCEPTANCE_UC_BILLING_TYPE_FULL === $billing_type );
        $capture_mandate->setRequestEmail( $request_email );
        $request_phone = ! is_add_payment_method_page();
        $capture_mandate->setRequestPhone( $request_phone );
        $capture_mandate->setRequestShipping( $is_express_pay_data );
        $force_tokenization = $this->gateway->is_subscriptions_activated && ( WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal() || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment );
        $capture_mandate->setShowAcceptedNetworkIcons( ! $is_express_pay_data );
        if ( !$force_tokenization ) {
            $capture_mandate->setRequestSaveCard($enable_save_card);
        }
			
		$payload = array(
			'targetOrigins'                 => array( $base_url ),
			'allowedCardNetworks'           => $allowed_cards,
			'allowedPaymentTypes'           => $payment_method_array,
			'country'                       => WC()->countries->get_base_country(),
			'locale'                        => get_locale(),
			'captureMandate'                => $capture_mandate,
			'orderInformation'              => $order_information,
			'clientVersion'                 => VISA_ACCEPTANCE_UC_CLIENT_VERSION,
			'transientTokenResponseOptions' => $transient_token_response_options,
		);
		return $payload;
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
