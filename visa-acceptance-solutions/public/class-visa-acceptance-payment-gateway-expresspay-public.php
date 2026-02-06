<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/public
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/api/request/payments/class-visa-acceptance-key-generation-request.php';
require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-visa-acceptance-payment-gateway-unified-checkout-public.php';

/**
 * Visa Acceptance Payment Gateway Express Pay Public Class
 *
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/public
 */
class Visa_Acceptance_Payment_Gateway_Expresspay_Public extends \WC_Payment_Gateway {
    /**
	 * The ID of this plugin.
	 *
	 * @var      string    $wc_payment_gateway_id    The ID of this plugin.
	 */
	private $wc_payment_gateway_id;

	/**
	 * The version of this plugin.
	 *
	 * @var  string  $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * The gateway object of this plugin.
	 *
	 * @var object $gateway The current payment gateways object.
	 */
	private $gateway;

	/**
	 * The gateway object of this plugin.
	 *
	 * @var      object    $plugin_public    The current payment gateways public object.
	 */
	public $is_subscriptions_activated = false;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param      string $wc_payment_gateway_id       The name of the plugin.
	 * @param      string $version               The version of this plugin.
	 * @param      object $gateway               The current payment gateways object.
	 */
	public function __construct( $wc_payment_gateway_id, $version, $gateway ) {

		$this->wc_payment_gateway_id = $wc_payment_gateway_id;
		$this->version               = $version;
		$this->gateway               = $gateway;
	}
	
    /**
	 * Function to Add Express Pay section at the top of checkout page.
	 */
	public function add_express_pay_at_normal_checkout() {
        $plugin_public = new  Visa_Acceptance_Payment_Gateway_Unified_Checkout_Public($this->wc_payment_gateway_id,$this->gateway,$this->version);
		$settings      = $plugin_public->get_uc_settings();
		$enable_gpay = ( isset( $settings['enabled_payment_methods'] ) && is_array( $settings['enabled_payment_methods'] ) && in_array( 'enable_gpay', $settings['enabled_payment_methods'], true ) ) ? true : false;
		$enable_apay = ( isset( $settings['enabled_payment_methods'] ) && is_array( $settings['enabled_payment_methods'] ) && in_array( 'enable_apay', $settings['enabled_payment_methods'], true ) ) ? true : false;
		$enable_paze = ( isset( $settings['enabled_payment_methods'] ) && is_array( $settings['enabled_payment_methods'] ) && in_array( 'enable_paze', $settings['enabled_payment_methods'], true ) ) ? true : false;
		$subscription_order = false;
		$is_zero_initial_payment = ( VISA_ACCEPTANCE_ZERO_AMOUNT === WC()->cart->get_total( 'edit' ) && VISA_ACCEPTANCE_YES === get_option( 'woocommerce_subscriptions_zero_initial_payment_requires_payment', 'no' ) ) ? true : false;
		$is_subscription_tokenization_enabled = false;
		$payment_gateway_unified_checkout = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$subscription_active   = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
		if ( $subscription_active ) {
			$is_subscription_tokenization_enabled = $this->gateway->is_subscriptions_activated;
			$subscription_order = WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal() || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
		}
		$get_data = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		//if ( (is_checkout() || isset( $get_data['pay_for_order'] ) ) && is_user_logged_in() && ! $is_zero_initial_payment) {
		if ( (is_checkout() || isset( $get_data['pay_for_order'] ) ) && ! $is_zero_initial_payment) {
			$ep_title        = __( 'Express Checkout', 'visa-acceptance-solutions' );
			$ep_divider_text = __( 'Or continue below', 'visa-acceptance-solutions' );
			?>
			<div id="wc-express-checkout-normal">
				<div id="wc-express-checkout-section">
					
					<div id="express-checkout-heading">
						<span><?php echo esc_html( $ep_title ); ?></span>
						<hr>
					</div>

					<div id="expressPaymentListContainer"></div>
				
				<?php
					$form_load_error = __( 'Unable to load the payment form. Please contact customer care for any assistance.', 'visa-acceptance-solutions' );
					echo '<div id="express_payment_form_load_error" style="display:none;color:red">' .
					'<p class="failure-error-message" id="wc-failure-error"> ' . esc_html( $form_load_error ) . ' </p>' .
					'</div>';
					
					if ( VISA_ACCEPTANCE_YES === $settings['tokenization'] && $is_subscription_tokenization_enabled && $subscription_order ) {
						ob_start();
						wc_print_notice( esc_html__( 'Item in your order is a subscription/recurring purchase. By continuing with payment, you agree that your payment method will be automatically charged at the price and frequency listed here until it ends or you cancel.', 'visa-acceptance-solutions' ), 'notice' );
						$response = ob_get_clean();
						echo '<div id="wc-express-checkout-normal-save-token-div">' . wp_kses_post( $response ) . '</div>';
					}

					$failure_error = __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' );
					echo '<div id="wc-error-failure" style="display:none;color:red">' .
					'<p class="failure-error-message" id="wc-failure-error"> ' . esc_html( $failure_error ) . ' </p>' .
					'</div>';
				?>
				</div>
				<!-- Divider -->
				<div id="wc-express-checkout-section-divider">
					<span><?php echo esc_html( $ep_divider_text ); ?></span>
					<hr>
				</div>
			</div>
			<?php
			if ( ! $is_subscription_tokenization_enabled && $subscription_order ) {
				echo '<style>#wc-express-checkout-section{display:none !important;}</style>';
				echo '<style>#wc-express-checkout-section-divider{display:none !important;}</style>';
			}
			if ( ! ( $enable_gpay || $enable_apay || $enable_paze ) ) {
				echo '<style>#wc-express-checkout-section{display:none !important;}</style>';
				echo '<style>#wc-express-checkout-section-divider{display:none !important;}</style>';
			}
		}
	}

