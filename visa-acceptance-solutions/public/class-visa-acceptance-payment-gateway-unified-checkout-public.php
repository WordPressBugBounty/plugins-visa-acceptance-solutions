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

use Pymt_Vas\Dependencies\CyberSource\Api\CustomerPaymentInstrumentApi;
use Pymt_Vas\Dependencies\CyberSource\Model\PatchCustomerPaymentInstrumentRequest;

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
		add_filter( 'script_loader_tag', array( $this, 'add_sri_integrity_to_script' ), VISA_ACCEPTANCE_VAL_TEN, VISA_ACCEPTANCE_VAL_THREE );
		// Restore notices from session for guest users on product page.
		add_action( 'woocommerce_before_single_product', array( $this, 'restore_notices_from_session' ), VISA_ACCEPTANCE_VAL_FIVE );
		// Filter payment tokens to hide eCheck for free trial.
        add_filter( 'woocommerce_get_customer_payment_tokens', array( $this, 'filter_payment_tokens_for_free_trial' ), VISA_ACCEPTANCE_VAL_TEN, VISA_ACCEPTANCE_VAL_TWO );
		add_action( 'send_headers', array( $this, 'add_clickjacking_headers' ) );
        add_action( 'wp_head', array( $this, 'add_clickjacking_script' ), 1 );
        add_action( 'current_screen', array( $this, 'admin_clickjacking_script' ) );
    }

	/**
	 * Only hook the anti-clickjack output into `admin_head` on this plugin's own screens,
	 * so it never hides the dashboard for unrelated admin pages.
	 *
	 * @param \WP_Screen $screen Current admin screen.
	 * @return void
	 */
	public function admin_clickjacking_script( $screen ) {
		$plugin_screens = array( 'woocommerce_page_wc-settings', 'shop_order', 'woocommerce_page_wc-orders' );
		if ( $screen && in_array( $screen->id, $plugin_screens, true ) ) {
			add_action( 'admin_head', array( $this, 'add_clickjacking_script' ), 1 );
		}
	}
    /**
     * Add clickjacking protection headers (X-Frame-Options / CSP frame-ancestors).
     *
     * @return void
     */
    public function add_clickjacking_headers() {
        if ( defined( 'VISA_ACCEPTANCE_CLICKJACK_HEADERS_SENT' ) ) {
            return;
        }
        define( 'VISA_ACCEPTANCE_CLICKJACK_HEADERS_SENT', true );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( "Content-Security-Policy: frame-ancestors 'self'" );
    }
    
	/**
     * Add JavaScript-based clickjacking protection.
     *
     * @return void
     */
    public function add_clickjacking_script() {
        if ( defined( 'VISA_ACCEPTANCE_CLICKJACK_SCRIPT_OUTPUT' ) ) {
            return;
        }
        define( 'VISA_ACCEPTANCE_CLICKJACK_SCRIPT_OUTPUT', true );

        // Compute the destination server-side (never from window.location) so the redirect.
        // target is not attacker-influenced client-side data.
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $trusted_url = esc_url_raw( home_url( $request_uri ) );

        wp_register_style( 'vas-anti-clickjack', false, array(), $this->version );
        wp_enqueue_style( 'vas-anti-clickjack' );
        wp_add_inline_style( 'vas-anti-clickjack', '#antiClickjack, html.vas-anti-clickjack { display: none !important; }' );

        wp_register_script( 'vas-anti-clickjack', false, array(), $this->version, false );
        wp_enqueue_script( 'vas-anti-clickjack' );
        wp_add_inline_script(
            'vas-anti-clickjack',
            '(function() {
			\'use strict\';

			// Server-rendered, sanitized destination URL - not derived from window.location.
			var trustedUrl = ' . wp_json_encode( $trusted_url ) . ';

			function showPage() {
				document.documentElement.style.display = "block";
			}

			function breakOut() {
				try {
					var target;
					try {
						target = new URL(trustedUrl);
					} catch (e) {
						document.body.innerHTML = \'\';
						return;
					}
					if (target.protocol !== \'http:\' && target.protocol !== \'https:\') {
						document.body.innerHTML = \'\';
						return;
					}
					if (top.location.origin !== target.origin) {
						// Cross-origin framing detected - clear page content.
						document.body.innerHTML = \'\';
						return;
					}
					if (top.location.href !== target.href) {
						top.location.replace(target.href);
					}
				} catch (e) {
					// If we can\'t access top.location due to cross-origin,
					// the page is being framed by an untrusted domain.
					document.body.innerHTML = \'\';
				}
			}

			// Check if page is being framed.
			if (self === top) {
				// Not in a frame, safe to display.
				showPage();
			} else {
				// Page is in a frame, attempt to break out.
				breakOut();
			}
			})();'
        );
    }

	/**
	 * Restore notices from session for guest users on product page.
	 */
	public function restore_notices_from_session() {
		if ( ! is_user_logged_in() && WC()->session && is_product() ) {
			$stored_notice_data = WC()->session->get( 'uc_error_notice' );
			if ( ! empty( $stored_notice_data ) && is_array( $stored_notice_data ) ) {
				$current_product_id = get_the_ID();
				$stored_product_id = isset( $stored_notice_data['product_id'] ) ? $stored_notice_data['product_id'] : VISA_ACCEPTANCE_VAL_ZERO;
				$notice_message = isset( $stored_notice_data['message'] ) ? $stored_notice_data['message'] : VISA_ACCEPTANCE_STRING_EMPTY;
				
				// Only show notice if we're on the same product page where error occurred.
				if ( $current_product_id === $stored_product_id && ! empty( $notice_message ) ) {
					wc_add_notice( $notice_message, VISA_ACCEPTANCE_ERROR );
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
	 * Add SRI integrity attribute to script tags.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle The script handle.
	 * @param string $src    The script source URL (unused but required by WordPress filter).
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
			// Don't load credit card CSS on product pages - express pay product page CSS handles that.
			if ( ! is_product() ) {
				wp_enqueue_style( $this->wc_payment_gateway_id, plugin_dir_url( __FILE__ ) . 'css/visa-acceptance-payment-gateway-credit-card-public.css', array(), $this->version, 'all' );
			}
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
		// Reading GET parameters for conditional script loading (public routing data, no nonce required).
		$get_data = isset( $_GET ) ? wp_unslash( $_GET ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public routing data only.
		if ( ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) || isset( $get_data['pay_for_order'] ) ) {
			if ( ! wp_doing_ajax() && isset( WC()->session ) ) {
				WC()->session->set( VISA_ACCEPTANCE_COOKIE_SCA_COUNT, '0' );
				// Clear stale JWT tokenization value so payment_fields() always waits for the fresh JWT read.
				WC()->session->set( "wc_{$this->wc_payment_gateway_id}_jwt_tokenization", null );
				WC()->session->set( "wc_{$this->wc_payment_gateway_id}_capture_context_jwt", null );
				// Store order ID in session so AJAX capture-context calls can resolve the correct order total.
				if ( isset( $get_data['pay_for_order'] ) ) {
					$pay_order_id = absint( get_query_var( 'order-pay' ) ?: get_query_var( get_option( 'woocommerce_checkout_pay_endpoint', 'order-pay' ) ) );
					if ( $pay_order_id > VISA_ACCEPTANCE_VAL_ZERO ) {
						WC()->session->set( 'vas_order_pay_id', $pay_order_id );
					}
				} else {
					WC()->session->set( 'vas_order_pay_id', null );
				}
			}
		}
		if ( ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) || isset( $get_data['pay_for_order'] ) || is_account_page() ) {
			$uc_settings       = $this->get_uc_settings();
			$payment_method    = new Visa_Acceptance_Payment_Methods( $this );
			$flex_request      = new Visa_Acceptance_Key_Generation( $this->gateway );
			$customer_data     = $payment_method->get_order_for_add_payment_method();
			$core_tokens       = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->wc_payment_gateway_id );
			$token_count       = count( $core_tokens );
			$token_type        = array();
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
			// Detect change payment method context.
            $is_change_payment_method = false;
            if ( $subscription_active && class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
                $is_change_payment_method = WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
            }
			foreach ( $core_tokens as $token ) {
				if ( ! $token instanceof \WC_Payment_Token ) {
					continue;
				}
                $data = $token->get_data();
                if ( $data['id'] ) {
                    // Handle both credit card and eCheck tokens - if card_type is null/empty, it's an eCheck.
                    $token_type[ $data['id'] ] = ! empty( $data['card_type'] ) ? $data['card_type'] : VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK;
                }
            }
			// Load capture context for both normal checkout and Express Pay scenarios.
			if ( is_checkout() || $this->is_user_in_add_payment_method_page() || isset( $get_data['pay_for_order'] ) ) {
				// Get both capture contexts and handle "response" key in one loop.
				if ( ! $is_subscription_tokenization_enabled && $subscription_order ) {
					$contexts = ['jwt' => null];
				}
				elseif ( $token_count > VISA_ACCEPTANCE_VAL_ZERO && ! $this->is_user_in_add_payment_method_page() ) {
					// Skip normal checkout JWT when saved cards exist, but still generate Express Pay JWT if enabled.
					$contexts = ['jwt' => null];
					
					$enable_express_pay = isset( $uc_settings['enable_express_pay'] ) && VISA_ACCEPTANCE_YES === $uc_settings['enable_express_pay'];
					if ( $enable_express_pay ) {
						$contexts['ep_jwt'] = $flex_request->get_unified_checkout_capture_context(true);
					}
				}
				else {
                    $contexts = [
                        'jwt' => $flex_request->get_unified_checkout_capture_context(),
                    ];
                    // Only generate Express Pay JWT if enable_express_pay is enabled.
                    $enable_express_pay = isset( $uc_settings['enable_express_pay'] ) && VISA_ACCEPTANCE_YES === $uc_settings['enable_express_pay'];
                    if ( $enable_express_pay && ! $this->is_user_in_add_payment_method_page() ) {
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
					$context = is_string($body) ? $body : VISA_ACCEPTANCE_STRING_EMPTY;
					// Record completeMandate.type by jti so order status mapping is correct at completion.
					if ( ! empty( $context ) ) {
						$this->store_capture_context_mandate_type( $context );
					}
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
				if ( ( ! empty( $contexts['jwt'] ) || ! empty( $contexts['ep_jwt'] ) ) && isset( WC()->session ) && isset( WC()->cart ) ) {
					// On order-pay page the cart is empty; use the order total to avoid caching 0.
					$pay_order_id_session = absint( WC()->session->get( 'vas_order_pay_id' ) );
					if ( $pay_order_id_session > VISA_ACCEPTANCE_VAL_ZERO ) {
						$pay_order_obj    = wc_get_order( $pay_order_id_session );
						$enqueue_cart_total = ( $pay_order_obj instanceof \WC_Order ) ? $pay_order_obj->get_total() : WC()->cart->get_totals()['total'];
					} else {
						$enqueue_cart_total = WC()->cart->get_totals()['total'];
					}
					$enqueue_chosen_ship   = WC()->session->get( 'chosen_shipping_methods' );
					WC()->session->set( "wc_{$this->wc_payment_gateway_id}_capture_context_total_amount", $enqueue_cart_total );
					WC()->session->set( "wc_{$this->wc_payment_gateway_id}_shipping_hash", md5( wp_json_encode( $enqueue_chosen_ship ) ) );
				}
				// Get client library from normal JWT, or fallback to Express Pay JWT if normal JWT is not available.
				$client_library = !empty($contexts['jwt']) 
					? $this->get_uc_client_library($contexts['jwt']) 
					: ( !empty($contexts['ep_jwt']) ? $this->get_uc_client_library($contexts['ep_jwt']) : array( 'url' => VISA_ACCEPTANCE_STRING_EMPTY, 'integrity' => VISA_ACCEPTANCE_STRING_EMPTY ) );

				$jwt_to_parse = ! empty( $contexts['jwt'] ) ? $contexts['jwt'] : ( ! empty( $contexts['ep_jwt'] ) ? $contexts['ep_jwt'] : VISA_ACCEPTANCE_STRING_EMPTY );
                $jwt_config = $this->get_capture_context_jwt_config( $jwt_to_parse );

				if ( ! empty( $contexts['jwt'] ) && ( null !== $jwt_config['request_save_credentials'] || null !== $jwt_config['token_create'] ) ) {
					$tokenize_on  = ( true === $jwt_config['request_save_credentials'] || true === $jwt_config['token_create'] );
					$tokenization = $tokenize_on ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO;
				}

				if ( isset( WC()->session ) ) {
                    if ( ! empty( $jwt_config['complete_mandate_type'] ) ) {
                        WC()->session->set( "wc_{$this->wc_payment_gateway_id}_complete_mandate_type", $jwt_config['complete_mandate_type'] );
                    } else {
                        // Clear any old value from session if new JWT doesn't have mandate type.
                        WC()->session->set( "wc_{$this->wc_payment_gateway_id}_complete_mandate_type", null );
                    }
                }

				if ( true === $jwt_config['decision_manager'] ) {
					$sessionid = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_device_data_session_id", VISA_ACCEPTANCE_STRING_EMPTY );
					if ( empty( $sessionid ) ) {
						$session_id = wp_generate_uuid4();
						WC()->session->set( "wc_{$this->wc_payment_gateway_id}_device_data_session_id", wc_clean( $session_id ) );
						$sessionid = $session_id;
					}
					$organization_id = VISA_ACCEPTANCE_ENVIRONMENT_TEST === $uc_settings[ VISA_ACCEPTANCE_ENVIRONMENT ] ? VISA_ACCEPTANCE_DF_ORG_ID_TEST : VISA_ACCEPTANCE_DF_ORG_ID_PROD;
					wp_enqueue_script( "wc-{$this->wc_payment_gateway_id}-device-data", self::get_dfp_url( $organization_id, $this->get_merchant_id(), $sessionid, true ), array(), $this->version, false );
				}
			}
			if ( isset( $uc_settings['enabled'] ) && ( VISA_ACCEPTANCE_YES === $uc_settings['enabled'] ) ) {
				wp_enqueue_style( $this->wc_payment_gateway_id, plugin_dir_url( __FILE__ ) . 'css/visa-acceptance-payment-gateway-public.css', array(), $this->version, VISA_ACCEPTANCE_STRING_ALL );
					// Enqueue additional unified checkout styles (extracted from inline styles for CSP compliance).
				wp_enqueue_style( $this->wc_payment_gateway_id . '-unified-checkout', plugin_dir_url( __FILE__ ) . 'css/visa-acceptance-unified-checkout-public.css', array( $this->wc_payment_gateway_id ), $this->version, VISA_ACCEPTANCE_STRING_ALL );
				if ( VISA_ACCEPTANCE_NO === $tokenization ) {
					$hide_saved_cards_css = '#wc-unified-checkout-saved-cards-options{display:none !important;} #wc-unified-checkout-saved-card{display:none !important;}';
					wp_add_inline_style( $this->wc_payment_gateway_id . '-unified-checkout', $hide_saved_cards_css );
				}
				// Enqueue Unified Checkout JS library with SRI integrity.
				if ( ! empty( $client_library['url'] ) ) {
					wp_enqueue_script( 'unified-checkout-library', $client_library['url'], array(), $this->version, false );
					// Store SRI integrity attribute if available.
					if ( ! empty( $client_library['integrity'] ) ) {
						self::$script_integrity['unified-checkout-library'] = $client_library['integrity'];
					}
				}
				// CVV verification for saved cards is always enabled — not controlled by admin setting.
				$saved_card_cvv = true;
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
						'tokenization'                    => $tokenization,
						'subscription_order'              => $subscription_order,
						'is_change_payment_method'        => $is_change_payment_method,
						'is_admin_order_page'             => is_admin(),
						'visa_acceptance_solutions_uc_id' => VISA_ACCEPTANCE_UC_ID,
						'visa_acceptance_solutions_uc_id_hyphen' => VISA_ACCEPTANCE_UC_ID_HYPHEN,
						'token_key'                       => $token_key,
						'saved_card_cvv'                  => $saved_card_cvv,
						'enable_express_pay'              => $enable_express_pay ?? false,
						'form_load_error'                 => __( 'Unable to load the payment form. Please contact customer care for any assistance.', 'visa-acceptance-solutions' ),
						'delete_card_text'                => __( 'Are you sure you want to delete this payment method?', 'visa-acceptance-solutions' ),
						'offline_text'                    => __( 'You are not connected to internet!!', 'visa-acceptance-solutions' ),
						'error_failure'                   => __( 'Unable to process your request. Please try again later.', 'visa-acceptance-solutions' ),
						'is_subscription_tokenization_enabled' => $is_subscription_tokenization_enabled,
						'store_browser_data_nonce'        => wp_create_nonce( 'store_browser_data_action' ),
						'get_saved_card_context_nonce'    => wp_create_nonce( 'wc_get_saved_card_context' ),
						'uc_library_proxy_nonce'          => wp_create_nonce( 'vas_uc_library_proxy' ),
						'get_addresses_nonce'             => wp_create_nonce( 'vas_get_addresses_nonce' ),
						'gateway_id'                      => $this->gateway->get_id(),
						'update_price_nonce'              => wp_create_nonce( 'wc_call_uc_update_price_action' ),
						'checkout_display_mode'           => isset( $uc_settings['checkout_display_mode'] ) ? $uc_settings['checkout_display_mode'] : 'sidebar',
						'order_pay_id'                    => isset( $get_data['pay_for_order'] ) ? absint( get_query_var( 'order-pay' ) ?: get_query_var( get_option( 'woocommerce_checkout_pay_endpoint', 'order-pay' ) ) ) : VISA_ACCEPTANCE_VAL_ZERO,
					)
					);
				
			}
		}
	}

	/**
	 * Gets merchant id for particular gateway.
	 *
	 * @return string|null
	 */
	public function get_merchant_id() {
		$settings = $this->gateway->get_config_settings();
		if ( isset( $settings[VISA_ACCEPTANCE_ENVIRONMENT] ) && VISA_ACCEPTANCE_ENVIRONMENT_PRODUCTION === $settings[VISA_ACCEPTANCE_ENVIRONMENT] ) {
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
		$get_data = isset( $_GET ) ? wp_unslash( $_GET ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public routing data only.
		$settings           = $this->get_uc_settings();
		$force_tokenization = false;
		if ( is_checkout() || isset( $get_data['pay_for_order'] ) ) {
			$force_tokenization = $this->gateway->is_wc_subscriptions_activated() && ( WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal() || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment );
			$description = $settings['description'];
			if ( $description ) {
				echo wp_kses_post( wpautop( wptexturize( $description ) ) );
			}
			$payment_method = new Visa_Acceptance_Payment_Methods( $this->gateway );
			$customer_data  = $payment_method->get_order_for_add_payment_method();
			$core_tokens    = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
			if ( ! empty( $core_tokens ) && ! wp_doing_ajax() ) {
                // Not gated on wp_doing_ajax(): WooCommerce re-renders this box via AJAX
                // (update_checkout) too, so skipping it there left the visible card stale.
                $payment_method->refresh_all_tokens_from_tms( $core_tokens);
            }
			
			// Track if any saved cards were rendered and if any is checked.
			$has_saved_cards = false;
			$has_default_card = false;
			$is_first_card = true;
			// Determine if the current order is a free trial to handle eCheck token exclusion.
            $key_gen_req_tmp = new Visa_Acceptance_Key_Generation_Request( $this->gateway );
            $order_pay_data  = $key_gen_req_tmp->get_admin_checkout_total_amount();
            if ( $order_pay_data['is_admin_order_pay_page'] ) {
                $cart_total = $order_pay_data['total_amount'];
            } else {
                $cart_total = isset( WC()->cart ) ? WC()->cart->get_totals()['total'] : VISA_ACCEPTANCE_ZERO_AMOUNT;
            }
            $is_free_trial = ( (float) VISA_ACCEPTANCE_ZERO_AMOUNT === (float) $cart_total );
			if ( ! empty( $core_tokens ) ) {
				foreach ( $core_tokens as $token ) {
					if ( ! $token instanceof \WC_Payment_Token ) {
                    	continue;
                	}
					$environment_saved = $token->get_meta( VISA_ACCEPTANCE_ENVIRONMENT );
					if ( $environment_saved === $settings[VISA_ACCEPTANCE_ENVIRONMENT] ) {
						$token_data    = $token->get_data();
						$card_type     = $token_data['card_type'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
						// Skip eCheck tokens for free trial orders ($0.00 total).
                        $is_echeck_token = ( VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $card_type ) || ( VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $token->get_type() );
                        if ( $is_free_trial && $is_echeck_token ) {
                            continue;
                        }
                        $has_saved_cards = true;
                        $last_four     = $token_data['last4'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
						$id_dasherized = $this->gateway->get_id_dasherized();
						// CustomerId change for Cvv.
						$id        = $token_data['id'];
						
						
						// Check if this is the default card, or if no default exists, check the first card.
						if ( $token->is_default() ) {
							$checked = 'checked="checked"';
							$has_default_card = true;
						} elseif ( $is_first_card && ! $has_default_card ) {
							$checked = 'checked="checked"';
						} else {
							$checked = VISA_ACCEPTANCE_STRING_EMPTY;
						}
						$is_first_card = false;
						
						$exp_month = $token_data['expiry_month'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
                        $exp_year  = $token_data['expiry_year'] ?? VISA_ACCEPTANCE_STRING_EMPTY;


						echo '<div id="wc-unified-checkout-saved-cards-options">' .
						'<input type="radio" id="wc-' . esc_attr( $id_dasherized ) . '-payment-token-' . esc_attr( $id ) . '" name="wc-' . esc_attr( $id_dasherized ) . '-payment-token" class="js-wc-' . esc_attr( $id_dasherized ) . '-payment-token wc-vas-payment-token-radio" value="' . esc_attr( $id ) . '" ' . esc_attr( $checked ) . '/>';
						$expiry_text = $is_echeck_token ? VISA_ACCEPTANCE_STRING_EMPTY : ' (expires ' . esc_html( $exp_month ) . esc_html( VISA_ACCEPTANCE_SLASH ) . esc_html( $exp_year ) . ')';
						
						echo '<label class="wc-payment-gateway-payment-form-saved-payment-method" for="wc-' . esc_attr( $id_dasherized ) . '-payment-token-' . esc_attr( $id ) . '">' .
							'&bull; &bull; &bull; ' . esc_html( $last_four ) . esc_html( $expiry_text ) .
							'</label></div>';
					}
				}
			}
			
			// Show "Use Other Payment Method" radio ONLY if saved cards exist and tokenization is enabled.
			// When no saved cards exist, UC form will be shown directly without a radio button.
			if ( VISA_ACCEPTANCE_YES === $settings['tokenization'] && $has_saved_cards ) {
				echo '<div id="wc-credit-card-use-new-payment-method-div">' .
					'<input type="radio" id="wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-use-new-payment-method" name="wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-payment-token" class="js-wc-payment-token js-wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-payment-token wc-vas-use-new-payment-radio" value="" />' .
					'<label class="wc-vas-use-new-payment-label" for="wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-use-new-payment-method">' . esc_html__( 'Use Other Payment Method', 'visa-acceptance-solutions' ) . '</label>' .
					'</div>';
			}
			// Reading POST data to check if this is a payment submission (WooCommerce verifies nonce in checkout handler).
			$post_data = isset( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$is_payment_submit_request = (
                ! empty( $post_data['transientToken'] ) ||
                ! empty( $post_data['blocks_token'] ) ||
                ! empty( $post_data['uc_complete_response'] )
            );
 
            // Containers for saved-card payment button, UC form, and error messages.
			echo '<div id="saved-card-pay-btn-container" style="display:none;"></div>' .
				'<div id="saved-card-uc-form-container" style="display:none;"></div>' .
				'<div id="saved-card-uc-error" style="display:none;color:red;margin-top:8px;"></div>';
		}
		if ( is_checkout() || is_add_payment_method_page() || isset( $get_data['pay_for_order'] ) ) {
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
			<div>
				<input type="hidden" id="uc_complete_response" name="uc_complete_response"/>
			</div>

			<?php
			if ( VISA_ACCEPTANCE_YES === $settings['tokenization'] && ( is_checkout() || isset( $get_data['pay_for_order'] ) ) && is_user_logged_in() ) {
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
	 * Gets the capture context for a saved card via AJAX.
	 *
	 */
	public function get_saved_card_capture_context() {
		check_ajax_referer( 'wc_get_saved_card_context', 'nonce' );
		$post_data   = isset( $_POST ) ? wp_unslash( $_POST ) : array();
		$wc_token_id = isset( $post_data['token_id'] ) ? absint( $post_data['token_id'] ) : VISA_ACCEPTANCE_VAL_ZERO;

		if ( empty( $wc_token_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing token ID.' ), VISA_ACCEPTANCE_FOUR_ZERO_ZERO );
			return;
		}

		$wc_token = WC_Payment_Tokens::get( $wc_token_id );
		if ( ! $wc_token || (int) $wc_token->get_user_id() !== (int) get_current_user_id() ) {
			wp_send_json_error( array( 'message' => 'Invalid token.' ), VISA_ACCEPTANCE_FOUR_ZERO_THREE );
			return;
		}
		$token_info = $wc_token->get_meta( 'token_information' );
		if ( is_string( $token_info ) ) {
			$token_info = maybe_unserialize( $token_info );
		}
		$is_echeck = ( VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $wc_token->get_type() );
 
        if ( $is_echeck ) {
            // eCheck tokens are keyed by instrument identifier ID, not payment instrument ID.
            $payment_instrument_id = ( is_array( $token_info ) && isset( $token_info['instrument_identifier_id'] ) )
                ? $token_info['instrument_identifier_id']
                : VISA_ACCEPTANCE_STRING_EMPTY;
        } else {
            $payment_instrument_id = ( is_array( $token_info ) && isset( $token_info['payment_instrument_id'] ) )
                ? $token_info['payment_instrument_id']
                : VISA_ACCEPTANCE_STRING_EMPTY;
        }

		if ( empty( $payment_instrument_id ) ) {
			wp_send_json_error( array( 'message' => 'Payment instrument ID not found.' ), VISA_ACCEPTANCE_FOUR_ZERO_ZERO );
			return;
		}

		$flex_request = new Visa_Acceptance_Key_Generation( $this->gateway );
		$response     = $flex_request->get_tms_token_capture_context( $payment_instrument_id, $is_echeck );

		if ( VISA_ACCEPTANCE_TWO_ZERO_ONE !== (int) $response['http_code'] || empty( $response['body'] ) ) {
			wp_send_json_error( array( 'message' => 'Failed to generate capture context.' ), VISA_ACCEPTANCE_FIVE_ZERO_ZERO );
			return;
		}

		// Extract UC library URL and integrity hash from the capture context JWT.
		$capture_context_jwt = $response['body'];

		// Free trial ($0 total) saved-card contexts must resolve to PREFER_AUTH, mirroring the forced
		// override already applied to new-card capture contexts for $0 orders (see get_capture_context_request()).
		$key_gen_req_for_trial = new Visa_Acceptance_Key_Generation_Request( $this->gateway );
		$order_pay_data_for_trial = $key_gen_req_for_trial->get_admin_checkout_total_amount();
		$cart_total_for_trial = $order_pay_data_for_trial['is_admin_order_pay_page']
			? $order_pay_data_for_trial['total_amount']
			: ( isset( WC()->cart ) ? WC()->cart->get_totals()['total'] : VISA_ACCEPTANCE_ZERO_AMOUNT );
		$is_free_trial_saved_card = ( (float) VISA_ACCEPTANCE_ZERO_AMOUNT === (float) $cart_total_for_trial );
		$this->store_capture_context_mandate_type(
			$capture_context_jwt,
			$is_free_trial_saved_card ? VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_PREFER_AUTH : null
		);

		$client_library = $this->get_uc_client_library( $capture_context_jwt );

		// Store the resolved client library URL in the session (not the AJAX JSON response)
		// so the client can later request it via the vas_uc_library_proxy same-origin proxy
		// endpoint instead of assigning a response-derived value to `script.src` directly.
		if ( isset( WC()->session ) && ! empty( $client_library['url'] ) ) {
			WC()->session->set( "wc_{$this->wc_payment_gateway_id}_saved_card_client_library_url", $client_library['url'] );
		}

		$response_data = array( 
			'jwt' => $capture_context_jwt,
		);

		// Include integrity hash only; the URL itself is resolved via the session-backed
		// proxy endpoint, never sent in this JSON response.
		if ( ! empty( $client_library['integrity'] ) ) {
			$response_data['client_library_integrity'] = $client_library['integrity'];
		}

		wp_send_json_success( $response_data );
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
		$complete_response_raw = VISA_ACCEPTANCE_STRING_EMPTY;
		$is_save_card          = VISA_ACCEPTANCE_NO;
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
		// Reading POST data during WooCommerce checkout process (WooCommerce verifies nonce in checkout handler).
		$post_data = isset( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout process handles nonce verification.
		if ( isset( $post_data['errorMessage'] ) && VISA_ACCEPTANCE_YES === $post_data['errorMessage'] ) {
			$message = VISA_ACCEPTANCE_INVALID_PAYMENT_DETAIL_ERROR;
            $this->mark_order_failed( $message );
            return array(
                'result'  => VISA_ACCEPTANCE_FAILURE,
                'message' => $message,
            );
		}

		// Check for saved card token ID in POST data. The token ID can be sent in multiple ways depending on the checkout flow.
        $wc_token_id = null;
        if ( ! empty( $post_data['token'] ) ) {
            $wc_token_id = absint( $post_data['token'] );
        } elseif ( ! empty( $post_data['wc-visa-acceptance-solutions-unified-checkout-payment-token'] ) && 'new' !== $post_data['wc-visa-acceptance-solutions-unified-checkout-payment-token'] ) {
            // Standard WooCommerce saved payment method field for normal checkout (all hyphens).
            $wc_token_id = absint( $post_data['wc-visa-acceptance-solutions-unified-checkout-payment-token'] );
        }
       
        if ( ! empty( $post_data['transientToken'] ) ) {
            $transient_token = wc_clean( wp_unslash( $post_data['transientToken'] ) );
        } elseif ( ! empty( $post_data['transienttoken'] ) ) {
            $transient_token = wc_clean( wp_unslash( $post_data['transienttoken'] ) );
        } elseif ( ! empty( $post_data['blocks_token'] ) ) {
			$transient_token = wc_clean( wp_unslash( $post_data['blocks_token'] ) );
		}
		if ( ! empty( $post_data['uc_complete_response'] ) ) {
			$complete_response_raw = wp_unslash( $post_data['uc_complete_response'] );
		}
		// It's needed to get any order details.
		$setting = $this->get_uc_settings();
		$subscription_active = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
		$subscription_order  = false;
		$change_payment_flow = false;
		if ( $subscription_active ) {
			$contains_subscription = function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order_id );
			$contains_renewal      = function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order );
			$is_subscription       = function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order );
			$change_payment_flow   = class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
			$subscription_order    = $contains_subscription || $contains_renewal || $is_subscription || $change_payment_flow;
		}

		if ( ! empty( $post_data['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$candidate_blocks_token = sanitize_text_field( wp_unslash( $post_data['token'] ) );
			// Only treat `token` as a saved-card token id when it is numeric.
			if ( ctype_digit( (string) $candidate_blocks_token ) ) {
				$blocks_token = $candidate_blocks_token;
			}
		}
		if ( ! empty( $post_data['wc-unified-checkout-tokenize-payment-method'] ) ) {
			$is_save_card = sanitize_html_class( wp_unslash( $post_data['wc-unified-checkout-tokenize-payment-method'] ) );
		}
		if ( ! empty( $post_data[ 'wc-' . VISA_ACCEPTANCE_UC_ID_HYPHEN . '-payment-token' ] ) ) {
			$token_id = sanitize_text_field( wp_unslash( $post_data[ 'wc-' . VISA_ACCEPTANCE_UC_ID_HYPHEN . '-payment-token' ] ) );
		}
		$token_parts = ! empty( $transient_token ) ? explode( VISA_ACCEPTANCE_FULL_STOP, $transient_token ) : array();
		$decoded_transient_token = isset( $token_parts[ VISA_ACCEPTANCE_VAL_ONE ] ) ? json_decode( base64_decode( $token_parts[ VISA_ACCEPTANCE_VAL_ONE ] ), true ) : null;// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( isset( $decoded_transient_token['metadata']['consumerPreference']['saveCard'] ) && true === $decoded_transient_token['metadata']['consumerPreference']['saveCard'] && VISA_ACCEPTANCE_YES === $setting['tokenization'] ) {
			$is_save_card = VISA_ACCEPTANCE_YES;
		}
		// Extract payment solution to identify digital wallets (GPay, Apple Pay, Paze).
		$payment_solution_value = isset( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) ? $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] : null;

		// For express pay (digital wallets), update order addresses from transient token if no saved card token is used.
		if ( ! empty( $transient_token ) && ! $token_id && ! $blocks_token ) {
			$is_express_pay = ! empty( $payment_solution_value );
			
			// Update order addresses from transient token for express pay (digital wallets).
			if ( $is_express_pay ) {
				$payment_details_response = $payment_uc->get_payment_details_from_transient_token( $transient_token );
				if ( $payment_details_response && isset( $payment_details_response['body'] ) ) {
					$payment_uc->update_order_addresses_from_payment_details( $order, $payment_details_response['body'] );
				}
			}
		}

		// Save payment solution meta for digital wallets.
		if ( null !== $payment_solution_value ) {
			if ( visa_acceptance_handle_hpos_compatibility() ) {
				$order->add_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_PAYMENT_SOLUTION, $payment_solution_value, false );
				$order->save_meta_data();
			} else {
				add_post_meta( $order->get_id(), VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_PAYMENT_SOLUTION, $payment_solution_value );
			}
		}

		$complete_response_raw = trim( $complete_response_raw );
		if ( in_array( $complete_response_raw, array( 'undefined', 'null', '[object Object]' ), true ) ) {
			$complete_response_raw = VISA_ACCEPTANCE_STRING_EMPTY;
		}
		// Force save card for subscription orders entering CM (token required for renewals).
		if ( $subscription_order && ! $change_payment_flow ) {
			$is_save_card = VISA_ACCEPTANCE_YES;
		}
		// Determine if the payment is an eCheck based on the transient token or saved token.
        $is_echeck = $payment_uc->is_echeck_from_transient_token( (string) $transient_token );
        if ( ! $is_echeck && ( $token_id || $blocks_token ) ) {
            $saved_token_lookup = $token_id ? $this->get_meta_data_token( $token_id ) : $this->get_meta_data_token( $blocks_token );
            if ( $saved_token_lookup && VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $saved_token_lookup->get_type() ) {
                $is_echeck = true;
            }
        }
		$is_normal_complete = ! empty( $complete_response_raw );
		if ( ! is_add_payment_method_page() && $is_normal_complete && ! $change_payment_flow) {
			$is_express_pay = ! empty( $payment_solution_value );
			try {
				$result = $this->handle_complete_mandate_response( $order, $complete_response_raw, $is_save_card, $payment_uc, $transient_token, $is_express_pay, $is_echeck, $wc_token_id );
			} catch ( \Exception $e ) {
				$this->gateway->add_logs_data( $e->getMessage(), true, 'CM Exception' );
				$result = array(
					VISA_ACCEPTANCE_SUCCESS => false,
					VISA_ACCEPTANCE_ERROR   => true,
					'message'               => __( 'Unable to complete payment. Please try again.', 'visa-acceptance-solutions' ),
				);
			}
			return $this->build_process_payment_response( $result, $order );
		}
		// For digital wallet paths without a complete response, never save card.
		if ( null !== $payment_solution_value ) {
			$is_save_card = VISA_ACCEPTANCE_NO;
		}
		if ( $subscription_active && class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) {
			$saved_token_id = ! empty( $token_id ) ? $token_id : $blocks_token;
			return $subscriptions->change_payment_method( $saved_token_id, $order, $transient_token, $complete_response_raw );
		}
		// Initialize result to prevent undefined variable errors.
		$result = null;
		if ( $blocks_token || $token_id ) {
			if ( ! isset( $setting['tokenization'] ) || VISA_ACCEPTANCE_YES !== $setting['tokenization'] ) {
				$message = __( 'Saved cards are not available. Please enter your card details to complete the purchase.', 'visa-acceptance-solutions' );
				$this->mark_order_failed( $message );
				return array(
					'result'  => VISA_ACCEPTANCE_FAILURE,
					'message' => $message,
				);
			}

			if ( $token_id ) {
				$token = $this->get_meta_data_token( $token_id );
				if ( $token ) {
					$result = $authorization_saved_card->do_transaction( $order, $token, false );
				}
			} else {
				$blocks_meta_token = $this->get_meta_data_token( $blocks_token );
				if ( $blocks_meta_token ) {
					$result = $authorization_saved_card->do_transaction( $order, $blocks_meta_token, false );
				}
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
			if ( ! is_product()) {
				WC()->cart->empty_cart();
			}
			$response_array = array(
				'result'   => VISA_ACCEPTANCE_SUCCESS,
				'redirect' => $this->gateway->get_return_url( $order ),
			);
		} elseif ( is_array($result) && $result[ VISA_ACCEPTANCE_ERROR ] ) {
			$message = $result[ VISA_ACCEPTANCE_ERROR ];
			if ( isset( $result['message'] ) && $result['message'] ) {
				$message = $result['message'];
			}

			if ( isset( $result['detailed_reason'] ) ) {
				$message .= VISA_ACCEPTANCE_BR;
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
				$message .= VISA_ACCEPTANCE_BR;
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
	 * Handles Unified Checkout Complete Mandate response payload.
	 *
	 * @param \WC_Order                    $order Order object.
	 * @param string                       $complete_response_raw Complete response payload.
	 * @param string                       $is_save_card Save card flag.
	 * @param Visa_Acceptance_Payment_UC   $payment_uc Payment helper.
	 * @param string|null                  $transient_token Transient token from checkout.
	 * @param bool                         $is_express_pay True when this is a digital-wallet (Express Pay) transaction.
	 * @param bool                         $is_echeck_from_caller True when caller (process_payment) already identified this as eCheck.
	 * @param int|null                     $wc_token_id WooCommerce saved token ID (if applicable).
	 * @return array
	 */
	private function handle_complete_mandate_response( $order, $complete_response_raw, $is_save_card, $payment_uc, $transient_token = null, $is_express_pay = false, $is_echeck_from_caller = false, $wc_token_id = null ) {
        $complete_payload = json_decode( $complete_response_raw, true );
        if ( is_string( $complete_payload ) ) {
            $complete_payload = json_decode( $complete_payload, true );
        }
 
        if ( ! is_array( $complete_payload ) ) {
            return $this->fail_order_response( __( 'Unable to validate the payment orchestration response. Please try again.', 'visa-acceptance-solutions' ) );
        }
 
        // Extract transaction ID from the complete response.
        $transaction_id = isset( $complete_payload['id'] ) ? sanitize_text_field( (string) $complete_payload['id'] ) : VISA_ACCEPTANCE_STRING_EMPTY;
        if ( empty( $transaction_id ) ) {
            return $this->fail_order_response( __( 'Unable to verify payment details. Please try again.', 'visa-acceptance-solutions' ) );
        }
 
        // Extract status from the complete response.
        $status = isset( $complete_payload['status'] ) ? (string) $complete_payload['status'] : VISA_ACCEPTANCE_STRING_EMPTY;
        // Transaction details (amount, token, processor info) are nested inside "details".
        $details = isset( $complete_payload['details'] ) ? $complete_payload['details'] : array();
 
        // Extract amount and currency from details.orderInformation.
        $amount_details = isset( $details['orderInformation']['amountDetails'] ) ? $details['orderInformation']['amountDetails'] : array();
        $amount   = isset( $amount_details['authorizedAmount'] ) ? $amount_details['authorizedAmount'] : ( isset( $amount_details['totalAmount'] ) ? $amount_details['totalAmount'] : null );
        $currency = isset( $amount_details['currency'] ) ? $amount_details['currency'] : null;
 
        if ( empty( $status ) ) {
            return $this->fail_order_response( __( 'Unable to determine payment status. Please try again.', 'visa-acceptance-solutions' ) );
        }
		
        $settings          = $this->gateway->get_gateway_settings();
        $auth_response     = new Visa_Acceptance_Authorization_Response( $this->gateway );
        $subscriptions     = new Visa_Acceptance_Payment_Gateway_Subscriptions();
        $bill_to_data = isset( $details['orderInformation']['billTo'] ) ? $details['orderInformation']['billTo'] : array();
        if ( ! empty( $bill_to_data ) ) {
            // phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
            $bill_to_field_map = array(
                'email'              => array( 'get_billing_email',      'set_billing_email',      'sanitize_email' ),
                'firstName'          => array( 'get_billing_first_name', 'set_billing_first_name', 'sanitize_text_field' ),
                'lastName'           => array( 'get_billing_last_name',  'set_billing_last_name',  'sanitize_text_field' ),
                'address1'           => array( 'get_billing_address_1',  'set_billing_address_1',  'sanitize_text_field' ),
                'locality'           => array( 'get_billing_city',       'set_billing_city',       'sanitize_text_field' ),
                'administrativeArea' => array( 'get_billing_state',      'set_billing_state',      'sanitize_text_field' ),
                'postalCode'         => array( 'get_billing_postcode',   'set_billing_postcode',   'sanitize_text_field' ),
                'country'            => array( 'get_billing_country',    'set_billing_country',    'sanitize_text_field' ),
                'phoneNumber'        => array( 'get_billing_phone',      'set_billing_phone',      'sanitize_text_field' ),
            );
            foreach ( $bill_to_field_map as $key => $cb ) {
                if ( ! empty( $bill_to_data[ $key ] ) && empty( $order->{$cb[VISA_ACCEPTANCE_VAL_ZERO]}() ) ) {
                    $order->{$cb[VISA_ACCEPTANCE_VAL_ONE]}( $cb[VISA_ACCEPTANCE_VAL_TWO]( $bill_to_data[ $key ] ) );
                }
            }
            // phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
            $order->save();
        }
        // Use eCheck status from caller (process_payment) if already identified from saved token.
        $is_echeck = $is_echeck_from_caller;
       
        //  Check transient token if not already identified.
        if ( ! $is_echeck && null !== $transient_token ) {
            $is_echeck = $payment_uc->is_echeck_from_transient_token( (string) $transient_token );
        }
       
        //Check for eCheck from payment information in complete response.
        if ( ! $is_echeck && isset( $details['paymentInformation']['bank'] ) ) {
            $is_echeck = true;
        }
       
        // Check top-level paymentInformation (alternate structure).
        if ( ! $is_echeck && isset( $complete_payload['paymentInformation']['bank'] ) ) {
            $is_echeck = true;
        }
		$payment_solution_name = isset( $details['processingInformation']['paymentSolution'] ) ? strtolower( (string) $details['processingInformation']['paymentSolution'] ) : VISA_ACCEPTANCE_STRING_EMPTY;
		$payment_card_type     = isset( $details['paymentInformation']['card']['type'] ) ? strtoupper( (string) $details['paymentInformation']['card']['type'] ) : VISA_ACCEPTANCE_STRING_EMPTY;
		$is_bank_transfer_marker = ( false !== strpos( $payment_solution_name, 'ideal' ) || false !== strpos( $payment_solution_name, 'bank transfer' ) || false !== strpos( $payment_solution_name, 'mltbt' ) || 'IDLPP' === $payment_card_type || 'MLTBT' === $payment_card_type );
        if ( ! $is_echeck && VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS === $status && ! $is_bank_transfer_marker && empty( $payment_card_type ) ) {
            $is_echeck = true;
        }
        $is_echeck_valid  = $is_echeck && in_array( $status, array( VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS, VISA_ACCEPTANCE_API_RESPONSE_STATUS_TRANSMITTED ), true );
        $is_echeck_dm_review = $is_echeck && VISA_ACCEPTANCE_API_RESPONSE_ECHECK_DM_STATUS === $status;
        $is_bank_transfer_pending = ( ! $is_echeck ) && (( 'PENDING' === strtoupper( $status ) && $is_bank_transfer_marker ) || 'SETTLE_INITIATED' === strtoupper( $status ));
 
        $has_ics_bill       = false;
        $tx_detail_response = null;
        $approved_statuses  = array( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED, VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_PENDING_REVIEW );
        // APM payments (PayPal, etc.) return COMPLETED status when payment is immediately finalized.
		$is_apm_completed = ! $is_echeck && VISA_ACCEPTANCE_STRING_COMPLETED === $status;
		$complete_mandate_type = VISA_ACCEPTANCE_STRING_EMPTY;
        $complete_response_cc_jti = isset( $complete_payload['metadata']['ccJti'] ) ? (string) $complete_payload['metadata']['ccJti'] : VISA_ACCEPTANCE_STRING_EMPTY;
        if ( ! empty( $complete_response_cc_jti ) && isset( WC()->session ) ) {
            $complete_mandate_types = WC()->session->get(
                "wc_{$this->wc_payment_gateway_id}_complete_mandate_types",
                array()
            );
            if ( is_array( $complete_mandate_types ) && isset( $complete_mandate_types[ $complete_response_cc_jti ] ) ) {
                $complete_mandate_type = strtoupper( trim( (string) $complete_mandate_types[ $complete_response_cc_jti ] ) );
            }
        }
        // Secondary fallback only when no mapped value exists for this ccJti.
        if ( empty( $complete_mandate_type ) && isset( $complete_payload['details']['captureContext'] ) ) {
            $_cm_jwt   = (string) $complete_payload['details']['captureContext'];
            $_cm_parts = explode( VISA_ACCEPTANCE_FULL_STOP, $_cm_jwt );
            if ( ! empty( $_cm_jwt ) && 3 === count( $_cm_parts ) ) {
                $_cm_decoded = json_decode( base64_decode( str_replace( array( '-', '_' ), array( '+', '/' ), $_cm_parts[ VISA_ACCEPTANCE_VAL_ONE ] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
                if ( isset( $_cm_decoded['ctx'][ VISA_ACCEPTANCE_VAL_ZERO ]['data']['completeMandate']['type'] ) ) {
                    $complete_mandate_type = strtoupper( trim( (string) $_cm_decoded['ctx'][ VISA_ACCEPTANCE_VAL_ZERO ]['data']['completeMandate']['type'] ) );
                }
            }
        }
		$is_apm_capture = $is_apm_completed && ( VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_CAPTURE === $complete_mandate_type );
		$is_apm_auth = $is_apm_completed && ( VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_CAPTURE !== $complete_mandate_type );

        if ( ! $is_echeck && in_array( $status, $approved_statuses, true ) ) {
            $tx_detail_response = ( new Visa_Acceptance_Payment_Adapter( $this->gateway ) )->get_transaction_details_sdk( $transaction_id );
            $has_ics_bill       = $this->detect_ics_bill( $tx_detail_response['body'] ?? null );
        } elseif ( $is_echeck && $is_echeck_valid ) {
            // eCheck complete-mandate responses often omit tokenInformation inline; fetch it via transaction details.
            $tx_detail_response = ( new Visa_Acceptance_Payment_Adapter( $this->gateway ) )->get_transaction_details_sdk( $transaction_id );
        }

        // Check if this is a virtual-only order with charge_virtual_orders enabled.
        $settings = $this->get_uc_settings();
        $is_virtual_charge = false;
        if ( isset( $settings['charge_virtual_orders'] ) && VISA_ACCEPTANCE_YES === $settings['charge_virtual_orders'] ) {
            if ( $order && $this->is_order_virtual( $order ) ) {
                $is_virtual_charge = true;
            }
        }
        $is_card_capture = ! $is_echeck
            && ! $is_apm_completed
            && VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status
            && VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_CAPTURE === $complete_mandate_type;

        $is_charge_transaction = $is_echeck
            || $is_virtual_charge
            || $is_apm_capture
            || $has_ics_bill
            || $is_card_capture;
        $this->gateway->encode_complete_log = true;
        $this->gateway->add_logs_data(
            array( 'body' => wp_json_encode( $complete_payload ) ),
            false, VISA_ACCEPTANCE_COMPLETE_MANDATE_STRING
        );
        $this->gateway->encode_complete_log = false;
 
        // Extract processor information for credit authorization details.
        $processor_info = isset( $details['processorInformation'] ) ? $details['processorInformation'] : array();
        $credit_auth_code = isset( $processor_info['approvalCode'] ) ? (string) $processor_info['approvalCode'] : null;
        $credit_auth_response = isset( $processor_info['responseCode'] ) ? (string) $processor_info['responseCode'] : null;
        $credit_auth_network_transaction_id = isset( $processor_info['networkTransactionId'] ) ? (string) $processor_info['networkTransactionId'] : null;
        $processor_transaction_id = isset( $processor_info['transactionId'] ) ? (string) $processor_info['transactionId'] : null;

		// Extract merchant reference number from complete response.
        $merchant_ref_code = isset( $complete_payload['clientReferenceInformation']['code'] )
            ? sanitize_text_field( (string) $complete_payload['clientReferenceInformation']['code'] )
            : ( isset( $details['clientReferenceInformation']['code'] )
                ? sanitize_text_field( (string) $details['clientReferenceInformation']['code'] )
                : VISA_ACCEPTANCE_STRING_EMPTY );

		// Extract timestamp.
        $submit_time = isset( $details['submitTimeUtc'] ) ? sanitize_text_field( (string) $details['submitTimeUtc'] ) : VISA_ACCEPTANCE_STRING_EMPTY;

		// Extract auth method from consumerAuthenticationInformation.
        $auth_method = VISA_ACCEPTANCE_STRING_EMPTY;
        if ( isset( $details['consumerAuthenticationInformation']['authenticationTransactionId'] ) ) {
            $auth_method = 'Strong Authentication (3DS)';
        } elseif ( isset( $details['consumerAuthenticationInformation']['eci'] ) ) {
            $eci_value = $details['consumerAuthenticationInformation']['eci'];
            $ecommerce_indicator = isset( $details['consumerAuthenticationInformation']['ecommerceIndicator'] ) ? strtoupper( (string) $details['consumerAuthenticationInformation']['ecommerceIndicator'] ) : '';
            $auth_method = 'ECI ' . $eci_value;
            if ( ! empty( $ecommerce_indicator ) ) {
                $auth_method .= ' (' . $ecommerce_indicator . ')';
            }
        } elseif ( isset( $details['consumerAuthenticationInformation']['eciRaw'] ) ) {
            $auth_method = 'ECI ' . $details['consumerAuthenticationInformation']['eciRaw'];
        }
 
        // Build payment_response_array — same structure as get_payment_response_array returns.
        $payment_response_array = array(
            'status'                             => $status,
            'request_id'                     	 => $transaction_id,
            'transaction_id'                     => $transaction_id,
            'amount'                             => $amount,
            'currency'                           => $currency,
            'credit_auth_code'                   => $credit_auth_code,
            'credit_auth_response'               => $credit_auth_response,
            'credit_auth_network_transaction_id' => $credit_auth_network_transaction_id,
            'processor_transaction_id'           => $processor_transaction_id,
            'merchant_ref_code'                  => $merchant_ref_code,
            'submit_time'                        => $submit_time,
            'auth_method'                        => $auth_method,
            'reason'                             => null,
            'message'                            => null,
            'httpcode'                           => VISA_ACCEPTANCE_TWO_ZERO_ONE,
        );
 
        if ( $is_echeck_valid || $is_echeck_dm_review || $is_bank_transfer_pending || $is_apm_completed || $auth_response->is_transaction_status_approved( $status ) ) {
            $should_save_token = VISA_ACCEPTANCE_YES === $is_save_card && (
                ( $is_echeck && $is_echeck_valid ) ||
                ( ! $is_echeck && VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ) ||
                ( ! $is_echeck && $is_apm_completed )
            );
			$is_subscription_order = $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) || wcs_is_subscription( $order ) );
            if ( $should_save_token ) {
            	$token_saved = $this->persist_complete_mandate_token( $transient_token, $order, $complete_payload, $tx_detail_response );
			} else {
				$token_saved = false;
			}
			// Link existing saved token to order if not already linked.
			if ( ! $token_saved && ! empty( $wc_token_id ) ) {
				$this->link_existing_token_from_transient( $transient_token, $order, $wc_token_id );
			}
		
			if ( $is_subscription_order ) {
				$subscriptions->add_payment_data_to_subscription( $order );
			}
            $transaction_type = $is_charge_transaction ? VISA_ACCEPTANCE_CHARGE_APPROVED : VISA_ACCEPTANCE_AUTH_APPROVED;
            $payment_uc->update_order_notes( $transaction_type, $order, $payment_response_array, null );
			
			// Persist payment action for webhook/fraud callbacks.
			if ( ! $is_echeck ) {
				$payment_action = $is_charge_transaction ? VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE : VISA_ACCEPTANCE_TRANSACTION_TYPE_AUTHORIZATION;
				$payment_uc->update_order_meta( $order, VISA_ACCEPTANCE_PAYMENT_ACCEPTANCE_SERVICE, $payment_action );
				if ( ! empty( $complete_mandate_type ) ) {
                    $payment_uc->update_order_meta( $order, '_vas_ebc_transaction_type', $complete_mandate_type );
                    // Skip subscription update for $0 free-trial orders: PREFER_AUTH is forced for $0 and is not the real EBC config.
                    $is_zero_amount_order = ( (float) $order->get_total() === 0.0 );
                    if ( ! $is_zero_amount_order && $this->gateway->is_subscriptions_activated && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
                        $parent_subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent' ) ) );
                        foreach ( $parent_subscriptions as $subscription ) {
                            $subscription->update_meta_data( '_vas_ebc_transaction_type', $complete_mandate_type );
                            $subscription->save();
                        }
                    }
                }
			}
            if ( $is_echeck_valid ) {
                $order->update_meta_data( '_vas_payment_type', VISA_ACCEPTANCE_CHECK );
                $order->save();
                $payment_uc->add_capture_data( $order, $payment_response_array );
                $payment_uc->update_order_notes( VISA_ACCEPTANCE_CHARGE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING );
			} elseif ( $is_bank_transfer_pending ) {
				// iDEAL/bank-transfer PENDING means payment initiated and awaiting confirmation.
				$payment_uc->add_transaction_data( $order, $payment_response_array );
				$payment_uc->update_order_notes( VISA_ACCEPTANCE_CHARGE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING );
            } elseif ( $is_echeck_dm_review ) {
                // eCheck PENDING_REVIEW — Decision Manager review.
                $order->update_meta_data( '_vas_payment_type', VISA_ACCEPTANCE_CHECK );
                $order->save();
                $payment_uc->update_order_meta( $order, VISA_ACCEPTANCE_DM_REVIEW_IS_CHARGE_META, VISA_ACCEPTANCE_YES );
                $payment_uc->update_order_notes( VISA_ACCEPTANCE_REVIEW_MESSAGE, $order, $payment_response_array, null );
                $payment_uc->add_review_transaction_data( $order, $payment_response_array );
                $payment_uc->update_order_notes( VISA_ACCEPTANCE_REVIEW_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING );
            } elseif ( $is_apm_capture ) {
                // APM (PayPal, etc.) COMPLETED with CAPTURE - payment immediately captured.
                $order->update_meta_data( '_vas_payment_type', 'apm' );
                $order->save();
                $payment_uc->add_capture_data( $order, $payment_response_array );
                $payment_uc->update_order_notes( VISA_ACCEPTANCE_CHARGE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING );
            } elseif ( $is_apm_auth ) {
                // APM (PayPal, etc.) COMPLETED with PREFER_AUTH - payment authorized, awaiting capture.
                $order->update_meta_data( '_vas_payment_type', 'apm' );
                $order->save();
                $payment_uc->add_transaction_data( $order, $payment_response_array );
                $payment_uc->update_order_notes( VISA_ACCEPTANCE_AUTHORIZE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD );
            } elseif ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ) {
                // Card AUTHORIZED.
                $order->update_meta_data( '_vas_payment_type', VISA_ACCEPTANCE_PAYMENT_TYPE_CARD );
                $order->save();
                if ( $is_charge_transaction ) {
                    $payment_uc->add_capture_data( $order, $payment_response_array );
                    $payment_uc->update_order_notes( VISA_ACCEPTANCE_CHARGE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING );
                } else {
                    $payment_uc->add_transaction_data( $order, $payment_response_array );
                    // Free trial / $0 orders authorize for a placeholder amount only; treat as processing, not on-hold.
                    if ( (float) $order->get_total() <= (float) VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT ) {
                        $payment_uc->update_order_notes( VISA_ACCEPTANCE_CHARGE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING );
                    } else {
                        $payment_uc->update_order_notes( VISA_ACCEPTANCE_AUTHORIZE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD );
                    }
                }
            } else {
                // Card AUTHORIZED_PENDING_REVIEW — DM review.
                $payment_uc->update_order_meta( $order, VISA_ACCEPTANCE_DM_REVIEW_IS_CHARGE_META, $is_charge_transaction ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO );
                $payment_uc->update_order_notes( VISA_ACCEPTANCE_REVIEW_MESSAGE, $order, $payment_response_array, null );
                $payment_uc->add_review_transaction_data( $order, $payment_response_array );
                $payment_uc->update_order_notes( VISA_ACCEPTANCE_REVIEW_TRANSACTION, $order, $payment_response_array, null );
            }
            return array(
                VISA_ACCEPTANCE_SUCCESS => true,
            );
        } else {
            $error_reason    = isset( $details['errorInformation']['reason'] ) ? (string) $details['errorInformation']['reason'] : VISA_ACCEPTANCE_STRING_EMPTY;
            // TMS saved-card payments use a dedicated key so they don't collide with PANENTRY SCA flags.
            if ( $is_express_pay ) {
                $sca_session_key = VISA_ACCEPTANCE_COOKIE_USE_3DS_EP_CHALLENGE;
            } elseif ( ! empty( $wc_token_id ) ) {
                $sca_session_key = VISA_ACCEPTANCE_COOKIE_USE_3DS_TMS_CHALLENGE;
            } else {
                $sca_session_key = VISA_ACCEPTANCE_COOKIE_USE_3DS_CHALLENGE;
            }
            $use3ds_challenge = isset( WC()->session ) ? WC()->session->get( $sca_session_key, VISA_ACCEPTANCE_STRING_EMPTY ) : VISA_ACCEPTANCE_STRING_EMPTY;

            $auth_failed_reasons = array( 'CONSUMER_AUTHENTICATION_FAILED', 'CUSTOMER_AUTHENTICATION_REQUIRED' );
            $dm_reject_values    = array( VISA_ACCEPTANCE_API_RESPONSE_DECISION_PROFILE_REJECT, VISA_ACCEPTANCE_API_RESPONSE_STATUS_DECISION_REJECT );
            $is_dm_reject        = in_array( $status, $dm_reject_values, true ) || in_array( $error_reason, $dm_reject_values, true );

            if ( VISA_ACCEPTANCE_STRING_CUSTOMER_AUTHENTICATION_REQUIRED === $error_reason
                && VISA_ACCEPTANCE_STRING_EMPTY === $use3ds_challenge
                && '2' !== $use3ds_challenge
            ) {
                $sca_retry_msg = __( 'Your card issuer requires an extra security check to approve this payment. Please try again and follow the verification steps.', 'visa-acceptance-solutions' );
                // Mark the order failed so it doesn't sit in Pending payment during the SCA retry.
                $payment_uc->update_order_notes( VISA_ACCEPTANCE_REJECT_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED );
                WC()->session->set( $sca_session_key, '1' );
                return array(
                    VISA_ACCEPTANCE_SUCCESS => false,
                    'reload'                => true,
                    'authRequired'          => true,
                    'message'               => $sca_retry_msg,
                );
            }

            // Non-SCA decline (or second SCA failure) — fail the order and return error.
            $payment_uc->add_transaction_data( $order, $payment_response_array );
            // Only add DM note when it was actually a Decision Manager rejection.
            if ( $is_dm_reject ) {
                $payment_uc->update_order_notes( VISA_ACCEPTANCE_AUTH_REJECT, $order, $payment_response_array, null );
            }
            $payment_uc->update_order_notes( VISA_ACCEPTANCE_REJECT_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED );

            // Clear the appropriate SCA session key.
            if ( isset( WC()->session ) ) {
                WC()->session->set( $sca_session_key, VISA_ACCEPTANCE_STRING_EMPTY );
                WC()->session->set( VISA_ACCEPTANCE_COOKIE_ERROR_MSG, VISA_ACCEPTANCE_STRING_EMPTY );
            }

            if ( in_array( $error_reason, $auth_failed_reasons, true ) ) {
                $error_message = __( 'Unable to process your request. Please try again later.', 'visa-acceptance-solutions' );
            } elseif ( $is_dm_reject ) {
                $error_message = __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' );
            } else {
                $error_message = isset( $complete_payload['errorInformation']['message'] ) ? $complete_payload['errorInformation']['message'] : __( 'Payment was declined. Please use another payment method.', 'visa-acceptance-solutions' );
            }
 
            return array(
                VISA_ACCEPTANCE_SUCCESS => false,
                VISA_ACCEPTANCE_ERROR   => true,
                'message'               => $error_message,
            );
        }
    }

	/**
	 * Creates a failure response array compatible with do_uc_transaction format.
	 *
	 * @param string $message User-facing error message.
	 * @return array
	 */
	private function fail_order_response( $message ) {
		return array(
			VISA_ACCEPTANCE_SUCCESS      => false,
			VISA_ACCEPTANCE_ERROR => true,
			'message'                    => $message,
		);
	}

	/**
	 * Persists card token for complete mandate flows.
	 *
	 * @param string|null $transient_token Transient token from checkout.
	 * @param WC_Order    $order Order object.
	 * @param array|null  $complete_payload Complete response payload from UC.
	 * @param array|null  $tx_detail_response Transaction details response from UC.
	 *
	 * @return bool
	 */
	private function persist_complete_mandate_token( $transient_token, $order, $complete_payload = null, $tx_detail_response = null ) {

        $payment_uc_helper = new Visa_Acceptance_Payment_UC( $this->gateway );
        $is_echeck         = ! empty( $transient_token ) && $payment_uc_helper->is_echeck_from_transient_token( (string) $transient_token );
        // Digital wallets (Google Pay, etc.) can complete without a transient token, but tokenInformation
        // may still be present directly in the Complete Response — so don't bail out here.
        if ( ! $is_echeck ) {
            // ── Path 1: tokenInformation already in the UC complete response ──
            $token_info_from_complete = isset( $complete_payload['details']['tokenInformation'] )
                ? $complete_payload['details']['tokenInformation']
                : null;
 
            if ( ! empty( $token_info_from_complete ) ) {
                // Build a synthetic response body matching what save_payment_method() expects.
                $synth_body = array_merge(
                    isset( $complete_payload['details'] ) ? $complete_payload['details'] : array(),
                    array(
                        'id'     => $complete_payload['id'] ?? VISA_ACCEPTANCE_STRING_EMPTY,
                        'status' => $complete_payload['status'] ?? VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED,
                    )
                );
                $response_for_save = array(
                    'body'      => wp_json_encode( $synth_body ),
                    'http_code' => VISA_ACCEPTANCE_TWO_ZERO_ONE,
                );
                $save_result = $this->save_payment_method( $response_for_save, $order );
                if ( is_array( $save_result ) && ! empty( $save_result['status'] ) ) {
                    if ( ! empty( $save_result['token'] ) ) {
                        $order->update_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN, $save_result['token'] );
                        $order->save();
                    }
                    return true;
                }
            }
            $tx_body = null;
            if ( ! empty( $tx_detail_response ) ) {
                $raw = $tx_detail_response['body'] ?? null;
                if ( is_string( $raw ) ) {
                    $tx_body = json_decode( $raw, true );
                } elseif ( is_object( $raw ) ) {
                    $tx_body = json_decode( wp_json_encode( $raw ), true );
                } elseif ( is_array( $raw ) ) {
                    $tx_body = $raw;
                }
            }
 
            if ( ! empty( $tx_body ) && ! empty( $tx_body['tokenInformation'] ) ) {
                $response_for_save = array(
                    'body'      => is_string( $tx_detail_response['body'] ) ? $tx_detail_response['body'] : wp_json_encode( $tx_body ),
                    'http_code' => VISA_ACCEPTANCE_TWO_ZERO_ONE,
                );
                $save_result = $this->save_payment_method( $response_for_save, $order );
                if ( is_array( $save_result ) && ! empty( $save_result['status'] ) ) {
                    if ( ! empty( $save_result['token'] ) ) {
                        $order->update_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN, $save_result['token'] );
						// Also save WooCommerce payment token ID for subscription linking.
                        if ( ! empty( $save_result['token_id'] ) ) {
                            $order->update_meta_data( '_payment_token_id', $save_result['token_id'] );
                        }
                        $order->save();
                    }
                    return true;
                }
            }
        }
		if ( $is_echeck ) {
            $token_info_from_complete = isset( $complete_payload['details']['tokenInformation'] )
                ? $complete_payload['details']['tokenInformation']
                : null;

            if ( ! empty( $token_info_from_complete ) ) {
                $synth_body = array_merge(
                    isset( $complete_payload['details'] ) ? $complete_payload['details'] : array(),
                    array(
                        'id'     => $complete_payload['id'] ?? VISA_ACCEPTANCE_STRING_EMPTY,
                        'status' => VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS,
                    )
                );
                $response_for_save = array(
                    'body'      => wp_json_encode( $synth_body ),
                    'http_code' => VISA_ACCEPTANCE_TWO_ZERO_ONE,
                );
                $save_result = $this->save_payment_method( $response_for_save, $order, true );
 
                if ( is_array( $save_result ) && ! empty( $save_result['status'] ) ) {
                    if ( ! empty( $save_result['token'] ) ) {
                        $order->update_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN, $save_result['token'] );
						// Also save WooCommerce payment token ID for subscription linking.
                        if ( ! empty( $save_result['token_id'] ) ) {
                            $order->update_meta_data( '_payment_token_id', $save_result['token_id'] );
                        }
                        $order->save();
                    }
                    return true;
                }
            }
			          // ── Fallback: eCheck completeMandate response omitted tokenInformation inline; pull it from transaction details ──
            $tx_body = null;
            if ( ! empty( $tx_detail_response ) ) {
                $raw = $tx_detail_response['body'] ?? null;
                if ( is_string( $raw ) ) {
                    $tx_body = json_decode( $raw, true );
                } elseif ( is_object( $raw ) ) {
                    $tx_body = json_decode( wp_json_encode( $raw ), true );
                } elseif ( is_array( $raw ) ) {
                    $tx_body = $raw;
                }
            }
 
            if ( ! empty( $tx_body ) && ! empty( $tx_body['tokenInformation'] ) ) {
                $response_for_save = array(
                    'body'      => is_string( $tx_detail_response['body'] ) ? $tx_detail_response['body'] : wp_json_encode( $tx_body ),
                    'http_code' => VISA_ACCEPTANCE_TWO_ZERO_ONE,
                );
                $save_result = $this->save_payment_method( $response_for_save, $order, true );
                if ( is_array( $save_result ) && ! empty( $save_result['status'] ) ) {
                    if ( ! empty( $save_result['token'] ) ) {
                        $order->update_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN, $save_result['token'] );
                        // Also save WooCommerce payment token ID for subscription linking.
                        if ( ! empty( $save_result['token_id'] ) ) {
                            $order->update_meta_data( '_payment_token_id', $save_result['token_id'] );
                        }
                        $order->save();
                    }
                    return true;
                }
            }
 
        }
        return false;
    }

	/**
     * Link existing WooCommerce payment token to order when using saved card via TMS_TOKEN.
     *
     * @param string    $transient_token Transient token from complete mandate.
     * @param \WC_Order $order Order object.
     * @param int|null  $wc_token_id WooCommerce payment token ID from POST data.
     * @return bool True if token was found and linked, false otherwise.
     */
    private function link_existing_token_from_transient( $transient_token, $order, $wc_token_id = null ) {
        try {
            // If WooCommerce token ID was provided directly (from POST data), use it
            if ( ! empty( $wc_token_id ) ) {
                $token = \WC_Payment_Tokens::get( $wc_token_id );
               
                if ( ! $token || $token->get_gateway_id() !== $this->gateway->get_id() ) {
                    return false;
                }
               
                // Get instrument ID from the token
                $token_info = $token->get_meta( 'token_information', true );
                if ( ! is_array( $token_info ) || empty( $token_info['instrument_identifier_id'] ) ) {
                    return false;
                }
               
                $instrument_id = $token_info['instrument_identifier_id'];
               
                // Link the token to the order
                $order->update_meta_data( '_payment_token_id', $token->get_id() );
                $order->update_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN, $instrument_id );
                $order->save();
               
                return true;
            }
           
            return false;
           
        } catch ( \Exception $e ) {
            return false;
        }
    }
 
 
	/**
	 * Builds the response array for process_payment, based on the result from handle_complete_mandate_response or do_transaction.
	 *
	 * @param array|null $result Result from handle_complete_mandate_response / do_transaction.
	 * @param \WC_Order  $order  Order object.
	 * @return array
	 */
	private function build_process_payment_response( $result, $order ) {
		if ( null === $result ) {
			$message = __( 'Payment processing failed. Please try again.', 'visa-acceptance-solutions' );
			$this->mark_order_failed( $message );
			return array(
				'result'  => VISA_ACCEPTANCE_FAILURE,
				'message' => $message,
			);
		}
		if ( is_array( $result ) && ! empty( $result['reload'] ) && ! empty( $result['authRequired'] ) ) {
			$sca_notice = isset( $result['message'] ) ? $result['message'] : __( 'Your card issuer requires an extra security check to approve this payment. Please try again and follow the verification steps.', 'visa-acceptance-solutions' );
			$this->mark_order_failed( $sca_notice. '<span class="vas-sca-notice-marker"></span>' );
			return array(
				'result'       => VISA_ACCEPTANCE_FAILURE,
				'reload'       => true,
				'authRequired' => true,
				'message'      => $sca_notice,
			);
		}

		if ( is_array( $result ) && ! empty( $result[ VISA_ACCEPTANCE_SUCCESS ] ) ) {
			if ( ! is_product() ) {
				WC()->cart->empty_cart();
			}
			return array(
				'result'   => VISA_ACCEPTANCE_SUCCESS,
				'redirect' => $this->gateway->get_return_url( $order ),
			);
		}

		if ( is_array( $result ) && ! empty( $result[ VISA_ACCEPTANCE_ERROR ] ) ) {
			$message = $result[ VISA_ACCEPTANCE_ERROR ];
			if ( isset( $result['message'] ) && $result['message'] ) {
				$message = $result['message'];
			}
			if ( isset( $result['detailed_reason'] ) ) {
				$message .= '<br>';
				$message .= $this->add_detailed_message( $result['detailed_reason'] );
			}
			$this->mark_order_failed( $message );
			return array(
				'result'  => VISA_ACCEPTANCE_FAILURE,
				'message' => $message,
				'reason'  => isset( $result['reason'] ) ? $result['reason'] : null,
			);
		}

		$message = __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' );
		if ( isset( $result['detailed_reason'] ) ) {
			$message .= '<br>';
			$message .= $this->add_detailed_message( $result['detailed_reason'] );
		}
		$this->mark_order_failed( $message );
		return array(
			'result'  => VISA_ACCEPTANCE_FAILURE,
			'message' => $message,
		);
	}

	/**
	 * Capture context Ajax callback.
	 */
	public function call_updates_action() {
		check_ajax_referer( 'wc_call_uc_update_price_action', 'nonce' );
		$post_data     = isset( $_POST ) ? wp_unslash( $_POST ) : array();
		$force_refresh = isset( $post_data['force_refresh'] ) && 'true' === $post_data['force_refresh'];
		$cc_only       = isset( $post_data['cc_only'] ) && 'true' === $post_data['cc_only'];
		$ep_only       = isset( $post_data['ep_only'] ) && 'true' === $post_data['ep_only'];

		// Belt-and-suspenders: if JS sent order_pay_id and session doesn't have it yet, store it now.
		$post_order_pay_id = isset( $post_data['order_pay_id'] ) ? absint( $post_data['order_pay_id'] ) : VISA_ACCEPTANCE_VAL_ZERO;
		if ( $post_order_pay_id > VISA_ACCEPTANCE_VAL_ZERO && isset( WC()->session ) && empty( WC()->session->get( 'vas_order_pay_id' ) ) ) {
			WC()->session->set( 'vas_order_pay_id', $post_order_pay_id );
		}

		// Blocks checkout syncs the billing address to the server on its own debounce, which can
		// lag behind our capture-context refresh. Apply the live values sent by the client so the
		// capture context's billTo is never built from a stale customer session.
		if ( ! empty( $post_data['billing_address'] ) ) {
			$billing_address = json_decode( $post_data['billing_address'], true );
			if ( is_array( $billing_address ) ) {
				$this->apply_posted_billing_address( $billing_address );
			}
		}

		if ( $force_refresh && isset( WC()->session ) ) {
			WC()->session->set( "wc_{$this->wc_payment_gateway_id}_capture_context_total_amount", null );
		}
		return wp_send_json( $this->updates_capture_context( $cc_only, $ep_only ) );
	}

	/**
	 * Applies a posted billing address (from Blocks checkout) to the current customer session.
	 *
	 * @param array $billing_address Associative array of WooCommerce billing field => value.
	 */
	private function apply_posted_billing_address( $billing_address ) {
		if ( ! isset( WC()->customer ) ) {
			return;
		}
		$field_setters = array(
			'first_name' => array( 'set_billing_first_name', 'sanitize_text_field' ),
			'last_name'  => array( 'set_billing_last_name', 'sanitize_text_field' ),
			'company'    => array( 'set_billing_company', 'sanitize_text_field' ),
			'address_1'  => array( 'set_billing_address_1', 'sanitize_text_field' ),
			'address_2'  => array( 'set_billing_address_2', 'sanitize_text_field' ),
			'city'       => array( 'set_billing_city', 'sanitize_text_field' ),
			'state'      => array( 'set_billing_state', 'sanitize_text_field' ),
			'postcode'   => array( 'set_billing_postcode', 'sanitize_text_field' ),
			'country'    => array( 'set_billing_country', 'sanitize_text_field' ),
			'email'      => array( 'set_billing_email', 'sanitize_email' ),
			'phone'      => array( 'set_billing_phone', 'sanitize_text_field' ),
		);
		foreach ( $field_setters as $field => $setter_config ) {
			if ( ! isset( $billing_address[ $field ] ) ) {
				continue;
			}
			list( $setter, $sanitizer ) = $setter_config;
			WC()->customer->{$setter}( $sanitizer( $billing_address[ $field ] ) );
		}
		WC()->customer->save();
	}

	/**
     * Updates and retrieves capture context JWTs for Unified Checkout and Express Pay.
     *
     * @param bool $cc_only Whether to only refresh the credit card capture context.
     * @param bool $ep_only Whether to only refresh the Express Pay capture context.
     * @return array Response array with success status and capture context JWTs.
     */
	public function updates_capture_context( $cc_only = false, $ep_only = false ) {
        $return_response        = array(
            VISA_ACCEPTANCE_SUCCESS => true,
            'capture_context_jwt' 		=> null,
            'capture_context_ep_jwt' 	=> null,
        );

        if ( isset( WC()->session ) && (
            '2' === WC()->session->get( VISA_ACCEPTANCE_COOKIE_USE_3DS_CHALLENGE, VISA_ACCEPTANCE_STRING_EMPTY ) ||
            '2' === WC()->session->get( VISA_ACCEPTANCE_COOKIE_USE_3DS_EP_CHALLENGE, VISA_ACCEPTANCE_STRING_EMPTY ) ||
            '2' === WC()->session->get( VISA_ACCEPTANCE_COOKIE_USE_3DS_TMS_CHALLENGE, VISA_ACCEPTANCE_STRING_EMPTY )
        ) ) {
            return $return_response;
        }

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

        // Track billing address changes so the capture context (billTo) stays in sync with what,
        // the customer entered on the checkout form, even when the order total is unchanged.
        $current_billing_hash = null;
        if ( isset( WC()->customer ) ) {
            $current_billing_hash = md5(
                wp_json_encode(
                    array(
                        WC()->customer->get_billing_first_name(),
                        WC()->customer->get_billing_last_name(),
                        WC()->customer->get_billing_address_1(),
                        WC()->customer->get_billing_address_2(),
                        WC()->customer->get_billing_city(),
                        WC()->customer->get_billing_state(),
                        WC()->customer->get_billing_postcode(),
                        WC()->customer->get_billing_country(),
                        WC()->customer->get_billing_email(),
                        WC()->customer->get_billing_phone(),
                    )
                )
            );
        }
        $last_billing_hash = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_billing_hash" );

        // Only generate Express Pay JWT if enable_express_pay is enabled and not a CC-only request.
        $enable_express_pay = isset( $uc_settings['enable_express_pay'] ) && VISA_ACCEPTANCE_YES === $uc_settings['enable_express_pay'] && ! $cc_only;
		
        // A plain (non-subscription) order with nothing to charge, e.g. a 100%-off coupon, has no payment to verify.
        $subscription_order_active = class_exists( 'WC_Subscriptions_Cart' ) && ( WC_Subscriptions_Cart::cart_contains_subscription() || ( function_exists( 'wcs_cart_contains_renewal' ) && wcs_cart_contains_renewal() ) || ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) );
        $return_response['hide_express_pay'] = ! $subscription_order_active && VISA_ACCEPTANCE_ZERO_AMOUNT === (string) $checkout_order_total;

        // Generate new capture context if: amount changed, OR no session amount exists (first load), OR shipping changed, OR billing address changed.
        if ( empty( $total_amount ) || $checkout_order_total !== $total_amount || $last_shipping_hash !== $current_shipping_hash || $last_billing_hash !== $current_billing_hash ) { // phpcs:ignore WordPress.Security.NonceVerification.
            $return_response[VISA_ACCEPTANCE_SUCCESS] = false;
            // ep_only skips CC JWT generation (e.g. shipping/discount update triggers only EP refresh).
            if ( ! $ep_only ) {
                $response_jwt = $flex_request->get_unified_checkout_capture_context();
            }
            if ( $enable_express_pay && ! $this->is_user_in_add_payment_method_page() ) {
                $response_ep_jwt = $flex_request->get_unified_checkout_capture_context( true );
            }
            
            // Handle normal JWT response.
            if (isset( $response_jwt['http_code'] ) && VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $response_jwt['http_code'] ) {
                $capture_context_jwt = ! empty( $response_jwt['body'] ) ? $response_jwt['body'] : VISA_ACCEPTANCE_STRING_EMPTY;
                $return_response['capture_context_jwt'] = $capture_context_jwt;
                $this->store_capture_context_mandate_type( $capture_context_jwt );
                $client_library_jwt = $this->get_uc_client_library( $capture_context_jwt );
                if ( ! empty( $client_library_jwt['url'] ) ) {
                    // Store resolved URL in session; only the SRI integrity hash (not a
                    // script.src sink) is sent back in the JSON response. The client fetches
                    // the actual script via the session-backed vas_uc_library_proxy endpoint.
                    if ( isset( WC()->session ) ) {
                        WC()->session->set( "wc_{$this->wc_payment_gateway_id}_client_library_url", $client_library_jwt['url'] );
                    }
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
                $this->store_capture_context_mandate_type( $capture_context_ep_jwt );
                $client_library_ep_jwt = $this->get_uc_client_library( $capture_context_ep_jwt );
                if ( ! empty( $client_library_ep_jwt['url'] ) ) {
                    if ( isset( WC()->session ) ) {
                        WC()->session->set( "wc_{$this->wc_payment_gateway_id}_client_library_ep_url", $client_library_ep_jwt['url'] );
                    }
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
                $return_response[VISA_ACCEPTANCE_SUCCESS] = true;
                // Update session with new total and shipping hash.
                WC()->session->set( "wc_{$this->wc_payment_gateway_id}_capture_context_total_amount", $checkout_order_total );
                if ( isset( $current_shipping_hash ) ) {
                    WC()->session->set( "wc_{$this->wc_payment_gateway_id}_shipping_hash", $current_shipping_hash );
                }
                if ( isset( $current_billing_hash ) ) {
                    WC()->session->set( "wc_{$this->wc_payment_gateway_id}_billing_hash", $current_billing_hash );
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
				if ( ! is_string( $capture_context ) || empty( $capture_context ) ) {
            		return $client_library;
        		}
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
	 * Validates that a given URL is an absolute HTTPS URL on a trusted CyberSource/Visa host.
	 *
	 * @param string $url URL to validate.
	 * @return bool
	 */
	private function is_trusted_script_url( $url ) {
        if ( ! is_string( $url ) || empty( $url ) ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) ) {
            return false;
        }
        $host = strtolower( $parts['host'] );
        foreach ( array( VISA_ACCEPTANCE_TRUSTED_HOST_CYBERSOURCE, VISA_ACCEPTANCE_TRUSTED_HOST_VISA ) as $trusted_host ) {
            if ( $host === $trusted_host || substr( $host, -( strlen( $trusted_host ) + 1 ) ) === '.' . $trusted_host ) {
                return true;
            }
        }
        return false;
    }

	/**
	 * Same-origin proxy for the dynamically-loaded UC client library script.
	 * 
	 */
	public function proxy_uc_library() {
		$get_data = isset( $_GET ) ? wp_unslash( $_GET ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce    = isset( $get_data['_wpnonce'] ) ? sanitize_text_field( $get_data['_wpnonce'] ) : VISA_ACCEPTANCE_STRING_EMPTY;
		if ( ! wp_verify_nonce( $nonce, 'vas_uc_library_proxy' ) ) {
			status_header( VISA_ACCEPTANCE_FOUR_ZERO_THREE );
			exit;
		}

		$mode = isset( $get_data['mode'] ) ? sanitize_key( $get_data['mode'] ) : 'cc';
		$url  = VISA_ACCEPTANCE_STRING_EMPTY;

		if ( isset( WC()->session ) ) {
			if ( 'saved_card' === $mode ) {
				$url = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_saved_card_client_library_url" );
			} elseif ( 'ep' === $mode ) {
				$url = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_client_library_ep_url" );
			} else {
				$url = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_client_library_url" );
			}
		}

		if ( ! $this->is_trusted_script_url( $url ) ) {
			status_header( VISA_ACCEPTANCE_FOUR_ZERO_FOUR );
			exit;
		}

		wp_redirect( $url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}
	
	/**
	 * Decodes capture context JWT and extracts mandate configuration.
	 *
	 * @param string $jwt Capture context JWT.
	 * @return array $config Associative array with mandate configuration:
	 */
	private function get_capture_context_jwt_config( $jwt ) {
		$config = array(
			'request_save_credentials' => null,
			'token_create'             => null,
			'decision_manager'         => null,
			'consumer_authentication'  => null,
			'complete_mandate_type'    => null,
		);
		if ( empty( $jwt ) || ! is_string( $jwt ) ) {
			return $config;
		}
		$parts = explode( VISA_ACCEPTANCE_FULL_STOP, $jwt );
		if ( VISA_ACCEPTANCE_VAL_THREE !== count( $parts ) ) {
			return $config;
		}
		$decoded = json_decode(
			base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				str_replace( array( VISA_ACCEPTANCE_HYPHEN, VISA_ACCEPTANCE_UNDERSCORE ), array( '+', VISA_ACCEPTANCE_SLASH ), $parts[ VISA_ACCEPTANCE_VAL_ONE ] )
			),
			true
		);
		if ( ! isset( $decoded['ctx'][ VISA_ACCEPTANCE_VAL_ZERO ]['data'] ) ) {
			return $config;
		}
		$data = $decoded['ctx'][ VISA_ACCEPTANCE_VAL_ZERO ]['data'];
		if ( isset( $data['captureMandate']['requestSaveCredentials'] ) ) {
			$config['request_save_credentials'] = (bool) $data['captureMandate']['requestSaveCredentials'];
		}
		if ( isset( $data['completeMandate']['tms']['tokenCreate'] ) ) {
			$config['token_create'] = (bool) $data['completeMandate']['tms']['tokenCreate'];
		}
		if ( isset( $data['completeMandate']['decisionManager'] ) ) {
			$config['decision_manager'] = (bool) $data['completeMandate']['decisionManager'];
		}
		if ( isset( $data['completeMandate']['consumerAuthentication'] ) ) {
			$consumer_auth_raw = $data['completeMandate']['consumerAuthentication'];
			// The JWT may return this as an array (e.g. ['3DS']) or a plain string.
			if ( is_array( $consumer_auth_raw ) ) {
				$config['consumer_authentication'] = in_array( '3DS', $consumer_auth_raw, true ) ? '3DS' : 'NONE';
			} else {
				$config['consumer_authentication'] = (string) $consumer_auth_raw;
			}
		}
		if ( isset( $data['completeMandate']['type'] ) ) {
			$config['complete_mandate_type'] = (string) $data['completeMandate']['type'];
		}
		return $config;
	}

	/**
	 * Decodes a Capture Context JWT and stores its completeMandate.type in the session, keyed by jti.
	 *
	 * Multiple Capture Contexts (regular, Express Pay, TMS saved-card) can be generated during a
	 * single checkout, so each context's mandate type is tracked separately by jti instead of being
	 * overwritten by a single global session value.
	 *
	 * @param string      $capture_context_jwt Capture Context JWT to decode.
	 * @param string|null $force_mandate_type  Optional override stored instead of the JWT's own type
	 *                                         (used to mirror the free-trial PREFER_AUTH override
	 *                                         already applied to new-card capture contexts).
	 */
	public function store_capture_context_mandate_type( $capture_context_jwt, $force_mandate_type = null ) {
		if ( ! is_string( $capture_context_jwt ) || empty( $capture_context_jwt ) ) {
			return;
		}

		$decoded_jwt = $this->safe_decode_jwt( $capture_context_jwt, false );
		if ( ! is_array( $decoded_jwt ) ) {
			return;
		}

		if ( ! isset( WC()->session ) || empty( $decoded_jwt['jti'] ) ) {
			return;
		}

		$jti = (string) $decoded_jwt['jti'];
		if ( null !== $force_mandate_type ) {
			$mandate_type = (string) $force_mandate_type;
		} else {
			$mandate_type = isset( $decoded_jwt['ctx'][ VISA_ACCEPTANCE_VAL_ZERO ]['data']['completeMandate']['type'] )
				? (string) $decoded_jwt['ctx'][ VISA_ACCEPTANCE_VAL_ZERO ]['data']['completeMandate']['type']
				: VISA_ACCEPTANCE_STRING_EMPTY;
		}
		$mandate_type = strtoupper( trim( $mandate_type ) );
		if ( empty( $mandate_type ) ) {
			return;
		}

		$mandate_types = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_complete_mandate_types", array() );
		if ( ! is_array( $mandate_types ) ) {
			$mandate_types = array();
		}
		$mandate_types[ $jti ] = $mandate_type;
		WC()->session->set( "wc_{$this->wc_payment_gateway_id}_complete_mandate_types", $mandate_types );
	}

	/**
	 * Determines whether to empty the cart before redirecting to the return URL.
	 *
	 * @return bool
	 */
	protected function empty_cart_before_redirect() {
		return true;
	}

	/**
	 * Validates and performs a safe redirect to allowed WooCommerce URLs only.
	 *
	 * @param string $redirect_url The URL to redirect to.
	 * @return void
	 */
	private function safe_wc_redirect( $redirect_url ) {
		// List of allowed redirect patterns (WooCommerce endpoints only).
		$allowed_urls = array(
			home_url(),
			wc_get_checkout_url(),
			wc_get_cart_url(),
			wc_get_page_permalink( 'myaccount' ),
		);

		// Check if URL starts with any allowed URL.
		$is_allowed = false;
		foreach ( $allowed_urls as $allowed_url ) {
			if ( strpos( $redirect_url, $allowed_url ) === VISA_ACCEPTANCE_VAL_ZERO ) {
				$is_allowed = true;
				break;
			}
		}
		// If not allowed, redirect to account page as fallback.
		if ( ! $is_allowed ) {
			$redirect_url = wc_get_page_permalink( 'myaccount' );
		}

		wp_safe_redirect( $redirect_url );
		exit();
	}

	/**
	 * Decodes JWT payload without signature verification (tokens verified by Visa API).
	 *
	 * @param string $jwt JWT token.
	 * @param bool   $verify_structure Whether to strictly validate structure.
	 * @return array|null Decoded payload or null on failure.
	 */
	private function safe_decode_jwt( $jwt, $verify_structure = true ) {
		if ( empty( $jwt ) || ! is_string( $jwt ) ) {
			return null;
		}

		// JWT must have 3 parts: header.payload.signature.
		$token_parts = explode( VISA_ACCEPTANCE_FULL_STOP, $jwt );
		if ( count( $token_parts ) !== 3 ) {
			if ( $verify_structure ) {
				$this->gateway->add_logs_data(
					'Invalid JWT structure: Expected 3 parts, got ' . count( $token_parts ),
					false,
					'JWT Validation Warning'
				);
				return null;
			}
			// Fallback for tokens with different structure.
			if ( count( $token_parts ) < VISA_ACCEPTANCE_VAL_TWO) {
				return null;
			}
		}
		// Decode the payload (second part).
		$payload_b64 = $token_parts[VISA_ACCEPTANCE_VAL_ONE];

		// URL-safe base64 decode.
		$payload_b64 = strtr( $payload_b64, '-_', '+/' );
		$pad         = strlen( $payload_b64 ) % VISA_ACCEPTANCE_VAL_FOUR;
		if ( $pad ) {
			$payload_b64 .= str_repeat( '=', VISA_ACCEPTANCE_VAL_FOUR - $pad );
		}
		$json = base64_decode( $payload_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $json ) {
			$this->gateway->add_logs_data(
				'Failed to base64 decode JWT payload',
				false,
				'JWT Validation Warning'
			);
			return null;
		}

		// Decode JSON.
		$decoded_token = json_decode( $json, true );
		if ( null === $decoded_token || JSON_ERROR_NONE !== json_last_error() ) {
			$this->gateway->add_logs_data(
				'Failed to JSON decode JWT payload: ' . json_last_error_msg(),
				false,
				'JWT Validation Warning'
			);
			return null;
		}
		if ( $verify_structure ) {
			// Warn if critical claims are missing (but don't fail).
			if ( ! isset( $decoded_token['exp'] ) && ! isset( $decoded_token['iat'] ) ) {
				$this->gateway->add_logs_data(
					'JWT missing standard time claims (exp/iat)',
					false,
					'JWT Validation Info'
				);
			}
		}
		return $decoded_token;
	}

	/**
	 * Validates a transient token for security.
	 *
	 * @param string $transient_token The token to validate.
	 * @return array Array with 'valid' (bool) and 'error' (string) keys.
	 */
	private function validate_transient_token( $transient_token ) {
		if ( empty( $transient_token ) ) {
			return array(
				'valid' => false,
				'error' => __( 'Token is empty.', 'visa-acceptance-solutions' ),
			);
		}

		// Generate a hash of the token for storage (to prevent storing full token).
		$token_hash = 'vas_token_' . hash( 'sha256', $transient_token );

		// Check if token has already been used (replay attack prevention).
		$token_used = get_transient( $token_hash );
		if ( false !== $token_used ) {
			return array(
				'valid' => false,
				'error' => __( 'This payment token has already been used. Please generate a new one.', 'visa-acceptance-solutions' ),
			);
		}

		// Parse JWT token to check expiration using safe decoder.
		$decoded_token = $this->safe_decode_jwt( $transient_token, true );

		if ( null === $decoded_token ) {
			return array(
				'valid' => false,
				'error' => __( 'Invalid payment token format. Please generate a new one.', 'visa-acceptance-solutions' ),
			);
		}

		// Check expiration (exp claim in JWT).
		if ( isset( $decoded_token['exp'] ) ) {
			$expiration_time = (int) $decoded_token['exp'];
			$current_time    = time();

			if ( $current_time > $expiration_time ) {
				return array(
					'valid' => false,
					'error' => __( 'Payment token has expired. Please generate a new one.', 'visa-acceptance-solutions' ),
				);
			}
		}

		// Check issued at time (iat claim) - token should not be too old.
		if ( isset( $decoded_token['iat'] ) ) {
			$issued_at    = (int) $decoded_token['iat'];
			$current_time = time();
			$max_age      = 3600; // 1 hour maximum age.

			if ( ( $current_time - $issued_at ) > $max_age ) {
				return array(
					'valid' => false,
					'error' => __( 'Payment token is too old. Please generate a new one.', 'visa-acceptance-solutions' ),
				);
			}
		}

		// Mark token as used (store for 2 hours to prevent replay).
		set_transient( $token_hash, true, 2 * HOUR_IN_SECONDS ); // 2 hours in seconds

		return array(
			'valid' => true,
			'error' => VISA_ACCEPTANCE_STRING_EMPTY,
		);
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
		$blocks_meta_token = null;
		$core_tokens       = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
		if ( is_array( $core_tokens ) && ! empty( $core_tokens ) ) {
			foreach ( $core_tokens as $core_token ) {
				if ( ! $core_token instanceof \WC_Payment_Token ) {
                    continue;
                }
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
	}

	/**
	 * Adds new Payment Method through Payment Methods page.
	 */
	public function add_payment_method() {
		$post_data = isset( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$payment_method = new Visa_Acceptance_Payment_Methods( $this->gateway );
		$payment_uc = new Visa_Acceptance_Payment_UC( $this->gateway );
		$transient_token = null;
		$complete_response_raw = null;
		if ( ! empty( $post_data['transientToken'] ) ) {
			$transient_token = sanitize_text_field( wp_unslash( $post_data['transientToken'] ) );

			// Validate transient token for security (expiration & replay protection).
			$validation = $this->validate_transient_token( $transient_token );
			if ( ! $validation['valid'] ) {
				wc_add_notice( $validation['error'], VISA_ACCEPTANCE_ERROR );
				$this->safe_wc_redirect( wc_get_endpoint_url( 'add-payment-method' ) );
				return;
			}
		}
		// Check if complete response is available (UCv1 with tokenCreate in completeMandate).
		if ( ! empty( $post_data['uc_complete_response'] ) ) {
			$complete_response_raw = wp_unslash( $post_data['uc_complete_response'] );
		}
		if ( $transient_token ) {
			$is_echeck = false;

			// Safely decode JWT token to check payment type.
			$decoded_token = $this->safe_decode_jwt( $transient_token, false );
			if ( null !== $decoded_token && isset( $decoded_token['metadata']['paymentType'] ) && VISA_ACCEPTANCE_CHECK === $decoded_token['metadata']['paymentType'] ) {
				$is_echeck = true;
			}

			// Update customer's billing address from transient token BEFORE creating token.
			// This ensures the billing address entered in the UC modal is used in the tokenization request.
			$payment_details_response = $payment_uc->get_payment_details_from_transient_token( $transient_token );
			if ( $payment_details_response && isset( $payment_details_response['body'] ) && isset( $payment_details_response['body']->orderInformation->billTo ) ) {
				$bill_to = $payment_details_response['body']->orderInformation->billTo;
				$customer = WC()->customer;
				
				// Update customer billing address from UC modal data.
				if ( ! empty( $bill_to->firstName ) ) $customer->set_billing_first_name( sanitize_text_field( $bill_to->firstName ) );
				if ( ! empty( $bill_to->lastName ) ) $customer->set_billing_last_name( sanitize_text_field( $bill_to->lastName ) );
				if ( ! empty( $bill_to->address1 ) ) $customer->set_billing_address_1( sanitize_text_field( $bill_to->address1 ) );
				if ( ! empty( $bill_to->address2 ) ) $customer->set_billing_address_2( sanitize_text_field( $bill_to->address2 ) );
				if ( ! empty( $bill_to->locality ) ) $customer->set_billing_city( sanitize_text_field( $bill_to->locality ) );
				if ( ! empty( $bill_to->administrativeArea ) ) $customer->set_billing_state( sanitize_text_field( $bill_to->administrativeArea ) );
				if ( ! empty( $bill_to->postalCode ) ) $customer->set_billing_postcode( sanitize_text_field( $bill_to->postalCode ) );
				if ( ! empty( $bill_to->country ) ) $customer->set_billing_country( sanitize_text_field( $bill_to->country ) );
				if ( ! empty( $bill_to->email ) ) $customer->set_billing_email( sanitize_email( $bill_to->email ) );
				if ( ! empty( $bill_to->phoneNumber ) ) $customer->set_billing_phone( sanitize_text_field( $bill_to->phoneNumber ) );
				
				$customer->save();
			}

			// UCv1 flow: If complete response is available, extract token from it instead of making separate TOKEN_CREATE request.
			if ( ! empty( $complete_response_raw ) ) {
				$result = $this->handle_add_payment_method_from_complete_response( $complete_response_raw, $transient_token, $is_echeck );
			}
			if ( $result['status'] ) {
				wc_add_notice( $result['message'] );
				$redirect_url = wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS );
			} else {
				wc_add_notice( $result['message'], VISA_ACCEPTANCE_ERROR );
				$redirect_url = wc_get_endpoint_url( 'add-payment-method' );
			}
		} else {
			wc_add_notice( __( 'Please enter card details', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_ERROR );
			$redirect_url = wc_get_endpoint_url( 'add-payment-method' );
		}
		$this->safe_wc_redirect( $redirect_url );
	}

	/**
	 * Handle adding a payment method using the complete response from the UCv1 flow.
	 *
	 * @param string $complete_response_raw Complete response from checkout.complete().
	 * @param string $transient_token Transient token.
	 * @param bool   $is_echeck Whether this is an eCheck payment.
	 * @return array Result array with status and message.
	 */
	private function handle_add_payment_method_from_complete_response( $complete_response_raw, $transient_token, $is_echeck = false ) {
		$complete_payload = json_decode( $complete_response_raw, true );
		if ( is_string( $complete_payload ) ) {
			$complete_payload = json_decode( $complete_payload, true );
		}

		if ( ! is_array( $complete_payload ) ) {
			return array(
				'status'  => false,
				'message' => __( 'Unable to process payment method. Please try again.', 'visa-acceptance-solutions' ),
			);
		}

		// Log the complete response.
		$this->gateway->encode_complete_log = true;
		$this->gateway->add_logs_data(
			array( 'body' => wp_json_encode( $complete_payload ) ),
			false,
			'Complete'
		);
		$this->gateway->encode_complete_log = false;

		// Check the status first.
		$status = isset( $complete_payload['status'] ) ? $complete_payload['status'] : '';
		$valid_statuses = array( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED, VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS, VISA_ACCEPTANCE_API_RESPONSE_STATUS_TRANSMITTED );
		
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			return array(
				'status'  => false,
				'message' => __( 'Unable to save card. Please try again later.', 'visa-acceptance-solutions' ),
			);
		}

		// Extract transaction details.
		$details = isset( $complete_payload['details'] ) ? $complete_payload['details'] : array();
		
		$token_info = isset( $details['tokenInformation'] ) ? $details['tokenInformation'] : null;

		if ( empty( $token_info ) ) {
			return array(
				'status'  => false,
				'message' => __( 'No token information found in response. Please try again.', 'visa-acceptance-solutions' ),
			);
		}

		// Extract payment information.
		$payment_info = isset( $details['paymentInformation'] ) ? $details['paymentInformation'] : array();
		
		// Directly save the token without making API call to fetch card details.
		return $this->save_token_from_complete_response( $token_info, $payment_info, $transient_token, $is_echeck );
	}

	/**
	 * Save token directly from complete response data without API call.
	 *
	 * @param array  $token_info Token information from complete response.
	 * @param array  $payment_info Payment information from complete response.
	 * @param string $transient_token Transient token containing card details.
	 * @param bool   $is_echeck Whether this is an eCheck payment.
	 * @return array Result array with status and message.
	 */
	private function save_token_from_complete_response( $token_info, $payment_info, $transient_token, $is_echeck = false ) {
		try {
			$payment_methods = new Visa_Acceptance_Payment_Methods( $this->gateway );
			$customer_data = $this->get_order_for_add_payment_method();

			// Extract token IDs with multiple fallback approaches.
			$customer_id = null;
			$payment_instrument_id = null;
			$instrument_identifier_id = null;

			// Approach 1: Standard nested structure.
			if ( isset( $token_info['customer']['id'] ) ) {
				$customer_id = $token_info['customer']['id'];
			}
			
			if ( isset( $token_info['paymentInstrument']['id'] ) ) {
				$payment_instrument_id = $token_info['paymentInstrument']['id'];
			}
			
			if ( isset( $token_info['instrumentIdentifier']['id'] ) ) {
				$instrument_identifier_id = $token_info['instrumentIdentifier']['id'];
			}

			// Approach 2: Alternative key names.
			if ( empty( $customer_id ) && isset( $token_info['customerTokenId'] ) ) {
				$customer_id = $token_info['customerTokenId'];
			}

			if ( empty( $payment_instrument_id ) && isset( $token_info['paymentInstrumentTokenId'] ) ) {
				$payment_instrument_id = $token_info['paymentInstrumentTokenId'];
			}

			if ( empty( $instrument_identifier_id ) && isset( $token_info['instrumentIdentifierTokenId'] ) ) {
				$instrument_identifier_id = $token_info['instrumentIdentifierTokenId'];
			}
			// For UCv1 add payment method flow, only instrumentIdentifier is returned.
			// Use it as the primary token identifier.
			if ( empty( $instrument_identifier_id ) ) {
				return array(
					'status'  => false,
					'message' => __( 'Missing token identifiers. Please check error logs for details.', 'visa-acceptance-solutions' ),
				);
			}

			// For add payment method with UCv1, paymentInstrument.id may not be present.
			// Use instrumentIdentifier.id as both if needed.
			if ( empty( $payment_instrument_id ) ) {
				$payment_instrument_id = $instrument_identifier_id;
			}

			// Get customer ID from current user if not in token response.
			if ( empty( $customer_id ) ) {
				$request = new Visa_Acceptance_Payment_Adapter( $this->gateway );
				$customer_id = $request->get_customer_id();
			}

			// Build token data array.
			$tokens = array(
				'id' => $customer_id,
				'payment_instrument_id' => $payment_instrument_id,
				'instrument_identifier_id' => $instrument_identifier_id,
			);

			if ( $is_echeck ) {
				// eCheck token data.
				$bank_info = isset( $payment_info['bank'] ) ? $payment_info['bank'] : array();
				$account_number = isset( $bank_info['account']['number'] ) ? $bank_info['account']['number'] : '';
               
                // Extract last 4 digits from account number (may be masked or plain).
                if ( ! empty( $account_number ) ) {
                    // Remove all non-digits.
                    $digits_only = preg_replace( '/\D/', '', $account_number );
                   
                    if ( strlen( $digits_only ) >= VISA_ACCEPTANCE_VAL_FOUR ) {
                        // Get last 4 digits.
                        $tokens['account_number_last_four'] = substr( $digits_only, -4 );
                    } else {
                        // If less than 4 digits, pad with X.
                        $tokens['account_number_last_four'] = str_pad( $digits_only, VISA_ACCEPTANCE_VAL_FOUR, 'X', STR_PAD_LEFT );
                    }
                } else {
                    $tokens['account_number_last_four'] = 'XXXX';
                }
				$tokens['account_type'] = isset( $bank_info['account']['type'] ) ? ucfirst( strtolower( $bank_info['account']['type'] ) ) : 'Checking';
			} else {
				// Credit card token data.
				$card_info = isset( $payment_info['card'] ) ? $payment_info['card'] : array();
				
				// Try to get card details from multiple possible locations.
				if ( empty( $card_info ) && isset( $payment_info['tokenizedCard'] ) ) {
					$card_info = $payment_info['tokenizedCard'];
				}
				
				$tokens['card_type'] = isset( $card_info['type'] ) ? $card_info['type'] : null;
				
				// Extract expiry date from transient token instead of using placeholders.
				$exp_month = '12';
				$exp_year = gmdate( 'Y' ) + VISA_ACCEPTANCE_VAL_FIVE; // Default to next year if not found.
				
				if ( ! empty( $transient_token ) ) {
					$token_parts = explode( VISA_ACCEPTANCE_FULL_STOP, $transient_token );
					if ( isset( $token_parts[1] ) ) {
						$decoded_token = json_decode( base64_decode( $token_parts[1] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
						if ( isset( $decoded_token['content']['paymentInformation']['card']['expirationMonth'] ) ) {
							$month_data = $decoded_token['content']['paymentInformation']['card']['expirationMonth'];
							// Handle both direct value and array with 'value' key.
							$month_value = is_array( $month_data ) && isset( $month_data['value'] ) ? $month_data['value'] : $month_data;
							$exp_month = str_pad( (string) $month_value, VISA_ACCEPTANCE_VAL_TWO, '0', STR_PAD_LEFT );
						}
						if ( isset( $decoded_token['content']['paymentInformation']['card']['expirationYear'] ) ) {
							$year_data = $decoded_token['content']['paymentInformation']['card']['expirationYear'];
							// Handle both direct value and array with 'value' key.
							$year_value = is_array( $year_data ) && isset( $year_data['value'] ) ? $year_data['value'] : $year_data;
							$exp_year = (string) $year_value;
						}
					}
				}
				
				// Fallback to card_info if transient token didn't have the data.
				$tokens['exp_month'] = isset( $card_info['expirationMonth'] ) ? $card_info['expirationMonth'] : $exp_month;
				$tokens['exp_year'] = isset( $card_info['expirationYear'] ) ? $card_info['expirationYear'] : $exp_year;
				
				// Use instrument identifier as card number with last 4 digits.
				// Extract last 4 from instrument identifier or use placeholder.
				$last_four = substr( $instrument_identifier_id, -4 );
				$tokens['card_number'] = 'XXXXXX' . $last_four;
				$tokens['first_six'] = 'XXXXXX';
				$tokens['last_four'] = $last_four;
			}
			// Look up an existing token for this customer to avoid creating duplicates.
			$lookup_id      = $is_echeck ? $payment_instrument_id : $instrument_identifier_id;
			$existing_token = null;
			$settings       = $this->gateway->get_config_settings();
			$environment_id = isset( $settings['environment'] ) ? $settings['environment'] : '';
			if ( ! empty( $customer_data['customer_id'] ) ) {
				$core_tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
				foreach ( $core_tokens as $ct ) {
				if ( ! $ct instanceof \WC_Payment_Token ) {
				continue;
				}
				if ( $is_echeck && 'echeck' !== strtolower( $ct->get_type() ) ) {
				continue;
				}
				if ( ! $is_echeck && 'echeck' === strtolower( $ct->get_type() ) ) {
				continue;
				}
				if ( $ct->get_token() === $lookup_id && $ct->get_meta( VISA_ACCEPTANCE_ENVIRONMENT ) === $environment_id ) {
				$existing_token = $ct;
				break;
				}
				}
			}

			// Use existing token object for update, or create a new one for first save.
			$is_new_card = null === $existing_token;
			$token_obj   = $is_new_card
				? ( $is_echeck ? new \Visa_Acceptance_Payment_Token_ECheck() : new \WC_Payment_Token_CC() )
				: $existing_token;

			// Set the token ID only when creating a new record.
			if ( $is_new_card ) {
				if ( $is_echeck ) {
				$token_obj->set_token( $payment_instrument_id );
				} else {
				$token_obj->set_token( $instrument_identifier_id );
				}
			}

			// Get data to save.
			$data  = $payment_methods->get_data_to_save( $tokens, $customer_data, $is_new_card, $is_echeck );
 
			$props = $payment_methods->get_props( $is_echeck );
			
			// Save token to database.
			$result = $payment_methods->save_token_to_database( $token_obj, $data, $props, $is_echeck );
			// For eCheck, fetch complete details from TMS to get actual last 4 digits.
            if ( $is_echeck && isset( $result['status'] ) && $result['status'] ) {
                $card_data_response = $payment_methods->get_card_details( $tokens, $this->gateway->get_config_settings() );
                if ( isset( $card_data_response[1] ) && 200 === $card_data_response[1] ) {
                    $updated_tokens = $payment_methods->get_card_token_information( $card_data_response, $tokens, true );
                    if ( isset( $updated_tokens['account_number_last_four'] ) && 'XXXX' !== $updated_tokens['account_number_last_four'] ) {
                        // Update the token with actual last 4 digits.
                        $token_obj->update_meta_data( 'last4', $updated_tokens['account_number_last_four'], true );
                        $token_obj->save();
                    }
                }
            }
			return $result;

		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data(
				array( 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString() ),
				false,
				'Save Token Error'
			);
			return array(
				'status'  => false,
				'message' => __( 'Unable to save payment method. Please try again.', 'visa-acceptance-solutions' ),
			);
		}
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
			$last_four = $method['method']['last4'];
			echo '&bull; &bull; &bull; ' . esc_html( $last_four );
		}
	}

	/**
	 * This method handles UI logic for default payment method.
	 *
	 * @param array $method payment method.
	 */
	public function uc_add_payment_method_default( $method ) {
		if ( $method[VISA_ACCEPTANCE_IS_DEFAULT] && $this->gateway->get_id() === $method['method']['gateway'] ) {
			echo '<mark class="default">' . esc_html__( 'Default', 'visa-acceptance-solutions' ) . '</mark>';
		}
	}

	/**
	 * Add custom notice at Payment method page.
	 *
	 * @param string $icon Icon.
	 * @param int    $id Id.
	 * @return string The gateway icon (or empty string for this gateway).
	 */
	public function custom_gateway_icon( $icon, $id ) {
		if ( $id === $this->wc_payment_gateway_id ) {
			if ( is_add_payment_method_page() ) {
				static $notice_printed = false;
				if ( ! $notice_printed ) {
					$notice_message = esc_html__(
						'We will contact your card issuer to verify your account. No payment will be taken.',
						'visa-acceptance-solutions'
					);
					wc_print_notice( $notice_message, 'notice' );
					$notice_printed = true;
				}
			}
			return VISA_ACCEPTANCE_STRING_EMPTY;
		}
		return $icon;
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
                        wc_add_notice( esc_html__( 'Please set another card to default and try deleting the card again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_ERROR );
                    }
                    if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
                        wp_safe_redirect( wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS ) );
                        exit();
                    }
                } else {
                    wc_add_notice( esc_html__( 'Card deletion failed. Please try again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_ERROR );
                    $this->safe_wc_redirect( wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS ) );
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
                wc_add_notice( esc_html__( 'Card deletion failed. Please try again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_ERROR );
            }
            if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
                wp_safe_redirect( wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS ) );
                exit();
            }
        }
    }

	/**
     * Filter payment tokens to hide eCheck for free trial orders.
     *
     * @param array $tokens Array of payment tokens.
     * @param int   $user_id User ID.
     * @return array Filtered tokens.
     */
    public function filter_payment_tokens_for_free_trial( $tokens, $user_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $user_id is required by the woocommerce_get_customer_payment_tokens filter signature.
        // Only apply this filter on checkout pages, not on My Account Payment Methods page.
        if ( ! is_checkout() ) {
            return $tokens;
        }
        // Check if cart exists and total is $0.00 (free trial).
        // Check if cart exists and has items.
        if ( ! isset( WC()->cart ) || ! WC()->cart->get_cart_contents_count() ) {
            return $tokens;
        }
        // Only filter when the cart total is actually $0.00 (free trial); otherwise keep eCheck tokens.
        if ( (float) VISA_ACCEPTANCE_ZERO_AMOUNT !== (float) WC()->cart->get_totals()['total'] ) {
            return $tokens;
        }
       
        // Filter out eCheck tokens for this gateway.
        $filtered_tokens = array();
        foreach ( $tokens as $token ) {
            if ( $token instanceof \Visa_Acceptance_Payment_Token_ECheck && $token->get_gateway_id() === $this->gateway->get_id() ) {
                // Skip eCheck tokens for free trial.
                continue;
            }
            $filtered_tokens[] = $token;
        }
       
        return $filtered_tokens;
    }

	/**
	 * Marks default card as default and remaining non default in db.
	 */
	public function set_token_default() {
		$data_store                = WC_Data_Store::load( 'payment-token' );
		$customer_data             = $this->get_order_for_add_payment_method();
		$core_tokens               = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
		$default_payment_method_id = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_default_card_id", VISA_ACCEPTANCE_STRING_EMPTY );
		foreach ( $core_tokens as $token ) {
			if ( ! $token instanceof \WC_Payment_Token ) {
                continue;
            }
			if ( (int) $default_payment_method_id === $token->get_id() ) {
				$data_store->set_default_status( $token->get_id(), true );
			} else {
				$data_store->set_default_status( $token->get_id(), false );
			}
		}
	}

	/**
	 * Triggers card default service.
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
				$this->gateway->add_logs_service_response( $api_response[VISA_ACCEPTANCE_VAL_ZERO],$api_response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID], true, 'Set as default Payment Method' );
				$return_array = array(
					'http_code' => $api_response[VISA_ACCEPTANCE_VAL_ONE],
					'body'      => $api_response[VISA_ACCEPTANCE_VAL_ZERO],
				);
				return $return_array;
			} catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
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
					wc_add_notice( esc_html__( 'Failed to update as default payment method. Please try again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_ERROR );
					wp_safe_redirect( wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS ) );
					exit();
				}
			} else {
				return;
			}
		} catch ( \Exception $e ) {
			$log_header = 'Set as default Payment Method';
			$this->gateway->add_logs_data( $e->getMessage(), false, $log_header );
			wc_add_notice( esc_html__( 'Failed to update as default payment method. Please try again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_ERROR );
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
		if ( $payment_token instanceof \WC_Payment_Token && $payment_token->get_gateway_id() === $this->gateway->get_id() ) {
			$method['token'] = $payment_token->get_token();
			if ( $payment_token->get_is_default() ) {
				WC()->session->set( "wc_{$this->wc_payment_gateway_id}_default_card_id", wc_clean( $payment_token->get_id() ) );
			}
			if ( $payment_token instanceof \Visa_Acceptance_Payment_Token_ECheck ) {
				$method['method']['brand'] = __( 'Account', 'visa-acceptance-solutions' );
			}
		}
		return $method;
	}

	/**
	 * AJAX handler to store browser data for 3DS device information.
	 */
	public function store_browser_data() {
		check_ajax_referer( 'store_browser_data_action', VISA_ACCEPTANCE_NONCE );
		
		$gateway_id = isset( $_POST['gateway_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_id'] ) ) : VISA_ACCEPTANCE_STRING_EMPTY;
		
		if ( empty( $gateway_id ) || ! isset( WC()->session ) ) {
			wp_send_json_error();
		}
		
		if ( isset( $_POST['screen_height'] ) ) {
			WC()->session->set( "wc_{$gateway_id}_browser_screen_height", absint( $_POST['screen_height'] ) );
		}
		
		if ( isset( $_POST['screen_width'] ) ) {
			WC()->session->set( "wc_{$gateway_id}_browser_screen_width", absint( $_POST['screen_width'] ) );
		}
		
		if ( isset( $_POST['color_depth'] ) ) {
			WC()->session->set( "wc_{$gateway_id}_browser_color_depth", absint( $_POST['color_depth'] ) );
		}
		
		if ( isset( $_POST['tz_offset'] ) ) {
			WC()->session->set( "wc_{$gateway_id}_browser_tz_offset", intval( $_POST['tz_offset'] ) );
		}
		
		if ( isset( $_POST['java_enabled'] ) ) {
			WC()->session->set( "wc_{$gateway_id}_browser_java_enabled", filter_var( wp_unslash( $_POST['java_enabled'] ), FILTER_VALIDATE_BOOLEAN ) );
		}
		
		if ( isset( $_POST['js_enabled'] ) ) {
			WC()->session->set( "wc_{$gateway_id}_browser_js_enabled", filter_var( wp_unslash( $_POST['js_enabled'] ), FILTER_VALIDATE_BOOLEAN ) );
		}
		
		wp_send_json_success();
	}

	/**
	 * Check if an order contains only virtual products.
	 *
	 * @param \WC_Order $order Order object.
	 * @return bool True if order contains only virtual products, false otherwise.
	 */
	private function is_order_virtual( $order ) {
		if ( ! $order ) {
			return false;
		}
		
		$items = $order->get_items();
		if ( empty( $items ) ) {
			return false;
		}
		
		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( ! $product || ! $product->is_virtual() ) {
				return false;
			}
		}
		
		return true;
	}
}
