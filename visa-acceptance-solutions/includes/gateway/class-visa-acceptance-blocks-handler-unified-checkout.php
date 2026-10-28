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

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

require_once plugin_dir_path( __DIR__ ) . 'api/payments/class-visa-acceptance-key-generation.php';
require_once plugin_dir_path( __DIR__ ) . 'class-visa-acceptance-payment-gateway-unified-checkout.php';
require_once plugin_dir_path( __DIR__ ) . 'api/payments/class-visa-acceptance-payment-methods.php';

/**
 *
 * Visa Acceptance Blocks Handler Unified Checkout Class
 * Handles Blocks Unified Checkout requests
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Blocks_Handler_Unified_Checkout extends AbstractPaymentMethodType {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;
	use Visa_Acceptance_Payment_Gateway_Public_Trait;
	use Visa_Acceptance_Payment_Gateway_Includes_Trait;

	/**
	 * Payment Gateway Name
	 *
	 * @var string $name
	 */
	protected $name = VISA_ACCEPTANCE_UC_ID;

	/**
	 * Plugin
	 *
	 * @var Plugin $plugin
	 */
	protected $plugin = null;

	/**
	 * Gateway
	 *
	 * @var Object $gateway gateway object.
	 */
	protected $gateway = null;

	/**
	 * Init Square Cart and Checkout Blocks handler class
	 */
	public function __construct() {
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
        $general_settings    = $this->gateway->get_config_settings();
        $payment_method_data = array();
        $enable_tokenization = false;
        $token_type          = array();
        $last_four           = array();
        $force_tokenization  = false;
        $uc_settings         = get_option( VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->get_id() . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS, array() );
        $token_key           = isset( $uc_settings['test_api_key'] ) ? $uc_settings['test_api_key'] : VISA_ACCEPTANCE_STRING_EMPTY;
        $subscription_order = false;
        $capture_context_response = null;
        $payment_gateway_unified_checkout = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
        if ( isset( $uc_settings['enabled'] ) && VISA_ACCEPTANCE_YES === $uc_settings['enabled'] && ( is_checkout() || is_admin() )) {
            $saved_card_token_cvv = true; // Always enabled — not controlled by admin setting.
            $tokenization         = ( isset( $uc_settings['tokenization'] ) && VISA_ACCEPTANCE_YES === $uc_settings['tokenization'] ) ? true : false;
            $enable_tokenization  = ( isset( $uc_settings['tokenization'] ) && VISA_ACCEPTANCE_YES === $general_settings['tokenization'] && is_user_logged_in() ) ? true : false;
            $payment_method       = new Visa_Acceptance_Payment_Methods( $this );
            $customer_data        = $payment_method->get_order_for_add_payment_method();
            $core_tokens          = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
            if ( ! empty( $core_tokens ) ) {
                // Not gated on wp_doing_ajax(): the Store API re-fetches this data via a
                // REST/ajax call after order placement, so skipping it there left the UI stale.
                ( new Visa_Acceptance_Payment_Methods( $this->gateway ) )->refresh_all_tokens_from_tms( $core_tokens );
            }
           
            // Check if this is a free trial (exclude eCheck for $0.00 orders)
            // Multiple detection methods to ensure accuracy in blocks checkout.
            $is_free_trial = false;
           
            // Method 1: Check cart total.
            if ( isset( WC()->cart ) && WC()->cart ) {
                WC()->cart->calculate_totals();
                $cart_total = WC()->cart->get_totals()['total'];
                if ( (float) VISA_ACCEPTANCE_ZERO_AMOUNT === (float) $cart_total ) {
                    $is_free_trial = true;
                }
            }
           
            // Method 2: Check if subscription with free trial (WooCommerce Subscriptions).
            if ( ! $is_free_trial && class_exists( 'WC_Subscriptions_Cart' ) ) {
                // Check if cart contains subscription.
                if ( method_exists( 'WC_Subscriptions_Cart', 'cart_contains_subscription' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
                    // Get subscription totals.
                    if ( method_exists( 'WC_Subscriptions_Cart', 'get_cart_subscription_totals' ) ) {
                        $totals = WC_Subscriptions_Cart::get_cart_subscription_totals();
                        if ( isset( $totals['total'] ) && (float) VISA_ACCEPTANCE_ZERO_AMOUNT === (float) $totals['total'] ) {
                            $is_free_trial = true;
                        }
                    }
                   
                    // Also check cart items directly for trial length.
                    if ( ! $is_free_trial && isset( WC()->cart ) && WC()->cart->get_cart() ) {
                        foreach ( WC()->cart->get_cart() as $cart_item ) {
                            $product = $cart_item['data'];
                            if ( $product && class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
                                $trial_length = WC_Subscriptions_Product::get_trial_length( $product->get_id() );
                                $signup_fee = (float) get_post_meta( $product->get_id(), '_subscription_sign_up_fee', true );
                                // If there's a trial and no signup fee, and cart total is 0, it's a free trial.
                                if ( VISA_ACCEPTANCE_VAL_ZERO < $trial_length && 0.0 === $signup_fee && isset( $cart_total ) && 0.0 === (float) $cart_total ) {
                                    $is_free_trial = true;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
           
            foreach ( $core_tokens as $token ) {
                if ( ! $token instanceof \WC_Payment_Token ) {
                   continue;
                }
               
                // Check if token matches current environment.
                $environment_saved = $token->get_meta( VISA_ACCEPTANCE_ENVIRONMENT );
                if ( $environment_saved !== $uc_settings[VISA_ACCEPTANCE_ENVIRONMENT] ) {
                    continue;
                }
               
                $data = $token->get_data();
                if ( $data['token'] ) {
                    // If card_type is null or empty, it's likely an eCheck token.
                    $token_card_type = ! empty( $data['card_type'] ) ? $data['card_type'] : VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK;
                   
                    // Skip eCheck tokens for free trial orders ($0.00 total).
                    $is_echeck_token = ( VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $token_card_type ) || ( VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $token->get_type() );
                    if ( $is_free_trial && $is_echeck_token ) {
                        continue;
                    }
                   
                    $token_type[ $token->get_id() ] = $token_card_type;
                    $last_four[ $token->get_id() ]  = $data['last4'];
                }
            }
            $subscription_active = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
            if ( $subscription_active ) {
                $subscription_order = WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal() || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
                $force_tokenization = $this->gateway->is_subscriptions_activated && ( WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal() || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment );
                if ( $force_tokenization ) {
                    $enable_tokenization = false;
                }
            }
 
            $payment_method_data = array(
                'title'                           => $uc_settings['title'],
                'description'                     => $uc_settings['description'],
                'supports'                        => $this->get_supported_features(),
                'ajax_url'                        => admin_url( 'admin-ajax.php' ),
                'enable_tokenization'             => $enable_tokenization,
                'force_tokenization'              => $force_tokenization,
                'last_four'                       => $last_four,
                'token_type'                      => $token_type,
                'saved_card_cvv'                  => $saved_card_token_cvv,
                'visa_acceptance_solutions_uc_id' => VISA_ACCEPTANCE_UC_ID,
                'token_key'                       => $token_key,
                'capture_context'                 => $capture_context_response,
                'subscription_order'              => $subscription_order,
                'tokenization'                    => $tokenization,
                'encrypt_const'                   => __( 'encrypt', 'visa-acceptance-solutions' ),
                'form_load_error'                 => __( 'Unable to load the payment form. Please contact customer care for any assistance.', 'visa-acceptance-solutions' ),
                'failure_error'                   => __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' ),
                // Bypassing condition for idle stage for version lower than 8.6.
                'isVersionSupported'              => version_compare( WC_VERSION, VISA_ACCEPTANCE_WC_VERSION_EIGHT_SIX_ZERO, '>=' ),
                'checkout_display_mode'           => isset( $uc_settings['checkout_display_mode'] ) ? $uc_settings['checkout_display_mode'] : 'sidebar',
                'get_saved_card_context_nonce'    => wp_create_nonce( 'wc_get_saved_card_context' ),
                'uc_library_proxy_nonce'          => wp_create_nonce( 'vas_uc_library_proxy' ),
                'get_addresses_nonce'             => wp_create_nonce( 'vas_get_addresses_nonce' ),
                'update_price_nonce'              => wp_create_nonce( 'wc_call_uc_update_price_action' ),
            );
        }
        return $payment_method_data;
    }


	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {

		$asset_path   = untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/../build/index-unified-checkout-asset.php';
		$version      = VISA_ACCEPTANCE_PLUGIN_VERSION;
		$dependencies = array();

		if ( file_exists( $asset_path ) ) {
			$asset        = require $asset_path;
			$version      = is_array( $asset ) && isset( $asset['version'] ) ? $asset['version'] : $version;
			$dependencies = is_array( $asset ) && isset( $asset['dependencies'] ) ? $asset['dependencies'] : $dependencies;
		}
		// Only enqueue block styles on cart/checkout pages or in the admin (block editor).
		if ( is_checkout() || is_cart() || is_admin() ) {
			wp_enqueue_style( 'wc-unified-checkout-cart-checkout-block', $this->gateway->get_plugin_url() . '/../public/css/visa-acceptance-payment-gateway-blocks.css', array(), $version );
		}

        if ( ! wp_script_is( 'wc-payment-method-unified-checkout', 'registered' ) ) {
			wp_register_script(
				'wc-payment-method-unified-checkout',
				$this->gateway->get_plugin_url() . '/../includes/build/index-unified-checkout.js',
				array( 'jquery', 'wp-i18n' ),
				$version,
				true
			);
			wp_set_script_translations(
				'wc-payment-method-unified-checkout',
				'visa-acceptance-solutions'
			);
		}
		return array( 'wc-payment-method-unified-checkout' );
	}

	/**
	 * Initializes the payment method type.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . VISA_ACCEPTANCE_UC_ID . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS, array() );
		$this->gateway  = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
	}
}
