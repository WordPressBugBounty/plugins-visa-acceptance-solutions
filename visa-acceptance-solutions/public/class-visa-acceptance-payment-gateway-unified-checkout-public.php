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

/**
 * Include all the necessary dependencies.
 */
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-key-generation.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-payment-uc.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-authorization-saved-card.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-payment-methods.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/class-visa-acceptance-payment-gateway-subscriptions.php';

use CyberSource\Api\CustomerPaymentInstrumentApi;
use CyberSource\Model\PatchCustomerPaymentInstrumentRequest;

/**
 * Visa Acceptance Payment Gateway Unified Checkout Public Class
 *
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/public
 */
class Visa_Acceptance_Payment_Gateway_Unified_Checkout_Public {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;
	use Visa_Acceptance_Payment_Gateway_Public_Trait;
	use Visa_Acceptance_Payment_Gateway_Includes_Trait;

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
	 * Script integrity values for SRI.
	 *
	 * @var array $script_integrity Script handle to integrity hash mapping.
	 */
	private static $script_integrity = array();

	/**
	 * Flag to track if Flex microform request has been generated in current request.
	 *
	 * @var bool $flex_microform_generated Flag to prevent duplicate Flex microform generation.
	 */
	private $flex_microform_generated = false;

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
		// Add filter to include SRI integrity in script tags.
		add_filter( 'script_loader_tag', array( $this, 'add_sri_integrity_to_script' ), 10, 3 );
		// Clear session flag after order is processed.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'clear_ep_jwt_session' ), 10 );
		// Restore notices from session for guest users on product page.
		add_action( 'woocommerce_before_single_product', array( $this, 'restore_notices_from_session' ), 5 );
	}

	/**
	 * Restore notices from session after page reload for guest users.
	 * This ensures error messages persist on product page after validation failure.
	 * Only displays notice on the specific product page where the error occurred.
	 */
	public function restore_notices_from_session() {
		// Only proceed if WooCommerce session is available and user is guest.
		if ( ! is_user_logged_in() && WC()->session && is_product() ) {
			$stored_notice_data = WC()->session->get( 'uc_error_notice' );
			if ( ! empty( $stored_notice_data ) && is_array( $stored_notice_data ) ) {
				$current_product_id = get_the_ID();
				$stored_product_id = isset( $stored_notice_data['product_id'] ) ? $stored_notice_data['product_id'] : 0;
				$notice_message = isset( $stored_notice_data['message'] ) ? $stored_notice_data['message'] : '';
				
				// Only show notice if we're on the same product page where error occurred.
				if ( $current_product_id == $stored_product_id && ! empty( $notice_message ) ) {
					wc_add_notice( $notice_message, 'error' );
				}
				// Clear the stored notice after checking.
				WC()->session->set( 'uc_error_notice', null );
			}
		}
	}

	/**
	 * Set SRI integrity value for a script handle.
	 *
	 * @param string $handle    The script handle.
	 * @param string $integrity The integrity hash value.
	 */
	public static function set_script_integrity( $handle, $integrity ) {
		if ( ! empty( $integrity ) ) {
			self::$script_integrity[ $handle ] = $integrity;
		}
	}

	/**
	 * Clear EP JWT flag after order creation.
	 */
	public function clear_ep_jwt_session() {
		// Reset flag for current request.
		$this->flex_microform_generated = false;
	}

	/**
	 * Add SRI integrity attribute to script tags.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle The script handle.
	 * @param string $src    The script source URL.
	 * @return string Modified script tag with integrity attribute.
	 */
	public function add_sri_integrity_to_script( $tag, $handle, $src ) {
		if ( isset( self::$script_integrity[ $handle ] ) && ! empty( self::$script_integrity[ $handle ] ) ) {
			// Check if integrity attribute already exists to prevent duplicates.
			if ( false === strpos( $tag, 'integrity=' ) ) {
				$integrity = self::$script_integrity[ $handle ];
				// Add integrity and crossorigin attributes after src attribute.
				$tag = preg_replace(
					'/(<script[^>]*\ssrc=["\'][^"\']*["\'])/',
					'$1 integrity="' . esc_attr( $integrity ) . '" crossorigin="anonymous"',
					$tag
				);
			}
		}
		// Change ID from unified-checkout-library-js to unified-checkout-library.
		if ( 'unified-checkout-library' === $handle ) {
			$tag = str_replace( 'id=\'unified-checkout-library-js\'', 'id=\'unified-checkout-library\'', $tag );
			$tag = str_replace( 'id="unified-checkout-library-js"', 'id="unified-checkout-library"', $tag );
		}
		return $tag;
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 */
	public function enqueue_styles() {
		$uc_settings = $this->get_uc_settings();
		if ( isset( $uc_settings['enabled'] ) && ( VISA_ACCEPTANCE_YES === $uc_settings['enabled'] ) ) {
			wp_enqueue_style( $this->wc_payment_gateway_id, plugin_dir_url( __FILE__ ) . 'css/visa-acceptance-payment-gateway-credit-card-public.css', array(), $this->version, 'all' );
		}
	}

	/**
	 * Checker whether user is in add payment method page or not.
	 *
	 * @return boolean
	 */
	public function is_user_in_add_payment_method_page() {
		global $wp;
		$page_id = wc_get_page_id( 'myaccount' );
		return ( $page_id && is_page( $page_id ) && isset( $wp->query_vars['add-payment-method'] ) );
	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 */
	public function enqueue_scripts() {
		$get_data = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_checkout() || isset( $get_data['pay_for_order'] ) || is_account_page() ) {
			$uc_settings       = $this->get_uc_settings();
			$payment_method    = new Visa_Acceptance_Payment_Methods( $this );
			$flex_request      = new Visa_Acceptance_Key_Generation( $this->gateway );
			$customer_data     = $payment_method->get_order_for_add_payment_method();
			$core_tokens       = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->wc_payment_gateway_id );
			$token_count       = count( $core_tokens );
			$token_type        = array();
			$digital_payment_methods        = array();
			$payer_auth_enable = ! empty( $uc_settings['enable_threed_secure'] ) ? $uc_settings['enable_threed_secure'] : VISA_ACCEPTANCE_STRING_EMPTY;
			$subscription_order = false;
			$is_subscription_tokenization_enabled = false;
			$payment_gateway_unified_checkout = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
			$subscription_active   = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
            if ( $subscription_active ) {
        	 	$is_subscription_tokenization_enabled = $this->gateway->is_subscriptions_activated;
            	$subscription_order = WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal() || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
            }
			$tokenization 	   = isset( $uc_settings['tokenization'] ) ? $uc_settings['tokenization'] : VISA_ACCEPTANCE_STRING_EMPTY;
			$client_library    = VISA_ACCEPTANCE_STRING_EMPTY;
			$token_key         = $uc_settings['test_api_key'];
			foreach ( $core_tokens as $token ) {
                if( VISA_ACCEPTANCE_NO === $tokenization ) {
                    echo '<style>#wc-unified-checkout-saved-cards-options{display:none !important;}</style>';
                    echo '<style>#wc-unified-checkout-saved-card{display:none !important;}</style>';
                }
                $data = $token->get_data();
                if ( $data['id'] ) {
                    $token_type[ $data['id'] ] = $data['card_type'];
                }
            }
			if ( isset( $uc_settings[ VISA_ACCEPTANCE_SETTING_ENABLE_DECISION_MANAGER ] ) && VISA_ACCEPTANCE_YES === $uc_settings[ VISA_ACCEPTANCE_SETTING_ENABLE_DECISION_MANAGER ] ) {
				$session_id = wp_generate_uuid4();
				WC()->session->set( "wc_{$this->wc_payment_gateway_id}_device_data_session_id", wc_clean( $session_id ) );
				$sessionid       = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_device_data_session_id", VISA_ACCEPTANCE_STRING_EMPTY );
				$organization_id = VISA_ACCEPTANCE_ENVIRONMENT_TEST === $uc_settings['environment'] ? VISA_ACCEPTANCE_DF_ORG_ID_TEST : VISA_ACCEPTANCE_DF_ORG_ID_PROD;
				wp_enqueue_script( "wc-{$this->wc_payment_gateway_id}-device-data", self::get_dfp_url( $organization_id, $this->get_merchant_id(), $sessionid, true ), array(), $this->version, false );
			}
			// Load Captue Context for both checkouts.
			if ( is_checkout() || $this->is_user_in_add_payment_method_page() || isset( $get_data['pay_for_order'] ) ) {
				// Get both capture contexts and handle "response" key in one loop.
				if ( ! $is_subscription_tokenization_enabled && $subscription_order ) {
					$contexts = ['jwt' => null];
				}
				else {
					$contexts = [
						'jwt' => $flex_request->get_unified_checkout_capture_context(),
					];
					// Only generate Express Pay JWT if digital wallet payments are enabled (not Click to Pay).
					$digital_payment_methods = array_intersect(
						! empty( $uc_settings['enabled_payment_methods'] ) ? $uc_settings['enabled_payment_methods'] : array(),
						array('enable_gpay', 'enable_apay', 'enable_paze')
					);
					if (! empty( $digital_payment_methods ) && ! $this->is_user_in_add_payment_method_page() ) {
						$contexts['ep_jwt'] = $flex_request->get_unified_checkout_capture_context(true);
					}
				}
				foreach ($contexts as $key => &$context) {
					if (!isset($context) || !is_array($context)) {
						continue;
					}
					$body = !empty($context['body']) ? $context['body'] : VISA_ACCEPTANCE_STRING_EMPTY;
					$msg_failed = (array)$body;
					if (array_key_exists("response", $msg_failed)) {
						$body = wp_json_encode($body);
					}
					$context = $body;
				}
				unset($context);

				if (!empty($contexts['jwt'])) {
					$this->add_uc_token([
						'jwt'    => $contexts['jwt'],
					]);
				}
				if ( !empty($contexts['ep_jwt']) ) {
					$this->add_uc_token([
						'ep_jwt' => $contexts['ep_jwt'],
					]);
				}
				$client_library = !empty($contexts['jwt']) ? $this->get_uc_client_library($contexts['jwt']) : array( 'url' => VISA_ACCEPTANCE_STRING_EMPTY, 'integrity' => VISA_ACCEPTANCE_STRING_EMPTY );
			}
			if ( isset( $uc_settings['enabled'] ) && ( VISA_ACCEPTANCE_YES === $uc_settings['enabled'] ) ) {
				wp_enqueue_style( $this->wc_payment_gateway_id, plugin_dir_url( __FILE__ ) . 'css/visa-acceptance-payment-gateway-public.css', array(), $this->version, VISA_ACCEPTANCE_STRING_ALL );
				// Enqueue Unified Checkout JS library with SRI integrity.
				if ( ! empty( $client_library['url'] ) ) {
					wp_enqueue_script( 'unified-checkout-library', $client_library['url'], array(), $this->version, false );
					// Store SRI integrity attribute if available.
					if ( ! empty( $client_library['integrity'] ) ) {
						self::$script_integrity['unified-checkout-library'] = $client_library['integrity'];
					}
				}
				// Load Flex microform library if CVV is required for saved cards.
				$saved_card_token_cvv = ( isset( $uc_settings['enable_token_csc'] ) && VISA_ACCEPTANCE_YES === $uc_settings['enable_token_csc'] ) ? true : false;
				
				if ( $saved_card_token_cvv ) {
					$flex_url = 'test' !== $uc_settings['environment'] ? VISA_ACCEPTANCE_FLEX_PROD_LIBRARY : VISA_ACCEPTANCE_FLEX_TEST_LIBRARY;
					wp_enqueue_script( 'wc-credit-card-flex-microform', $flex_url, array(), $this->version, true );
				}
				
				wp_enqueue_script( VISA_ACCEPTANCE_GATEWAY_ID_UNDERSCORE . $this->wc_payment_gateway_id, plugin_dir_url( __FILE__ ) . 'js/visa-acceptance-payment-gateway-unified-checkout-public.js', array( 'jquery' ), $this->version, true );

				wp_localize_script(
					VISA_ACCEPTANCE_GATEWAY_ID_UNDERSCORE . $this->wc_payment_gateway_id,
					'visa_acceptance_ajaxUCObj',
					array(
						'ajax_url'                        => admin_url( 'admin-ajax.php' ),
						'token_type'                      => $token_type,
						'token_cnt'                       => $token_count,
						'is_user_logged_in'               => is_user_logged_in(),
						'payment_method'                  => VISA_ACCEPTANCE_GATEWAY_UC,
						'checkout_page'                   => is_checkout(),
						'payer_auth_enabled'              => $payer_auth_enable,
						'tokenization'                    => $tokenization,
						'subscription_order'               => $subscription_order,
						'visa_acceptance_solutions_uc_id' => VISA_ACCEPTANCE_UC_ID,
						'visa_acceptance_solutions_uc_id_hyphen' => VISA_ACCEPTANCE_UC_ID_HYPHEN,
						'token_key'                       => $token_key,
						'saved_card_cvv'                  => $saved_card_token_cvv,
						'enabled_payment_methods'         => $digital_payment_methods ? $digital_payment_methods : array(),
						'encrypt_const'                   => __( 'encrypt', 'visa-acceptance-solutions' ),
						'form_load_error'                 => __( 'Unable to load the payment form. Please contact customer care for any assistance.', 'visa-acceptance-solutions' ),
						'delete_card_text'                => __( 'Are you sure you want to delete this payment method?', 'visa-acceptance-solutions' ),
						'offline_text'                    => __( 'You are not connected to internet!!', 'visa-acceptance-solutions' ),
						'error_failure'                   => __( 'Unable to process your request. Please try again later.', 'visa-acceptance-solutions' ),
						'is_subscription_tokenization_enabled' => $is_subscription_tokenization_enabled,
					)
				);
				if ( VISA_ACCEPTANCE_YES === $payer_auth_enable ) {
					$this->load_payer_auth_script( $payer_auth_enable, $uc_settings );
				}
			}
		}
	}

	/**
	 * Initializes payer auth script if enabled.
	 *
	 * @param string $payer_auth_enable payer auth condition.
	 * @param array  $uc_settings configuration.
	 */
	public function load_payer_auth_script( $payer_auth_enable, $uc_settings ) {
		$nonce_setup         = wp_create_nonce( 'wc_call_uc_payer_auth_setup_action' );
		$nonce_enrollment    = wp_create_nonce( 'wc_call_uc_payer_auth_enrollment_action' );
		$nonce_validation    = wp_create_nonce( 'wc_call_uc_payer_auth_validation_action' );
		$nonce_error_handler = wp_create_nonce( 'wc_call_uc_payer_auth_error_handler' );
		$cardinal_url        = ( VISA_ACCEPTANCE_ENVIRONMENT_TEST === $uc_settings['environment'] ) ? VISA_ACCEPTANCE_CARDINAL_TEST_LIBRARY : VISA_ACCEPTANCE_CARDINAL_PRODUCTION_LIBRARY;
		$product_page        = is_product();
        $product_name = '';
        if( ! is_product() ) {
            if ( $product_page ) {
                global $product;
                if ( $product instanceof WC_Product ) {
                    $product_name = $product->get_name();
                }
            }
            wp_enqueue_style( $this->wc_payment_gateway_id . VISA_ACCEPTANCE_UNDERSCORE_PAYER_AUTH, plugin_dir_url( __FILE__ ) . 'css/visa-acceptance-payment-gateway-payer-auth-public.css', array(), $this->version, VISA_ACCEPTANCE_STRING_ALL );
            wp_enqueue_script( $this->wc_payment_gateway_id . VISA_ACCEPTANCE_UNDERSCORE_PAYER_AUTH, plugin_dir_url( __FILE__ ) . 'js/visa-acceptance-payment-gateway-payer-auth-public.js', array( 'jquery' ), $this->version, false );
        }
		$payer_auth_params = array(
			'admin_url'                              => admin_url( 'admin-ajax.php' ),
			'cardinal_url'                           => $cardinal_url,
			'payer_auth_enabled'                     => $payer_auth_enable,
			'nonce_setup'                            => $nonce_setup,
			'nonce_enrollment'                       => $nonce_enrollment,
			'nonce_validation'                       => $nonce_validation,
			'nonce_error_handler'                    => $nonce_error_handler,
			'product_page'							 => $product_page,
			'product_name' 							 => $product_name,
			'payment_method'                         => VISA_ACCEPTANCE_GATEWAY_UC,
			'visa_acceptance_solutions_uc_id_hyphen' => VISA_ACCEPTANCE_UC_ID_HYPHEN,
		);
		wp_localize_script( $this->wc_payment_gateway_id . VISA_ACCEPTANCE_UNDERSCORE_PAYER_AUTH, VISA_ACCEPTANCE_UC_PAYER_AUTH_PARAM, $payer_auth_params );
	}
	/**
	 * Gets merchant id for particular gateway.
	 *
	 * @return string|null
	 */
	public function get_merchant_id() {
		$settings = $this->gateway->get_config_settings();
		if ( isset( $settings['environment'] ) && VISA_ACCEPTANCE_ENVIRONMENT_PRODUCTION === $settings['environment'] ) {
			$merchant_id = isset( $settings['merchant_id'] ) ? $settings['merchant_id'] : null;
		} else {
			$merchant_id = isset( $settings['test_merchant_id'] ) ? $settings['test_merchant_id'] : null;
		}
		return $merchant_id;
	}

	/**
	 * Gets Unified Checkout gateway settings.
	 */
	public function get_uc_settings() {
		$payment_method = VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->wc_payment_gateway_id . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS;
		$uc_setting     = get_option( $payment_method, array() );
		return $uc_setting;
	}

	/**
	 * Adds payment fields which can be used as POST variables.
	 */
	public function payment_fields() {
		$get_data = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$settings           = $this->get_uc_settings();
		$is_zero_initial_payment = ( VISA_ACCEPTANCE_ZERO_AMOUNT === WC()->cart->get_total( 'edit' ) && VISA_ACCEPTANCE_YES === get_option( 'woocommerce_subscriptions_zero_initial_payment_requires_payment', 'no' ) ) ? true : false;
		$flex_request           = new Visa_Acceptance_Key_Generation( $this->gateway );
		$force_tokenization = false;
		if ( is_checkout() ) {
			$force_tokenization = $this->gateway->is_subscriptions_activated && ( WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal() || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment );
			// Description in Normal checkout.
			$description = $settings['description'];
			if ( $description ) {
				echo wp_kses_post( wpautop( wptexturize( $description ) ) );
			}
			$payment_method = new Visa_Acceptance_Payment_Methods( $this );
			$customer_data  = $payment_method->get_order_for_add_payment_method();
			$core_tokens    = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
			if ( ! empty( $core_tokens ) ) {
				foreach ( $core_tokens as $token ) {
					$environment_saved = $token->get_meta( VISA_ACCEPTANCE_ENVIRONMENT );
					if ( $environment_saved === $settings['environment'] ) {
						$token_data    = $token->get_data();
						$card_type     = $token_data['card_type'];
						$last_four     = $token_data['last4'];
						$id_dasherized = $this->gateway->get_id_dasherized();
						// CustomerId change for Cvv.
						$id        = $token_data['id'];
						$image_url = $this->get_image_url( $card_type );
						$checked   = checked( $token->is_default(), true, false );
						$exp_month = $token_data['expiry_month'];
						$exp_year  = $token_data['expiry_year'];
						$image_id  = attachment_url_to_postid( $image_url ); // Get the attachment ID from the URL.

						echo '<div id="wc-unified-checkout-saved-cards-options">' .
						'<input type="radio" id="wc-' . esc_attr( $id_dasherized ) . '-payment-token-' . esc_attr( $id ) . '" name="wc-' . esc_attr( $id_dasherized ) . '-payment-token" class="js-wc-' . esc_attr( $id_dasherized ) . '-payment-token" style="width:auto; margin-right:.5em;" value="' . esc_attr( $id ) . '" ' . esc_attr( $checked ) . '/>';

						// Use wp_get_attachment_image() if the image is in the Media Library.
						if ( $image_id ) {
							echo wp_get_attachment_image(
								$image_id,
								'thumbnail',
								false,
								array(
									'alt'    => $card_type,
									'title'  => $card_type,
									'width'  => '30',
									'height' => '20',
									'style'  => 'width: 30px; height: 20px;',
								)
							);
						}
						echo '<label class="wc-payment-gateway-payment-form-saved-payment-method" for="wc-' . esc_attr( $id_dasherized ) . '-payment-token-' . esc_attr( $id ) . '">' .
							'&bull; &bull; &bull; ' . esc_html( $last_four ) . ' (expires ' . esc_html( $exp_month ) . esc_html( VISA_ACCEPTANCE_SLASH ) . esc_html( $exp_year ) . ')' .
							'</label></div>';
						if ( VISA_ACCEPTANCE_YES === $settings['enable_token_csc'] ) {
							echo '<div class="wc-unified-checkout-saved-card"  id="token-' . esc_attr( $id ) . '">' .
								'<label class="wc-unified-checkout-payment-form-label"> ' . esc_html__( 'Enter Security Code', 'visa-acceptance-solutions' ) . '<span class="required">*</span>' .
								'<div class="cvv-field-container">' .
									// Flex microform container (hidden by default).
									'<div id="flex-cvv-' . esc_attr( $id ) . '" class="flex-microform-field" style="border: 1px solid #ccc; border-radius: 4px; padding: 8px; font-size: 14px; min-height: 40px; width: 100%; margin-top: 5px; display: none;"></div>' .
									// Regular input field (shown by default, hidden when Flex loads).
									'<input type="password" autoComplete="new-password" id="wc-unified-checkout-saved-card-cvn" class="wc-unified-checkout-saved-card-cvn" name="csc-saved-card-' . esc_attr( $id ) . '" placeholder="***" minLength=3 maxLength=4 style="margin-top: 5px" required/>' .
								'</div>' .
								'</label>' .
								'<div class="cvv-div" id="error-' . esc_attr( $id ) . '">' .
									'<p class="credit-card-error-message-saved-card" id="wc-csc-saved-card-error">' . esc_html__( 'Please enter valid Security Code', 'visa-acceptance-solutions' ) . '</p>' .
								'</div>' .
							'</div>';
						}
					}
				}

				// Only show "Use a new card" option if tokenization is enabled.
				if ( VISA_ACCEPTANCE_YES === $settings['tokenization'] ) {
				echo '<div id="wc-credit-card-use-new-payment-method-div">' .
					'<input type="radio" id="wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-use-new-payment-method" name="wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-payment-token" class="js-wc-payment-token js-wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-payment-token" style="width:auto; margin-right: .5em;" value="" />' .
					'<label style="display:inline; margin-left: 8px;" for="wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-use-new-payment-method">' . esc_html__( 'Use a new card', 'visa-acceptance-solutions' ) . '</label>' .
					'</div>';
				}
			}
			if( ! wp_doing_ajax() ) {
				$this->flex_microform_generated = true;
			}
			// Use flag to prevent duplicate execution within same request.
			if ( is_user_logged_in() && (! $this->flex_microform_generated || isset( $get_data['pay_for_order'] )) ) {
				$this->get_flex_microform_request( $settings, $is_zero_initial_payment, $flex_request );
				$this->flex_microform_generated = false;
			}
			$capture_context = $this->updates_capture_context();
			
			// Output jwt_updated for normal checkout (use capture_context_jwt key).
			if ( ! empty( $capture_context['capture_context_jwt'] ) ) {
				echo '<input type="hidden" id="jwt_updated" value="' . esc_attr( $capture_context['capture_context_jwt'] ) . '"/>';
			}
			
			// Output ep_jwt_updated for express pay.
			if ( ! empty( $capture_context['capture_context_ep_jwt'] ) ) {
				echo '<input type="hidden" id="ep_jwt_updated" value="' . esc_attr( $capture_context['capture_context_ep_jwt'] ) . '"/>';
			}
		}
		if ( is_checkout() || is_add_payment_method_page() ) {
			$failure_error = __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' );
			echo '<div id="wc-error-failure" style="display:none;color:red">' .
			'<p class="failure-error-message" id="wc-failure-error"> ' . esc_html( $failure_error ) . ' </p>' .
			'</div>';
		}
		?>
			<div>
				<input type="hidden" id="transientToken" name="transientToken"/>
			</div>
			<div>
				<input type="hidden" id="errorMessage" name="errorMessage"/>
			</div>

			<?php
			if ( VISA_ACCEPTANCE_YES === $settings['tokenization'] && is_checkout() && is_user_logged_in() ) {
				if ( $force_tokenization ) {
					ob_start();
					wc_print_notice( esc_html__( 'One or more items in your order is a subscription/recurring purchase. By continuing with payment, you agree that your payment method will be automatically charged at the price and frequency listed here until it ends or you cancel.', 'visa-acceptance-solutions' ), 'notice' );
					$response = ob_get_clean();
					echo '<div id="wc-unified-checkout-normal-save-token-div">' . wp_kses_post( $response ) . '</div>';
				} else {
					echo '<div id="wc-unified-checkout-save-token-div">' .
					'<input type="Checkbox" id="wc-unified-checkout-tokenize-payment-method" name="wc-unified-checkout-tokenize-payment-method" value= "yes"/>' .
					'<label class="wc-unified-checkout-payment-form-label" for="wc-unified-checkout-tokenize-payment-method">' . esc_html__( 'Save payment information to my account for future purchases.', 'visa-acceptance-solutions' ) . '</label>' .
					'</div>' .
					'<div class="clear"></div>';
				}
			}
			?>
			<?php
			if ( isset( $settings['enable_threed_secure'] ) && VISA_ACCEPTANCE_YES === $settings['enable_threed_secure'] ) {
				?>
				<div>
				<input type="hidden" id="payer_auth_enabled" name="payer_auth_enabled" value = "yes"/>
			</div>
			<?php } ?>
		<?php
	}

	/**
     * Get Flex microform request and output hidden input field.
     *
     * @param array $uc_settings Unified Checkout settings.
     * @param bool  $is_zero_initial_payment Is zero initial payment for subscription.
     * @param Visa_Acceptance_Key_Generation $flex_request Flex request object.
     */
    private function get_flex_microform_request( $uc_settings, $is_zero_initial_payment, $flex_request ) {
        // Check if user has saved cards.
        $customer_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), $this->gateway->id );
        $user_has_saved_cards = ! empty( $customer_tokens );
        $flex_capture_context = null;
        if ( VISA_ACCEPTANCE_YES === $uc_settings['enable_token_csc'] && ! $is_zero_initial_payment && is_user_logged_in() && VISA_ACCEPTANCE_YES === $uc_settings['tokenization'] && $user_has_saved_cards) {
            $capture_context_response = $flex_request->get_flex_microform_capture_context();
            $flex_capture_context = !empty($capture_context_response['body']) ? $capture_context_response['body'] : null;
        }
        echo '<input type="hidden" id="flex_cvv_token_data" name="flex_cvv_token_data" value="' . esc_attr( $flex_capture_context ) . '"/>';        
    }
	
	/**
	 * Adds Unified Checkout JWT Token to UI to fetch it for JS purposes.
	 *
	 * @param mixed $capture_context Capture Context.
	 */
	public function add_uc_token( $capture_context ) {
		if ( is_array( $capture_context ) ) {
			foreach ( $capture_context as $key => $value ) {
				?>
				<div>
					<input type="hidden" id="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>"/>
				</div>
				<?php
			}
		} else {
			?>
			<div>
				<input type="hidden" id="jwt" value="<?php echo esc_attr( $capture_context ); ?>"/>
			</div>
			<?php
		}
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$response_array        = null;
		$order                 = wc_get_order( $order_id );
		$blocks_token          = null;
		$token_id              = null;
		$transient_token       = null;
		$is_save_card          = VISA_ACCEPTANCE_NO;
		$saved_card_cvv        = null;
		$saved_card_cvv_blocks = null;
		$flex_cvv_token        = null;
		$payer_auth_enabled    = VISA_ACCEPTANCE_STRING_EMPTY; //phpcs:ignore
		$decrypt_data          = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$payment_gateway_unified_checkout = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$subscriptions  	   = new Visa_Acceptance_Payment_Gateway_Subscriptions();
		$payment_uc 		   = new Visa_Acceptance_Payment_UC( $this->gateway );
		$authorization_saved_card = new Visa_Acceptance_Authorization_Saved_Card( $this->gateway );
		if ( $order->get_payment_method() !== $this->wc_payment_gateway_id ) {
			$return_array = array(
				'result'  => VISA_ACCEPTANCE_FAILURE,
				'message' => __( 'Invalid payment method', 'visa-acceptance-solutions' ),
			);
			return $return_array;
		}
		$post_data = $_POST; //phpcs:ignore
		if ( isset( $post_data['errorMessage'] ) && VISA_ACCEPTANCE_YES === $post_data['errorMessage'] ) {
			return null;
		}

		if ( isset( $post_data['errorPayerAuth'] ) && VISA_ACCEPTANCE_YES === $post_data['errorPayerAuth'] ) {
			$return_array = array(
				'result'   => VISA_ACCEPTANCE_FAILURE,
				'redirect' => wc_get_checkout_url(),
			);
			return $return_array;
		}
		// The following two POST variables are for Normal Checkout and Blocks Checkout TT respectively.
		if ( ! empty( $post_data['transientToken'] ) ) {
			$transient_token = wc_clean( wp_unslash( $post_data['transientToken'] ) );
		} elseif ( ! empty( $post_data['blocks_token'] ) ) {
			$transient_token = wc_clean( wp_unslash( $post_data['blocks_token'] ) );
		}
		if ( ! empty( $post_data['payer_auth_enabled'] ) ) {
			$payer_auth_enabled = wc_clean( wp_unslash( $post_data['payer_auth_enabled'] ) );
		}
		// It's needed to get any order details.
		$setting = $this->get_uc_settings();
		$digital_payment_method = '';
		if(!empty($setting['enabled_payment_methods'])) {
			foreach($setting['enabled_payment_methods'] as $digital_method) {
				if('enable_gpay' === $digital_method) {
					$digital_payment_method = $digital_method;
				}
			}
		}
		$random_bytes  = random_bytes( VISA_ACCEPTANCE_VAL_TWO ); // phpcs:ignore
		$random_number = unpack( 'n', $random_bytes )[ VISA_ACCEPTANCE_VAL_ONE ] % 900 + 100; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! empty( $post_data['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$blocks_token = sanitize_text_field( wp_unslash( $post_data['token'] ) );
		}
		if ( ! empty( $post_data['wc_cc_security_code_blocks'] ) && ! empty( $post_data['ext_id'] ) && ! empty( $post_data['ref_id'] ) ) {
			$encrypted_cvv         = sanitize_text_field( wp_unslash( $post_data['wc_cc_security_code_blocks'] ) );
			$ext_id                = sanitize_text_field( wp_unslash( $post_data['ext_id'] ) );
			$val_id                = $this->get_uc_settings()['test_api_key'];
			$ref_id                = sanitize_text_field( wp_unslash( $post_data['ref_id'] ) );
			$saved_card_cvv_blocks = $decrypt_data->decrypt_cvv( $encrypted_cvv, $ext_id, $val_id, $ref_id );
		}
		if ( ! empty( $post_data['wc-unified-checkout-tokenize-payment-method'] ) ) {
			$is_save_card = sanitize_html_class( wp_unslash( $post_data['wc-unified-checkout-tokenize-payment-method'] ) );
		}
		if ( ! empty( $post_data[ 'wc-' . VISA_ACCEPTANCE_UC_ID_HYPHEN . '-payment-token' ] ) ) {
			$token_id = sanitize_text_field( wp_unslash( $post_data[ 'wc-' . VISA_ACCEPTANCE_UC_ID_HYPHEN . '-payment-token' ] ) );
		}
		
		// Extract Flex CVV token if present.
		if ( ! empty( $post_data['flex_cvv_token'] ) ) {
			$flex_cvv_token = sanitize_text_field( wp_unslash( $post_data['flex_cvv_token'] ) );
		}
		
		if ( ! empty( $post_data[ 'csc-saved-card-' . $token_id ] ) && ! empty( $post_data[ 'extId-' . $token_id ] ) && ! empty( $post_data[ 'refId-' . $token_id ] ) ) {
			$encrypted_csc  = sanitize_text_field( wp_unslash( $post_data[ 'csc-saved-card-' . $token_id ] ) );
			$ext_id         = sanitize_text_field( wp_unslash( $post_data[ 'extId-' . $token_id ] ) );
			$val_id         = $this->get_uc_settings()['test_api_key'];
			$ref_id         = sanitize_text_field( wp_unslash( $post_data[ 'refId-' . $token_id ] ) );
			$saved_card_cvv = $decrypt_data->decrypt_cvv( $encrypted_csc, $ext_id, $val_id, $ref_id );
		}
		$decoded_transient_token = ! empty( $transient_token ) ? json_decode( base64_decode( explode( '.', $transient_token )[VISA_ACCEPTANCE_ONE] ), true ) : null;// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		
		// Retrieve and update address from Google Pay using CyberSource API BEFORE any processing.
		if ( ! empty( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) && 
		     VISA_ACCEPTANCE_GPAY_PAYMENTSOLUTION_VALUE === $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) {
			$payment_uc_helper = new Visa_Acceptance_Payment_UC( $this->gateway );
			$payment_details_response = $payment_uc_helper->get_payment_details_from_transient_token( $transient_token );
			
			if ( $payment_details_response && isset( $payment_details_response['body'] ) ) {
				$payment_uc_helper->update_order_addresses_from_payment_details( $order, $payment_details_response['body'] );
			}
		}
		
		if ( isset( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) ) {
			$payment_solution = $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'];
			if ( visa_acceptance_handle_hpos_compatibility() ) {
				$order->add_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_PAYMENT_SOLUTION, $payment_solution, false );
				$order->save_meta_data();
			} else {
				add_post_meta( $order->get_id(), VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_PAYMENT_SOLUTION, $payment_solution );
			}
		}
		if ( (isset( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) ) || ( isset( $decoded_transient_token['metadata']['consumerPreference']['saveCard'] ) && true === $decoded_transient_token['metadata']['consumerPreference']['saveCard'] && VISA_ACCEPTANCE_YES === $setting['tokenization'] ) ) {
             // Check if this is a Google Pay transaction - don't save cards for Google Pay.
             if ( isset( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) &&
                  VISA_ACCEPTANCE_GPAY_PAYMENTSOLUTION_VALUE === $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) {
                 $is_save_card = VISA_ACCEPTANCE_NO;
             } else {
                 $is_save_card = VISA_ACCEPTANCE_YES;
             }
        }
		$subscription_active              = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
		if ( $subscription_active && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) {
			$saved_token_id = ! empty( $token_id ) ? $token_id : $blocks_token;
			return $subscriptions->change_payment_method( $saved_token_id, $order, $transient_token );
		}
		if(isset( $setting['enable_threed_secure'] ) && VISA_ACCEPTANCE_YES === $setting['enable_threed_secure'] && VISA_ACCEPTANCE_YES === $payer_auth_enabled) {
            if(('enable_gpay' === $digital_payment_method && false === $decoded_transient_token['metadata']['cardholderAuthenticationStatus'] && isset( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) && VISA_ACCEPTANCE_GPAY_PAYMENTSOLUTION_VALUE === $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) || ( ! isset( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] )) ) {
				// Store Flex CVV token in order meta for later use in 3DS flow.
                if ( ! empty( $flex_cvv_token ) ) {
                    update_post_meta( $order_id, VISA_ACCEPTANCE_WC_UC_ID . '_flex_cvv_token', $flex_cvv_token );
                }
                if ( ! empty( $post_data['blocks_token'] ) ) {
                    if ( VISA_ACCEPTANCE_YES === $is_save_card ) {
                        $this->update_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_BLOCKS . $order_id, VISA_ACCEPTANCE_YES );
                    }
                    $redirect = VISA_ACCEPTANCE_PAYER_AUTH_BLOCKS . $random_number . VISA_ACCEPTANCE_UNDERSCORE . $order_id;
                } elseif ( $blocks_token ) {
                    $this->update_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_NORMAL . $order_id, $saved_card_cvv_blocks );
                    $redirect = VISA_ACCEPTANCE_PAYER_AUTH_WITH_TOKEN . $random_number . VISA_ACCEPTANCE_UNDERSCORE . $order_id . VISA_ACCEPTANCE_UNDERSCORE . $blocks_token;
 
                } elseif ( $token_id ) {
                    // Payer Auth shortcode saved card token.
                    update_post_meta( $order_id, VISA_ACCEPTANCE_SAVED_CARD_NORMAL . $order_id, $saved_card_cvv );
                    $redirect = VISA_ACCEPTANCE_PAYER_AUTH_WITH_TOKEN . $random_number . VISA_ACCEPTANCE_UNDERSCORE . $order_id;
                } else {
                    $redirect = VISA_ACCEPTANCE_PAYER_AUTH_NORMAL . $random_number . VISA_ACCEPTANCE_UNDERSCORE . $order_id;
                }
                $return_array = array(
                    'result'   => VISA_ACCEPTANCE_SUCCESS,
                    'redirect' => $redirect,
                );
                return $return_array;
 
            }
        }

		if ( isset( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) ) {
			$is_save_card = VISA_ACCEPTANCE_NO;
		}

		// Initialize result to prevent undefined variable errors.
		$result = null;

		if ( $blocks_token || $token_id ) {
			if ( $token_id ) {
				$token = $this->get_meta_data_token( $token_id );
				if ( $token ) {
					$result = $authorization_saved_card->do_transaction( $order, $token, $saved_card_cvv, false, $flex_cvv_token );
				}
			} else {
				$blocks_meta_token = $this->get_meta_data_token( $blocks_token );
				if ( $blocks_meta_token ) {
					$result = $authorization_saved_card->do_transaction( $order, $blocks_meta_token, $saved_card_cvv_blocks, false, $flex_cvv_token );
				}
			}
		} else {
			if ( $this->gateway->is_subscriptions_activated ) {
				if ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order ) || wcs_is_subscription( $order ) ) {
					$is_save_card = VISA_ACCEPTANCE_YES;
				}
        	}
			if ( ! empty( $transient_token ) ){
                $result = $payment_uc->do_transaction( $order, $transient_token, $is_save_card );
            }
		}

		if ( $this->gateway->is_subscriptions_activated ) {
            if ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order ) || wcs_is_subscription( $order ) ) {
                $subscriptions->add_payment_data_to_subscription( $order );
            }
        }
		
		if ( null === $result ) {
			// No payment processing occurred.
			$message = __( 'Payment processing failed. Please try again.', 'visa-acceptance-solutions' );
			$this->mark_order_failed( $message );
			$response_array = array(
				'result'  => VISA_ACCEPTANCE_FAILURE,
				'message' => $message,
			);
		} elseif ( is_array($result) && $result[ VISA_ACCEPTANCE_SUCCESS ] ) {
			// Empty cart if not on product page.
			if ( ! is_product()) {
				WC()->cart->empty_cart();
			}
			$response_array = array(
				'result'   => VISA_ACCEPTANCE_SUCCESS,
				'redirect' => $this->gateway->get_return_url( $order ),
			);
		} elseif ( is_array($result) && $result[ VISA_ACCEPTANCE_STRING_ERROR ] ) {
			$message = $result[ VISA_ACCEPTANCE_STRING_ERROR ];
			if ( isset( $result['message'] ) && $result['message'] ) {
				$message = $result['message'];
			}

			if ( isset( $result['detailed_reason'] ) ) {
				$message .= '<br>';
				$message .= $this->add_detailed_message( $result['detailed_reason'] );
			}
			$this->mark_order_failed( $message );
			$response_array = array(
				'result'  => VISA_ACCEPTANCE_FAILURE,
				'message' => $message,
				'reason'  => $result['reason'],
			);
		} else {
			$message = __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' );
			if ( isset( $result['detailed_reason'] ) ) {
				$message .= '<br>';
				$message .= $this->add_detailed_message( $result['detailed_reason'] );
			}
			$this->mark_order_failed( $message );
			$response_array = array(
				'result'  => VISA_ACCEPTANCE_FAILURE,
				'message' => $message,
			);

		}
		return $response_array;
	}

	/**
	 * Payer Auth AJAX Setup Callback.
	 */
	public function call_setup_action() {
		$nonce = isset( $_POST['nounce'] ) ? sanitize_text_field( wp_unslash( $_POST['nounce'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.
		wp_verify_nonce( $nonce, 'wc_call_uc_payer_auth_setup_action' );//phpcs:ignore WordPress.Security.NonceVerification.
		$post_data          = $_POST;
		$data               = ( ! empty( $post_data['data'] ) ) ? wc_clean( wp_unslash( $post_data['data'] ) ) : null;
		$saved_token        = ( ! empty( $post_data['savedtoken'] ) ) ? wc_clean( wp_unslash( $post_data['savedtoken'] ) ) : null;
		$saved_token        = ( ! empty( $saved_token ) ) ? $this->get_meta_data_token( $saved_token ) : null;
		$order_id           = ( ! empty( $post_data['orderid'] ) ) ? absint( wp_unslash( $post_data['orderid'] ) ) : null;
		$uc_payment_gateway = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$return_response    = array(
			'error'             => VISA_ACCEPTANCE_VAL_ZERO,
			'message'           => VISA_ACCEPTANCE_STRING_EMPTY,
			'status'            => VISA_ACCEPTANCE_STRING_EMPTY,
			'dataCollectionUrl' => VISA_ACCEPTANCE_STRING_EMPTY,
			'accessToken'       => VISA_ACCEPTANCE_STRING_EMPTY,
			'referenceId'       => VISA_ACCEPTANCE_STRING_EMPTY,
		);
		// Calling Payer Auth Setup API.
		$api_response = $uc_payment_gateway->payer_auth_setup( $data, $saved_token, $order_id );
		if ( VISA_ACCEPTANCE_STRING_COMPLETED === $api_response['status'] ) {
			$return_response['status']            = $api_response['status'];
			$return_response['dataCollectionUrl'] = $api_response['dataCollectionUrl'];
			$return_response['accessToken']       = $api_response['accessToken'];
			$return_response['referenceId']       = $api_response['referenceId'];
		} else {
			$return_response = $api_response;
			if ( isset( $return_response['error'] ) ) {
				wc_clear_notices();
				wc_add_notice( $return_response['error'], VISA_ACCEPTANCE_STRING_ERROR );
			}
		}

		wp_send_json( $return_response );
	}

	/**
	 * Capture context Ajax callback
	 */
	public function call_updates_action() {
		return wp_send_json( $this->updates_capture_context() );
	}

	/**
     * Updates and retrieves capture context JWTs for Unified Checkout and Express Pay.
     *
     * @return array Response array with success status and capture context JWTs.
     */
	public function updates_capture_context() {
        $return_response        = array(
            'success'         			=> true,
            'capture_context_jwt' 		=> null,
            'capture_context_ep_jwt' 	=> null,
        );
        $uc_settings            = $this->get_uc_settings();
        $checkout_order_total   = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$client_library_jwt 	= VISA_ACCEPTANCE_STRING_EMPTY;
		$client_library_ep_jwt 	= VISA_ACCEPTANCE_STRING_EMPTY;
        
        // Force cart recalculation to get fresh totals with updated shipping.
        if ( isset( WC()->cart ) && ! is_admin() ) {
            WC()->cart->calculate_totals();
        }
        
        $total_amount           = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_capture_context_total_amount" );
        $key_generation_request = new Visa_Acceptance_Key_Generation_Request( $this->gateway );
        $flex_request           = new Visa_Acceptance_Key_Generation( $this->gateway );
        $checkout_total_amount  = $key_generation_request->get_admin_checkout_total_amount();
        if ( $checkout_total_amount['is_admin_order_pay_page'] ) {
            $checkout_order_total = isset( $checkout_total_amount['total_amount'] ) ? $checkout_total_amount['total_amount'] : $checkout_order_total;
        }
        else {
            $checkout_order_total = isset( WC()->cart ) ? WC()->cart->get_totals()['total'] : $checkout_order_total;
        }
        
        // Track shipping method changes (including subscription recurring shipping).
        $current_shipping_hash = null;
        if ( isset( WC()->session ) ) {
            $chosen_shipping = WC()->session->get( 'chosen_shipping_methods' );
            $current_shipping_hash = md5( wp_json_encode( $chosen_shipping ) );
        }
        $last_shipping_hash = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_shipping_hash" );
        // Only generate Express Pay JWT if digital wallet payments are enabled (not Click to Pay).
        $digital_payment_methods = array_intersect(
            ! empty( $uc_settings['enabled_payment_methods'] ) ? $uc_settings['enabled_payment_methods'] : array(),
            array('enable_gpay', 'enable_apay', 'enable_paze')
        );
        // Generate new capture context if: amount changed, OR no session amount exists (first load), OR shipping changed.
        if ( empty( $total_amount ) || $checkout_order_total !== $total_amount || $last_shipping_hash !== $current_shipping_hash ) { // phpcs:ignore WordPress.Security.NonceVerification.
            $return_response['success'] = false;
            $response_jwt           = $flex_request->get_unified_checkout_capture_context();
            if (! empty( $digital_payment_methods ) && ! $this->is_user_in_add_payment_method_page()) {
                $response_ep_jwt            = $flex_request->get_unified_checkout_capture_context(true);
            }
            // Handle normal JWT response.
            if (isset( $response_jwt['http_code'] ) && VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $response_jwt['http_code'] ) {
                $capture_context_jwt = ! empty( $response_jwt['body'] ) ? $response_jwt['body'] : VISA_ACCEPTANCE_STRING_EMPTY;
                $return_response['capture_context_jwt'] = $capture_context_jwt;
                $client_library_jwt = $this->get_uc_client_library( $capture_context_jwt );
                if ( ! empty( $client_library_jwt['url'] ) ) {
                    $return_response['client_library_url'] = $client_library_jwt['url'];
                    $return_response['client_library_integrity'] = $client_library_jwt['integrity'];
                    wp_enqueue_script( 'unified-checkout-library', $client_library_jwt['url'], array(), null, false ); // phpcs:ignore
                    // Store SRI integrity for script tag filter.
                    if ( ! empty( $client_library_jwt['integrity'] ) ) {
                        self::$script_integrity['unified-checkout-library'] = $client_library_jwt['integrity'];
                    }
                }
            }
 
            // Handle Express Pay JWT response.
            if ( isset( $response_ep_jwt['http_code'] ) && VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $response_ep_jwt['http_code'] ) {
                $capture_context_ep_jwt = ! empty( $response_ep_jwt['body'] ) ? $response_ep_jwt['body'] : VISA_ACCEPTANCE_STRING_EMPTY;
                $return_response['capture_context_ep_jwt'] = $capture_context_ep_jwt;
                $client_library_ep_jwt = $this->get_uc_client_library( $capture_context_ep_jwt );
                if ( ! empty( $client_library_ep_jwt['url'] ) ) {
                    $return_response['client_library_ep_url'] = $client_library_ep_jwt['url'];
                    $return_response['client_library_ep_integrity'] = $client_library_ep_jwt['integrity'];
                    wp_enqueue_script( 'unified-checkout-library', $client_library_ep_jwt['url'], array(), null, false ); // phpcs:ignore
                    // Store SRI integrity for script tag filter.
                    if ( ! empty( $client_library_ep_jwt['integrity'] ) ) {
                        self::$script_integrity['unified-checkout-library'] = $client_library_ep_jwt['integrity'];
                    }
                }
            }
            
            // Set success to true if at least one context is available.
            if ( ! empty( $return_response['capture_context_jwt'] ) || ! empty( $return_response['capture_context_ep_jwt'] ) ) {
                $return_response['success'] = true;
                // Update session with new total and shipping hash.
                WC()->session->set( "wc_{$this->wc_payment_gateway_id}_capture_context_total_amount", $checkout_order_total );
                if ( isset( $current_shipping_hash ) ) {
                    WC()->session->set( "wc_{$this->wc_payment_gateway_id}_shipping_hash", $current_shipping_hash );
                }
            }
        }
        return $return_response;
    }

	/**
	 * Fetches uc client library and SRI hash
	 *
	 * @param string $capture_context capture context.
	 * @return array Returns array with 'url' and 'integrity' keys
	 */
	public function get_uc_client_library( $capture_context ) {
		$client_library            = array(
			'url'       => VISA_ACCEPTANCE_STRING_EMPTY,
			'integrity' => VISA_ACCEPTANCE_STRING_EMPTY,
		);
		$capture_context_component = explode( VISA_ACCEPTANCE_FULL_STOP, $capture_context );
		if ( VISA_ACCEPTANCE_VAL_THREE === count( $capture_context_component ) ) {
			$decoded_payload = json_decode( base64_decode( $capture_context_component[ VISA_ACCEPTANCE_VAL_ONE ] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( ! isset( $decoded_payload ) ) {
				$decoded_payload = json_decode( base64_decode( str_replace( array( VISA_ACCEPTANCE_HYPHEN, VISA_ACCEPTANCE_UNDERSCORE ), array( '+', VISA_ACCEPTANCE_SLASH ), $capture_context_component[ VISA_ACCEPTANCE_VAL_ONE ] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			}
		}
		if ( isset( $decoded_payload ) && isset( $decoded_payload['ctx'][ VISA_ACCEPTANCE_VAL_ZERO ]['data'] ) ) {
			$data = $decoded_payload['ctx'][ VISA_ACCEPTANCE_VAL_ZERO ]['data'];
			$client_library['url'] = isset( $data['clientLibrary'] ) ? $data['clientLibrary'] : VISA_ACCEPTANCE_STRING_EMPTY;
			$client_library['integrity'] = isset( $data['clientLibraryIntegrity'] ) ? $data['clientLibraryIntegrity'] : VISA_ACCEPTANCE_STRING_EMPTY;
		}
		return $client_library;
	}
	
	/**
	 * Payer Auth AJAX Enrollment Callback.
	 */
	public function call_enrollment_action() {
		$nonce = isset( $_POST['nounce'] ) ? sanitize_text_field( wp_unslash( $_POST['nounce'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.
		wp_verify_nonce( $nonce, 'wc_call_uc_payer_auth_enrollment_action' ); //phpcs:ignore WordPress.Security.NonceVerification.
		$post_data          = $_POST;
		$is_save_card     = ( ! empty( $post_data['isSaveCard'] ) ) ? wc_clean( wp_unslash( $post_data['isSaveCard'] ) ) : null;
		$card_token         = ( ! empty( $post_data['cardtoken'] ) ) ? wc_clean( wp_unslash( $post_data['cardtoken'] ) ) : null;
		$saved_token        = ( ! empty( $post_data['savedtoken'] ) ) ? wc_clean( wp_unslash( $post_data['savedtoken'] ) ) : null;
		$saved_token        = ( ! empty( $saved_token ) ) ? $this->get_meta_data_token( $saved_token ) : null;
		$order_id           = ( ! empty( $post_data['orderid'] ) ) ? absint( wp_unslash( $post_data['orderid'] ) ) : null;
		$reference_id       = ( ! empty( $post_data['referenceId'] ) ) ? wc_clean( wp_unslash( $post_data['referenceId'] ) ) : null;
		$sca_case           = ( ! empty( $post_data['scaCase'] ) ) ? wc_clean( wp_unslash( $post_data['scaCase'] ) ) : null;
		$flex_cvv_token     = ( ! empty( $post_data['flexCvvToken'] ) ) ? sanitize_text_field( wp_unslash( $post_data['flexCvvToken'] ) ) : null;
		$return_response    = array(
		'error'       => VISA_ACCEPTANCE_VAL_ZERO,
		'message'     => VISA_ACCEPTANCE_STRING_EMPTY,
		'status'      => VISA_ACCEPTANCE_STRING_EMPTY,
		'stepUpUrl'   => VISA_ACCEPTANCE_STRING_EMPTY,
		'accessToken' => VISA_ACCEPTANCE_STRING_EMPTY,
		);
		$uc_payment_gateway = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		if ( ! ( ! empty( $saved_token ) && $saved_token instanceof WC_Payment_Token ) && $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) ) {
		$is_save_card = VISA_ACCEPTANCE_YES;
		}
		if ( ! empty( $post_data['cardtoken'] ) ) {
		$card_token = wc_clean( wp_unslash( $post_data['cardtoken'] ) );
		}
		$setting = $this->get_uc_settings();
		$decoded_card_token = ! empty( $card_token ) ? json_decode( base64_decode( explode( '.', $card_token )[VISA_ACCEPTANCE_ONE] ), true ) : null;// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( isset( $decoded_card_token['metadata']['consumerPreference']['saveCard'] ) && true === $decoded_card_token['metadata']['consumerPreference']['saveCard'] && VISA_ACCEPTANCE_YES === $setting['tokenization'] ){
		$is_save_card = VISA_ACCEPTANCE_YES;
		}
		// Calling Payer Auth Setup API.
		$api_response = $uc_payment_gateway->payer_auth_enrollment( $order_id, $card_token, $saved_token, $is_save_card, $reference_id, $sca_case, $flex_cvv_token );
		if ( VISA_ACCEPTANCE_PENDING_AUTHENTICATION === $api_response['status'] ) {
		$return_response['status']      = $api_response['status'];
		$return_response['stepUpUrl']   = $api_response['stepUpUrl'];
		$return_response['accessToken'] = $api_response['accessToken'];
		$return_response['pareq']       = $api_response['pareq'];
		} else {
		$return_response = $api_response;
		if ( isset( $return_response['error'] ) ) {
			wc_clear_notices();
			wc_add_notice( $return_response['error'], VISA_ACCEPTANCE_STRING_ERROR );
		}
		}

		wp_send_json( $return_response );
	}

	/**
	 * Payer Auth AJAX Validation Callback.
	*/
	public function call_validation_action() {
		$nonce = isset( $_POST['nounce'] ) ? sanitize_text_field( wp_unslash( $_POST['nounce'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.
		wp_verify_nonce( $nonce, 'wc_call_uc_payer_auth_validation_action' ); //phpcs:ignore WordPress.Security.NonceVerification.
		$post_data          = $_POST;
		$is_save_card     = ( ! empty( $post_data['tokenCheckbox'] ) ) ? wc_clean( wp_unslash( $post_data['tokenCheckbox'] ) ) : null;
		$card_token         = ( ! empty( $post_data['cardtoken'] ) ) ? wc_clean( wp_unslash( $post_data['cardtoken'] ) ) : null;
		$saved_token        = ( ! empty( $post_data['savedtoken'] ) ) ? wc_clean( wp_unslash( $post_data['savedtoken'] ) ) : null;
		$saved_token        = ( ! empty( $saved_token ) ) ? $this->get_meta_data_token( $saved_token ) : null;
		$order_id           = ( ! empty( $post_data['orderid'] ) ) ? absint( wp_unslash( $post_data['orderid'] ) ) : null;
		$auth_id            = ( ! empty( $post_data['authid'] ) ) ? wc_clean( wp_unslash( $post_data['authid'] ) ) : null;
		$pareq              = ( ! empty( $post_data['pareq'] ) ) ? wc_clean( wp_unslash( $post_data['pareq'] ) ) : null;
		$sca_case           = ( ! empty( $post_data['scaCase'] ) ) ? wc_clean( wp_unslash( $post_data['scaCase'] ) ) : null;
		$flex_cvv_token     = ( ! empty( $post_data['flexCvvToken'] ) ) ? sanitize_text_field( wp_unslash( $post_data['flexCvvToken'] ) ) : null;
		// If no Flex CVV token from AJAX, try to retrieve from order meta.
				if ( empty( $flex_cvv_token ) && ! empty( $order_id ) ) {
					$flex_cvv_token = get_post_meta( $order_id, VISA_ACCEPTANCE_WC_UC_ID . '_flex_cvv_token', true );
				}
		$uc_payment_gateway = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		// Calling Payer Auth Setup API.
		if ( ! ( ! empty( $saved_token ) && $saved_token instanceof WC_Payment_Token ) && $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) ) {
		$is_save_card = VISA_ACCEPTANCE_YES;
		}
		if ( ! empty( $post_data['cardtoken'] ) ) {
		$card_token = wc_clean( wp_unslash( $post_data['cardtoken'] ) );
		}
		$setting = $this->get_uc_settings();
		$decoded_card_token = ! empty( $card_token ) ? json_decode( base64_decode( explode( '.', $card_token )[VISA_ACCEPTANCE_ONE] ), true ) : null;// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( isset( $decoded_card_token['metadata']['consumerPreference']['saveCard'] ) && true === $decoded_card_token['metadata']['consumerPreference']['saveCard'] && VISA_ACCEPTANCE_YES === $setting['tokenization'] ){
		$is_save_card = VISA_ACCEPTANCE_YES;
		}
		$return_response = $uc_payment_gateway->payer_auth_validation( $order_id, $card_token, $saved_token, $is_save_card, $auth_id, $pareq, $sca_case, $flex_cvv_token );
		// Clean up stored Flex CVV token for security after validation.
				if ( ! empty( $order_id ) ) {
					delete_post_meta( $order_id, VISA_ACCEPTANCE_WC_UC_ID . '_flex_cvv_token' );
				}
		if ( isset( $return_response['error'] ) ) {
			wc_clear_notices();
			wc_add_notice( $return_response['error'], VISA_ACCEPTANCE_STRING_ERROR );
			// Store error with product ID for guest users on product page.
			if ( ! is_user_logged_in() && ! empty( sanitize_text_field( wp_unslash($_SERVER['HTTP_REFERER'] ) ) ) ) {
                $referer = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] )) );
				if ( isset( $referer['query'] ) ) {
					parse_str( $referer['query'], $query_params );
					if ( isset( $query_params['add-to-cart'] ) ) {
						$product_id = absint( $query_params['add-to-cart'] );
						if ( ! WC()->session->has_session() ) {
							WC()->session->set_customer_session_cookie( true );
						}
						WC()->session->set( 'uc_error_notice', array(
							'message' => $return_response['error'],
							'product_id' => $product_id
						) );
					}
				}
			}
		}
		wp_send_json( $return_response );
	}

	/**
	 * Payer Auth Custom Endpoint return url.
	 */
	public function payment_gateway_register_endpoint() {
		register_rest_route(
			VISA_ACCEPTANCE_PLUGIN_DOMAIN . '/v1',
			VISA_ACCEPTANCE_SLASH . VISA_ACCEPTANCE_GATEWAY_ID_UNDERSCORE . 'payer_auth_response',
			array(
				'methods'             => VISA_ACCEPTANCE_REQUEST_METHOD_POST,
				'callback'            => array( $this, 'handle_post' ),
				'permission_callback' => array( $this, 'allow_public_access' ),
			)
		);
	}

	/**
	 * Determines if the customers cart should be emptied before redirecting to the payment form, after the order is created.
	 *
	 * Gateways can set this to false if they want the cart to remain intact until a successful payment is made.
	 *
	 * @since 5.0.0
	 *
	 * @return bool
	 */
	protected function empty_cart_before_redirect() {

		return true;
	}

	/**
	 * Permission callback function
	 */
	public function allow_public_access() {
		return true;
	}

	/**
	 * Error Handler for Payer Authentication Blocks.
	 */
	public function call_error_handler() {
		$nonce = isset( $_POST['nounce'] ) ? sanitize_text_field( wp_unslash( $_POST['nounce'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.
		wp_verify_nonce( $nonce, 'wc_call_uc_payer_auth_error_handler' ); //phpcs:ignore WordPress.Security.NonceVerification.
		wc_clear_notices();
		wp_send_json_success();
	}


	/**
	 * Get meta data of token.
	 *
	 * @param mixed $blocks_token_id blocks token id.
	 *
	 * @return \WC_Payment_Token $blocks_meta_token core token.
	 */
	public function get_meta_data_token( $blocks_token_id ) {
		$customer_data     = $this->get_order_for_add_payment_method();
		$payment_method    = new Visa_Acceptance_Payment_Methods( $this );
		$blocks_meta_token = null;
		$core_tokens       = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
		if ( is_array( $core_tokens ) && ! empty( $core_tokens ) ) {
			foreach ( $core_tokens as $core_token ) {
				if ( ( $core_token->get_id() === (int) $blocks_token_id ) && ( $this->gateway->get_environment() === $core_token->get_meta( VISA_ACCEPTANCE_ENVIRONMENT ) ) ) {
						$blocks_meta_token = $core_token;
				}
			}
		}
		return $blocks_meta_token;
	}

	/**
	 * Creates a mock order for adding payment method.
	 *
	 * @return array
	 */
	public function get_order_for_add_payment_method() {
		$user       = get_userdata( get_current_user_id() );
		$properties = array(
			'currency'    => get_woocommerce_currency(), // default to base store currency.
			'customer_id' => isset( $user->ID ) ? $user->ID : VISA_ACCEPTANCE_STRING_EMPTY,
		);
		return $properties;
	} // phpcs:ignore WordPress.Security.NonceVerification

	/**
	 * Adds new Payment Method through Payment Methods page.
	 */
	public function add_payment_method() {
		$post_data 		= $_POST; //phpcs:ignore
		$payment_method = new Visa_Acceptance_Payment_Methods( $this->gateway );
		if ( ! empty( $post_data['transientToken'] ) ) {
			$transient_token = sanitize_text_field( wp_unslash( $post_data['transientToken'] ) );
		}
		if ( $transient_token ) {
			$result         = $payment_method->create_token( $transient_token );
			if ( $result['status'] ) {
				wc_add_notice( $result['message'] );
				$redirect_url = wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS );
			} else {
				wc_add_notice( $result['message'], VISA_ACCEPTANCE_STRING_ERROR );
				$redirect_url = wc_get_endpoint_url( 'add-payment-method' );
			}
		} else {
			wc_add_notice( __( 'Please enter card details', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_STRING_ERROR );
			$redirect_url = wc_get_endpoint_url( 'add-payment-method' );
		}
		wp_safe_redirect( $redirect_url );
		exit();
	}

	/**
	 * Deletes payment token.
	 *
	 * @param mixed $core_token_id core token id.
	 * @param mixed $core_token core token.
	 *
	 * @return array
	 */
	public function uc_payment_token_deleted( $core_token_id, $core_token ) {
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-visa-acceptance-payment-gateway-unified-checkout.php';
		$uc_payment = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();

		$result_response = $uc_payment->init_payment_token_deleted( $core_token_id, $core_token );
		return $result_response;
	}

	/**
	 * This method handles UI logic for Payment methods page in Front Office UI.
	 *
	 * @param array $columns columns.
	 *
	 * @return array
	 */
	public function uc_add_payment_methods_columns( $columns = array() ) {

		$title_column = array( 'title' => __( 'Title', 'visa-acceptance-solutions' ) );
		$columns      = $this->array_insert_after( $columns, 'method', $title_column );

		$details_column = array( 'details' => __( 'Details', 'visa-acceptance-solutions' ) );
		$columns        = $this->array_insert_after( $columns, 'title', $details_column );

		$default_column = array( 'default' => __( 'Default?', 'visa-acceptance-solutions' ) );
		$columns        = $this->array_insert_after( $columns, 'expires', $default_column );

		// backwards compatibility for 3rd parties using the filter with the old column keys.
		if ( array_key_exists( 'expiry', $columns ) ) {

			$columns['expires'] = $columns['expiry'];
			unset( $columns['expiry'] );
		}

		// subscriptions.
		if ( ! isset( $columns['subscriptions'] ) ) {
			$default_column = array( 'subscriptions' => __( 'Subscriptions', 'visa-acceptance-solutions' ) );
			$columns        = $this->array_insert_after( $columns, 'default', $default_column );
		}

		return $columns;
	}

	/**
	 * This method adds Payment method title.
	 *
	 * @param mixed $method payment method.
	 */
	public function uc_add_payment_method_title( $method ) {
		$gateway_title = null;
		if ( $method['method']['gateway'] === $this->gateway->get_id() ) {
			$gateway_title = $this->gateway->get_title();
		}
		if ( $gateway_title ) {
			echo '<div class="method title"> ' . esc_html( $gateway_title ) . ' </div>';
		}
	}

	/**
	 * This method provides image of the Card used for Payment.
	 *
	 * @param mixed $method Payment Method.
	 */
	public function uc_add_payment_method_details( $method ) {
		if ( $this->gateway->get_id() === $method['method']['gateway'] ) {
			$card_type = $method['method']['brand'];
			$last_four = $method['method']['last4'];
			$image_url = $this->get_image_url( $card_type );
			$image_id  = attachment_url_to_postid( $image_url ); // Get the attachment ID from the URL.
			if ( $image_id ) {
				echo wp_get_attachment_image(
					$image_id,
					'thumbnail',
					false,
					array(
						'alt'    => $card_type,
						'title'  => $card_type,
						'width'  => '30',
						'height' => '20',
						'style'  => 'width: 30px; height: 20px;',
					)
				);
			}
			echo ' &bull; &bull; &bull; ' . esc_html( $last_four );
		}
	}

	/**
	 * This method handles UI logic for default payment method.
	 *
	 * @param array $method payment method.
	 */
	public function uc_add_payment_method_default( $method ) {
		if ( $method['is_default'] && $this->gateway->get_id() === $method['method']['gateway'] ) {
			echo '<mark class="default">' . esc_html__( 'Default', 'visa-acceptance-solutions' ) . '</mark>';
		}
	}

	/**
	 * Sends image url.
	 *
	 * @param mixed $type card type.
	 */
	public function get_image_url( $type ) {

		$image_type = strtolower( $type );
		if ( VISA_ACCEPTANCE_CARD === $type ) {
			$image_type = VISA_ACCEPTANCE_CC_PLAIN;
		}
		$image_extension = VISA_ACCEPTANCE_SVG_EXTENSION;
		if ( is_readable( $this->get_payment_gateway_framework_assets_path() . '/img/card-' . $image_type . $image_extension ) ) {
			return \WC_HTTPS::force_https_url( $this->get_payment_gateway_framework_assets_url() . '/img/card-' . $image_type . $image_extension );
		}
		if ( is_readable( $this->get_payment_gateway_framework_assets_path() . '/img/card-' . $image_type . $image_extension ) ) {
			return \WC_HTTPS::force_https_url( $this->get_payment_gateway_framework_assets_url() . '/img/card-' . $image_type . $image_extension );
		}
		return null;
	}

	/**
	 * Payment gateway assests path.
	 */
	public function get_payment_gateway_framework_assets_path() {
		return untrailingslashit( plugin_dir_path( __FILE__ ) . '../public' );
	}

	/**
	 * Payment gateway assests url.
	 */
	public function get_payment_gateway_framework_assets_url() {
		return untrailingslashit( plugins_url( '/../public/', __FILE__ ) );
	}


	/**
	 * Handles post.
	 *
	 * @param WP_REST_Request $request request.
	 */
	public function handle_post( WP_REST_Request $request ) {
		$request_data = $request;
		$post_data    = $_POST; //phpcs:ignore
		if ( isset( $post_data['TransactionId'] ) ) {
			$transaction_id = wc_clean( wp_unslash( $post_data['TransactionId'] ) );
			// Changing the header Content type so that it will execute the script below, not just print it.
			header( VISA_ACCEPTANCE_CONTENT_TYPE_HEADER );
			// Calling invokevalidation function.
			echo "<script>window.parent.invokeValidation('" . esc_js( $transaction_id ) . "');</script>";
			exit();
		} else {
			$message = __( 'We encountered an error. Please try again later.', 'visa-acceptance-solutions' );
			$this->mark_order_failed( $message );
			$checkout_url = esc_url( wc_get_checkout_url() );
			header( 'Content-Type: text/html' );
			echo "<script>window.parent.location.href = '" . esc_js( $checkout_url ) . "';</script>";
			exit();
		}
	}
	/**
	 * Add custom notice at Payment method page.
	 *
	 * @param string $icon Icon.
	 * @param int    $id Id.
	 */
	public function custom_gateway_icon( $icon, $id ) {
		$notice_message = esc_html__( 'We will contact your card issuer to verify your account. No payment will be taken.', 'visa-acceptance-solutions' );
		if ( is_add_payment_method_page() && $id === $this->wc_payment_gateway_id ) {
			wc_print_notice( $notice_message, 'notice' );
		}
	}

	/**
	 * Trigger delete the payment method service.
	 *
	 * @param any $check check.
	 * @param any $token token.
	 * @param any $force_delete force_delete.
	 */
	public function wp_kama_woocommerce_pre_delete_object_type_filter( $check, $token, $force_delete ) {
        $forced_delete = $force_delete;
        try {
            if ( $token instanceof \WC_Payment_Token && $this->gateway->get_id() === $token->get_gateway_id() ) {
                $core_tokens = \WC_Payment_Tokens::get_customer_tokens( $token->get_user_id(), $this->gateway->get_id() );
                $response    = $token->is_default() && VISA_ACCEPTANCE_VAL_ONE < count( $core_tokens ) ? array( 'http_code' => 409 ) : $this->uc_payment_token_deleted( $token->get_id(), $token );
                if ( isset( $response['http_code'] ) && ( VISA_ACCEPTANCE_TWO_ZERO_FOUR === (int) $response['http_code'] || VISA_ACCEPTANCE_FOUR_ZERO_FOUR === (int) $response['http_code'] ) ) {
                    return $check;
                } elseif ( ( isset( $response['http_code'] ) && 409 === (int) $response['http_code'] ) ) {
                    if ( is_admin() ) {
                        return $check;
                    }
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( esc_html__( 'Please set another card to default and try deleting the card again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_STRING_ERROR );
                    }
                    if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
                        wp_safe_redirect( wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS ) );
                        exit();
                    }
                } else {
                    wc_add_notice( esc_html__( 'Card deletion failed. Please try again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_STRING_ERROR );
                    wp_safe_redirect( wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS ) );
                    exit();
                }
            } else {
                return $check;
            }
        } catch ( \Exception $e ) {
            $log_header = VISA_ACCEPTANCE_CARD_DELETION;
            $this->gateway->add_logs_data( $e->getMessage(), false, $log_header );
            if ( is_admin() ) {
                return $check;
            }
            if ( function_exists( 'wc_add_notice' ) ) {
                wc_add_notice( esc_html__( 'Card deletion failed. Please try again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_STRING_ERROR );
            }
            if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
                wp_safe_redirect( wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS ) );
                exit();
            }
        }
    }

	/**
	 * Marks default card as default and remaining non default in db
	 */
	public function set_token_default() {
		$data_store                = WC_Data_Store::load( 'payment-token' );
		$customer_data             = $this->get_order_for_add_payment_method();
		$core_tokens               = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
		$default_payment_method_id = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_default_card_id", VISA_ACCEPTANCE_STRING_EMPTY );
		foreach ( $core_tokens as $token ) {
			if ( (int) $default_payment_method_id === $token->get_id() ) {
				$data_store->set_default_status( $token->get_id(), true );
			} else {
				$data_store->set_default_status( $token->get_id(), false );
			}
		}
	}

	/**
	 * Triggers card default service
	 *
	 * @param Token $token Token.
	 * 
	 * @return array
	 */
	public function uc_payment_token_default($token) {
		$payment_method    = new Visa_Acceptance_Payment_Methods( $this );
		$token_data       = $payment_method->build_token_data( $token );
		$request         = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client      = $request->get_api_client(true);
		$payments_api    = new CustomerPaymentInstrumentApi( $api_client );
		$payload = [
			'default' => true,
		];
		if ( ! empty( $payload ) ) {
			$this->gateway->add_logs_data( wp_json_encode($payload), true, 'Set as default Payment Method' );
			$patch_customer_payment_instrument_request = new PatchCustomerPaymentInstrumentRequest($payload);
			try {
				$api_response = $payments_api->patchCustomersPaymentInstrument( $token_data['token_information']['id'], $token_data['token_information']['payment_instrument_id'],$patch_customer_payment_instrument_request  );
				$this->gateway->add_logs_service_response( $api_response[0],$api_response[2][VISA_ACCEPTANCE_V_C_CORRELATION_ID], true, 'Set as default Payment Method' );
				$return_array = array(
					'http_code' => $api_response[1],
					'body'      => $api_response[0],
				);
				return $return_array;
			} catch ( \CyberSource\ApiException $e ) {
				$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, 'Set as default Payment Method' );
			}
		}
	}

	/**
	 * Trigger default the payment method service.
	 *
	 * @param any $token_id token id.
	 * @param any $token token.
	 */
	public function wp_kama_woocommerce_set_default( $token_id, $token ) {
		try {
			$customer_data    = $this->get_order_for_add_payment_method();
			$core_tokens      = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
			$default_token_id = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_default_card_id", null );
			if ( $token instanceof \WC_Payment_Token && $this->gateway->get_id() === $token->get_gateway_id() && VISA_ACCEPTANCE_VAL_ONE < count( $core_tokens ) && ! $this->is_user_in_add_payment_method_page() && ! is_checkout() && (int) $token_id !== (int) $default_token_id ) {
				$response      = $this->uc_payment_token_default( $token );
				$response_body = json_decode( $response['body'] );
				$default_state = $response_body->default;
				if ( ( isset( $response['http_code'] ) && VISA_ACCEPTANCE_TWO_ZERO_ZERO === (int) $response['http_code'] ) || $default_state ) {
					return;
				} else {
					$this->set_token_default();
					wc_clear_notices();
					wc_add_notice( esc_html__( 'Failed to update as default payment method. Please try again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_STRING_ERROR );
					wp_safe_redirect( wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS ) );
					exit();
				}
			} else {
				return;
			}
		} catch ( \Exception $e ) {
			$log_header = 'Set as default Payment Method';
			$this->gateway->add_logs_data( $e->getMessage(), false, $log_header );
			wc_add_notice( esc_html__( 'Failed to update as default payment method. Please try again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_STRING_ERROR );
			wp_safe_redirect( wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS ) );
			exit();
		}
	}

	/**
	 * Gives saved payment methods.
	 *
	 * @param array  $method method.
	 * @param object $payment_token payment token.
	 * @return Customer
	 */
	public function wp_kama_woocommerce_saved_payment_methods_list_filter( $method, $payment_token ) {
		if ( $payment_token->get_gateway_id() === $this->gateway->get_id() ) {
			$method['token'] = $payment_token->get_token();
			if ( $payment_token->get_is_default() ) {
				WC()->session->set( "wc_{$this->wc_payment_gateway_id}_default_card_id", wc_clean( $payment_token->get_id() ) );
			}
		}
		return $method;
	}
}
