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

require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-payment-methods.php';
require_once plugin_dir_path( __FILE__ ) . 'class-visa-acceptance-settings-validator.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-webhook-handler.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-reporting.php';

use Pymt_Vas\Dependencies\CyberSource\Authentication\Core\MerchantConfiguration as WebhookMerchantConfiguration;
use Pymt_Vas\Dependencies\CyberSource\Configuration as WebhookConfiguration;
use Pymt_Vas\Dependencies\CyberSource\Logging\LogConfiguration as WebhookLogConfiguration;
use Pymt_Vas\Dependencies\CyberSource\ApiClient as WebhookApiClient;
use Pymt_Vas\Dependencies\CyberSource\ApiException as WebhookApiException;
use Pymt_Vas\Dependencies\CyberSource\Api\ManageWebhooksApi;
use Pymt_Vas\Dependencies\CyberSource\Api\CreateNewWebhooksApi;

/**
 *
 * Visa Acceptance Payment Gateway Unified Checkout Class
 *
 * Handles hooks, dependencies and other functionality
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Payment_Gateway_Unified_Checkout extends \WC_Payment_Gateway {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;
	use Visa_Acceptance_Payment_Gateway_Public_Trait;

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @var      Visa_Acceptance_Payment_Gateway_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The current version of the plugin.
	 *
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * The Public gateway object of this plugin.
	 *
	 * @var      object    $plugin_public    The current payment gateways public object.
	 */
	protected $plugin_public;

	/**
	 * The Public gateway object of this plugin.
	 *
	 * @var      object    $plugin_public    The current payment gateways public object.
	 */
	protected $express_pay_plugin_public;

	/**
	 * The Admin object of this plugin.
	 *
	 * @var      object    $plugin_admin    The current payment gateways admin object.
	 */
	protected $plugin_admin;

	/**
	 * The gateway object of this plugin.
	 *
	 * @var      object    $plugin_public    The current payment gateways public object.
	 */
	public $is_subscriptions_activated = false;


	/**
	 * Used by complete mandate flow to encode Authorization/Charge responses.
	 *
	 * @var bool
	 */
	public $encode_complete_log = false;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 */
	public function __construct() {
		$this->version = defined( 'VISA_ACCEPTANCE_PLUGIN_VERSION' ) ? VISA_ACCEPTANCE_PLUGIN_VERSION : VISA_ACCEPTANCE_FALLBACK_VERSION;

		// Setup general properties.
		$this->setup_properties();

		add_action( 'send_headers', array( $this, 'add_clickjacking_headers' ) );
		add_action( 'wp_head', array( $this, 'add_clickjacking_script' ), 1 );
		add_action( 'current_screen', array( $this, 'admin_clickjacking_script' ) );

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->enabled = $this->get_option( 'enabled' );	
		$this->define_public_hooks();
        // Initialize settings immediately (no translations used).
		$this->init_settings();
		
		// Delay form fields initialization to prevent translation warnings.
		// Form fields will be lazy-loaded when accessed in admin.
		if ( did_action( 'init' ) ) {
			// If init already fired, load form fields now.
			$this->init_form_fields();
		} else {
			// Hook to init to load form fields after translations are ready.
			add_action( 'init', array( $this, 'init_form_fields' ), 5 );
		}

		// trigger payment_fields function.
		$this->has_fields = true;

		// Get settings.
		
		if ( VISA_ACCEPTANCE_YES === $this->enabled ) {
			$this->title = $this->get_option( 'title', VISA_ACCEPTANCE_PLUGIN_DISPLAY_NAME );
		}

		$this->description = $this->get_option( 'description' );

		// Register all CyberSource webhook REST endpoints (fraud/DM/FME/NT + UC) via one handler.
        add_action( 'rest_api_init', array( $this, 'register_fraud_webhook_endpoint' ) );
		$this->is_subscriptions_activated = $this->is_wc_subscriptions_activated() ? ( VISA_ACCEPTANCE_YES === $this->enabled && ( VISA_ACCEPTANCE_YES === $this->get_option( 'tokenization' ) ) ) : false;
		if ( $this->is_subscriptions_activated ) {
			$this->supports = array(
				'products',
				'tokenization',
				'subscriptions',
				'subscription_cancellation',
				'subscription_suspension',
				'subscription_reactivation',
				'subscription_amount_changes',
				'subscription_date_changes',
				'subscription_payment_method_change',
				'subscription_payment_method_change_customer',
				'subscription_payment_method_change_admin',
				'multiple_subscriptions',
			);

		}
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
	 * Guarded via a global constant so the headers are only ever sent once per request,
	 * even though Visa_Acceptance_Payment_Gateway_Unified_Checkout_Admin registers the
	 * same 'send_headers' hook independently.
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
	 * Is wc subscriptions activated.
	 *
	 * @return boolean $is_actived
	 */
	public function is_wc_subscriptions_activated() {
		$is_actived     = false;
		$active_plugins = (array) get_option( 'active_plugins', array() );
		foreach ( $active_plugins as $active_plugin ) {
			$active_plugin = explode( '/', $active_plugin );
			if ( in_array( 'woocommerce-subscriptions.php', $active_plugin, true ) ) {
				$is_actived = true;
				break;
			}
		}
		return $is_actived;
	}

	/**
	 * Function for `woocommerce_bulk_action_ids` filter-hook.
	 *
	 * @param array  $ids    Array of order IDs.
	 * @param string $action action.
	 * @param string $text text.
	 *
	 * @return array
	 */
	public function wp_kama_woocommerce_bulk_action_ids_filter( $ids, $action, $text ) {
		foreach ( $ids as $order_id ) {
			$order      = wc_get_order( $order_id );
			$order_type = $order->get_payment_method();

			// If order type is of Visa Acceptance Solutions, proceed with the logic.
			if ( in_array( $order_type, array( VISA_ACCEPTANCE_UC_ID, VISA_ACCEPTANCE_SV_GATEWAY_ID ), true ) ) {
				$settings = $this->get_gateway_settings();

				// Check if the service call is enabled and the action is valid.
				if ( VISA_ACCEPTANCE_YES === $settings['enable_paid_capture'] && ( VISA_ACCEPTANCE_MARK_PROCESSING === $action || VISA_ACCEPTANCE_MARK_COMPLETED === $action ) ) {
					// Use a transient to prevent duplicate service calls for the same order.
					$transient_key = "visa_acceptance_solutions_service_call_in_progress_{$order_id}";

					// Use atomic locking to prevent race conditions.
					if ( false === get_transient( $transient_key ) && set_transient( $transient_key, true, 50 * MINUTE_IN_SECONDS ) ) {
						// Set a transient to lock the service call for this order.
						try {
							// Perform the service call based on the order type.
							if ( VISA_ACCEPTANCE_UC_ID === $order_type ) {
								$this->init_process_capture( $order_id, VISA_ACCEPTANCE_UC_ID );
							} else {
								$this->init_process_capture( $order_id, VISA_ACCEPTANCE_SV_GATEWAY_ID );
							}
						} catch ( Exception $e ) {
							// Log the error and ensure the transient is deleted.
							wc_get_logger()->error( "Error processing capture for order {$order_id}: " . $e->getMessage() );
						} finally {
							// Delete the transient after the service call is completed.
							delete_transient( $transient_key );
						}
					}
				}
			}
		}
		// Return the IDs after the bulk action.
		return $ids;
	}

	/**
	 * Registers both CyberSource webhook REST endpoints via the unified handler.
	 *
	 * @return void
	 */
	public function register_fraud_webhook_endpoint() {
		( new Visa_Acceptance_Webhook_Handler( $this ) )->register_rest_route();
	}

	/**
	 * Resolves the API credentials needed for webhook management calls.
	 *
	 * @return array { is_test: bool, host: string, merchant_id: string, api_key: string, secret_key: string }
	 */
	private function get_webhook_api_credentials() {
		$settings    = $this->get_gateway_settings();
		$is_test     = VISA_ACCEPTANCE_ENVIRONMENT_PRODUCTION !== ( $settings[ VISA_ACCEPTANCE_ENVIRONMENT ] ?? VISA_ACCEPTANCE_STRING_EMPTY );
		$host        = $is_test ? VISA_ACCEPTANCE_REQUEST_HOST_APITEST : VISA_ACCEPTANCE_REQUEST_HOST_APIPRODUCTION;
		$merchant_id = $this->get_merchant_id();
		$api_key     = $is_test ? ( $settings['test_api_key'] ?? VISA_ACCEPTANCE_STRING_EMPTY ) : ( $settings['api_key'] ?? VISA_ACCEPTANCE_STRING_EMPTY );
		$secret_key  = $is_test ? ( $settings['test_api_shared_secret'] ?? VISA_ACCEPTANCE_STRING_EMPTY ) : ( $settings['api_shared_secret'] ?? VISA_ACCEPTANCE_STRING_EMPTY );
		return compact( 'is_test', 'host', 'merchant_id', 'api_key', 'secret_key' );
	}

	/**
	 * Builds a CyberSource SDK API client configured for HTTP Signature webhook management calls.
	 *
	 * @param bool   $is_test     Whether we are in test mode.
	 * @param string $merchant_id Merchant ID.
	 * @param string $api_key     API key ID.
	 * @param string $secret_key  Base64-encoded shared secret.
	 * @return \Pymt_Vas\Dependencies\CyberSource\ApiClient
	 */
	private function get_webhook_api_client( $is_test, $merchant_id, $api_key, $secret_key ) {
		$host = $is_test ? VISA_ACCEPTANCE_REQUEST_HOST_APITEST : VISA_ACCEPTANCE_REQUEST_HOST_APIPRODUCTION;

		$merchant_configuration = new WebhookMerchantConfiguration();
		$merchant_configuration->setAuthenticationType( 'HTTP_SIGNATURE' );
		$merchant_configuration->setRunEnvironment( $host );
		$merchant_configuration->setMerchantID( $merchant_id );
		$merchant_configuration->setApiKeyID( $api_key );
		$merchant_configuration->setSecretKey( $secret_key );
		$merchant_configuration->setLogConfiguration( new WebhookLogConfiguration() );

		$configuration = new WebhookConfiguration();
		$configuration->setHost( $host );
		$configuration->setSSLVerification( ! $is_test );

		return new WebhookApiClient( $configuration, $merchant_configuration );
	}

	/**
	 * Calls the KMS asymmetric egress key endpoint directly via the SDK's ApiClient.
	 *
	 * The generated ManageWebhooksApi::saveAsymEgressKey() response model does not expose the
	 * keyInformation payload, so the raw SDK-decoded response is read instead of the typed model,
	 * while still relying on the SDK for authentication, transport and error handling.
	 *
	 * @param \Pymt_Vas\Dependencies\CyberSource\ApiClient $api_client Configured SDK API client.
	 * @param array                                        $body       Request body.
	 * @return object Decoded response body.
	 * @throws WebhookApiException On non-2xx response.
	 */
	private function call_kms_asym_egress_key( $api_client, array $body ) {
		$headers = array(
			'Accept'       => $api_client->selectHeaderAccept( array( 'application/json;charset=utf-8' ) ),
			'Content-Type' => $api_client->selectHeaderContentType( array( 'application/json;charset=utf-8' ) ),
		);

		[ $data ] = $api_client->callApi( '/kms/egress/v2/keys-asym', 'POST', array(), $body, $headers, null, '/kms/egress/v2/keys-asym', false );

		return $data;
	}

	/**
	 * Extracts a readable error message from a CyberSource SDK ApiException response body.
	 *
	 * @param WebhookApiException $e Exception thrown by the SDK.
	 * @return string
	 */
	private function get_error_message_from_exception( $e ) {
		$body    = $e->getResponseBody();
		$decoded = is_string( $body ) ? json_decode( $body, true ) : json_decode( wp_json_encode( $body ), true );

		if ( is_array( $decoded ) ) {
			return $decoded['message'] ?? ( $decoded['errors'][ VISA_ACCEPTANCE_VAL_ZERO ]['message'] ?? $e->getMessage() );
		}
		return $e->getMessage();
	}

	/**
     * Subscribes to CyberSource Decision Manager AND Fraud Management Essentials
     *
     * @return void
     */
    private function subscribe_fraud_webhook() {
        try {
            [ 'is_test' => $is_test, 'host' => $host, 'merchant_id' => $merchant_id, 'api_key' => $api_key, 'secret_key' => $secret_key ] = $this->get_webhook_api_credentials();
 
            // Always remove any already-subscribed DM/FME webhook first, even if this attempt ends up skipped/failed below.
            $this->force_delete_orphan_webhooks_by_product_ids(
                $host,
                $merchant_id,
                $api_key,
                $secret_key,
                $is_test,
                array('decisionManager', 'fraudManagementEssentials',
                ), 'Fraud Management webhook Cleanup', true
            );
            delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_ID_OPTION );
            delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_FME_ID_OPTION );
 
            $available_fraud_product = $this->get_available_fraud_management_product( $host, $merchant_id, $api_key, $secret_key, $is_test );
            if ( null === $available_fraud_product ) {
                return;
            }
            delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_SECRET_OPTION );
            delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_KEY_ID_OPTION );

            $signing_key = $this->ensure_webhook_signing_key( $host, $merchant_id, $api_key, $secret_key, VISA_ACCEPTANCE_FRAUD_WEBHOOK_SECRET_OPTION, VISA_ACCEPTANCE_FRAUD_WEBHOOK_KEY_ID_OPTION, 'Fraud Management webhook', $is_test );
            if ( ! $signing_key['success'] ) {
                return;
            }
 
            $webhook_token = get_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_TOKEN_OPTION, VISA_ACCEPTANCE_STRING_EMPTY );
            if ( empty( $webhook_token ) ) {
                $webhook_token = bin2hex( random_bytes( 16 ) );
                update_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_TOKEN_OPTION, $webhook_token );
            }
 
            // Product-specific configuration.
            $is_dm = 'decisionManager' === $available_fraud_product;
            $config = array(
                'product_id'        => $is_dm ? 'decisionManager' : 'fraudManagementEssentials',
                'label'             => $is_dm ? 'DM' : 'FME',
                'name'              => $is_dm ? 'Visa Acceptance Decision Manager' : 'Visa Acceptance FME',
                'webhook_id_option' => $is_dm ? VISA_ACCEPTANCE_FRAUD_WEBHOOK_ID_OPTION : VISA_ACCEPTANCE_FRAUD_WEBHOOK_FME_ID_OPTION,
                'event_types'       => $is_dm
                    ? array( 'risk.profile.decision.reject', 'risk.casemanagement.decision.accept', 'risk.casemanagement.decision.reject' )
                    : array( 'risk.profile.decision.review', 'risk.profile.decision.reject', 'risk.casemanagement.decision.accept', 'risk.casemanagement.decision.reject' ),
            );
 
            $body = array(
                'name'              => $config['name'],
                'description'       => $config['name'] . ' fraud notifications',
                'organizationId'    => $merchant_id,
                'products'          => array( array( 'productId' => $config['product_id'], 'eventTypes' => $config['event_types'] ) ),
                'webhookUrl'        => add_query_arg( 'token', $webhook_token, Visa_Acceptance_Webhook_Handler::get_webhook_url() ),
                'healthCheckUrl'    => add_query_arg( 'token', $webhook_token, Visa_Acceptance_Webhook_Handler::get_webhook_url() ),
                'notificationScope' => 'SELF',
                'securityPolicy'    => array( 'securityType' => 'key' ),
                'retryPolicy'       => array( 'algorithm' => 'ARITHMETIC', 'firstRetry' => VISA_ACCEPTANCE_VAL_FIVE, 'interval' => VISA_ACCEPTANCE_VAL_FIVE, 'numberOfRetries' => VISA_ACCEPTANCE_VAL_THREE, 'deactivateFlag' => 'false', 'repeatSequenceCount' => VISA_ACCEPTANCE_VAL_ZERO ),
            );
 
            $success = $this->create_fraud_webhook_subscription( $host, $body, $merchant_id, $api_key, $secret_key, $is_test, $config['webhook_id_option'], $config['label'] );
 
            if ( $success ) {
				$this->add_logs_data( array( 'Fraud Management webhook ' . $config['label'] . ' subscribed successfully.' ), false, 'Fraud Management webhook Subscribe ' . $config['label'] );
            } else {
                delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_TOKEN_OPTION );
                delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_SECRET_OPTION );
                delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_KEY_ID_OPTION );
            }
 
        } catch ( \Throwable $e ) {
            $this->add_logs_data( array( 'Error subscribing Fraud Management webhook: ' . get_class( $e ) . ' - ' . $e->getMessage() ), false, 'Fraud Management webhook Subscribe', true );
        }
    }

    /**
     * Checks which fraud management product is enabled for the merchant (DM or FME).
	 * 
     * @param string $host        API host.
     * @param string $merchant_id Merchant ID.
     * @param string $api_key     API key ID.
     * @param string $secret_key  API shared secret.
     * @param bool   $is_test     Whether we are in test mode.
     * @return string|null 'decisionManager' or 'fraudManagementEssentials' if enabled, null if neither.
     */
    private function get_available_fraud_management_product( $host, $merchant_id, $api_key, $secret_key, $is_test ) {
        try {
            $api_client   = $this->get_webhook_api_client( $is_test, $merchant_id, $api_key, $secret_key );
            // SDK hardcodes 'application/hal+json' for this endpoint, which the API rejects with 406; override to match the other webhook endpoints.
            $api_client->setAcceptHeader( 'application/json;charset=utf-8' );
            $products_api = new CreateNewWebhooksApi( $api_client );
            [ $products ] = $products_api->findProductsToSubscribe( $merchant_id );
 
            $product_ids = array();
            foreach ( (array) $products as $product ) {
                $product_ids[] = $product->getProductId();
            }
 
            // Extract productId values and check for DM or FME.
            return in_array( 'decisionManager', $product_ids, true ) ? 'decisionManager'
                : ( in_array( 'fraudManagementEssentials', $product_ids, true ) ? 'fraudManagementEssentials' : null );
        } catch ( WebhookApiException $e ) {
            $this->add_logs_data( array( 'Fraud Management webhook: Products API failed - ' . $this->get_error_message_from_exception( $e ) ), false, 'Fraud Management webhook Subscribe', true );
            return null;
        }
    }

	/**
     * Creates a single webhook subscription via CyberSource SDK and stores the resulting ID.
     *
     * @param string $host             API host (without scheme).
     * @param array  $body             Subscription request body.
     * @param string $merchant_id      Merchant ID.
     * @param string $api_key          API key ID.
     * @param string $secret_key       Base64-encoded shared secret.
     * @param bool   $is_test          Whether running in test mode.
     * @param string $webhook_id_option WP option key to store the returned webhookId.
     * @param string $label            Short label for log messages ('DM' or 'FME').
     * @return bool True on success, false on failure.
     */
    private function create_fraud_webhook_subscription( $host, array $body, $merchant_id, $api_key, $secret_key, $is_test, $webhook_id_option, $label ) {
        try {
            $api_client   = $this->get_webhook_api_client( $is_test, $merchant_id, $api_key, $secret_key );
            $webhooks_api = new CreateNewWebhooksApi( $api_client );
            [ $result ]   = $webhooks_api->notificationSubscriptionsV2WebhooksPost( wp_json_encode( $body ) );
        } catch ( WebhookApiException $e ) {
            $this->add_logs_data( array( 'Fraud Management webhook ' . $label . ' failed: ' . $this->get_error_message_from_exception( $e ) ), false, 'Fraud Management webhook Subscribe ' . $label, true );
            return false;
        }

        // Log only essential info, not full response body.
        $webhook_id = $result->getWebhookId();
        $status     = $result->getStatus();
        $this->add_logs_data( wp_json_encode( array( 'webhookId' => $webhook_id ?? 'none', 'status' => $status ?? 'unknown' ) ), false, 'Fraud Management webhook Subscribe ' . $label );

        if ( ! $webhook_id ) {
            $this->add_logs_data( array( 'Fraud Management webhook ' . $label . ' failed: no webhookId in response.' ), false, 'Fraud Management webhook Subscribe ' . $label, true );
            return false;
        }

        update_option( $webhook_id_option, sanitize_text_field( $webhook_id ) );
        if ( 'ACTIVE' !== ( $status ?? VISA_ACCEPTANCE_STRING_EMPTY ) ) {
            $this->activate_fraud_webhook( $webhook_id, $host, $merchant_id, $api_key, $secret_key, $is_test, $label );
        }
        // Invalidate webhooks cache so the table shows fresh data.
        delete_transient( 'vas_webhooks_list_' . $merchant_id );

        $this->add_logs_data( array( 'Fraud Management webhook ' . $label . ' subscribed. ID: ' . $webhook_id . ' Status: ' . ( $status ?? 'unknown' ) ), false, 'Fraud Management webhook Subscribe ' . $label );
        return true;
    }

	/**
     * Activates a previously created webhook subscription via CyberSource API.
     *
     * @param string $webhook_id  Webhook ID to activate.
     * @param string $host        API host.
     * @param string $merchant_id Merchant ID.
     * @param string $api_key     API key ID.
     * @param string $secret_key  Base64-encoded shared secret.
     * @param bool   $is_test     Whether running in test mode.
     * @param string $label       Short label for log messages ('DM' or 'FME').
     * @return void
     */
    private function activate_fraud_webhook( $webhook_id, $host, $merchant_id, $api_key, $secret_key, $is_test, $label ) {
        try {
            $api_client   = $this->get_webhook_api_client( $is_test, $merchant_id, $api_key, $secret_key );
            $webhooks_api = new ManageWebhooksApi( $api_client );
            $webhooks_api->notificationSubscriptionsV2WebhooksWebhookIdStatusPut( $webhook_id, wp_json_encode( array( 'status' => 'ACTIVE' ) ) );
            $this->add_logs_data( array( 'Fraud Management webhook ' . $label . ' activated.' ), false, 'Fraud Management webhook Subscribe ' . $label );
        } catch ( WebhookApiException $e ) {
            $this->add_logs_data( array( 'Fraud Management webhook ' . $label . ' activation failed: ' . $this->get_error_message_from_exception( $e ) ), false, 'Fraud Management webhook Subscribe ' . $label, true );
        } catch ( \Throwable $e ) {
            $this->add_logs_data( array( 'Fraud Management webhook ' . $label . ' activation exception: ' . get_class( $e ) . ' - ' . $e->getMessage() ), false, 'Fraud Management webhook Subscribe ' . $label, true );
        }
    }

	/**
	 * Deletes any orphaned webhook subscriptions that match the given product IDs.
     *
     * @param string   $host        API host.
     * @param string   $merchant_id Merchant ID.
     * @param string   $api_key     API key ID.
     * @param string   $secret_key  API shared secret.
     * @param bool     $is_test     Whether we are in test mode.
     * @param string[] $product_ids productId values to match (e.g. ['unifiedCheckout']).
     * @param string   $log_context Log context label.
     * @param bool     $list_v2     Whether to use the v2 list endpoint (for UC) or v1 (for APM).
     * @return void
     */
    private function force_delete_orphan_webhooks_by_product_ids( $host, $merchant_id, $api_key, $secret_key, $is_test, array $product_ids, $log_context, $list_v2 = false ) {
        try {
            $api_client   = $this->get_webhook_api_client( $is_test, $merchant_id, $api_key, $secret_key );
            $webhooks_api = new ManageWebhooksApi( $api_client );
            // Only the v2 "list by organization" endpoint has an SDK equivalent; the v1 (APM) listing is unused since APM is currently disabled.
            [ $subscriptions ] = $webhooks_api->getWebhookSubscriptionsByOrg( $merchant_id );
        } catch ( WebhookApiException $e ) {
            $this->add_logs_data( array( $log_context . ': could not list existing subscriptions - ' . $this->get_error_message_from_exception( $e ) ), false, $log_context, true );
            return;
        }
 
        foreach ( (array) $subscriptions as $sub ) {
            $existing_id = $sub->getWebhookId();
            $products    = $sub->getProducts();
            $existing_product = ( is_array( $products ) && isset( $products[ VISA_ACCEPTANCE_VAL_ZERO ] ) ) ? $products[ VISA_ACCEPTANCE_VAL_ZERO ]->getProductId() : null;

            if ( $existing_id && in_array( $existing_product, $product_ids, true ) ) {
                try {
                    $webhooks_api->deleteWebhookSubscription( $existing_id );
                } catch ( WebhookApiException $e ) {
                    $this->add_logs_data( array( $log_context . ': delete subscription ID: ' . $existing_id . ' failed - ' . $this->get_error_message_from_exception( $e ) ), false, $log_context, true );
                }
            }
        }
    }

	/**
     * Generates a new symmetric HMAC signing key for webhook validation via CyberSource KMS API.
     *
     * @param string $host        API host.
     * @param string $merchant_id Merchant ID.
     * @param string $api_key     API key ID.
     * @param string $secret_key  API shared secret.
     * @param bool   $is_test     Whether we are in test mode.
     * @return array { success: bool, key: string, key_id: string, error: string }
     */
    private function generate_webhook_signing_key( $host, $merchant_id, $api_key, $secret_key, $is_test = false ) {
        $body = array(
            'clientRequestAction' => 'CREATE',
            'keyInformation'      => array(
                'provider'       => 'nrtd',
                'tenant'         => $merchant_id,
                'keyType'        => 'sharedSecret',
                'organizationId' => $merchant_id,
            ),
        );

        try {
            $api_client   = $this->get_webhook_api_client( $is_test, $merchant_id, $api_key, $secret_key );
            $webhooks_api = new CreateNewWebhooksApi( $api_client );
            [ $result, $http_code ] = $webhooks_api->saveSymEgressKey( null, null, null, wp_json_encode( $body ) );
        } catch ( WebhookApiException $e ) {
            return array( 'success' => false, 'error' => $this->get_error_message_from_exception( $e ) );
        }

        $key_information = $result->getKeyInformation();
        $key             = $key_information ? $key_information->getKey() : null;
        $key_id          = $key_information ? $key_information->getKeyId() : null;

        // Log only status, not sensitive key data.
        $this->add_logs_data( wp_json_encode( array( 'code' => $http_code, 'status' => $result->getStatus() ?? 'UNKNOWN' ) ), true, 'Webhook Key Generation' );

        if ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $http_code && $key && $key_id ) {
            return array( 'success' => true, 'key' => $key, 'key_id' => $key_id );
        }

        return array( 'success' => false, 'error' => 'Unknown error (HTTP ' . $http_code . ')' );
    }

    /**
     * Synchronizes webhook signature key and key ID across all webhook option stores.
     *
     * @param string $key Raw base64-encoded symmetric key returned by /kms/egress/v2/keys-sym.
     * @param string $key_id Key ID returned alongside the key.
     * @return void
     */
    private function sync_all_webhook_sig_key_options( $key, $key_id ) {
        $clean_key    = sanitize_text_field( $key );
        $clean_key_id = sanitize_text_field( $key_id );

        // Fraud / DM / FME webhook.
        update_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_SECRET_OPTION, $clean_key );
        update_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_KEY_ID_OPTION, $clean_key_id );

        // Unified Checkout webhook.
        update_option( VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_OPTION, $clean_key );
        update_option( VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_ID_OPTION, $clean_key_id );

        /*Alternative Payment Methods webhook.
        update_option( VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_OPTION, $clean_key );
        update_option( VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_ID_OPTION, $clean_key_id );
        */
    }

    /**
	 * Generates a fresh HMAC signing key and syncs it to all webhook options.
	 * 
	 * @return void
	 */
	private function generate_and_sync_fresh_signing_key() {
		try {
			// Clear all existing signing keys first.
			delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_SECRET_OPTION );
			delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_KEY_ID_OPTION );
			delete_option( VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_OPTION );
			delete_option( VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_ID_OPTION );
			delete_option( VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_OPTION );
			delete_option( VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_ID_OPTION );
			[ 'is_test' => $is_test, 'host' => $host, 'merchant_id' => $merchant_id, 'api_key' => $api_key, 'secret_key' => $secret_key ] = $this->get_webhook_api_credentials();
			
			$signing_key_result = $this->generate_webhook_signing_key( $host, $merchant_id, $api_key, $secret_key, $is_test );
			if ( ! $signing_key_result['success'] ) {
				$this->add_logs_data( array( 'Fresh signing key generation failed - ' . $signing_key_result['error'] ), false, 'Webhook Subscribe', true );
				return;
			}
			
			$this->sync_all_webhook_sig_key_options( $signing_key_result['key'], $signing_key_result['key_id'] );
		} catch ( \Throwable $e ) {
			$this->add_logs_data( array( 'Exception generating fresh signing key: ' . get_class( $e ) . ' - ' . $e->getMessage() ), false, 'Webhook Subscribe', true );
		}
	}

	/**
     * Unsubscribes from any existing Fraud Management webhook subscriptions (DM and FME) and clears stored IDs and credentials.
     *
     * @return void
     */
    private function unsubscribe_fraud_webhook() {
        $dm_webhook_id  = get_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_ID_OPTION, VISA_ACCEPTANCE_STRING_EMPTY );
        $fme_webhook_id = get_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_FME_ID_OPTION, VISA_ACCEPTANCE_STRING_EMPTY );
 
        if ( empty( $dm_webhook_id ) && empty( $fme_webhook_id ) ) {
            return;
        }
        try {
            [ 'is_test' => $is_test, 'host' => $host, 'merchant_id' => $merchant_id, 'api_key' => $api_key, 'secret_key' => $secret_key ] = $this->get_webhook_api_credentials();
 
            // Delete Decision Manager and FME subscriptions using shared helper.
            if ( ! empty( $dm_webhook_id ) ) {
                $this->delete_single_webhook( $dm_webhook_id, $host, $merchant_id, $api_key, $secret_key, $is_test, VISA_ACCEPTANCE_NOTIFICATION_SUBSCRIPTIONS_PATH, VISA_ACCEPTANCE_FRAUD_WEBHOOK_ID_OPTION, 'Fraud Management webhook DM' );
            }
            if ( ! empty( $fme_webhook_id ) ) {
                $this->delete_single_webhook( $fme_webhook_id, $host, $merchant_id, $api_key, $secret_key, $is_test, VISA_ACCEPTANCE_NOTIFICATION_SUBSCRIPTIONS_PATH, VISA_ACCEPTANCE_FRAUD_WEBHOOK_FME_ID_OPTION, 'Fraud Management webhook FME' );
            }
 
            // Clear shared credentials once both are removed.
            delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_SECRET_OPTION );
            delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_KEY_ID_OPTION );
            delete_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_TOKEN_OPTION );
			// Invalidate webhooks cache so the table shows fresh data.
            delete_transient( 'vas_webhooks_list_' . $merchant_id );
 
        } catch ( \Throwable $e ) {
            $this->add_logs_data( array( 'Error unsubscribing Fraud Management webhook: ' . get_class( $e ) . ' - ' . $e->getMessage() ), false, 'Fraud Management webhook Unsubscribe', true );
        }
    }
 
    /**
     * Subscribes to the CyberSource UC webhook (uc.orders.transactionresults).
     *
     * @return void
     */
    private function subscribe_uc_webhook() {
        $this->subscribe_encrypted_webhook(
            'unifiedCheckout',
            array( 'uc.orders.transactionresults' ),
            'get_uc_webhook_url',
            VISA_ACCEPTANCE_UC_WEBHOOK_ID_OPTION,
            VISA_ACCEPTANCE_UC_WEBHOOK_PRIVATE_KEY_OPTION,
            VISA_ACCEPTANCE_UC_WEBHOOK_MLE_KEY_ID_OPTION,
            'UC Webhook'
        );
    }

    /**
     * Subscribes to the CyberSource APM webhook (apm.orders.transactionresults).
     *
     * @param string $product_id         Product ID ('unifiedCheckout' or 'alternativePaymentMethods').
     * @param array  $event_types        Array of event type strings.
     * @param string $webhook_url_method Method name on Visa_Acceptance_Webhook_Handler to get webhook URL.
     * @param string $webhook_id_option  WP option constant for storing webhook ID.
     * @param string $private_key_option WP option constant for storing private key path.
     * @param string $mle_key_id_option  WP option constant for storing MLE key ID.
     * @param string $log_label          Label for log messages (e.g., 'UC Webhook', 'APM Webhook').
     * @return void
     */
    private function subscribe_encrypted_webhook( $product_id, $event_types, $webhook_url_method, $webhook_id_option, $private_key_option, $mle_key_id_option, $log_label ) {
        // Determine signing key options based on product type.
        $sig_key_option = 'unifiedCheckout' === $product_id ? VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_OPTION : VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_OPTION;
        $key_id_option  = 'unifiedCheckout' === $product_id ? VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_ID_OPTION : VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_ID_OPTION;
        try {
            [ 'is_test' => $is_test, 'host' => $host, 'merchant_id' => $merchant_id, 'api_key' => $api_key, 'secret_key' => $secret_key ] = $this->get_webhook_api_credentials();
            $api_client = $this->get_webhook_api_client( $is_test, $merchant_id, $api_key, $secret_key );
            $manage_api = new ManageWebhooksApi( $api_client );
            $create_api = new CreateNewWebhooksApi( $api_client );

            // Clean up any previous subscription first (delete old webhook ID).
            $old_webhook_id = get_option( $webhook_id_option, VISA_ACCEPTANCE_STRING_EMPTY );
            if ( ! empty( $old_webhook_id ) ) {
                try {
                    $manage_api->deleteWebhookSubscription( $old_webhook_id );
                } catch ( WebhookApiException $e ) {
                    $this->add_logs_data( array( $log_label . ': failed to delete previous subscription - ' . $this->get_error_message_from_exception( $e ) ), false, $log_label . ' Subscribe', true );
                }
            }
            delete_option( $webhook_id_option );
            delete_option( $private_key_option );
            delete_option( $mle_key_id_option );
			// Delete any orphaned webhook subscriptions that aren't tracked in WordPress options.
            $use_list_v2 = ( 'unifiedCheckout' === $product_id );
            $this->force_delete_orphan_webhooks_by_product_ids(
                $host,
                $merchant_id,
                $api_key,
                $secret_key,
                $is_test,
                array( $product_id ),
                $log_label . ' Cleanup',
                $use_list_v2
            );

            // Always clear stale signing key options before subscription so ensure_webhook_signing_key() generates a fresh KMS key.
            delete_option( $sig_key_option );
            delete_option( $key_id_option );

            // Read and validate certificate files.
            $cert_result = $this->read_webhook_certificates( $log_label );
            if ( ! $cert_result['success'] ) {
                return;
            }
            [ 'private_key' => $private_key_pem, 'public_cert_b64' => $pub_b64, 'private_key_path' => $private_key_path ] = $cert_result;

            $signing_key = $this->ensure_webhook_signing_key( $host, $merchant_id, $api_key, $secret_key, $sig_key_option, $key_id_option, $log_label, $is_test );
            if ( ! $signing_key['success'] ) {
                return;
            }
 
            // Register public key via /kms/egress/v2/keys-asym (SDK response model lacks keyInformation, so the raw SDK-decoded body is read directly).
            $asym_body = array(
                'clientRequestAction' => 'STORE',
                'keyInformation'      => array(
                    'provider'       => 'nrtd',
                    'tenant'         => $merchant_id,
                    'keyType'        => 'publickey',
                    'organizationId' => $merchant_id,
                    'pub'            => $pub_b64,
                    'expiryDuration' => '365',
                ),
            );

            try {
                $asym_decoded = $this->call_kms_asym_egress_key( $api_client, $asym_body );
            } catch ( WebhookApiException $e ) {
                $this->add_logs_data( array( $log_label . ': key registration failed - ' . $this->get_error_message_from_exception( $e ) ), false, $log_label . ' Subscribe', true );
                return;
            }

            $key_status = $asym_decoded->status ?? 'UNKNOWN';
            $this->add_logs_data( array( 'status' => $key_status ), false, $log_label . ' Key Register' );

            $mle_key_id = $asym_decoded->keyInformation->keyId ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property comes from the CyberSource API response object.
            if ( ! $mle_key_id ) {
                $this->add_logs_data( array( $log_label . ': no keyId in key registration response.' ), false, $log_label . ' Subscribe', true );
                return;
            }
 
            // Store the private key PEM content.
            update_option( $private_key_option, $private_key_pem );
            update_option( $mle_key_id_option, sanitize_text_field( $mle_key_id ) );
 
            $webhook_url = call_user_func( array( 'Visa_Acceptance_Webhook_Handler', $webhook_url_method ) );
            $sub_body    = array(
                'name'              => 'Visa Acceptance ' . $log_label,
                'description'       => $log_label . ' notifications',
                'organizationId'    => $merchant_id,
                'products'          => array(
                    array(
                        'productId'  => $product_id,
                        'eventTypes' => $event_types,
                    ),
                ),
                'webhookUrl'        => $webhook_url,
                'healthCheckUrl'    => $webhook_url,
                'notificationScope' => 'SELF',
                'retryPolicy'       => array(
                    'algorithm'           => 'ARITHMETIC',
                    'firstRetry'          => VISA_ACCEPTANCE_VAL_ONE,
                    'interval'            => VISA_ACCEPTANCE_VAL_ONE,
                    'numberOfRetries'     => VISA_ACCEPTANCE_VAL_THREE,
                    'deactivateFlag'      => 'false',
                    'repeatSequenceCount' => VISA_ACCEPTANCE_VAL_ZERO,
                ),
                'securityPolicy'    => array(
                    'securityType' => 'key',
                ),
                'additionalAttributes' => array(
                    array(
                        'name'  => 'encryptionKeyId',
                        'value' => $mle_key_id,
                    ),
                    array(
                        'name'  => 'encryptionAlgorithm',
                        'value' => 'RSA_OAEP_256',
                    ),
                ),
            );

            try {
                [ $sub_result ] = $create_api->notificationSubscriptionsV2WebhooksPost( wp_json_encode( $sub_body ) );
                $this->add_logs_data( array( 'webhookId' => $sub_result->getWebhookId() ?? 'none', 'status' => $sub_result->getStatus() ?? 'unknown' ), false, $log_label . ' Subscribe' );
            } catch ( WebhookApiException $e ) {
                $error = $this->get_error_message_from_exception( $e );

                if ( VISA_ACCEPTANCE_FOUR_ZERO_ZERO === (int) $e->getCode() && str_contains( $error, 'already exist' ) ) {
                    $retry_result = $this->retry_webhook_if_exists( $create_api, $sub_body, $host, $merchant_id, $api_key, $secret_key, $is_test, $product_id, $log_label );
                    if ( ! $retry_result['success'] ) {
                        $this->add_logs_data( array( $log_label . ': subscription creation failed after retry - ' . $retry_result['error'] ), false, $log_label . ' Subscribe', true );
                        return;
                    }
                    $sub_result = $retry_result['result'];
                } else {
                    $this->add_logs_data( array( $log_label . ': subscription creation failed - ' . $error ), false, $log_label . ' Subscribe', true );
                    return;
                }
            }
 
            $webhook_id = $sub_result->getWebhookId();
            if ( ! $webhook_id ) {
                $this->add_logs_data( array( $log_label . ': no webhookId in subscription response.' ), false, $log_label . ' Subscribe', true );
                return;
            }
            update_option( $webhook_id_option, sanitize_text_field( $webhook_id ) );
			// Invalidate webhooks cache so the table shows fresh data.
            delete_transient( 'vas_webhooks_list_' . $merchant_id );
 
            // Step 5 - Activate the subscription.
            $this->activate_uc_webhook_subscription( $webhook_id, $host, $merchant_id, $api_key, $secret_key, $is_test, $log_label );
 
        } catch ( \Throwable $e ) {
            $this->add_logs_data( array( $log_label . ' subscribe error: ' . get_class( $e ) . ' - ' . $e->getMessage() ), false, $log_label . ' Subscribe', true );
        }
    }

	/**
     * Reads and validates webhook certificate files with security checks.
     *
     * @param string $log_label Label for log messages.
     * @return array { success: bool, private_key: string, public_cert_b64: string, private_key_path: string }
     */
    private function read_webhook_certificates( $log_label ) {
        // Use .p12 key file from MLE settings.
        $p12_path     = $this->get_option( 'mle_certificate_path', VISA_ACCEPTANCE_STRING_EMPTY );
        $p12_password = $this->get_option( 'mle_key_password', VISA_ACCEPTANCE_STRING_EMPTY );
 
        if ( empty( $p12_path ) ) {
            $this->add_logs_data( array( $log_label . ': MLE .p12 key file path not configured in settings.' ), false, $log_label . ' Subscribe', true );
            return array( 'success' => false );
        }
        if ( ! file_exists( $p12_path ) || ! is_readable( $p12_path ) ) {
            $this->add_logs_data( array( $log_label . ': .p12 key file not found or not readable. Path: ' . $p12_path ), false, $log_label . ' Subscribe', true );
            return array( 'success' => false );
        }
 
        $file_size = filesize( $p12_path );
        if ( false === $file_size || $file_size > 5242880 ) {
            $this->add_logs_data( array( $log_label . ': .p12 key file too large or unreadable.' ), false, $log_label . ' Subscribe', true );
            return array( 'success' => false );
        }
 
        // Read and parse the .p12 file.
        $p12_content = file_get_contents( $p12_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file, not remote URL.
        if ( false === $p12_content || empty( $p12_content ) ) {
            $this->add_logs_data( array( $log_label . ': failed to read .p12 key file.' ), false, $log_label . ' Subscribe', true );
            return array( 'success' => false );
        }
 
        $certs = array();
        if ( ! openssl_pkcs12_read( $p12_content, $certs, (string) $p12_password ) ) {
            $ssl_error = openssl_error_string();
            $this->add_logs_data( array( $log_label . ': failed to parse .p12 key file. OpenSSL: ' . $ssl_error ), false, $log_label . ' Subscribe', true );
            return array( 'success' => false );
        }
 
        // Extract private key.
        if ( empty( $certs['pkey'] ) ) {
            $this->add_logs_data( array( $log_label . ': no private key found in .p12 file.' ), false, $log_label . ' Subscribe', true );
            return array( 'success' => false );
        }
        $private_key_pem = $certs['pkey'];
 
        // Extract public certificate.
        if ( empty( $certs['cert'] ) ) {
            $this->add_logs_data( array( $log_label . ': no certificate found in .p12 file.' ), false, $log_label . ' Subscribe', true );
            return array( 'success' => false );
        }
 
        // Extract base64-encoded certificate data (without PEM headers).
        if ( ! preg_match( '/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $certs['cert'], $matches ) ) {
            $this->add_logs_data( array( $log_label . ': invalid certificate format in .p12 file.' ), false, $log_label . ' Subscribe', true );
            return array( 'success' => false );
        }
 
        $pub_b64 = trim( preg_replace( '/\s+/', VISA_ACCEPTANCE_STRING_EMPTY, $matches[VISA_ACCEPTANCE_VAL_ONE] ) );
        if ( empty( $pub_b64 ) || strlen( $pub_b64 ) < 100 ) {
            $this->add_logs_data( array( $log_label . ': extracted certificate data from .p12 invalid or too short.' ), false, $log_label . ' Subscribe', true );
            return array( 'success' => false );
        }
 
        return array(
            'success'          => true,
            'private_key'      => $private_key_pem,
            'public_cert_b64'  => $pub_b64,
            'private_key_path' => $p12_path,
        );
    }

	/**
     * Deletes the UC webhook subscription and clears all stored options.
     *
     * @return void
     */
    public function unsubscribe_uc_webhook() {
        $this->unsubscribe_encrypted_webhook(
            VISA_ACCEPTANCE_UC_WEBHOOK_ID_OPTION,
            VISA_ACCEPTANCE_NOTIFICATION_SUBSCRIPTIONS_PATH,
            array(
                VISA_ACCEPTANCE_UC_WEBHOOK_PRIVATE_KEY_OPTION,
                VISA_ACCEPTANCE_UC_WEBHOOK_MLE_KEY_ID_OPTION,
                VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_OPTION,
                VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_ID_OPTION,
            ),
            'UC Webhook'
        );
    }
 
    /**
     * Subscribes to the CyberSource APM webhook (apm.payments.updated).
     *
     * @return void
     */
    /*public function subscribe_apm_webhook() {
        $this->subscribe_encrypted_webhook(
            'alternativePaymentMethods',
            array( VISA_ACCEPTANCE_APM_WEBHOOK_EVENT ),
            'get_apm_webhook_url',
            VISA_ACCEPTANCE_APM_WEBHOOK_ID_OPTION,
            VISA_ACCEPTANCE_APM_WEBHOOK_PRIVATE_KEY_OPTION,
            VISA_ACCEPTANCE_APM_WEBHOOK_MLE_KEY_ID_OPTION,
            'APM Webhook'
        );
    }*/

	/**
     * Deletes the APM webhook subscription and clears all stored options.
     *
     * @return void
     */
    /*public function unsubscribe_apm_webhook() {
        $this->unsubscribe_encrypted_webhook(
            VISA_ACCEPTANCE_APM_WEBHOOK_ID_OPTION,
            VISA_ACCEPTANCE_NOTIFICATION_SUBSCRIPTIONS_PATH,
            array(
                VISA_ACCEPTANCE_APM_WEBHOOK_PRIVATE_KEY_OPTION,
                VISA_ACCEPTANCE_APM_WEBHOOK_MLE_KEY_ID_OPTION,
                VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_OPTION,
                VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_ID_OPTION,
            ),
            'APM Webhook'
        );
    }*/

	/**
     * Unsubscribes from an encrypted webhook and clears stored options.
     *
     * @param string $webhook_id_option WP option constant for webhook ID.
     * @param string $api_path          API path v2.
     * @param array  $options_to_delete Array of WP option names to delete.
     * @param string $log_label         Label for log messages.
     * @return void
     */
    private function unsubscribe_encrypted_webhook( $webhook_id_option, $api_path, array $options_to_delete, $log_label ) {
        $webhook_id = get_option( $webhook_id_option, VISA_ACCEPTANCE_STRING_EMPTY );
       
        // Clean up stored options.
        foreach ( $options_to_delete as $option ) {
            delete_option( $option );
        }
       
        if ( empty( $webhook_id ) ) {
            return;
        }
 
        try {
            [ 'is_test' => $is_test, 'host' => $host, 'merchant_id' => $merchant_id, 'api_key' => $api_key, 'secret_key' => $secret_key ] = $this->get_webhook_api_credentials();
            $this->delete_single_webhook( $webhook_id, $host, $merchant_id, $api_key, $secret_key, $is_test, $api_path, $webhook_id_option, $log_label );
        } catch ( \Throwable $e ) {
            $this->add_logs_data( array( $log_label . ' unsubscribe error: ' . get_class( $e ) . ' - ' . $e->getMessage() ), false, $log_label . ' Unsubscribe', true );
        }
    }

 
    /**
     * Builds the HTTP Signature auth headers for direct webhook API calls.
     *
     * @param string $host          API host (without scheme).
     * @param string $resource_path Resource path e.g. /notification-subscriptions/v2/webhooks.
     * @param string $method        HTTP method: POST, DELETE, GET.
     * @param string $body          Raw JSON body (empty string for DELETE/GET).
     * @param string $merchant_id   Merchant ID.
     * @param string $api_key       API key ID.
     * @param string $secret_key    Base64-encoded shared secret.
     * @param bool   $force_no_body Force no-body signature even for POST (e.g. trigger endpoint).
     * @return array
     */
    public function build_webhook_http_signature_headers( $host, $resource_path, $method, $body, $merchant_id, $api_key, $secret_key, $force_no_body = false ) {
        $date         = gmdate( 'D, d M Y H:i:s' ) . ' GMT';
        $method_lower = strtolower( $method );
        $has_body     = ! $force_no_body && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true );
 
        if ( $has_body ) {
            $digest           = 'SHA-256=' . base64_encode( hash( 'sha256', $body, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            $signature_string = "host: {$host}\ndate: {$date}\nrequest-target: {$method_lower} {$resource_path}\ndigest: {$digest}\nv-c-merchant-id: {$merchant_id}";
            $header_names     = 'host date request-target digest v-c-merchant-id';
        } else {
            $digest           = null;
            $signature_string = "host: {$host}\ndate: {$date}\nrequest-target: {$method_lower} {$resource_path}\nv-c-merchant-id: {$merchant_id}";
            $header_names     = 'host date request-target v-c-merchant-id';
        }
 
        $decoded_secret = base64_decode( $secret_key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $signature      = base64_encode( hash_hmac( 'sha256', $signature_string, $decoded_secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
 
        $signature_header = 'keyid="' . $api_key . '", algorithm="HmacSHA256", headers="' . $header_names . '", signature="' . $signature . '"';
 
        $headers = array(
            'Host'            => $host,
            'Date'            => $date,
            'v-c-merchant-id' => $merchant_id,
            'Signature'       => $signature_header,
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json',
        );
 
        if ( null !== $digest ) {
            $headers['Digest'] = $digest;
        }
        return $headers;
    }

    /**
	 * Activates a webhook subscription by sending a PUT request to the status endpoint.
	 *
	 * @param string $webhook_id  Webhook ID to activate.
	 * @param string $host        API host.
	 * @param string $merchant_id Merchant ID.
	 * @param string $api_key     API key.
	 * @param string $secret_key  API secret.
	 * @param bool   $is_test     Test mode flag.
	 * @param string $log_label   Label for log messages.
	 * @return void
	 */
	public function activate_uc_webhook_subscription( $webhook_id, $host, $merchant_id, $api_key, $secret_key, $is_test, $log_label ) {
		try {
			$api_client = $this->get_webhook_api_client( $is_test, $merchant_id, $api_key, $secret_key );
			$manage_api = new ManageWebhooksApi( $api_client );
			$manage_api->notificationSubscriptionsV2WebhooksWebhookIdStatusPut( $webhook_id, wp_json_encode( array( 'status' => 'ACTIVE' ) ) );
			$this->add_logs_data( array( $log_label . ' activated.' ), false, $log_label . ' Subscribe' );
		} catch ( WebhookApiException $e ) {
			$this->add_logs_data( array( $log_label . ': activation failed - ' . $this->get_error_message_from_exception( $e ) ), false, $log_label . ' Subscribe', true );
		}
	}

	/**
	 * Retries webhook subscription creation if a subscription already exists, after force-deleting any orphan subscriptions for the given product IDs.
	 *
	 * @param CreateNewWebhooksApi $create_api  SDK API instance used to (re)create the subscription.
	 * @param array        $sub_body    Subscription request body.
	 * @param string       $host        API host.
	 * @param string       $merchant_id Merchant ID.
	 * @param string       $api_key     API key.
	 * @param string       $secret_key  API secret.
	 * @param bool         $is_test     Test mode flag.
	 * @param string|array $product_ids Product ID(s) to delete.
	 * @param string       $log_label   Label for log messages.
	 * @return array { success: bool, result: object|null, error: string|null } Retry response.
	 */
	private function retry_webhook_if_exists( $create_api, array $sub_body, $host, $merchant_id, $api_key, $secret_key, $is_test, $product_ids, $log_label ) {
		$product_ids_array = is_array( $product_ids ) ? $product_ids : array( $product_ids );
		$this->force_delete_orphan_webhooks_by_product_ids( $host, $merchant_id, $api_key, $secret_key, $is_test, $product_ids_array, $log_label . ' Subscribe', true );

		try {
			[ $result ] = $create_api->notificationSubscriptionsV2WebhooksPost( wp_json_encode( $sub_body ) );
			$this->add_logs_data( array( 'webhookId' => $result->getWebhookId() ?? 'none', 'status' => $result->getStatus() ?? 'unknown' ), false, $log_label . ' Subscribe Retry' );
			return array( 'success' => true, 'result' => $result, 'error' => null );
		} catch ( WebhookApiException $e ) {
			return array( 'success' => false, 'result' => null, 'error' => $this->get_error_message_from_exception( $e ) );
		}
	}

	/**
	 * Ensures a webhook signing key exists by reusing existing or generating new.
	 *
	 * @param string $host        API host.
	 * @param string $merchant_id Merchant ID.
	 * @param string $api_key     API key.
	 * @param string $secret_key  API secret.
	 * @param string $sig_key_opt WP option name for signing key.
	 * @param string $key_id_opt  WP option name for key ID.
	 * @param string $log_label   Label for log messages.
	 * @param bool   $is_test     Whether we are in test mode.
	 * @return array { success: bool, key: string, key_id: string } Returns false on failure.
	 */
	private function ensure_webhook_signing_key( $host, $merchant_id, $api_key, $secret_key, $sig_key_opt, $key_id_opt, $log_label, $is_test=false ) {
		$existing_key    = get_option( $sig_key_opt, VISA_ACCEPTANCE_STRING_EMPTY );
		$existing_key_id = get_option( $key_id_opt, VISA_ACCEPTANCE_STRING_EMPTY );

		if ( ! empty( $existing_key ) && ! empty( $existing_key_id ) ) {
			// Log the reused keyId so stale-key issues are visible in debug logs.
			$this->add_logs_data( array( $log_label . ': reusing existing signing key. key_id=' . $existing_key_id ), false, $log_label . ' Subscribe' );
			return array( 'success' => true, 'key' => $existing_key, 'key_id' => $existing_key_id );
		}

		$result = $this->generate_webhook_signing_key( $host, $merchant_id, $api_key, $secret_key, $is_test );

		if ( ! $result['success'] ) {
			$this->add_logs_data( array( $log_label . ': signing key generation failed - ' . $result['error'] ), false, $log_label . ' Subscribe', true );
			return array( 'success' => false );
		}

		// Sync to all webhook option stores so every active subscription reflects the latest org-level key.
		$this->sync_all_webhook_sig_key_options( $result['key'], $result['key_id'] );
		$this->add_logs_data( array( $log_label . ': signing key generated. key_id=' . $result['key_id'] ), false, $log_label . ' Subscribe' );

		return array( 'success' => true, 'key' => $result['key'], 'key_id' => $result['key_id'] );
	}

	/**
	 * Deletes a single webhook subscription by ID.
	 *
	 * @param string $webhook_id  Webhook ID to delete.
	 * @param string $host        API host.
	 * @param string $merchant_id Merchant ID.
	 * @param string $api_key     API key.
	 * @param string $secret_key  API secret.
	 * @param bool   $is_test     Test mode flag.
	 * @param string $api_path    API path (v1 or v2).
	 * @param string $option_name WP option name to delete on success.
	 * @param string $log_label   Label for log messages.
	 * @return void
	 */
	private function delete_single_webhook( $webhook_id, $host, $merchant_id, $api_key, $secret_key, $is_test, $api_path, $option_name, $log_label ) {
        try {
            $api_client = $this->get_webhook_api_client( $is_test, $merchant_id, $api_key, $secret_key );
            $manage_api = new ManageWebhooksApi( $api_client );
            $manage_api->deleteWebhookSubscription( $webhook_id );

            if ( $option_name ) {
                delete_option( $option_name );
            }
            // Invalidate webhooks cache so the table shows fresh data.
            delete_transient( 'vas_webhooks_list_' . $merchant_id );
            $this->add_logs_data( array( $log_label . ' unsubscribed.' ), false, $log_label );
        } catch ( WebhookApiException $e ) {
            // Treat 404 (already deleted) as success.
            if ( VISA_ACCEPTANCE_FOUR_ZERO_FOUR === (int) $e->getCode() ) {
                if ( $option_name ) {
                    delete_option( $option_name );
                }
                delete_transient( 'vas_webhooks_list_' . $merchant_id );
                $this->add_logs_data( array( $log_label . ' unsubscribed. (already deleted)' ), false, $log_label );
                return;
            }
            $this->add_logs_data( array( $log_label . ' unsubscribe failed: ' . $this->get_error_message_from_exception( $e ) ), false, $log_label, true );
        }
    }

	/**
	 * Prevents the order status change without triggering any follow-on transactions.
	 *
	 * @param int    $order_id order id.
	 * @param string $status_from the status to be changed from.
	 * @param string $status_to the status to be changed to.
	 * @param object $that class object.
	 * @throws Exception Exception to prevent order status change.
	 */
	public function prevent_order_status_change( $order_id, $status_from, $status_to, $that ) {
    $order = wc_get_order( $order_id );
		if ( in_array( $order->get_payment_method( VISA_ACCEPTANCE_EDIT ), array( VISA_ACCEPTANCE_UC_ID, VISA_ACCEPTANCE_SV_GATEWAY_ID ), true ) ) {
			if ( ( $status_from !== $status_to ) && is_admin() ) {
				if ( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD === $status_from && ( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING === $status_to || VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED === $status_to ) ) {
					// Skip settlement: already charged via EBC SALE, or a free-trial $0 PREFER_AUTH with nothing to capture.
					$auth_amount   = $this->get_order_meta( $order, VISA_ACCEPTANCE_AUTH_AMOUNT );
					$is_free_trial = VISA_ACCEPTANCE_STRING_EMPTY !== $auth_amount && ( (float) $auth_amount <= (float) VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT );
					if ( $this->is_order_fully_captured( $order ) || $is_free_trial ) {
						return;
					}
					$settings = $this->get_gateway_settings();
					if ( ( VISA_ACCEPTANCE_YES === $settings['enable_paid_capture'] ) && ( $order->get_payment_method( VISA_ACCEPTANCE_EDIT ) === VISA_ACCEPTANCE_UC_ID ) ) {
						$this->init_process_capture( $order_id, VISA_ACCEPTANCE_UC_ID );
					} else {
						$this->init_process_capture( $order_id, VISA_ACCEPTANCE_SV_GATEWAY_ID );
					}
				}
			}
		}
	}

  /**
   * Process the admin options.
   *
   * Validates the title field and prevents saving if it does not meet the criteria.
   *
   * @return bool
   */
  	public function process_admin_options() {
		$post_data = $this->get_post_data();
		$field_prefix = 'woocommerce_' . $this->get_id() . VISA_ACCEPTANCE_UNDERSCORE;
		$environment = isset( $post_data[ $field_prefix . VISA_ACCEPTANCE_ENVIRONMENT ] ) ? sanitize_text_field( $post_data[ $field_prefix . VISA_ACCEPTANCE_ENVIRONMENT ] ) : VISA_ACCEPTANCE_STRING_EMPTY;
		$sanitized_data = array();
		$validation_errors = array();
		$raw_title = isset( $post_data[ $field_prefix . 'title' ] ) ? $post_data[ $field_prefix . 'title' ] : VISA_ACCEPTANCE_STRING_EMPTY;
		$trimmed_title = trim( $raw_title );

		if ( empty( $trimmed_title ) ) {
			$validation_errors[] = __( 'Title is required. Please fill out this field.', 'visa-acceptance-solutions' );
		} elseif ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 \-_.,!@#$%&*()\'"]*$/', $trimmed_title ) ) {
			$validation_errors[] = __( 'The title contains invalid characters. It must start with a letter, number, or space.', 'visa-acceptance-solutions' );
		} else {
			$sanitized_data[ $field_prefix . 'title' ] = sanitize_text_field( $trimmed_title );
		}
		// Validate UC Client Version (optional field, only 1.x allowed).
        $raw_uc_client_version = isset( $post_data[ $field_prefix . 'uc_client_version' ] ) ? $post_data[ $field_prefix . 'uc_client_version' ] : VISA_ACCEPTANCE_STRING_EMPTY;
        $trimmed_uc_client_version = trim( $raw_uc_client_version );
       
        if ( ! empty( $trimmed_uc_client_version ) ) {
            // Validate format: Must be MAJOR.MINOR format.
            if ( ! preg_match( '/^\d+\.\d+$/', $trimmed_uc_client_version ) ) {
                $validation_errors[] = __( 'Unified Checkout Client Version is invalid. Please enter correct version e.g. 1.x.', 'visa-acceptance-solutions' );
            }
            // Only allow 1.x versions.
            elseif ( ! preg_match( '/^1\.\d+$/', $trimmed_uc_client_version ) ) {
                $validation_errors[] = __( 'Unified Checkout Client Version is invalid. Please enter correct version e.g. 1.x.', 'visa-acceptance-solutions' );
            } else {
                $sanitized_data[ $field_prefix . 'uc_client_version' ] = sanitize_text_field( $trimmed_uc_client_version );
            }
        } else {
            // Empty value is allowed (API will use latest version).
            $sanitized_data[ $field_prefix . 'uc_client_version' ] = VISA_ACCEPTANCE_STRING_EMPTY;
        }
 
		// Validate Key File Path (mle_certificate_path).
		$raw_mle_certificate_path = isset( $post_data[ $field_prefix . 'mle_certificate_path' ] ) ? $post_data[ $field_prefix . 'mle_certificate_path' ] : VISA_ACCEPTANCE_STRING_EMPTY;
		$trimmed_mle_certificate_path = trim( $raw_mle_certificate_path );

		if ( empty( $trimmed_mle_certificate_path ) ) {
			$validation_errors[] = __( 'Key File Path is required. Please fill out this field.', 'visa-acceptance-solutions' );
		} elseif ( ! preg_match( '/\.p12$/i', $trimmed_mle_certificate_path ) ) {
			$validation_errors[] = __( 'Invalid Key File Path. Only .p12 files are allowed.', 'visa-acceptance-solutions' );
		} else {
			$sanitized_data[ $field_prefix . 'mle_certificate_path' ] = sanitize_text_field( $trimmed_mle_certificate_path );
		}

		// Validate Key Password (mle_key_password).
		$raw_mle_key_password = isset( $post_data[ $field_prefix . 'mle_key_password' ] ) ? $post_data[ $field_prefix . 'mle_key_password' ] : VISA_ACCEPTANCE_STRING_EMPTY;
		$trimmed_mle_key_password = trim( $raw_mle_key_password );

		if ( empty( $trimmed_mle_key_password ) ) {
			$validation_errors[] = __( 'Key Password is required. Please fill out this field.', 'visa-acceptance-solutions' );
		} else {
			$sanitized_data[ $field_prefix . 'mle_key_password' ] = sanitize_text_field( $trimmed_mle_key_password );
		}

		if ( VISA_ACCEPTANCE_ENVIRONMENT_TEST === $environment ) {
			$raw_test_merchant_id = isset( $post_data[ $field_prefix . 'test_merchant_id' ] ) ? $post_data[ $field_prefix . 'test_merchant_id' ] : VISA_ACCEPTANCE_STRING_EMPTY;
			$trimmed_test_merchant_id = trim( $raw_test_merchant_id );

			if ( empty( $trimmed_test_merchant_id ) ) {
				$validation_errors[] = __( 'Test Merchant ID is required. Please fill out this field.', 'visa-acceptance-solutions' );
			} elseif ( preg_match( '/[^[:alnum:]\-_]/', $trimmed_test_merchant_id ) ) {
				$validation_errors[] = __( 'Test Merchant ID contains invalid characters. Only alphanumeric characters, hyphens, and underscores are allowed.', 'visa-acceptance-solutions' );
			} else {
				$sanitized_data[ $field_prefix . 'test_merchant_id' ] = sanitize_text_field( $trimmed_test_merchant_id );
			}
			$raw_test_api_key = isset( $post_data[ $field_prefix . 'test_api_key' ] ) ? $post_data[ $field_prefix . 'test_api_key' ] : VISA_ACCEPTANCE_STRING_EMPTY;

			if ( empty( $raw_test_api_key ) ) {
				$validation_errors[] = __( 'Test API Key is required. Please fill out this field.', 'visa-acceptance-solutions' );
			} else {
				$sanitized_data[ $field_prefix . 'test_api_key' ] = sanitize_text_field( $raw_test_api_key );
			}
			$raw_test_api_shared_secret = isset( $post_data[ $field_prefix . 'test_api_shared_secret' ] ) ? $post_data[ $field_prefix . 'test_api_shared_secret' ] : VISA_ACCEPTANCE_STRING_EMPTY;

			if ( empty( $raw_test_api_shared_secret ) ) {
				$validation_errors[] = __( 'Test API Shared Secret Key is required. Please fill out this field.', 'visa-acceptance-solutions' );
			} else {
				$sanitized_data[ $field_prefix . 'test_api_shared_secret' ] = sanitize_text_field( $raw_test_api_shared_secret );
			}

		} else {
			$raw_merchant_id = isset( $post_data[ $field_prefix . 'merchant_id' ] ) ? $post_data[ $field_prefix . 'merchant_id' ] : VISA_ACCEPTANCE_STRING_EMPTY;
			$trimmed_merchant_id = trim( $raw_merchant_id );

			if ( empty( $trimmed_merchant_id ) ) {
				$validation_errors[] = __( 'Merchant ID is required. Please fill out this field.', 'visa-acceptance-solutions' );
			} elseif ( preg_match( '/[^[:alnum:]\-_]/', $trimmed_merchant_id ) ) {
				$validation_errors[] = __( 'Merchant ID contains invalid characters. Only alphanumeric characters, hyphens, and underscores are allowed.', 'visa-acceptance-solutions' );
			} else {
				$sanitized_data[ $field_prefix . 'merchant_id' ] = sanitize_text_field( $trimmed_merchant_id );
			}
			$raw_api_key = isset( $post_data[ $field_prefix . 'api_key' ] ) ? $post_data[ $field_prefix . 'api_key' ] : VISA_ACCEPTANCE_STRING_EMPTY;

			if ( empty( $raw_api_key ) ) {
				$validation_errors[] = __( 'API Key is required. Please fill out this field.', 'visa-acceptance-solutions' );
			} else {
				$sanitized_data[ $field_prefix . 'api_key' ] = sanitize_text_field( $raw_api_key );
			}
			$raw_api_shared_secret = isset( $post_data[ $field_prefix . 'api_shared_secret' ] ) ? $post_data[ $field_prefix . 'api_shared_secret' ] : VISA_ACCEPTANCE_STRING_EMPTY;

			if ( empty( $raw_api_shared_secret ) ) {
				$validation_errors[] = __( 'API Shared Secret Key is required. Please fill out this field.', 'visa-acceptance-solutions' );
			} else {
				$sanitized_data[ $field_prefix . 'api_shared_secret' ] = sanitize_text_field( $raw_api_shared_secret );
			}
		}

		if ( ! empty( $validation_errors ) ) {
			$validation_errors = array_unique( $validation_errors );
			foreach ( $validation_errors as $error ) {
				WC_Admin_Settings::add_error( $error );
			}
			return false;
		}
		// Manage webhook subscription based on the Fraud Screening toggle state and credential changes.
		$dm_field_key     = $field_prefix . VISA_ACCEPTANCE_SETTING_ENABLE_FRAUD_WEBHOOK;
		$dm_new_value     = isset( $post_data[ $dm_field_key ] ) ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO;
		$current_settings = $this->get_gateway_settings();
		$dm_old_value     = isset( $current_settings[ VISA_ACCEPTANCE_SETTING_ENABLE_FRAUD_WEBHOOK ] )
			? $current_settings[ VISA_ACCEPTANCE_SETTING_ENABLE_FRAUD_WEBHOOK ]
			: VISA_ACCEPTANCE_NO;
		$enabled_field_key  = $field_prefix . 'enabled';
		$gateway_new_enabled = isset( $post_data[ $enabled_field_key ] ) ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO;
		$gateway_old_enabled = isset( $current_settings['enabled'] ) ? $current_settings['enabled'] : VISA_ACCEPTANCE_NO;
 
		$cred_fields       = array( 'merchant_id', 'api_key', 'api_shared_secret', 'test_merchant_id', 'test_api_key', 'test_api_shared_secret', 'environment' );
		$credentials_changed = false;
		foreach ( $cred_fields as $cred_field ) {
			$new_val = isset( $post_data[ $field_prefix . $cred_field ] ) ? sanitize_text_field( $post_data[ $field_prefix . $cred_field ] ) : VISA_ACCEPTANCE_STRING_EMPTY;
			$old_val = isset( $current_settings[ $cred_field ] ) ? (string) $current_settings[ $cred_field ] : VISA_ACCEPTANCE_STRING_EMPTY;
			if ( $new_val !== $old_val ) {
				$credentials_changed = true;
				break;
			}
		}
		// Detect changes to the MLE .p12 certificate fields (replaces old PEM cert/key fields).
		$cert_key_fields  = array( 'mle_certificate_path', 'mle_key_password' );
		$cert_key_changed = false;
		foreach ( $cert_key_fields as $ck_field ) {
			$new_val = isset( $post_data[ $field_prefix . $ck_field ] ) ? trim( $post_data[ $field_prefix . $ck_field ] ) : VISA_ACCEPTANCE_STRING_EMPTY;
			$old_val = isset( $current_settings[ $ck_field ] ) ? trim( (string) $current_settings[ $ck_field ] ) : VISA_ACCEPTANCE_STRING_EMPTY;
			if ( $new_val !== $old_val ) {
				$cert_key_changed = true;
				break;
			}
		}

		// Only subscribe/unsubscribe webhooks if gateway is enabled.
        if ( VISA_ACCEPTANCE_YES === $gateway_new_enabled ) {
            // Gateway is enabled - manage Fraud Management webhook subscription.
            if ( $dm_new_value !== $dm_old_value ) {
                if ( VISA_ACCEPTANCE_YES === $dm_new_value ) {
                    // DM toggled ON: generate fresh signing key and subscribe Fraud Management webhook.
                    parent::process_admin_options();
					$this->unsubscribe_fraud_webhook();
					$this->unsubscribe_uc_webhook();
                    $this->generate_and_sync_fresh_signing_key();
                    $this->subscribe_fraud_webhook();
        			// Also re-subscribe UC and APM to ensure they retrieve their keys from CyberSource.
            		$this->subscribe_uc_webhook();
                    // $this->subscribe_apm_webhook();
                    return true;
                } else {
                    // DM toggled OFF: unsubscribe Fraud Management webhook only.
                    $this->unsubscribe_fraud_webhook();
                }
            } elseif ( VISA_ACCEPTANCE_YES === $dm_new_value && ( $credentials_changed || ( VISA_ACCEPTANCE_YES === $gateway_new_enabled && VISA_ACCEPTANCE_YES !== $gateway_old_enabled ) ) ) {
                parent::process_admin_options();
                // Generate ONE fresh signing key for all webhooks to ensure it's the newest in the org.
				$this->unsubscribe_fraud_webhook();
				$this->unsubscribe_uc_webhook();
                $this->generate_and_sync_fresh_signing_key();
                $this->subscribe_fraud_webhook();
                $this->subscribe_uc_webhook();
                // APM disabled per client requirement.
                // $this->subscribe_apm_webhook();
                return true;
            }
        } else {
            // Gateway is disabled - don't subscribe any webhooks.
            if ( VISA_ACCEPTANCE_YES !== $gateway_old_enabled ) {
                // Gateway was disabled and remains disabled - do nothing.
                return parent::process_admin_options();
            }
            // Gateway is being disabled - unsubscribe all webhooks.
            $this->unsubscribe_fraud_webhook();
            $this->unsubscribe_uc_webhook();
            // APM disabled per client requirement.
            // $this->unsubscribe_apm_webhook();
            return parent::process_admin_options();
        }
 
        // UC + APM webhooks - only subscribe when gateway is enabled.
        // Re-subscribe on credential/certificate changes or when gateway is being enabled.
        $gateway_enabling  = VISA_ACCEPTANCE_YES === $gateway_new_enabled && VISA_ACCEPTANCE_YES !== $gateway_old_enabled;
       
        if ( VISA_ACCEPTANCE_YES === $gateway_new_enabled && ( $credentials_changed || $gateway_enabling || $cert_key_changed ) ) {
            // Save new credentials/keys first so subscribe picks them up.
            parent::process_admin_options();
            
            $this->unsubscribe_uc_webhook();
            $this->subscribe_uc_webhook();
            // APM disabled per client requirement.
            // $this->subscribe_apm_webhook();
            return true;
        }
        return parent::process_admin_options();
	}

	/**
	 * Gets merchant id for particular gateway.
	 *
	 * @return string|null
	 */
	public function get_merchant_id() {
		$settings = $this->get_gateway_settings();
		if ( isset( $settings[VISA_ACCEPTANCE_ENVIRONMENT] ) && VISA_ACCEPTANCE_ENVIRONMENT_PRODUCTION === $settings[VISA_ACCEPTANCE_ENVIRONMENT] ) {
			$merchant_id = isset( $settings['merchant_id'] ) ? $settings['merchant_id'] : null;
		} else {
			$merchant_id = isset( $settings['test_merchant_id'] ) ? $settings['test_merchant_id'] : null;
		}
		return $merchant_id;
	}

	/**
	 * Setup general properties for the gateway.
	 *
	 * @return void
	 */
	protected function setup_properties() {
		$this->id                 = VISA_ACCEPTANCE_UC_ID;
		// Don't translate these yet - they will be translated after 'init' hook.
		$this->method_title       = 'Visa Acceptance Solutions';
		$this->method_description = 'Choose from a range of secure payment options.';
		$this->icon               = plugins_url( '/../public/img/brand-icon.png', __FILE__ );
		$this->has_fields         = true;
		
		// Translate properties after init hook.
		add_action( 'init', array( $this, 'translate_properties' ), 99 );
	}
	
	/**
	 * Translate properties after WordPress init.
	 * This prevents "translation loaded too early" warnings.
	 *
	 * @return void
	 */
	public function translate_properties() {
		if ( did_action( 'init' ) ) {
			$this->method_title       = __( 'Visa Acceptance Solutions', 'visa-acceptance-solutions' );
			$this->method_description = __( 'Choose from a range of secure payment options.', 'visa-acceptance-solutions' );
		}
	}

	/**
	 * Returns the method title for admin page.
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->title;
	}

	/**
	 * Gets the configuration settings for the particular gateway
	 *
	 * @return array
	 */
	public function get_config_settings() {
		$settings = $this->get_gateway_settings();
		return $settings;
	}

	/**
	 * Returns settings stored in current gateway.
	 *
	 * @return array
	 */
	public function get_gateway_settings() {

		return get_option( VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->get_id() . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS, array() );
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Visa_Acceptance_Payment_Gateway_Loader. Orchestrates the hooks of the plugin.
	 * - Visa_Acceptance_Solutions_Admin. Defines all hooks for the admin area.
	 * - Visa_Acceptance_Solutions_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @return void
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-visa-acceptance-payment-gateway-loader.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-visa-acceptance-payment-gateway-unified-checkout-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'public/class-visa-acceptance-payment-gateway-unified-checkout-public.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * express pay side of the site.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'public/class-visa-acceptance-payment-gateway-expresspay-public.php';
		

		/**
		 * The class responsible for defining all actions that occur in the CURL API's
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-payment-uc.php';

		$this->loader = new Visa_Acceptance_Payment_Gateway_Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 * with WordPress.
	 *
	 * @return void
	 */
	private function set_locale() {

		$this->loader->add_action( 'plugins_loaded', $this, 'visa_acceptance_initialize' );
	}

	/**
	 * Load the plugin text domain for translation.
	 * Initialize the plugin.
	 */
	private function visa_acceptance_initialize() {
		require_once 'includes/class-visa-acceptance-solutions.php';

		if ( class_exists( VISA_ACCEPTANCE_WOOCOMMERCE_CONSTANT ) ) {
			// Initialize the Visa_Acceptance_Solutions instance.
			$GLOBALS[ 'visa_acceptance_' . VISA_ACCEPTANCE_SOLUTION_TEXT ] = Visa_Acceptance_Solutions::instance();
		} else {
			return;
		}
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @return void
	 */
	private function define_admin_hooks() {
        $this->plugin_admin = new Visa_Acceptance_Payment_Gateway_Unified_Checkout_Admin( $this->get_id(), $this->get_version(), $this );
        $settings           = $this->get_gateway_settings();
        $this->loader->add_action( 'woocommerce_update_options_payment_gateways_' . $this->id,  $this, 'process_admin_options'  );
        $this->loader->add_action( 'admin_notices', $this->plugin_admin, 'sandbox_admin_notice' );
        if ( isset( $settings['enabled'] ) && VISA_ACCEPTANCE_YES === $settings['enabled'] ) {
            $this->loader->add_action( 'admin_enqueue_scripts', $this->plugin_admin, 'enqueue_styles' );
            $this->loader->add_action( 'admin_enqueue_scripts', $this->plugin_admin, 'enqueue_scripts' );
            $this->loader->add_action( 'woocommerce_order_item_add_action_buttons', $this->plugin_admin, 'add_capture_button', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_ONE );
            $this->loader->add_action( 'wp_ajax_wc_capture_action', $this->plugin_admin, 'ajax_process_capture' );
			$this->loader->add_action( 'wp_ajax_visa_acceptance_partial_capture', $this->plugin_admin, 'ajax_process_partial_capture' );
            $this->loader->add_action( 'woocommerce_admin_order_items_after_line_items', $this->plugin_admin, 'render_partial_capture_inline_hook' );
            $this->loader->add_action( 'woocommerce_order_status_changed', $this, 'prevent_order_status_change', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_FOUR );
            $this->loader->add_filter( 'woocommerce_bulk_action_ids', $this, 'wp_kama_woocommerce_bulk_action_ids_filter', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_THREE );
		
        }
    }

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @return void
	 */
	private function define_public_hooks() {

		// if payment gateway is disabled, then no need to enqueue JS too.
		if ( VISA_ACCEPTANCE_NO === $this->enabled ) {
			return;
		}
		$this->plugin_public = new Visa_Acceptance_Payment_Gateway_Unified_Checkout_Public( $this->get_id(), $this->get_version(), $this );
		$this->express_pay_plugin_public = new Visa_Acceptance_Payment_Gateway_Expresspay_Public( $this->get_id(), $this->get_version(), $this );
		$settings            = $this->get_gateway_settings();
		if ( isset( $settings['enabled'] ) && VISA_ACCEPTANCE_YES === $settings['enabled'] ) {
			$this->loader->add_action( 'wp_enqueue_scripts', $this->plugin_public, 'enqueue_styles' );
			$this->loader->add_action( 'wp_enqueue_scripts', $this->plugin_public, 'enqueue_scripts' );
			$this->loader->add_action( 'wp_ajax_' . $this->get_id() . '_action', $this->plugin_public, $this->get_id() . '_action' );
			$this->loader->add_filter( 'woocommerce_payment_methods_list_item', $this->plugin_public, 'wp_kama_woocommerce_saved_payment_methods_list_filter', VISA_ACCEPTANCE_VAL_TEN, VISA_ACCEPTANCE_VAL_TWO );
			$gateway_id_for_env_filter = $this->get_id();
			$current_environment      = isset( $settings[ VISA_ACCEPTANCE_ENVIRONMENT ] ) ? $settings[ VISA_ACCEPTANCE_ENVIRONMENT ] : VISA_ACCEPTANCE_ENVIRONMENT_TEST;
			add_filter( 'woocommerce_get_customer_payment_tokens', function( $tokens, $customer_id, $gateway_filter ) use ( $gateway_id_for_env_filter, $current_environment ) {
				if ( ! empty( $gateway_filter ) && $gateway_filter !== $gateway_id_for_env_filter ) {
					return $tokens;
				}
				foreach ( $tokens as $key => $token ) {
					if ( $token instanceof \WC_Payment_Token && $token->get_gateway_id() === $gateway_id_for_env_filter && $token->get_meta( VISA_ACCEPTANCE_ENVIRONMENT ) !== $current_environment ) {
						unset( $tokens[ $key ] );
					}
				}
				return $tokens;
			}, VISA_ACCEPTANCE_VAL_TEN, VISA_ACCEPTANCE_VAL_THREE );
			// Hide saved tokens from WooCommerce Blocks REST API when tokenization is disabled.
            if ( ! isset( $settings['tokenization'] ) || VISA_ACCEPTANCE_YES !== $settings['tokenization'] ) {
                $gateway_id = $this->get_id();
                add_filter( 'woocommerce_get_customer_payment_tokens', function( $tokens, $customer_id, $gateway_filter ) use ( $gateway_id ) {
                    if ( empty( $gateway_filter ) || $gateway_filter === $gateway_id ) {
                        foreach ( $tokens as $key => $token ) {
							if ( $token instanceof \WC_Payment_Token && $token->get_gateway_id() === $gateway_id ) {
                                unset( $tokens[ $key ] );
                            }
                        }
                    }
                    return $tokens;
                }, VISA_ACCEPTANCE_VAL_TEN, VISA_ACCEPTANCE_VAL_THREE );
            }
			$gateway_id = $this->get_id();
            add_filter( 'woocommerce_get_customer_payment_tokens', function( $tokens, $customer_id, $gateway_filter ) use ( $gateway_id ) {
                if ( ! empty( $gateway_filter ) && $gateway_filter !== $gateway_id ) {
                    return $tokens;
                }
                $default_key = null;
                foreach ( $tokens as $key => $token ) {
                    if ( $token instanceof \WC_Payment_Token && $token->get_gateway_id() === $gateway_id && $token->is_default() ) {
                        $default_key = $key;
                        break;
                    }
                }
                if ( null !== $default_key ) {
                    $default_token = $tokens[ $default_key ];
                    unset( $tokens[ $default_key ] );
                    $tokens = array( $default_key => $default_token ) + $tokens;
                }
                return $tokens;
            }, VISA_ACCEPTANCE_VAL_ELEVEN, VISA_ACCEPTANCE_VAL_THREE );
			// For Delete Card data.
			$wc_version = defined( 'WC_VERSION' ) && WC_VERSION ? WC_VERSION : null;
			if ( version_compare( $wc_version, VISA_ACCEPTANCE_WC_VERSION_EIGHT_ONE_ZERO, VISA_ACCEPTANCE_GREATER_THAN_OR_EQUAL_TO ) ) {
				$this->loader->add_filter( 'woocommerce_pre_delete_data', $this->plugin_public, 'wp_kama_woocommerce_pre_delete_object_type_filter', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_THREE );
			} else {
				$this->loader->add_action( 'woocommerce_payment_token_deleted', $this->plugin_public, 'uc_payment_token_deleted', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );
			}
			// For Dafault Card data.
			$this->loader->add_filter( 'woocommerce_payment_token_set_default', $this->plugin_public, 'wp_kama_woocommerce_set_default', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );

			$this->loader->add_filter( 'woocommerce_account_payment_methods_columns', $this->plugin_public, 'uc_add_payment_methods_columns' );
			$this->loader->add_action( 'woocommerce_account_payment_methods_column_title', $this->plugin_public, 'uc_add_payment_method_title' );
			$this->loader->add_action( 'woocommerce_account_payment_methods_column_details', $this->plugin_public, 'uc_add_payment_method_details' );
			$this->loader->add_action( 'woocommerce_account_payment_methods_column_default', $this->plugin_public, 'uc_add_payment_method_default' );

			// For Accepted Card Logos.
			$this->loader->add_filter( 'woocommerce_gateway_icon', $this->plugin_public, 'custom_gateway_icon', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY, VISA_ACCEPTANCE_VAL_TWO );
			$this->loader->add_action( 'wp_ajax_wc_call_uc_update_price_action', $this->plugin_public, 'call_updates_action' );
			$this->loader->add_action( 'wp_ajax_nopriv_wc_call_uc_update_price_action', $this->plugin_public, 'call_updates_action' );

			// Browser data collection for 3DS device information.
			$this->loader->add_action( 'wp_ajax_store_browser_data', $this->plugin_public, 'store_browser_data' );
			$this->loader->add_action( 'wp_ajax_nopriv_store_browser_data', $this->plugin_public, 'store_browser_data' );	
			// TMS saved card capture context for UC CVV widget (logged-in users only).
			$this->loader->add_action( 'wp_ajax_wc_get_saved_card_context', $this->plugin_public, 'get_saved_card_capture_context' );
			$this->loader->add_action( 'wp_ajax_vas_uc_library_proxy', $this->plugin_public, 'proxy_uc_library' );
			$this->loader->add_action( 'wp_ajax_nopriv_vas_uc_library_proxy', $this->plugin_public, 'proxy_uc_library' );
			// For Express pay - only register hooks when ExpressPay is enabled.
            $express_pay_enabled = isset( $settings['enable_express_pay'] ) ? $settings['enable_express_pay'] : VISA_ACCEPTANCE_YES;
            if ( VISA_ACCEPTANCE_YES === $express_pay_enabled ) {
            $this->loader->add_action( 'wp_ajax_get_addresses_from_transient_token', $this->express_pay_plugin_public, 'ajax_get_addresses_from_transient_token' );
            $this->loader->add_action( 'wp_ajax_nopriv_get_addresses_from_transient_token', $this->express_pay_plugin_public, 'ajax_get_addresses_from_transient_token' );
            // For Express pay at normal checkout page.
            $this->loader->add_action( 'woocommerce_checkout_before_customer_details', $this->express_pay_plugin_public, 'add_express_pay_at_normal_checkout' );
            // For Express pay at Pay for orders page.
            $this->loader->add_action('before_woocommerce_pay', $this->express_pay_plugin_public, 'add_express_pay_at_normal_checkout', VISA_ACCEPTANCE_VAL_FIVE);
            }
 
		}
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 */
	 public function run() {
		$this->loader->run();
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @return    Visa_Acceptance_Payment_Gateway_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Handles the admin options.
	 *
	 * @return void
	 */
	public function admin_options() {
		parent::admin_options();
		$this->plugin_admin->admin_options();
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Get Plugin URL.
	 *
	 * @return string
	 */
	public function get_plugin_url() {

		$plugin_url = untrailingslashit( plugins_url( VISA_ACCEPTANCE_SLASH, __FILE__ ) );

		return $plugin_url;
	}

	/**
	 * Initialize Form Fields.
	 * This is delayed until 'init' hook to ensure translations are loaded.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		// Prevent multiple initializations.
		if ( ! empty( $this->form_fields ) ) {
			return;
		}
		
		$this->form_fields = $this->plugin_admin->init_form_fields();
	}

	/**
	 * Returns the current environment.
	 *
	 * @return string
	 */
	public function get_environment() {
		$settings = $this->get_gateway_settings();
		return ! empty( $settings[VISA_ACCEPTANCE_ENVIRONMENT] ) ? $settings[VISA_ACCEPTANCE_ENVIRONMENT] : VISA_ACCEPTANCE_STRING_EMPTY;
	}

	/**
	 * Display the payment fields on the checkout page
	 */
	public function payment_fields() {
		$this->plugin_public->payment_fields();
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param  int $order_id Order ID.
	 * @return array|null
	 */
	public function process_payment( $order_id ) {
		$response = null;
		// Condition to check order_id exists and valid or not.
		if ( $order_id || wc_get_order( $order_id ) ) {
			// Simply return if Invalid or non-existent order ID.
			$response = $this->plugin_public->process_payment( $order_id );
		}
		return $response;
	}

	/**
	 * Get feature supports for plugin.
	 *
	 * @param array $feature feature.
	 *
	 * @return boolean
	 */
	public function supports( $feature ) {
		$services_supported = array( VISA_ACCEPTANCE_ADD_PAYMENT_METHOD, VISA_ACCEPTANCE_TOKENIZATION, VISA_ACCEPTANCE_REFUNDS );
		$settings           = $this->get_gateway_settings();
		if ( VISA_ACCEPTANCE_ADD_PAYMENT_METHOD === $feature || VISA_ACCEPTANCE_TOKENIZATION === $feature ) {
			return ( isset( $settings['tokenization'] ) && VISA_ACCEPTANCE_YES === $settings['tokenization'] ) ? in_array( $feature, $services_supported, true ) : false;
		} elseif ( in_array( $feature, $this->supports, true ) ) {
			return true;
		} else {
			return in_array( $feature, $services_supported, true );
		}
	}

	/**
	 * Validates order.
	 *
	 * @param string|int $order_id order id.
	 * @param any        $order order object.
	 * @return array
	 */
	public function validate_order( $order_id, $order ) {
		$response_data = array();
		if ( ! $order ) {
			$response_data[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_CALLBACK_INVALID_ID_ERROR;
		} elseif ( ! current_user_can( VISA_ACCEPTANCE_EDIT_SHOP_ORDER, $order_id ) ) {
			$response_data[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_CALLBACK_INVALID_PERMISSIONS_ERROR;
		} elseif ( $order->get_payment_method( VISA_ACCEPTANCE_EDIT ) !== $this->id ) {
			$response_data[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_CALLBACK_INVALID_PAYMENT_METHOD_ERROR;
		}
		return $response_data;
	}

	/**
	 * Initiates refund process.
	 *
	 * @param int    $order_id order id.
	 * @param string $amount refund amount.
	 * @param string $reason efund reason.
	 *
	 * @return array
	 */
	public function process_refund( $order_id, $amount = null, $reason = VISA_ACCEPTANCE_STRING_EMPTY ) {
		$order  = wc_get_order( $order_id );
		$result = null;
		if ( ! $order ) {
			$result = new \WP_Error( 'vas_invalid_order', __( 'Invalid order ID.', 'visa-acceptance-solutions' ) );
		} elseif ( ! current_user_can( VISA_ACCEPTANCE_EDIT_SHOP_ORDER, $order_id ) ) {
			$result = new \WP_Error( 'vas_invalid_permissions', __( 'You do not have permission to refund this order.', 'visa-acceptance-solutions' ) );
		} elseif ( $order->get_payment_method( VISA_ACCEPTANCE_EDIT ) !== $this->id ) {
			$result = new \WP_Error( 'vas_invalid_payment_method', __( 'This order was not paid via Visa Acceptance Solutions.', 'visa-acceptance-solutions' ) );
		} else {
			$result = $this->plugin_admin->process_refund( $order_id, $amount, $reason );
		}
		return $result;
	}

	/**
	 * Checks whether order can be refunded or not.
	 *
	 * @param object $order order details.
	 *
	 * @return boolean
	 */
	public function can_refund_order( $order ) {
		return ! in_array( $order->get_status(), array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_REFUNDED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING ), true ) && ( $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID ) || $this->get_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID ) );
	}

	/**
	 * Initializes capture process
	 *
	 * @param int    $order_id order id.
	 * @param string $gateway_id gateway id.
	 *
	 * @return array
	 */
	public function init_process_capture( $order_id, $gateway_id ) {
		$order         = wc_get_order( $order_id );
		$response_data = array();
		if ( ! $order ) {
			$response_data[VISA_ACCEPTANCE_ERROR] = VISA_ACCEPTANCE_CALLBACK_INVALID_ID_ERROR;
		} elseif ( ! current_user_can( VISA_ACCEPTANCE_EDIT_SHOP_ORDER, $order_id ) ) {
			$response_data[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_CALLBACK_INVALID_PERMISSIONS_ERROR;
		} elseif ( $order->get_payment_method( 'edit' ) !== $gateway_id ) {
			$response_data[VISA_ACCEPTANCE_ERROR] = VISA_ACCEPTANCE_CALLBACK_INVALID_PAYMENT_METHOD_ERROR;
		}
		require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-capture.php';
		if ( empty( $response_data ) ) {
			$capture       = new Visa_Acceptance_Capture( $this );
			$response_data = $capture->perform_capture( $order, $response_data );
		}
		return $response_data;
	}

	/**
	 * Mask response.
	 *
	 * @param string $response response.
	 *
	 * @return string $response.
	 */
	public function mask_response( $response ) {
		$mask_data   = new Visa_Acceptance_Payment_Adapter( $this );
		$response    = ( is_array( $response ) || is_object( $response ) ) ? wp_json_encode( $response, JSON_UNESCAPED_SLASHES ) : $response;
		$decode_data = ! empty( $response ) ? json_decode( $response, true ) : false;

		if ( $decode_data ) {
			$fields_to_mask = array(
				'paymentInformation.card' => array( 'expirationYear', 'expirationMonth', 'prefix', 'suffix', 'securityCode' ),
				'deviceInformation'     => array( 'fingerprintSessionId' ),
                // Capture context request: PII is nested under the 'data' key.
                'data.orderInformation.billTo'     => array( 'firstName', 'lastName', 'address1', 'address2', 'address3', 'postalCode', 'locality', 'phoneNumber', 'email' ),
                'data.orderInformation.shipTo'     => array( 'firstName', 'lastName', 'address1', 'address2', 'address3', 'postalCode', 'locality', 'phoneNumber', 'email' ),
                // Token creation and payment requests: PII is directly under orderInformation.
                'orderInformation.billTo'          => array( 'firstName', 'lastName', 'address1', 'address2', 'address3', 'postalCode', 'locality', 'phoneNumber', 'email' ),
                'orderInformation.shipTo'          => array( 'firstName', 'lastName', 'address1', 'address2', 'address3', 'postalCode', 'locality', 'phoneNumber', 'email' ),
                // Key generation responses: mask sensitive key data.
                'keyInformation'                   => array( 'pub', 'keyId', 'kid' ),
			);

			foreach ( $fields_to_mask as $section => $fields ) {
				$data = &$decode_data;
				foreach ( explode( VISA_ACCEPTANCE_FULL_STOP, $section ) as $key ) {
					if ( isset( $data[ $key ] ) ) {
						$data = &$data[ $key ];
					} else {
						continue 2; // Skip to the next iteration of the outer loop.
					}
				}
				foreach ( $fields as $field ) {
					if ( ! empty( $data[ $field ] ) ) {
						$data[ $field ] = $mask_data->mask_value( $data[ $field ] );
					}
				}
			}
			$response = wp_json_encode( $decode_data );
		}
		return $response;
	}

	/**
	 * Adds data to the logs
	 *
	 * @param any     $data request/response data.
	 * @param boolean $is_request indicates whether the data is request.
	 * @param string  $log_header log header.
	 * @param boolean $is_catch_error indicates whether the error to log.
	 * @return void
	 */
	public function add_logs_header_response( $data, $is_request, $log_header, $is_catch_error = false ) {
		$settings = $this->get_gateway_settings();
		if ( isset( $settings['enable_logs'] ) && ( VISA_ACCEPTANCE_YES === $settings['enable_logs'] ) ) {
			$logger = wc_get_logger();
			// Change log name to gateway title.
			$context = array( 'source' => $this->get_title_dasherized( $this->method_title ) );
			$data = is_array( $data ) ? wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : $data;
			if ( $is_request ) {
				$log  = wp_strip_all_tags( $this->get_title() ) . VISA_ACCEPTANCE_SPACE . $log_header . VISA_ACCEPTANCE_RESPONSE . PHP_EOL . wp_json_encode($data, JSON_UNESCAPED_SLASHES) . PHP_EOL;
			}
			$logger->info( $log, $context );
		}
	}

	/**
	 * Adds data to the logs
	 *
	 * @param any     $data request/response data.
	 * @param string  $correlation_id correlation id.
	 * @param boolean $is_request indicates whether the data is request.
	 * @param string  $log_header log header.
	 * @param boolean $is_catch_error indicates whether the error to log.
	 * @return void
	 */
	public function add_logs_service_response( $data, $correlation_id, $is_request, $log_header, $is_catch_error = false ) {
		$settings = $this->get_gateway_settings();
		if ( isset( $settings['enable_logs'] ) && ( VISA_ACCEPTANCE_YES === $settings['enable_logs'] ) ) {
			$logger = wc_get_logger();
			// Change log name to gateway title.
			$context = array( 'source' => $this->get_title_dasherized( $this->method_title ) );
			$data = is_array( $data ) ? wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : ( is_object( $data ) ? (string) $data : $data );
			if ( $is_request ) {
				$data = $this->mask_response( $data );
				$log  = wp_strip_all_tags( $this->get_title() ) . VISA_ACCEPTANCE_SPACE . $log_header . VISA_ACCEPTANCE_RESPONSE . PHP_EOL . '{"correlation id":' .$correlation_id.'}'. $data . PHP_EOL;
			}
			$logger->info( $log, $context );
		}
	}

	/**
	 * Adds data to the logs
	 *
	 * @param array   $data request/response data.
	 * @param boolean $is_request indicates whether the data is request.
	 * @param string  $log_header log header.
	 * @param boolean $is_catch_error indicates whether the error to log.
	 * @return void
	 */
	public function add_logs_data( $data, $is_request, $log_header, $is_catch_error = false ) {
        $settings = $this->get_gateway_settings();
        if ( isset( $settings['enable_logs'] ) && ( VISA_ACCEPTANCE_YES === $settings['enable_logs'] ) ) {
            $logger = wc_get_logger();
            // Change Log name as Title.
            $context = array( 'source' => $this->get_title_dasherized( $this->method_title ) );
            $log_title = wp_strip_all_tags( $this->get_title() );
            if ( $is_request ) {
				$data = is_array( $data ) ? wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : $data;
                $data = $this->mask_response( $data );
                $log  = $log_title . VISA_ACCEPTANCE_SPACE . $log_header . VISA_ACCEPTANCE_REQUEST . PHP_EOL . $data . PHP_EOL;
 
            } elseif ( is_wp_error( $data ) ) {
				$error_messages = $data->get_error_messages();
				$error_message  = array( 'Could not receive/fetch API response.' );
				if ( in_array( __( 'A valid URL was not provided.', 'visa-acceptance-solutions' ), $error_messages, true ) ) {
					$error_messages = $error_message;
				}
				$log = wp_strip_all_tags( $this->get_title() ) . VISA_ACCEPTANCE_SPACE . $log_header . VISA_ACCEPTANCE_RESPONSE . PHP_EOL . ( is_string( $error_messages ) ? $error_messages : wp_json_encode( $error_messages, JSON_UNESCAPED_SLASHES ) ) . PHP_EOL;
			} elseif ( $is_catch_error ) {
                $data = is_string( $data ) ? $data : wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
                $log  = $log_title . VISA_ACCEPTANCE_SPACE . $log_header . PHP_EOL . $data . PHP_EOL;
            } else {
				// Check if 'body' key exists before accessing it.
				$response_data = isset( $data['body'] ) ? $data['body'] : $data;
				$response_data = $this->mask_response( $response_data );
				$response_data = is_string( $response_data ) ? $response_data : wp_json_encode( $response_data, JSON_UNESCAPED_SLASHES );
				if ( stripos( $log_header, 'webhook' ) !== false ) {
				$log_data = $response_data;
				} else {
					$log_data = base64_encode( $response_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding log data for storage, not obfuscating code.
				}

				$log = wp_strip_all_tags( $this->get_title() ) . VISA_ACCEPTANCE_SPACE . $log_header . VISA_ACCEPTANCE_RESPONSE . PHP_EOL .
				$log_data . PHP_EOL;
			}
            $logger->info( $log, $context );
        }
    }

	/**
	 * Initiates the Add Payment Method flow.
	 *
	 * @return array
	 */
	public function add_payment_method() {
		return $this->plugin_public->add_payment_method();
	}

	/**
	 * Initiates the token delete process.
	 *
	 * @param array $core_token_id core token id.
	 * @param array $core_token core token.
	 *
	 * @return array
	 */
	public function init_payment_token_deleted( $core_token_id, $core_token ) {
		$payment_method = new Visa_Acceptance_Payment_Methods( $this );
		$response       = $payment_method->delete_token_from_gateway( $core_token_id, $core_token );
		return $response;
	}
}