    /**
	 * Function for `woocommerce_after_add_to_cart_button` express-pay action-hook for prdo.
	 * 
	 * @return void
	 */
	public function add_express_pay_at_product_page() {
        $plugin_public = new  Visa_Acceptance_Payment_Gateway_Unified_Checkout_Public($this->wc_payment_gateway_id,$this->gateway,$this->version)  ;
		$settings          = $plugin_public->get_uc_settings();
		$flex_request      = new Visa_Acceptance_Key_Generation( $this->gateway );
		$enable_gpay 	   = ( isset( $settings['enabled_payment_methods'] ) && is_array( $settings['enabled_payment_methods'] ) && in_array( 'enable_gpay', $settings['enabled_payment_methods'], true ) ) ? true : false;
		$enable_apay 	   = ( isset( $settings['enabled_payment_methods'] ) && is_array( $settings['enabled_payment_methods'] ) && in_array( 'enable_apay', $settings['enabled_payment_methods'], true ) ) ? true : false;
		$enable_paze 	   = ( isset( $settings['enabled_payment_methods'] ) && is_array( $settings['enabled_payment_methods'] ) && in_array( 'enable_paze', $settings['enabled_payment_methods'], true ) ) ? true : false;
		$payer_auth_enable = ! empty( $settings['enable_threed_secure'] ) ? $settings['enable_threed_secure'] : VISA_ACCEPTANCE_STRING_EMPTY;
		$force_tokenization = $this->gateway->is_subscriptions_activated && class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( wc_get_product( get_the_ID() ) );
		
		// Check if product is variable - hide Express Pay until variation is selected.
		$product = wc_get_product( get_the_ID() );
		$is_variable_product = $product && ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) );
		$is_grouped = $product && $product->is_type( 'grouped' );
		
		//if ( ( $enable_gpay || $enable_apay || $enable_paze ) && is_product() && is_user_logged_in()) {
		if ( ( $enable_gpay || $enable_apay || $enable_paze ) && is_product()) {
			$user_id 	= get_current_user_id();
			$customer 	= new WC_Customer( $user_id );
			$billing  	= $customer->get_billing();  // array with all billing fields.
			$shipping 	= $customer->get_shipping(); // array with all shipping fields.
			$ep_title        = __( 'Express Checkout', 'visa-acceptance-solutions' );
			wp_enqueue_script( VISA_ACCEPTANCE_GATEWAY_ID_UNDERSCORE . $this->wc_payment_gateway_id . '-product', plugin_dir_url( __FILE__ ) . 'js/visa-acceptance-payment-gateway-express-pay-product-page.js', array( 'jquery' ), $this->version, true );
			$express_pay_hidden = $this->hide_express_pay_product_page_tokenization_disabled();
			if ( ! $express_pay_hidden ) {
				$response = $flex_request->get_unified_checkout_capture_context(true);
			}
			$capture_context = ! empty( $response['body'] ) ? $response['body'] : VISA_ACCEPTANCE_STRING_EMPTY;
			$msg_failed 	 = (array)$capture_context;
			if (array_key_exists("response", $msg_failed)) {
				$capture_context = wp_json_encode($capture_context);
			}
			$plugin_public->add_uc_token( $capture_context );
			$client_library = $plugin_public->get_uc_client_library($capture_context);
			if ( ! empty( $client_library['url'] ) ) {
				wp_enqueue_script( 'unified-checkout-library', $client_library['url'], array(), $this->version, false );// phpcs:ignore WordPress.Security.NonceVerification
				// Store SRI integrity attribute if available.
				if ( ! empty( $client_library['integrity'] ) ) {
					Visa_Acceptance_Payment_Gateway_Unified_Checkout_Public::set_script_integrity( 'unified-checkout-library', $client_library['integrity'] );
				}
			}
			if ( ! empty( $capture_context['capture_context'] ) ) {
				echo '<input type="hidden" id ="jwt" value="' . esc_attr( $capture_context['capture_context'] ) . '"/>';
			}
			
			// Hide Express Pay for variable products until variation is selected and for grouped products until a product is selected.
			$hide_style = ( $is_variable_product || $is_grouped ) ? ' style="display:none;"' : '';
			?>
			<div id="wc-express-checkout-product"<?php echo $hide_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<div id="wc-express-checkout-section">
					<div id="express-checkout-heading">
						<span><?php echo esc_html( $ep_title ); ?></span>
						<hr>
					</div>
					<div id="expressPaymentListContainer_product"></div>
					<div>
						<input type="hidden" id="transientToken" name="transientToken" value="' . esc_attr($_GET['transientToken']) . '"/>
					</div>
					<div>
						<input type="hidden" id="errorMessage" name="errorMessage"/>
					</div>
					<?php
					$product_cost = null;
					$capture_context_component = explode( VISA_ACCEPTANCE_FULL_STOP, $capture_context );
					if ( VISA_ACCEPTANCE_VAL_THREE === count( $capture_context_component ) ) {
						$decoded_payload = json_decode( base64_decode( $capture_context_component[ VISA_ACCEPTANCE_VAL_ONE ] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
						if ( isset( $decoded_payload ) ) {
							$product_cost = $decoded_payload['ctx'][ VISA_ACCEPTANCE_VAL_ZERO ]['data']['orderInformation']['amountDetails']['totalAmount'];
						}
					}
					$this->show_notice_applicable_tax_shipping_at_product_page();
					if ( VISA_ACCEPTANCE_YES === $settings['tokenization'] && $force_tokenization ) {
						ob_start();
						wc_print_notice( esc_html__( 'Item in your order is a subscription/recurring purchase. By continuing with payment, you agree that your payment method will be automatically charged at the price and frequency listed here until it ends or you cancel.', 'visa-acceptance-solutions' ), 'notice' );
						$response = ob_get_clean();
						echo '<div id="wc-express-checkout-product-page-save-token-div">' . wp_kses_post( $response ) . '</div>';
					}

					$failure_error = __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' );
					echo '<div id="wc-error-failure" style="display:none;color:red">' .
					'<p class="failure-error-message" id="wc-failure-error"> ' . esc_html( $failure_error ) . ' </p>' .
					'</div>';
					?>
				</div>
			</div>
			<?php

			// Check if product is grouped.
			$is_grouped = $product && $product->is_type( 'grouped' );
			$grouped_product_ids = array();
			if ( $is_grouped ) {
				$grouped_product_ids = $product->get_children();
			}
			
			wp_localize_script(
				VISA_ACCEPTANCE_GATEWAY_ID_UNDERSCORE . $this->wc_payment_gateway_id . '-product',
				// Normal checkout ajax array.
				'express_pay_ajaxUCObj',
				array(
					'ajax_url'                        => admin_url( 'admin-ajax.php' ),
					'payment_method'                  => VISA_ACCEPTANCE_GATEWAY_UC,
					'payer_auth_enabled'              => $payer_auth_enable,
					'product_id'                      => get_the_ID(),
					'billing_details'                 => $billing,
					'shipping_details'                => $shipping,
					'nonce'                           => wp_create_nonce( 'validate_customer_postcode_express_pay' ),
					'customer_postalcode'             => WC()->customer->get_shipping_postcode(),
					'visa_acceptance_solutions_uc_id' => VISA_ACCEPTANCE_UC_ID,
					'visa_acceptance_solutions_uc_id_hyphen' => VISA_ACCEPTANCE_UC_ID_HYPHEN,
					'encrypt_const'                   => __( 'encrypt', 'visa-acceptance-solutions' ),
					'form_load_error'                 => __( 'Unable to load the payment form. Please contact customer care for any assistance.', 'visa-acceptance-solutions' ),
					'error_failure'                   => __( 'Unable to process your request. Please try again later.', 'visa-acceptance-solutions' ),
					'is_grouped_product'              => $is_grouped,
					'grouped_product_ids'             => $grouped_product_ids,
				)
			);
			if ( VISA_ACCEPTANCE_YES === $payer_auth_enable ) {
				wp_enqueue_style( $this->wc_payment_gateway_id . VISA_ACCEPTANCE_UNDERSCORE_PAYER_AUTH, plugin_dir_url( __FILE__ ) . 'css/visa-acceptance-payment-gateway-payer-auth-public.css', array(), $this->version, VISA_ACCEPTANCE_STRING_ALL );
				wp_enqueue_script( $this->wc_payment_gateway_id . VISA_ACCEPTANCE_UNDERSCORE_PAYER_AUTH, plugin_dir_url( __FILE__ ) . 'js/visa-acceptance-payment-gateway-payer-auth-public.js', array( 'jquery' ), $this->version, false );
				$plugin_public->load_payer_auth_script( $payer_auth_enable, $settings );
			}
		}
	}

	/**
	 * Function to hide express pay section on product page if subscription tokenization is disabled.
	 * 
	 * @return boolean
	 */
	private function hide_express_pay_product_page_tokenization_disabled() {
		$product = wc_get_product();
		$subscription_order = ( $product && $product->get_type() === 'subscription' ) ? true : false;
		$is_subscriptions_tokenization_enabled = $this->gateway->is_subscriptions_activated;
		$value = false;
		if ( ! $is_subscriptions_tokenization_enabled && $subscription_order ) {
			echo '<style>#wc-express-checkout-product{display:none !important;}</style>';
			ob_start();
			wc_print_notice( esc_html__( 'There are no payment methods available. Please contact us for help placing your order.', 'visa-acceptance-solutions' ), 'error' );
			$response = ob_get_clean();
			echo '<div id="wc-express-checkout-product-page-subscription-tokenization-notice-div">' . wp_kses_post( $response ) . '</div>';
			$value = true;
		}
		return $value;
	}

	/**
	 * Function for `wp_ajax_product_page_quantity_update` express-pay action-hook for product page.
	 * 
	 * @return int
	 */
	public function product_page_quantity_update() {
		$plugin_public = new  Visa_Acceptance_Payment_Gateway_Unified_Checkout_Public($this->wc_payment_gateway_id,$this->gateway,$this->version)  ;
		$post 		= $_POST; // phpcs:ignore WordPress.Security.NonceVerification
		$product_id = isset( $post['product_id'] ) ? intval( $post['product_id'] ) : 0;
		$quantity   = isset( $post['quantity'] ) ? intval( $post['quantity'] ) : VISA_ACCEPTANCE_ONE;
		$grouped_items_raw = isset( $post['grouped_items'] ) ? $post['grouped_items'] : array();
		$grouped_items = array();
		// Sanitize grouped items.
		if ( is_array( $grouped_items_raw ) ) {
			foreach ( $grouped_items_raw as $item_id => $item_qty ) {
				$grouped_items[ intval( $item_id ) ] = intval( $item_qty );
			}
		}
		$force_refresh = isset( $post['force_refresh'] ) ? intval( $post['force_refresh'] ) : 0;
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => 'Missing product ID' ), 400 );
		}
		$return_response = array(
			'success'         		 => true,
			'capture_context_ep_jwt' => null,
		);
		$product_order_total    = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$total_amount           = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_capture_context_total_amount" );
		$key_generation_request = new Visa_Acceptance_Key_Generation_Request( $this->gateway );
		$flex_request           = new Visa_Acceptance_Key_Generation( $this->gateway );
		// Build address array using logged-in user's shipping info.
		$address = array(
			'country'   => WC()->customer->get_shipping_country(),
			'state'     => WC()->customer->get_shipping_state(),
			'postcode'  => WC()->customer->get_shipping_postcode(),
			'city'      => WC()->customer->get_shipping_city(),
			'address_1' => WC()->customer->get_shipping_address(),
			'address_2' => WC()->customer->get_shipping_address_2(),
		);
		
		// Check if this is a grouped product.
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'grouped' ) && ! empty( $grouped_items ) ) {
			$product_order_total = $key_generation_request->get_grouped_product_total_amount( $grouped_items, $address );
		} else {
			$product_page_total_amount = $key_generation_request->get_product_page_total_amount($product_id, $quantity,$address);
			$product_order_total	   = isset( $product_page_total_amount['total_amount'] ) ? $product_page_total_amount['total_amount'] : $product_page_total_amount;
		}
		
		// Check if product is a subscription with trial.
		$product = wc_get_product($product_id);
		$is_trial_subscription = false;
		if ( class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
			$trial_length = WC_Subscriptions_Product::get_trial_length( $product_id );
			$is_trial_subscription = $trial_length > 0;
		}
		
		// Generate new capture context if amount changed OR if it's a trial subscription OR if force refresh requested.
		// Also generate when there is no existing total (common for guest users).
        if ( $force_refresh || $is_trial_subscription || empty( $total_amount ) || (string) $product_order_total !== (string) $total_amount ) { // phpcs:ignore WordPress.Security.NonceVerification.
			$return_response['success'] = false;
			$response_ep_jwt 			= $flex_request->get_unified_checkout_capture_context(true, $product_id, $quantity, $grouped_items);
			// Handle Express Pay JWT response.
            if ( isset( $response_ep_jwt['http_code'] ) && VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $response_ep_jwt['http_code'] ) {
                $capture_context_ep_jwt = ! empty( $response_ep_jwt['body'] ) ? $response_ep_jwt['body'] : VISA_ACCEPTANCE_STRING_EMPTY;
                $return_response['capture_context_ep_jwt'] = $capture_context_ep_jwt;
                $client_library = $plugin_public->get_uc_client_library($capture_context_ep_jwt);
                if ( ! empty( $client_library['url'] ) ) {
                    wp_enqueue_script( 'unified-checkout-library', $client_library['url'], array(), $this->version, false );// phpcs:ignore WordPress.Security.NonceVerification
                    // Store SRI integrity attribute if available.
                    if ( ! empty( $client_library['integrity'] ) ) {
                        Visa_Acceptance_Payment_Gateway_Unified_Checkout_Public::set_script_integrity( 'unified-checkout-library', $client_library['integrity'] );
                    }
                }
            }
        }
            
        // Set success to true if at least one context is available.
        if ( ! empty( $return_response['capture_context_ep_jwt'] ) ) {
            $return_response['success'] = true;
        }
		return wp_send_json($return_response);
	}

	/**
	 * Extract billing and shipping addresses from transient token
	 * 
	 * @param string $transient_token Transient token.
	 * @return array Array with 'billing' and 'shipping' keys containing address arrays.
	 */
	private function get_addresses_from_transient_token( $transient_token ) {
		$billing = array();
		$shipping = array();
		
		if ( empty( $transient_token ) ) {
			return array( 'billing' => $billing, 'shipping' => $shipping );
		}
		
		$payment_uc = new Visa_Acceptance_Payment_UC( $this->gateway );
		$payment_details_response = $payment_uc->get_payment_details_from_transient_token( $transient_token );
		
		if ( ! $payment_details_response || ! isset( $payment_details_response['body'] ) ) {
			return array( 'billing' => $billing, 'shipping' => $shipping );
		}
		
		$payment_details = $payment_details_response['body'];
		
		// Extract billing address.
		if ( isset( $payment_details->orderInformation ) && isset( $payment_details->orderInformation->billTo ) ) {
			$bill_to = $payment_details->orderInformation->billTo;
			
			$billing = array(
				'first_name' => isset( $bill_to->firstName ) ? sanitize_text_field( $bill_to->firstName ) : '',
				'last_name'  => isset( $bill_to->lastName ) ? sanitize_text_field( $bill_to->lastName ) : '',
				'company'    => '',
				'address_1'  => isset( $bill_to->address1 ) ? sanitize_text_field( $bill_to->address1 ) : '',
				'address_2'  => isset( $bill_to->address2 ) ? sanitize_text_field( $bill_to->address2 ) : '',
				'city'       => isset( $bill_to->locality ) ? sanitize_text_field( $bill_to->locality ) : '',
				'postcode'   => isset( $bill_to->postalCode ) ? sanitize_text_field( $bill_to->postalCode ) : '',
				'country'    => isset( $bill_to->country ) ? sanitize_text_field( $bill_to->country ) : '',
				'state'      => isset( $bill_to->administrativeArea ) ? sanitize_text_field( $bill_to->administrativeArea ) : '',
				'email'      => isset( $bill_to->email ) ? sanitize_email( $bill_to->email ) : '',
				'phone'      => isset( $bill_to->phoneNumber ) ? sanitize_text_field( $bill_to->phoneNumber ) : '',
			);
		}
		
		// Extract shipping address.
		if ( isset( $payment_details->orderInformation ) && isset( $payment_details->orderInformation->shipTo ) ) {
			$ship_to = $payment_details->orderInformation->shipTo;
			
			$shipping = array(
				'first_name' => isset( $ship_to->firstName ) ? sanitize_text_field( $ship_to->firstName ) : '',
				'last_name'  => isset( $ship_to->lastName ) ? sanitize_text_field( $ship_to->lastName ) : '',
				'company'    => '',
				'address_1'  => isset( $ship_to->address1 ) ? sanitize_text_field( $ship_to->address1 ) : '',
				'address_2'  => isset( $ship_to->address2 ) ? sanitize_text_field( $ship_to->address2 ) : '',
				'city'       => isset( $ship_to->locality ) ? sanitize_text_field( $ship_to->locality ) : '',
				'postcode'   => isset( $ship_to->postalCode ) ? sanitize_text_field( $ship_to->postalCode ) : '',
				'country'    => isset( $ship_to->country ) ? sanitize_text_field( $ship_to->country ) : '',
				'state'      => isset( $ship_to->administrativeArea ) ? sanitize_text_field( $ship_to->administrativeArea ) : '',
				'phone'      => isset( $ship_to->phoneNumber ) ? sanitize_text_field( $ship_to->phoneNumber ) : '',
			);
		}
		
		return array( 'billing' => $billing, 'shipping' => $shipping );
	}

	/**
	 * AJAX handler to get addresses from transient token for blocks checkout
	 * 
	 * @return void
	 */
	public function ajax_get_addresses_from_transient_token() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$transient_token = isset( $_POST['transientToken'] ) ? sanitize_text_field( wp_unslash( $_POST['transientToken'] ) ) : '';
		
		if ( empty( $transient_token ) ) {
			wp_send_json_error( array( 'message' => 'Transient token is required.' ) );
			return;
		}
		
		$addresses = $this->get_addresses_from_transient_token( $transient_token );
		
		wp_send_json_success( $addresses );
	}

	/**
	 * Function for `wp_ajax_express_pay_for_order` express-pay action-hook for product page.
	 * 
	 * @return void
	 */
	public function express_pay_product_page_pay_for_order() {
		$post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification
		$product_id 		= isset($post['product_id']) ? intval($post['product_id']) : 0;
		$transient_token 	= isset($post['transientToken']) ?  $post['transientToken'] : '';
		// Get billing and shipping from transient token if available.
		$addresses = $this->get_addresses_from_transient_token( $transient_token );
		$billing_from_token = $addresses['billing'];
		$shipping_from_token = $addresses['shipping'];
		
		// Use transient token data if available, otherwise fallback to frontend data.
		$billing_from_frontend = isset($post['billing_details']) ? $post['billing_details'] : array();
		$shipping_from_frontend = isset($post['shipping_details']) ? $post['shipping_details'] : array();
		
		$billing 			= ! empty( $billing_from_token ) ? $billing_from_token : $billing_from_frontend;
		$shipping 			= ! empty( $shipping_from_token ) ? $shipping_from_token : $shipping_from_frontend;
		$payment_method 	= isset($post['payment_method']) ? sanitize_text_field($post['payment_method']) : '';
		$payer_auth_enabled = isset($post['payer_auth_enabled']) ? $post['payer_auth_enabled'] : false ;
		$transient_token 	= isset($post['transientToken']) ?  $post['transientToken'] : '';
		$token_id 			= isset($post['token_id']) ? $post['token_id'] : '';
		$customer_id 		= get_current_user_id();
		$quantity   		= isset($post['quantity']) ? intval($post['quantity']) : VISA_ACCEPTANCE_ONE;
		
		$grouped_items_raw  = isset($post['grouped_items']) ? $post['grouped_items'] : array();
		$grouped_items = array();
		// Sanitize grouped items.
		if ( is_array( $grouped_items_raw ) ) {
			foreach ( $grouped_items_raw as $item_id => $item_qty ) {
				$grouped_items[ intval( $item_id ) ] = intval( $item_qty );
			}
		}
		
		// For variable products, use variation_id if provided.
		$variation_id 		= isset($post['variation_id']) ? intval($post['variation_id']) : 0;
		$product_id_to_add = $variation_id ? $variation_id : $product_id;

		$payment_gateway_unified_checkout = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$key_generation_request 		    = new Visa_Acceptance_Key_Generation_Request( $this->gateway );
        $subscription_active    = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
		
		// Check if this is a grouped product.
		$product = wc_get_product( $product_id );
		$is_grouped_product = $product && $product->is_type( 'grouped' );

		if ($is_grouped_product && !empty($grouped_items)) {
			// Handle grouped product order creation.
			$this->process_grouped_product_order($grouped_items, $customer_id, $billing, $shipping, $payment_method, $payer_auth_enabled, $transient_token, $token_id, $subscription_active);
		} elseif ($product_id_to_add) {
			// Add product to order.
			$product = wc_get_product($product_id_to_add);
			$product_price = $product->get_price() * $quantity;

			if ( ! $product ) {
				wp_send_json_error( array( 'message' => 'Invalid product.' ) );
			}

			if ( class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product ) && $subscription_active ) {
				$trial_length = WC_Subscriptions_Product::get_trial_length( $product_id_to_add );
				$trial_period = WC_Subscriptions_Product::get_trial_period( $product_id_to_add );
				// Sync and prorate settings. 
				$synchronise_renewal = get_option('woocommerce_subscriptions_sync_payments', 'no');
				$prorate_synced_payments = get_option('woocommerce_subscriptions_prorate_synced_payments', 'no');
				$signup_fee = (float) get_post_meta( $product->get_id(), '_subscription_sign_up_fee', true );
				$signup_fee_total = $signup_fee * $quantity;
				$is_prorated = false;
				if ( VISA_ACCEPTANCE_YES === $synchronise_renewal ) {
					if ( VISA_ACCEPTANCE_NO === $prorate_synced_payments ) {
						// Never (do not charge any recurring amount) - only signup fee.
						$product_price = $signup_fee_total > 0 ? $signup_fee_total : VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
					} elseif ( VISA_ACCEPTANCE_YES === $prorate_synced_payments || 'virtual' === $prorate_synced_payments ) {
						// Prorate subscription product (all or virtual only).
						$should_prorate = ( VISA_ACCEPTANCE_YES === $prorate_synced_payments ) || ( 'virtual' === $prorate_synced_payments && $product->is_virtual() );
						
						if ( $should_prorate ) {
							$period = WC_Subscriptions_Product::get_period( $product );
							$interval = WC_Subscriptions_Product::get_interval( $product );
							$unit_price = $product->get_price();
							$key_generation_request = new Visa_Acceptance_Key_Generation_Request( $this->gateway );
							$next_sync = $key_generation_request->get_next_synchronised_date_for_product( $product_id );
							$prorated_price = $key_generation_request->calculate_prorated_amount( $unit_price, $quantity, $period, $interval, $next_sync );
							$product_price = $prorated_price + $signup_fee_total;
							$is_prorated = true;
						} else {
							// Virtual proration but product is not virtual - charge full amount + signup fee.
							$product_price = $product_price + $signup_fee_total;
						}
					}
				}
				// Create subscription parent order.
				$order = wc_create_order( array(
					'customer_id' => $customer_id,
					'status'      => 'pending',
				) );
			} else {
				// Normal order.
				$order = wc_create_order();
			}
			$item_id = $order->add_product($product, $quantity);
			// Add shipping to order.
			$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
			$packages = WC()->shipping->calculate_shipping( WC()->cart->get_shipping_packages() );
			
			// For free trial products or empty cart, build package manually.
			if ( empty( $packages ) || ( isset($trial_length) && $trial_length > VISA_ACCEPTANCE_VAL_ZERO ) ) {
				$packages[] = array(
					'contents'        => array(
						'product_' . $product_id_to_add => array(
							'data'     => $product,
							'quantity' => $quantity,
						)
					),
					'contents_cost'   => $product->get_price() * $quantity,
					'applied_coupons' => array(),
					'destination'     => array(
						'country'   => ! empty( $shipping['country'] ) ? $shipping['country'] : WC()->customer->get_shipping_country(),
						'state'     => ! empty( $shipping['state'] ) ? $shipping['state'] : WC()->customer->get_shipping_state(),
						'postcode'  => ! empty( $shipping['postcode'] ) ? $shipping['postcode'] : WC()->customer->get_shipping_postcode(),
						'city'      => ! empty( $shipping['city'] ) ? $shipping['city'] : WC()->customer->get_shipping_city(),
						'address'   => ! empty( $shipping['address_1'] ) ? $shipping['address_1'] : WC()->customer->get_shipping_address(),
						'address_2' => ! empty( $shipping['address_2'] ) ? $shipping['address_2'] : WC()->customer->get_shipping_address_2(),
					),
				);
				$packages = WC()->shipping->calculate_shipping( $packages );
			}
			
			// If no shipping method in session, get first available method from packages.
			if ( empty( $chosen_methods ) && ! empty( $packages ) ) {
				$min_cost = null;
				$min_rate = null;
				
				foreach ( $packages as $package ) {
					if ( ! empty( $package['rates'] ) ) {
						foreach ( $package['rates'] as $rate ) {
							$rate_cost = (float) $rate->get_cost();
							// Only consider rates with non-zero cost.
							if ( $rate_cost > 0 && ( null === $min_cost || $rate_cost < $min_cost ) ) {
								$min_cost = $rate_cost;
								$min_rate = $rate;
							}
						}
					}
				}
				
				// If no non-zero rate found, fall back to first available rate.
				if ( null === $min_rate ) {
					foreach ( $packages as $package ) {
						if ( ! empty( $package['rates'] ) ) {
							$first_rate = reset( $package['rates'] );
							$chosen_methods = array( $first_rate->get_id() );
							break;
						}
					}
				} else {
					$chosen_methods = array( $min_rate->get_id() );
				}
			}
			// Initialize flag to track if this is a placeholder amount order.
			$is_placeholder_amount = false;
			
			// Check if this is a free trial (no signup fee) - skip shipping.
			$skip_shipping_for_trial = false;
			if ( isset($trial_length) && $trial_length > 0 && isset($signup_fee_total) && $signup_fee_total <= 0 ) {
				$skip_shipping_for_trial = true;
			}
			
			// Check if this is a "Never" case with no signup fee (0.01 placeholder) - skip shipping.
			$skip_shipping_for_placeholder = false;
			if ( isset($synchronise_renewal) && isset($prorate_synced_payments) && isset($signup_fee_total) ) {
				$is_never_case_check = ( 'yes' === $synchronise_renewal && 'no' === $prorate_synced_payments );
				if ( $is_never_case_check && $signup_fee_total <= 0 && ! $product->is_virtual() ) {
					$skip_shipping_for_placeholder = true;
				}
			}
			
			// Skip shipping for virtual products, free trial products, or placeholder amount products.
			if ( ! empty( $chosen_methods ) && ! $product->is_virtual() && ! $skip_shipping_for_trial && ! $skip_shipping_for_placeholder ) {
				$chosen_method = $chosen_methods[0];
			
				// Get matching rate from packages.
				foreach ( $packages as $package ) {
					foreach ( $package['rates'] as $rate_id => $rate ) {
						if ( $rate_id === $chosen_method ) {
							// Create a shipping item.
							$shipping_item = new WC_Order_Item_Shipping();
							$shipping_item->set_method_title( $rate->get_label() );
							$shipping_item->set_method_id( $rate->get_id() );
							$shipping_item->set_total( $rate->get_cost() );
						
							// Taxes if applied.
							$shipping_item->set_taxes( array(
								'total' => $rate->get_taxes()
							));
							$order->add_item( $shipping_item );
						}
					}
				}
			}
			// Get signup fee - only for actual subscription products.
			if ( $subscription_active && class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
				$signup_fee   = (float) get_post_meta( $product->get_id(), '_subscription_sign_up_fee', true );
				$signup_fee_total = $signup_fee * $quantity;

				$product_sync_date = get_post_meta( $product_id_to_add, '_subscription_payment_sync_date', true );
				$product_do_not_sync = empty( $product_sync_date ) || '0' === $product_sync_date;
				
				// Check if this is a "Never" (no recurring charge) synchronized subscription.
				$synchronise_renewal = get_option('woocommerce_subscriptions_sync_payments', 'no');
				$prorate_synced_payments = get_option('woocommerce_subscriptions_prorate_synced_payments', 'no');
				$is_never_case = ( 'yes' === $synchronise_renewal && 'no' === $prorate_synced_payments );
				
				// Calculate total amount based on subscription type.
				if ( $product_do_not_sync ) {
					$total_amount = $signup_fee_total + ($product->get_price() * $quantity);
				} elseif ( $is_never_case ) {
					// Never case: only signup fee (or $0.00 if no signup fee).
					$total_amount = $signup_fee_total > VISA_ACCEPTANCE_VAL_ZERO ? $signup_fee_total : VISA_ACCEPTANCE_ZERO_AMOUNT;
					// Track if this is a zero amount order for non-virtual products.
					// Virtual products should still get tax but no shipping.
					if ( ($signup_fee > 0 || $is_never_case || isset($is_prorated)) && ! ($trial_length > 0)) {
						// Remove shipping items from order as no shipping should apply.
						foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
							$order->remove_item( $item_id );
						}
					}
				} elseif ( isset($is_prorated) && $is_prorated ) {
					// Proration case: product_price already includes signup fee.
					$total_amount = $product_price;
				} else {
					// Regular case: product price + signup fee.
					$total_amount = $signup_fee_total + $product_price;
				}

				if (( $signup_fee > 0 || $is_never_case || isset($is_prorated)) && ! ($trial_length > 0)) {
					// Remove any default items (the unwanted first row).
					foreach ( $order->get_items() as $itemn_id => $item ) {
						$order->remove_item( $itemn_id );
					}
					$item_fee = new WC_Order_Item_Product();

					$item_fee->set_product_id( $product_id );
					$item_fee->set_name( $product->get_name() );
					$item_fee->set_quantity( $quantity );
					$item_fee->set_subtotal( $total_amount );
					$item_fee->set_total( $total_amount );

				}

				if ( $trial_length > VISA_ACCEPTANCE_VAL_ZERO ) {
					$item    = $order->get_item( $item_id );
					$item->set_subtotal( 0 );
					$item->set_total( 0 );
					$item->save();
				}
			}
			
			// Set customer ID.
			if ($customer_id) {
				$order->set_customer_id($customer_id);
			}

			// Set billing details.
			if (! empty($billing)) {
				$order->set_address($billing, 'billing');
			}

			// Set shipping details.
			if (! empty($shipping)) {
				$order->set_address($shipping, 'shipping');
			}

			if (! empty($payment_method)) {
				$order->set_payment_method($payment_method);
			} else {
				$order->set_payment_method($this->wc_payment_gateway_id);
			}
			
			// Set payment method title so it displays on order received page.
			$payment_method_title = $this->gateway->get_title();
			if ( ! empty( $payment_method_title ) ) {
				$order->set_payment_method_title( $payment_method_title );
			}

			if (! $payer_auth_enabled) {
				$order->update_meta_data('_payer_auth_enabled', VISA_ACCEPTANCE_NO);
			} else {
				$order->update_meta_data('_payer_auth_enabled', VISA_ACCEPTANCE_YES);
			}

			if (! empty($transient_token)) {
				$order->update_meta_data('_transientToken', $transient_token);
				
				// Retrieve and update address from Google Pay using CyberSource API.
				$decoded_transient_token = json_decode( base64_decode( explode( '.', $transient_token )[VISA_ACCEPTANCE_ONE] ), true );
				if ( ! empty( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) && 
				     VISA_ACCEPTANCE_GPAY_PAYMENTSOLUTION_VALUE === $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) {
					$payment_uc = new Visa_Acceptance_Payment_UC( $this->gateway );
					$payment_details_response = $payment_uc->get_payment_details_from_transient_token( $transient_token );
					
					if ( $payment_details_response && isset( $payment_details_response['body'] ) ) {
						$payment_uc->update_order_addresses_from_payment_details( $order, $payment_details_response['body'] );
					}
				}
			}

			if (! empty($token_id)) {
				$order->update_meta_data('_token_id', $token_id);
			}

			$this->set_express_pay_order_attributes($order);

			if ( $subscription_active ) {
				if ( ! empty ($order) && WC_Subscriptions_Product::is_subscription( $product ) ) {

				if ( 0 === $customer_id ) {
					$billing_email = $order->get_billing_email();
					if ( ! empty( $billing_email ) ) {
						if ( email_exists( $billing_email ) ) {
							// Use existing account.
							$user = get_user_by( 'email', $billing_email );
							if ( $user ) {
								$customer_id = $user->ID;
							}
						} else {
							// Create new customer account.
							$customer_id = wc_create_new_customer( $billing_email, '', wp_generate_password() );
						}
						
						// Log the user in automatically.
						if ( $customer_id && ! is_wp_error( $customer_id ) ) {
							wp_set_current_user( $customer_id );
							wp_set_auth_cookie( $customer_id, true );
							
							// Update WooCommerce session and customer data.
							if ( WC()->session ) {
								WC()->session->set_customer_session_cookie( true );
							}
							WC()->customer->set_id( $customer_id );
							}
							
							// Update order with customer ID.
							$order->set_customer_id( $customer_id );
							$order->save();
					} else {
							wp_send_json_error( array( 'message' => 'Billing email is required for subscription orders.' ) );
							return;
					}
				}
				if ( 0 === $customer_id ) {
					$billing_email = $order->get_billing_email();
					if ( ! empty( $billing_email ) ) {
						if ( email_exists( $billing_email ) ) {
							// Use existing account.
							$user = get_user_by( 'email', $billing_email );
							if ( $user ) {
								$customer_id = $user->ID;
							}
						} else {
							// Create new customer account.
							$customer_id = wc_create_new_customer( $billing_email, '', wp_generate_password() );
						}
						
						// Log the user in automatically.
						if ( $customer_id && ! is_wp_error( $customer_id ) ) {
							wp_set_current_user( $customer_id );
							wp_set_auth_cookie( $customer_id, true );
							
							// Update WooCommerce session and customer data.
							if ( WC()->session ) {
								WC()->session->set_customer_session_cookie( true );
							}
							WC()->customer->set_id( $customer_id );
							}
							
							// Update order with customer ID.
							$order->set_customer_id( $customer_id );
							$order->save();
					} else {
							wp_send_json_error( array( 'message' => 'Billing email is required for subscription orders.' ) );
							return;
					}
				}
					
					// Get subscription details from product.
					$interval = WC_Subscriptions_Product::get_interval( $product );
					$period   = WC_Subscriptions_Product::get_period( $product );
					$length   = WC_Subscriptions_Product::get_length( $product );

					$start_date = gmdate( 'Y-m-d H:i:s' );
					$trial_end  = WC_Subscriptions_Product::get_trial_expiration_date( $product );
					$end_date   = ( $length > 0 ) ? WC_Subscriptions_Product::get_expiration_date( $product ) : 0;
					
					// Create subscription.
					$subscription = wcs_create_subscription ( array (
						'customer_id'        => $customer_id,
						'order_id'   		 => $order->get_id(),
						'billing_period'     => $period,
						'billing_interval'   => $interval,
						'start_date'         => $start_date,
						'trial_end_date'     => $trial_end,
						'trial_length'    	 =>	 $length,
						'end_date'           => $end_date,
						'status'             => 'pending',
					) );

					if ( is_wp_error( $subscription ) ) {
						wp_send_json_error( array( 'message' => 'Failed to create subscription order.' ) );
					}
				
					// Link subscription back to parent order.
					$subscription->set_parent_id( $order->get_id() );
					$subscription->add_product( $product, $quantity );
					if ( $trial_length > 0 ) {
						// Calculate trial end date.
						$trial_end = wcs_add_time( $trial_length, $trial_period, gmdate( 'U' ) );
					
						// Set next payment date to trial end.
						$subscription->update_dates( array(
							'trial_end'    => gmdate( 'Y-m-d H:i:s', $trial_end ),
							'next_payment' => gmdate( 'Y-m-d H:i:s', $trial_end ),
						) );
						if ( empty( $packages ) ) {
							$packages[] = array(
								'contents'        => WC()->cart->get_cart(),
								'contents_cost'   => WC()->cart->get_cart_contents_total(),
								'applied_coupons' => WC()->cart->get_applied_coupons(),
								'destination'     => array(
									'country'   => WC()->customer->get_shipping_country(),
									'state'     => WC()->customer->get_shipping_state(),
									'postcode'  => WC()->customer->get_shipping_postcode(),
									'city'      => WC()->customer->get_shipping_city(),
									'address'   => WC()->customer->get_shipping_address(),
									'address_2' => WC()->customer->get_shipping_address_2(),
								),
							);
						}
						$packages = WC()->shipping->calculate_shipping( $packages );
					} else {
						$packages = WC()->shipping->calculate_shipping( WC()->cart->get_shipping_packages() );
					}
					$subs_chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
					
					// If no shipping method in session, get first available method from packages.
					if ( empty( $subs_chosen_methods ) && ! empty( $packages ) ) {
						$min_cost = null;
						$min_rate = null;
						
						foreach ( $packages as $package ) {
							if ( ! empty( $package['rates'] ) ) {
								foreach ( $package['rates'] as $rate ) {
									$rate_cost = (float) $rate->get_cost();
									// Only consider rates with non-zero cost.
									if ( $rate_cost > 0 && ( null === $min_cost || $rate_cost < $min_cost ) ) {
										$min_cost = $rate_cost;
										$min_rate = $rate;
									}
								}
							}
						}
						
						// If no non-zero rate found, fall back to first available rate.
						if ( null === $min_rate ) {
							foreach ( $packages as $package ) {
								if ( ! empty( $package['rates'] ) ) {
									$first_rate = reset( $package['rates'] );
									$subs_chosen_methods = array( $first_rate->get_id() );
									break;
								}
							}
						} else {
							$subs_chosen_methods = array( $min_rate->get_id() );
						}
					}
					// Skip shipping for virtual products.
					if ( ! empty( $subs_chosen_methods ) && ! $product->is_virtual() ) {
						foreach($subs_chosen_methods as $shipping_key => $shipping_value) {
							// Get matching rate from packages.
							foreach ( $packages as $package ) {
								foreach ( $package['rates'] as $rate_id => $rate ) {
									if ( $rate_id === $shipping_value ) {
									
										// Create a shipping item.
										$sub_item = new WC_Order_Item_Shipping();
										$sub_item->set_method_title( $rate->get_label() );
										$sub_item->set_method_id( $rate->get_id() );
										$sub_item->set_total( $rate->get_cost() );
									
										// Taxes if applied.
										$sub_item->set_taxes( array(
											'total' => $rate->get_taxes()
										));
									}
								}
							}
						}
					}
					$subscription->set_address( $order->get_address( 'billing' ), 'billing' );
					$subscription->set_address( $order->get_address( 'shipping' ), 'shipping' );
					$subscription->set_payment_method( $order->get_payment_method() );
					$subscription->add_item($sub_item);
					$subscription->calculate_totals();
					$subscription->save();
				}
			}
			$order->add_item( $item_fee );
			// Calculate totals and save order.
			// Skip calculate_totals for placeholder amount (0.01) to prevent tax application.
			if(!($trial_length > VISA_ACCEPTANCE_VAL_ZERO) && !$is_placeholder_amount) {
				$order->calculate_totals();
				// Update authorization amount to match final order total if it was a placeholder.
				if ( $is_placeholder_amount && $order->get_total() > VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT ) {
					$order->update_meta_data( VISA_ACCEPTANCE_AUTH_AMOUNT, $order->get_total() );
				}
			}
			$order->save();

			$order_id = $order->get_id();
			$order    = wc_get_order( $order_id );

			$gateway_id = $this->gateway;
			if ( $gateway_id && method_exists( $gateway_id, 'process_payment' ) ) {
				$result = $gateway_id->process_payment( $order_id );
				wp_send_json_success(['redirect_url' => $result['redirect']]);
			} else {
				wp_send_json_error(array('message' => 'Payment gateway not found.'));
			}
		} else {
			wp_send_json_error(array('message' => 'Product ID is required.'));
		}
	}

	/**
	 * Function to set order attributes.
	 *
	 * @param WC_Order $order The order object to set attributes for.
	 */
	private function set_express_pay_order_attributes($order) {

		/**
		 * Helper function to parse SourceBuster cookie parts into key-value array.
		 *
		 * @param string $cookie_value The cookie value to parse.
		 * @return array Parsed cookie data.
		 */
		function visa_acceptance_parse_sbjs_cookie ($cookie_value) {
			$data = array();
			$parts = explode('|||', $cookie_value);
			foreach ($parts as $part) {
				if (strpos($part, '=') !== false) {
					list($key, $value) = explode('=', $part, 2);
					$data[$key] = $value;
				}
			}
			return $data;
		}

		// Default values.
		$source_type = 'typein'; // For Direct.
		$device_type = 'Desktop';
		$session_pages = 1;

		// Parse sbjs_session for page views with enhanced reliability.
		if (isset($_COOKIE['sbjs_session'])) {
			$cookie_value = sanitize_text_field( wp_unslash( $_COOKIE['sbjs_session'] ) );
			$session_data = visa_acceptance_parse_sbjs_cookie ($cookie_value);
			$pgs = $session_data['pgs'] ?? null;

			if (null === $pgs) {
				// Attempt base64 decode if plain parsing fails.
				$decoded = base64_decode($cookie_value, true); //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				if (false !== $decoded) {
					$session_data = visa_acceptance_parse_sbjs_cookie ($decoded);
					$pgs = $session_data['pgs'] ?? null;
				}
			}

			if (is_numeric($pgs)) {
				$session_pages = max(1, intval($pgs)); // Ensure at least 1 page view.
			} else {
				// Fallback: Extract number using regex with multiple patterns.
				$patterns = ['/pgs=(\d+)/', '/pages=(\d+)/']; // Handle variations in cookie format.
				foreach ($patterns as $pattern) {
					if (preg_match($pattern, $cookie_value, $matches)) {
						$session_pages = max(1, intval($matches[1]));
						break;
					}
				}
			}
		}

		// Device type: Enhanced detection from user agent with more tablet identifiers.
		$user_agent_lower = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) );
		if (preg_match('/(tablet|ipad|playbook|silk|kindle|surface|gt-p|sch-i|sgp|kfsowi)/i', $user_agent_lower)) {
			$device_type = 'Tablet';
		} elseif (wp_is_mobile()) {
			$device_type = 'Mobile';
		} else {
			$device_type = 'Desktop';
		}

		// Set only required attribution meta keys (hidden by default with underscore prefix).
		$order->update_meta_data('_wc_order_attribution_source_type', $source_type);
		$order->update_meta_data('_wc_order_attribution_device_type', $device_type);
		$order->update_meta_data('_wc_order_attribution_session_pages', $session_pages);
	}

	/**
	 * Function to show custom tax and shipping message on product page.
	 */
	private function show_notice_applicable_tax_shipping_at_product_page() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$display_prices_incl_tax = get_option('woocommerce_tax_display_shop') === 'incl';
		// Set base price based on tax display setting.
		if ( $display_prices_incl_tax ) {
			$base_price = (float) wc_get_price_including_tax( $product );
		} else {
			$base_price = (float) $product->get_price();
		}
		//Also considering shipping cost for saved customers with previous orders.
		$effective_price = (float) wc_get_price_including_tax( $product );
		if ( wc_shipping_enabled() && $product->needs_shipping() ) {
			$zones = WC_Shipping_Zones::get_zones();
			$shipping_cost = VISA_ACCEPTANCE_ZERO_AMOUNT;
			if ( ! empty( $zones ) ) {
				foreach ( $zones as $zone ) {
					foreach ( $zone['shipping_methods'] as $method ) {
						if ( VISA_ACCEPTANCE_YES === $method->enabled ) {
							$shipping_cost = (float) $method->get_option( 'cost', VISA_ACCEPTANCE_ZERO_AMOUNT );
						}
					}
				}
			}
			$effective_price += $shipping_cost;

		}
		if ( round( $base_price, wc_get_price_decimals() ) !== round( $effective_price, wc_get_price_decimals() ) ){
			ob_start();
			wc_print_notice( esc_html__( 'Note: This order may include shipping charges and applicable taxes.', 'visa-acceptance-solutions' ), 'notice' );
			$response = ob_get_clean();
			echo '<div id="wc-express-checkout-product-page-tax-shipping-notice">'. wp_kses_post( $response ) . '</div>';
		}
	}
	
	/**
	 * Process grouped product order creation with support for normal and subscription products.
	 * 
	 * @param array $grouped_items Array of product IDs and quantities.
	 * @param int $customer_id Customer ID.
	 * @param array $billing Billing address.
	 * @param array $shipping Shipping address.
	 * @param string $payment_method Payment method.
	 * @param bool $payer_auth_enabled Payer authentication enabled.
	 * @param string $transient_token Transient token.
	 * @param string $token_id Token ID.
	 * @param bool $subscription_active Subscription plugin active.
	 */
	private function process_grouped_product_order($grouped_items, $customer_id, $billing, $shipping, $payment_method, $payer_auth_enabled, $transient_token, $token_id, $subscription_active) {
		// Validate grouped items.
		if (empty($grouped_items)) {
			wp_send_json_error(array('message' => 'No products selected in grouped product.'));
		}

		$has_subscription = false;
		foreach ($grouped_items as $item_id => $item_qty) {
			if ($item_qty > 0 && class_exists('WC_Subscriptions_Product')) {
				$item_product = wc_get_product($item_id);
				if ($item_product && WC_Subscriptions_Product::is_subscription($item_product)) {
					$has_subscription = true;
					break;
				}
			}
		}
		if ($has_subscription && $subscription_active) {
			$order = wc_create_order(array(
				'customer_id' => $customer_id,
				'status'      => 'pending',
			));
		} else {
			$order = wc_create_order();
		}
		
		$subscription_products = array();
		foreach ($grouped_items as $item_id => $item_qty) {
			$item_qty = intval($item_qty);
			if ($item_qty <= 0) {
				continue;
			}
			
			$item_product = wc_get_product($item_id);
			if (!$item_product) {
				continue;
			}
			$is_subscription = class_exists('WC_Subscriptions_Product') && WC_Subscriptions_Product::is_subscription($item_product);
			
			if ($is_subscription && $subscription_active) {
				$subscription_products[] = array(
					'product' => $item_product,
					'quantity' => $item_qty,
					'product_id' => $item_id
				);
				$trial_length = WC_Subscriptions_Product::get_trial_length($item_id);
				$signup_fee = (float)get_post_meta($item_id, '_subscription_sign_up_fee', true);
				$signup_fee_total = $signup_fee * $item_qty;
				
				if ($trial_length > 0) {
					$item_id_obj = $order->add_product($item_product, $item_qty);
					$item = $order->get_item($item_id_obj);
					$item->set_subtotal(0);
					$item->set_total(0);
					$item->save();
					if ($signup_fee > 0) {
						$fee_item = new WC_Order_Item_Fee();
						$fee_item->set_name($item_product->get_name() . ' - Sign-up Fee');
						$fee_item->set_total($signup_fee_total);
						$order->add_item($fee_item);
					}
				} else {
					// Handle synchronization for subscription products.
					$product_sync_date = get_post_meta($item_id, '_subscription_payment_sync_date', true);
					$product_do_not_sync = empty($product_sync_date) || '0' === $product_sync_date;
					$synchronise_renewal = get_option('woocommerce_subscriptions_sync_payments', 'no');
					$prorate_synced_payments = get_option('woocommerce_subscriptions_prorate_synced_payments', 'no');
					$is_never_case = ('yes' === $synchronise_renewal && 'no' === $prorate_synced_payments);
					
					if ($product_do_not_sync) {
						// No synchronization - add product normally with signup fee.
						$order->add_product($item_product, $item_qty);
						if ($signup_fee > 0) {
							$fee_item = new WC_Order_Item_Fee();
							$fee_item->set_name($item_product->get_name() . ' - Sign-up Fee');
							$fee_item->set_total($signup_fee_total);
							$order->add_item($fee_item);
						}
					} elseif ($is_never_case) {
						// Never case: only charge signup fee.
						if ($signup_fee_total > VISA_ACCEPTANCE_VAL_ZERO) {
							// Add product with signup fee as a custom order item.
							$item_fee = new WC_Order_Item_Product();
							$item_fee->set_product_id($item_id);
							$item_fee->set_name($item_product->get_name());
							$item_fee->set_quantity($item_qty);
							$item_fee->set_subtotal($signup_fee_total);
							$item_fee->set_total($signup_fee_total);
							$order->add_item($item_fee);
						} elseif ($item_product->is_virtual()) {
							// For virtual products, charge full amount.
							$order->add_product($item_product, $item_qty);
						} else {
							// For non-virtual products with no signup fee, add placeholder.
							$item_fee = new WC_Order_Item_Product();
							$item_fee->set_product_id($item_id);
							$item_fee->set_name($item_product->get_name());
							$item_fee->set_quantity($item_qty);
							$item_fee->set_subtotal(VISA_ACCEPTANCE_ZERO_AMOUNT);
							$item_fee->set_total(VISA_ACCEPTANCE_ZERO_AMOUNT);
							$order->add_item($item_fee);
						}
					} elseif ('yes' === $prorate_synced_payments || 'virtual' === $prorate_synced_payments) {
						// Proration case.
						$should_prorate = ('yes' === $prorate_synced_payments) || ('virtual' === $prorate_synced_payments && $item_product->is_virtual());
						
						if ($should_prorate) {
							$period = WC_Subscriptions_Product::get_period($item_product);
							$interval = WC_Subscriptions_Product::get_interval($item_product);
							$unit_price = $item_product->get_price();
							$key_generation_request = new Visa_Acceptance_Key_Generation_Request($this->gateway);
							$next_sync = $key_generation_request->get_next_synchronised_date_for_product($item_id);
							$prorated_price = $key_generation_request->calculate_prorated_amount($unit_price, $item_qty, $period, $interval, $next_sync);
							
							// Calculate total with signup fee.
							$total_amount = $prorated_price + $signup_fee_total;
							
							// Add as custom order item with combined amount.
							$item_fee = new WC_Order_Item_Product();
							$item_fee->set_product_id($item_id);
							$item_fee->set_name($item_product->get_name());
							$item_fee->set_quantity($item_qty);
							$item_fee->set_subtotal($total_amount);
							$item_fee->set_total($total_amount);
							$order->add_item($item_fee);
						} else {
							// Virtual proration but product is not virtual - charge full amount.
							$order->add_product($item_product, $item_qty);
							if ($signup_fee > 0) {
								$fee_item = new WC_Order_Item_Fee();
								$fee_item->set_name($item_product->get_name() . ' - Sign-up Fee');
								$fee_item->set_total($signup_fee_total);
								$order->add_item($fee_item);
							}
						}
					} else {
						// Regular case: charge full recurring amount + signup fee.
						$order->add_product($item_product, $item_qty);
						if ($signup_fee > 0) {
							$fee_item = new WC_Order_Item_Fee();
							$fee_item->set_name($item_product->get_name() . ' - Sign-up Fee');
							$fee_item->set_total($signup_fee_total);
							$order->add_item($fee_item);
						}
					}
				}
			} else {
				// Normal product.
				$order->add_product($item_product, $item_qty);
			}
		}
		
		// Add shipping.
		$chosen_methods = WC()->session->get('chosen_shipping_methods');
		$packages = WC()->shipping->calculate_shipping(WC()->cart->get_shipping_packages());
		
		if (empty($packages)) {
			$packages = $this->build_shipping_packages_for_grouped($grouped_items, $shipping);
			$packages = WC()->shipping->calculate_shipping($packages);
		}
		
		$this->add_shipping_to_order($order, $packages, $chosen_methods);
		
		// Set customer and address details.
		if ($customer_id) {
			$order->set_customer_id($customer_id);
		}
		if (!empty($billing)) {
			$order->set_address($billing, 'billing');
		}
		if (!empty($shipping)) {
			$order->set_address($shipping, 'shipping');
		}
		
		// Set payment method.
		if (!empty($payment_method)) {
			$order->set_payment_method($payment_method);
		} else {
			$order->set_payment_method($this->wc_payment_gateway_id);
		}
		
		$payment_method_title = $this->gateway->get_title();
		if (!empty($payment_method_title)) {
			$order->set_payment_method_title($payment_method_title);
		}
		
		// Set order meta.
		if (!$payer_auth_enabled) {
			$order->update_meta_data('_payer_auth_enabled', VISA_ACCEPTANCE_NO);
		} else {
			$order->update_meta_data('_payer_auth_enabled', VISA_ACCEPTANCE_YES);
		}
		if (!empty($transient_token)) {
			$order->update_meta_data('_transientToken', $transient_token);
			
			// Retrieve and update address from Google Pay using CyberSource API.
			$decoded_transient_token = json_decode( base64_decode( explode( '.', $transient_token )[VISA_ACCEPTANCE_ONE] ), true );
			if ( ! empty( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) && 
			     VISA_ACCEPTANCE_GPAY_PAYMENTSOLUTION_VALUE === $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) {
				$payment_uc = new Visa_Acceptance_Payment_UC( $this->gateway );
				$payment_details_response = $payment_uc->get_payment_details_from_transient_token( $transient_token );
				
				if ( $payment_details_response && isset( $payment_details_response['body'] ) ) {
					$payment_uc->update_order_addresses_from_payment_details( $order, $payment_details_response['body'] );
				}
			}
		}
		if (!empty($token_id)) {
			$order->update_meta_data('_token_id', $token_id);
		}
		
		$this->set_express_pay_order_attributes($order);
		
		// Process subscriptions if any.
		if ($subscription_active && !empty($subscription_products)) {
			$this->create_subscriptions_for_grouped($order, $subscription_products, $customer_id, $shipping, $packages);
		}
		
		// Calculate totals and save.
		$order->calculate_totals();
		$order->save();
		
		$order_id = $order->get_id();
		$order = wc_get_order($order_id);
		$gateway_id = $this->gateway;
		if ($gateway_id && method_exists($gateway_id, 'process_payment')) {
			$result = $gateway_id->process_payment($order_id);
			wp_send_json_success(['redirect_url' => $result['redirect']]);
		} else {
			wp_send_json_error(array('message' => 'Payment gateway not found.'));
		}
	}
	
	/**
	 * Build shipping packages for grouped products.
	 * 
	 * @param array $grouped_items Grouped product items.
	 * @param array $shipping Shipping address.
	 * @return array Shipping packages.
	 */
	private function build_shipping_packages_for_grouped($grouped_items, $shipping) {
		$contents = array();
		$contents_cost = 0;
		
		foreach ($grouped_items as $item_id => $item_qty) {
			if ($item_qty <= 0) continue;
			
			$item_product = wc_get_product($item_id);
			if (!$item_product) continue;
			
			$contents['product_' . $item_id] = array(
				'data' => $item_product,
				'quantity' => $item_qty,
			);
			$contents_cost += $item_product->get_price() * $item_qty;
		}
		
		return array(
			array(
				'contents' => $contents,
				'contents_cost' => $contents_cost,
				'applied_coupons' => array(),
				'destination' => array(
					'country' => !empty($shipping['country']) ? $shipping['country'] : WC()->customer->get_shipping_country(),
					'state' => !empty($shipping['state']) ? $shipping['state'] : WC()->customer->get_shipping_state(),
					'postcode' => !empty($shipping['postcode']) ? $shipping['postcode'] : WC()->customer->get_shipping_postcode(),
					'city' => !empty($shipping['city']) ? $shipping['city'] : WC()->customer->get_shipping_city(),
					'address' => !empty($shipping['address_1']) ? $shipping['address_1'] : WC()->customer->get_shipping_address(),
					'address_2' => !empty($shipping['address_2']) ? $shipping['address_2'] : WC()->customer->get_shipping_address_2(),
				),
			)
		);
	}
	
	/**
	 * Add shipping to order.
	 * 
	 * @param WC_Order $order Order object.
	 * @param array $packages Shipping packages.
	 * @param array $chosen_methods Chosen shipping methods.
	 */
	private function add_shipping_to_order($order, $packages, $chosen_methods) {
		if (empty($chosen_methods) && !empty($packages)) {
			$min_cost = null;
			$min_rate = null;
			
			foreach ($packages as $package) {
				if (!empty($package['rates'])) {
					foreach ($package['rates'] as $rate) {
						$rate_cost = (float)$rate->get_cost();
						if ($rate_cost > 0 && (null === $min_cost || $rate_cost < $min_cost)) {
							$min_cost = $rate_cost;
							$min_rate = $rate;
						}
					}
				}
			}
			
			if (null === $min_rate) {
				foreach ($packages as $package) {
					if (!empty($package['rates'])) {
						$first_rate = reset($package['rates']);
						$chosen_methods = array($first_rate->get_id());
						break;
					}
				}
			} else {
				$chosen_methods = array($min_rate->get_id());
			}
		}
		
		if (!empty($chosen_methods)) {
			$chosen_method = $chosen_methods[0];
			foreach ($packages as $package) {
				foreach ($package['rates'] as $rate_id => $rate) {
					if ($rate_id === $chosen_method) {
						$shipping_item = new WC_Order_Item_Shipping();
						$shipping_item->set_method_title($rate->get_label());
						$shipping_item->set_method_id($rate->get_id());
						$shipping_item->set_total($rate->get_cost());
						$shipping_item->set_taxes(array('total' => $rate->get_taxes()));
						$order->add_item($shipping_item);
						return;
					}
				}
			}
		}
	}
	
	/**
	 * Create subscriptions for grouped products.
	 * 
	 * @param WC_Order $order Parent order.
	 * @param array $subscription_products Subscription products array.
	 * @param int $customer_id Customer ID.
	 * @param array $shipping Shipping address.
	 * @param array $packages Shipping packages.
	 */
	private function create_subscriptions_for_grouped($order, $subscription_products, $customer_id, $shipping, $packages) {
		foreach ($subscription_products as $sub_product_data) {
			$item_product = $sub_product_data['product'];
			$item_qty = $sub_product_data['quantity'];
			$product_id = $sub_product_data['product_id'];
			
			$interval = WC_Subscriptions_Product::get_interval($item_product);
			$period = WC_Subscriptions_Product::get_period($item_product);
			$length = WC_Subscriptions_Product::get_length($item_product);
			$trial_length = WC_Subscriptions_Product::get_trial_length($product_id);
			$trial_period = WC_Subscriptions_Product::get_trial_period($product_id);
			
			$start_date = gmdate('Y-m-d H:i:s');
			$trial_end = WC_Subscriptions_Product::get_trial_expiration_date($item_product);
			$end_date = ($length > 0) ? WC_Subscriptions_Product::get_expiration_date($item_product) : 0;
			
			$subscription = wcs_create_subscription(array(
				'customer_id' => $customer_id,
				'order_id' => $order->get_id(),
				'billing_period' => $period,
				'billing_interval' => $interval,
				'start_date' => $start_date,
				'trial_end_date' => $trial_end,
				'trial_length' => $trial_length,
				'end_date' => $end_date,
				'status' => 'pending',
			));
			
			if (is_wp_error($subscription)) {
				continue;
			}
			
			$subscription->set_parent_id($order->get_id());
			$subscription->add_product($item_product, $item_qty);
			
			if ($trial_length > 0) {
				$trial_end_timestamp = wcs_add_time($trial_length, $trial_period, gmdate('U'));
				$subscription->update_dates(array(
					'trial_end' => gmdate('Y-m-d H:i:s', $trial_end_timestamp),
					'next_payment' => gmdate('Y-m-d H:i:s', $trial_end_timestamp),
				));
			}
			$this->add_shipping_to_order($subscription, $packages, WC()->session->get('chosen_shipping_methods'));
			$subscription->set_address($order->get_address('billing'), 'billing');
			$subscription->set_address($order->get_address('shipping'), 'shipping');
			$subscription->set_payment_method($order->get_payment_method());
			$subscription->calculate_totals();
			$subscription->save();
		}
	}
}