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
require_once plugin_dir_path( __DIR__ ) . 'includes/api/class-visa-acceptance-api-client.php';
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

		// visa acceptance need JavaScript to process a token only on cart/checkout pages.
		$get_data = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$response_array = array();
		if ( is_checkout() || isset( $get_data['pay_for_order'] ) || is_account_page() ) {
			$uc_settings       = $this->get_uc_settings();
			$payment_method    = new Visa_Acceptance_Payment_Methods( $this );
			$flex_request      = new Visa_Acceptance_Key_Generation( $this->gateway );
			$customer_data     = $payment_method->get_order_for_add_payment_method();
			$core_tokens       = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->wc_payment_gateway_id );
			$token_count       = count( $core_tokens );
			$token_type        = array();
			$payer_auth_enable = ! empty( $uc_settings['enable_threed_secure'] ) ? $uc_settings['enable_threed_secure'] : VISA_ACCEPTANCE_STRING_EMPTY;
			$client_library    = VISA_ACCEPTANCE_STRING_EMPTY;
			$token_key         = $uc_settings['test_api_key'];
			foreach ( $core_tokens as $token ) {
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
			if ( is_checkout() || $this->is_user_in_add_payment_method_page() || isset( $get_data['pay_for_order'] ) ) {
				$response        = $flex_request->get_unified_checkout_capture_context();
				$capture_context = ! empty( $response['body'] ) ? $response['body'] : VISA_ACCEPTANCE_STRING_EMPTY;
				$msg_failed = (array)$capture_context;
				if (array_key_exists("response", $msg_failed)) {
					$capture_context = wp_json_encode($capture_context);
				}
				$this->add_uc_token( $capture_context );
				$client_library = $this->get_uc_client_library( $capture_context );
			}
			if ( isset( $uc_settings['enabled'] ) && ( VISA_ACCEPTANCE_YES === $uc_settings['enabled'] ) ) {
				wp_enqueue_style( $this->wc_payment_gateway_id, plugin_dir_url( __FILE__ ) . 'css/visa-acceptance-payment-gateway-public.css', array(), $this->version, VISA_ACCEPTANCE_STRING_ALL );
				wp_enqueue_script( 'unified-checkout-js-library', $client_library, array(), $this->version, false );// phpcs:ignore WordPress.Security.NonceVerification
				wp_enqueue_script( VISA_ACCEPTANCE_GATEWAY_ID_UNDERSCORE . $this->wc_payment_gateway_id, plugin_dir_url( __FILE__ ) . 'js/visa-acceptance-payment-gateway-unified-checkout-public.js', array( 'jquery' ), $this->version, true );
				wp_localize_script(
					VISA_ACCEPTANCE_GATEWAY_ID_UNDERSCORE . $this->wc_payment_gateway_id,
					'visa_acceptance_ajaxUCObj',
					array(
						'ajax_url'                        => admin_url( 'admin-ajax.php' ),
						'token_type'                      => $token_type,
						'token_cnt'                       => $token_count,
						'payment_method'                  => VISA_ACCEPTANCE_GATEWAY_UC,
						'checkout_page'                   => is_checkout(),
						'user_logged_in'                  => is_user_logged_in(),
						'payer_auth_enabled'              => $payer_auth_enable,
						'visa_acceptance_solutions_uc_id' => VISA_ACCEPTANCE_UC_ID,
						'visa_acceptance_solutions_uc_id_hyphen' => VISA_ACCEPTANCE_UC_ID_HYPHEN,
						'token_key'                       => $token_key,
						'encrypt_const'                   => __( 'encrypt', 'visa-acceptance-solutions' ),
						'form_load_error'                 => __( 'Unable to load the payment form. Please contact customer care for any assistance.', 'visa-acceptance-solutions' ),
						'delete_card_text'                => __( 'Are you sure you want to delete this payment method?', 'visa-acceptance-solutions' ),
						'offline_text'                    => __( 'You are not connected to internet!!', 'visa-acceptance-solutions' ),
						'error_failure'                   => __( 'Unable to process your request. Please try again later.', 'visa-acceptance-solutions' ),
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

		wp_enqueue_style( $this->wc_payment_gateway_id . VISA_ACCEPTANCE_UNDERSCORE_PAYER_AUTH, plugin_dir_url( __FILE__ ) . 'css/visa-acceptance-payment-gateway-payer-auth-public.css', array(), $this->version, VISA_ACCEPTANCE_STRING_ALL );
		wp_enqueue_script( $this->wc_payment_gateway_id . VISA_ACCEPTANCE_UNDERSCORE_PAYER_AUTH, plugin_dir_url( __FILE__ ) . 'js/visa-acceptance-payment-gateway-payer-auth-public.js', array( 'jquery' ), $this->version, false );
		$payer_auth_params = array(
			'admin_url'                              => admin_url( 'admin-ajax.php' ),
			'cardinal_url'                           => $cardinal_url,
			'payer_auth_enabled'                     => $payer_auth_enable,
			'nonce_setup'                            => $nonce_setup,
			'nonce_enrollment'                       => $nonce_enrollment,
			'nonce_validation'                       => $nonce_validation,
			'nonce_error_handler'                    => $nonce_error_handler,
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
		$settings           = $this->get_uc_settings();
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
								'<input type="password" autoComplete="new-password" id="wc-unified-checkout-saved-card-cvn" class="wc-unified-checkout-saved-card-cvn" name="csc-saved-card-' . esc_attr( $id ) . '" placeholder="***" minLength=3 maxLength=4 style=" margin-top: 5px" required/>' .
								'</label>' .
								'<div class="cvv-div" id="error-' . esc_attr( $id ) . '">' .
									'<p class="credit-card-error-message-saved-card" id="wc-csc-saved-card-error">' . esc_html__( 'Please enter valid Security Code', 'visa-acceptance-solutions' ) . '</p>' .
								'</div>' .
							'</div>';
						}
					}
				}

				echo '<div id="wc-credit-card-use-new-payment-method-div">' .
						'<input type="radio" id="wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-use-new-payment-method" name="wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-payment-token" class="js-wc-payment-token js-wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-payment-token" style="width:auto; margin-right: .5em;" value="" />' .
						'<label style="display:inline; margin-left: 8px;" for="wc-' . esc_attr( $this->gateway->get_id_dasherized() ) . '-use-new-payment-method">' . esc_html__( 'Use a new card', 'visa-acceptance-solutions' ) . '</label>' .
					'</div>';
			}
			$capture_context = $this->updates_capture_context();
			if ( ! empty( $capture_context['capture_context'] ) ) {
				echo '<input type="hidden" id ="jwt_updated" value="' . esc_attr( $capture_context['capture_context'] ) . '"/>';
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
					echo '<div id="wc-unified-checkout-save-token-div">' . wp_kses_post( $response ) . '</div>';
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
	 * Adds Unified Checkout JWT Token to UI to fetch it for JS purposes.
	 *
	 * @param mixed $capture_context Capture Context.
	 */
	public function add_uc_token( $capture_context ) {
		?>
			<div>
				<input type="hidden" id="jwt" value="<?php echo esc_html( $capture_context ); ?>"/>
			</div>
		<?php
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
		$is_save_card_blocks   = null;
		$blocks_token          = null;
		$token_id              = null;
		$transient_token       = null;
		$is_save_card          = null;
		$saved_card_cvv        = null;
		$saved_card_cvv_blocks = null;
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

		$random_bytes  = random_bytes( VISA_ACCEPTANCE_VAL_TWO ); // phpcs:ignore
		$random_number = unpack( 'n', $random_bytes )[ VISA_ACCEPTANCE_VAL_ONE ] % 900 + 100; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $post_data[ 'wc-' . VISA_ACCEPTANCE_UC_ID . '-new-payment-method' ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$is_save_card_blocks = wc_clean( wp_unslash( $post_data[ 'wc-' . VISA_ACCEPTANCE_UC_ID . '-new-payment-method' ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
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
		if ( ! empty( $post_data[ 'csc-saved-card-' . $token_id ] ) && ! empty( $post_data[ 'extId-' . $token_id ] ) && ! empty( $post_data[ 'refId-' . $token_id ] ) ) {
			$encrypted_csc  = sanitize_text_field( wp_unslash( $post_data[ 'csc-saved-card-' . $token_id ] ) );
			$ext_id         = sanitize_text_field( wp_unslash( $post_data[ 'extId-' . $token_id ] ) );
			$val_id         = $this->get_uc_settings()['test_api_key'];
			$ref_id         = sanitize_text_field( wp_unslash( $post_data[ 'refId-' . $token_id ] ) );
			$saved_card_cvv = $decrypt_data->decrypt_cvv( $encrypted_csc, $ext_id, $val_id, $ref_id );
		}
		$decoded_transient_token = ! empty( $transient_token ) ? json_decode( base64_decode( explode( '.', $transient_token )[1] ), true ) : null;// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( isset( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) ) {
			$payment_solution = $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'];
			if ( handle_hpos_compatibility() ) {
				$order->add_meta_data( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_PAYMENT_SOLUTION, $payment_solution, false );
				$order->save_meta_data();
			} else {
				add_post_meta( $order->get_id(), VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_PAYMENT_SOLUTION, $payment_solution );
			}
		}
		$subscription_active              = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
		if ( $subscription_active && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) {
			$saved_token_id = ! empty( $token_id ) ? $token_id : $blocks_token;
			return $subscriptions->change_payment_method( $saved_token_id, $order, $transient_token );
		}
		if ( isset( $setting['enable_threed_secure'] ) && VISA_ACCEPTANCE_YES === $setting['enable_threed_secure'] && VISA_ACCEPTANCE_YES === $payer_auth_enabled && ! isset( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) ) {
			if ( ! empty( $post_data['blocks_token'] ) ) {
				if ( $is_save_card_blocks ) {
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

		if ( VISA_ACCEPTANCE_ONE === (int) $is_save_card_blocks && ! isset( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) ) {
			$is_save_card = VISA_ACCEPTANCE_YES;
		}

		if ( isset( $decoded_transient_token['content']['processingInformation']['paymentSolution']['value'] ) ) {
			$is_save_card = VISA_ACCEPTANCE_NO;
		}

		if ( $blocks_token || $token_id ) {
			if ( $token_id ) {
				$token = $this->get_meta_data_token( $token_id );
				if ( $token ) {
					$result = $authorization_saved_card->do_transaction( $order, $token, $saved_card_cvv );
				}
			} else {
				$blocks_meta_token = $this->get_meta_data_token( $blocks_token );
				if ( $blocks_meta_token ) {
					$result = $authorization_saved_card->do_transaction( $order, $blocks_meta_token, $saved_card_cvv_blocks );
				}
			}
		} else {
			if ( $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order ) ) ) {
				$is_save_card = VISA_ACCEPTANCE_YES;
			}
			if ( ! empty( $transient_token ) ){
                $result = $payment_uc->do_transaction( $order, $transient_token, $is_save_card );
            }
		}

		if ( $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order ) ) ) {
			$subscriptions->add_payment_data_to_subscription( $order );
		}
		if ( is_array($result) && $result[ VISA_ACCEPTANCE_SUCCESS ] ) {
			WC()->cart->empty_cart();
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
	 * Generates capture context if total amount changes in cart
	 */
	public function updates_capture_context() {
		$return_response        = array(
			'success'         => true,
			'capture_context' => null,
		);
		$client_library         = VISA_ACCEPTANCE_STRING_EMPTY;
		$checkout_order_total   = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$total_amount           = WC()->session->get( "wc_{$this->wc_payment_gateway_id}_capture_context_total_amount" );
		$key_generation_request = new Visa_Acceptance_Key_Generation_Request( $this->gateway );
		$flex_request           = new Visa_Acceptance_Key_Generation( $this->gateway );
		$checkout_total_amount  = $key_generation_request->get_admin_checkout_total_amount();
		if ( $checkout_total_amount['is_admin_order_pay_page'] ) {
			$checkout_order_total = isset( $checkout_total_amount['total_amount'] ) ? $checkout_total_amount['total_amount'] : $checkout_order_total;
		} else {
			$checkout_order_total = isset( WC()->cart ) ? WC()->cart->get_totals()['total'] : $checkout_order_total;
		}
		if ( ! empty( $total_amount ) && $checkout_order_total !== $total_amount ) { // phpcs:ignore WordPress.Security.NonceVerification.
			$return_response['success'] = false;
			$response                   = $flex_request->get_unified_checkout_capture_context();
			if ( isset( $response['http_code'] ) && VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $response['http_code'] ) {
				$capture_context                    = ! empty( $response['body'] ) ? $response['body'] : VISA_ACCEPTANCE_STRING_EMPTY;
				$return_response['capture_context'] = $capture_context;
				$return_response['success']         = true;
				$client_library                     = $this->get_uc_client_library( $capture_context );
				wp_enqueue_script( 'unified-checkout-js-library', $client_library, array(), null, false );// phpcs:ignore
			}
		}
		return $return_response;
	}

	/**
	 * Fetches uc client library
	 *
	 * @param string $capture_context capture context.
	 * @return any
	 */
	public function get_uc_client_library( $capture_context ) {
		$client_library            = VISA_ACCEPTANCE_STRING_EMPTY;
		$capture_context_component = explode( VISA_ACCEPTANCE_FULL_STOP, $capture_context );
		if ( VISA_ACCEPTANCE_VAL_THREE === count( $capture_context_component ) ) {
			$decoded_payload = json_decode( base64_decode( $capture_context_component[ VISA_ACCEPTANCE_VAL_ONE ] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( ! isset( $decoded_payload ) ) {
				$decoded_payload = json_decode( base64_decode( str_replace( array( VISA_ACCEPTANCE_HYPHEN, VISA_ACCEPTANCE_UNDERSCORE ), array( '+', VISA_ACCEPTANCE_SLASH ), $capture_context_component[ VISA_ACCEPTANCE_VAL_ONE ] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			}
		}
		if ( isset( $decoded_payload ) ) {
			$client_library = $decoded_payload['ctx'][ VISA_ACCEPTANCE_VAL_ZERO ]['data']['clientLibrary'];
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
		$token_checkbox     = ( ! empty( $post_data['tokenCheckbox'] ) ) ? wc_clean( wp_unslash( $post_data['tokenCheckbox'] ) ) : null;
		$card_token         = ( ! empty( $post_data['cardtoken'] ) ) ? wc_clean( wp_unslash( $post_data['cardtoken'] ) ) : null;
		$saved_token        = ( ! empty( $post_data['savedtoken'] ) ) ? wc_clean( wp_unslash( $post_data['savedtoken'] ) ) : null;
		$saved_token        = ( ! empty( $saved_token ) ) ? $this->get_meta_data_token( $saved_token ) : null;
		$order_id           = ( ! empty( $post_data['orderid'] ) ) ? absint( wp_unslash( $post_data['orderid'] ) ) : null;
		$reference_id       = ( ! empty( $post_data['referenceId'] ) ) ? wc_clean( wp_unslash( $post_data['referenceId'] ) ) : null;
		$sca_case           = ( ! empty( $post_data['scaCase'] ) ) ? wc_clean( wp_unslash( $post_data['scaCase'] ) ) : null;
		$return_response    = array(
			'error'       => VISA_ACCEPTANCE_VAL_ZERO,
			'message'     => VISA_ACCEPTANCE_STRING_EMPTY,
			'status'      => VISA_ACCEPTANCE_STRING_EMPTY,
			'stepUpUrl'   => VISA_ACCEPTANCE_STRING_EMPTY,
			'accessToken' => VISA_ACCEPTANCE_STRING_EMPTY,
		);
		$uc_payment_gateway = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		if ( ! ( ! empty( $saved_token ) && $saved_token instanceof WC_Payment_Token ) && $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) ) {
			$token_checkbox = VISA_ACCEPTANCE_YES;
		}
		// Calling Payer Auth Setup API.
		$api_response = $uc_payment_gateway->payer_auth_enrollment( $order_id, $card_token, $saved_token, $token_checkbox, $reference_id, $sca_case );
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
		$token_checkbox     = ( ! empty( $post_data['tokenCheckbox'] ) ) ? wc_clean( wp_unslash( $post_data['tokenCheckbox'] ) ) : null;
		$card_token         = ( ! empty( $post_data['cardtoken'] ) ) ? wc_clean( wp_unslash( $post_data['cardtoken'] ) ) : null;
		$saved_token        = ( ! empty( $post_data['savedtoken'] ) ) ? wc_clean( wp_unslash( $post_data['savedtoken'] ) ) : null;
		$saved_token        = ( ! empty( $saved_token ) ) ? $this->get_meta_data_token( $saved_token ) : null;
		$order_id           = ( ! empty( $post_data['orderid'] ) ) ? absint( wp_unslash( $post_data['orderid'] ) ) : null;
		$auth_id            = ( ! empty( $post_data['authid'] ) ) ? wc_clean( wp_unslash( $post_data['authid'] ) ) : null;
		$pareq              = ( ! empty( $post_data['pareq'] ) ) ? wc_clean( wp_unslash( $post_data['pareq'] ) ) : null;
		$sca_case           = ( ! empty( $post_data['scaCase'] ) ) ? wc_clean( wp_unslash( $post_data['scaCase'] ) ) : null;
		$uc_payment_gateway = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		// Calling Payer Auth Setup API.
		if ( ! ( ! empty( $saved_token ) && $saved_token instanceof WC_Payment_Token ) && $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) ) {
			$token_checkbox = VISA_ACCEPTANCE_YES;
		}
		$return_response = $uc_payment_gateway->payer_auth_validation( $order_id, $card_token, $saved_token, $token_checkbox, $auth_id, $pareq, $sca_case );
		if ( isset( $return_response['error'] ) ) {
			wc_clear_notices();
			wc_add_notice( $return_response['error'], VISA_ACCEPTANCE_STRING_ERROR );
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
					wc_add_notice( esc_html__( 'Please set another card to default and try deleting the card again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_STRING_ERROR );
					wp_safe_redirect( wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS ) );
					exit();
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
			wc_add_notice( esc_html__( 'Card deletion failed. Please try again.', 'visa-acceptance-solutions' ), VISA_ACCEPTANCE_STRING_ERROR );
			wp_safe_redirect( wc_get_account_endpoint_url( VISA_ACCEPTANCE_PAYMENT_METHODS ) );
			exit();
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
		$settings     = $this->gateway->get_config_settings();
		$payment_method    = new Visa_Acceptance_Payment_Methods( $this );
		$token_data       = $payment_method->build_token_data( $token );
		$request         = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client      = $request->get_api_client();
		$payments_api    = new CustomerPaymentInstrumentApi( $api_client );
		$payload = [
			'default' => true,
		];
		if ( ! empty( $payload ) ) {
			$this->gateway->add_logs_data( wp_json_encode($payload), true, 'Set as default Payment Method' );
			$patch_customer_payment_instrument_request = new PatchCustomerPaymentInstrumentRequest($payload);
			try {
				$api_response = $payments_api->patchCustomersPaymentInstrument( $token_data['token_information']['id'], $token_data['token_information']['payment_instrument_id'],$patch_customer_payment_instrument_request  );
				$this->gateway->add_logs_service_response( $api_response[0],$api_response[2]['v-c-correlation-id'], true, 'Set as default Payment Method' );
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
