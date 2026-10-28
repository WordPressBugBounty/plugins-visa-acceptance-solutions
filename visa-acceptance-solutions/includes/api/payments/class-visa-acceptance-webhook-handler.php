<?php

/**
 * Visa Acceptance Webhook Handler
 * 
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes/api/payments
 */

if (! defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/../class-visa-acceptance-request.php';
require_once __DIR__ . '/class-visa-acceptance-auth-reversal.php';
require_once __DIR__ . '/class-visa-acceptance-refund.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';

/**
 * Webhook Handler for CyberSource payment notifications.
 *
 * Handles incoming webhook requests for UC, APM, and Fraud Management events.
 */
class Visa_Acceptance_Webhook_Handler extends Visa_Acceptance_Request
{

	/**
	 * Gateway instance.
	 *
	 * @var object
	 */
	public $gateway;

	/**
	 * Constructor.
	 *
	 * @param object $gateway Gateway instance.
	 */
	public function __construct($gateway)
	{
		parent::__construct($gateway);
		$this->gateway = $gateway;
	}

	/**
	 * Creates a standardized health check response for webhook endpoints.
	 *
	 * @param string $type          Webhook type identifier (e.g., 'fraud-webhook').
	 * @param string $label         Log label (e.g., 'Fraud Management Webhook').
	 * @param string $webhook_id_opt WP option name that stores the webhook ID (optional).
	 * @return \WP_REST_Response
	 */
	private function create_health_check_response($type, $label, $webhook_id_opt = null)
	{
		// Only log if webhook is actually subscribed (has a stored webhook ID).
		if ($webhook_id_opt && ! empty(get_option($webhook_id_opt, VISA_ACCEPTANCE_STRING_EMPTY))) {
			$this->gateway->add_logs_data(array($label . ' health check received - ' . VISA_ACCEPTANCE_TWO_ZERO_ZERO . ' OK.'), false, $label);
		}
		return new \WP_REST_Response(array('status' => 'ok', 'type' => $type), VISA_ACCEPTANCE_TWO_ZERO_ZERO);
	}

	/**
	 * Builds the full webhook URL for a given route.
	 *
	 * @param string $route      Route constant (e.g., VISA_ACCEPTANCE_UC_WEBHOOK_ROUTE).
	 * @param bool   $strip_port Whether to remove :443 from HTTPS URLs (default: true).
	 * @return string
	 */
	private static function build_webhook_url($route, $strip_port = true)
	{
		$url = rest_url(VISA_ACCEPTANCE_NAMESPACE . $route);
		if ($strip_port) {
			$url = str_replace(':443/', '/', $url);
		}
		return $url;
	}

	/**
	 * Validates the organizationId and webhookId(s) in the payload against stored values.
	 *
	 * @param array        $payload            Decoded payload.
	 * @param string|array $webhook_id_options WP option name(s) for stored webhook ID(s).
	 *                                          Array for multiple IDs (e.g., fraud has DM + FME).
	 * @param string       $log_context        Log label (e.g., 'UC Response Webhook').
	 * @return bool
	 */
	private function validate_webhook_ids(array $payload, $webhook_id_options, $log_context)
	{
		// Validate organizationId.
		$incoming_org_id = $payload['organizationId'] ?? ($payload[VISA_ACCEPTANCE_VAL_ZERO]['organizationId'] ?? null);
		if ($incoming_org_id) {
			$merchant_id = $this->gateway->get_merchant_id();
			if (! empty($merchant_id) && ! hash_equals($merchant_id, $incoming_org_id)) {
				$this->gateway->add_logs_data(array($log_context . ': organizationId mismatch. Expected: ' . $merchant_id . ' Got: ' . $incoming_org_id), false, $log_context, true);
				return false;
			}
		}

		// Validate webhookId(s).
		$incoming_webhook_id = $payload['webhookId'] ?? null;
		if ($incoming_webhook_id) {
			// Support both single option (string) and multiple options (array).
			$options = is_array($webhook_id_options) ? $webhook_id_options : array($webhook_id_options);
			$stored_ids = array();
			$match_found = false;

			foreach ($options as $option_name) {
				$stored_id = get_option($option_name, VISA_ACCEPTANCE_STRING_EMPTY);
				if (! empty($stored_id)) {
					$stored_ids[] = $stored_id;
					if (hash_equals($stored_id, $incoming_webhook_id)) {
						$match_found = true;
					}
				}
			}

			// If we have stored IDs and none matched, reject.
			if (! empty($stored_ids) && ! $match_found) {
				$this->gateway->add_logs_data(array($log_context . ': webhookId mismatch. Expected: ' . implode(' or ', $stored_ids) . ' Got: ' . $incoming_webhook_id), false, $log_context, true);
				return false;
			}
		}

		return true;
	}

	/**
	 * Registers the WP REST API webhook receiver endpoint.
	 *
	 * @return void
	 */
	public function register_rest_route()
	{
		// Fraud / DM / FME endpoint.
		register_rest_route(
			VISA_ACCEPTANCE_NAMESPACE,
			VISA_ACCEPTANCE_FRAUD_WEBHOOK_ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'handle_fraud_request'),
					'permission_callback' => array($this, 'validate_webhook_permission'),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array($this, 'handle_fraud_health_check'),
					'permission_callback' => array($this, 'validate_health_check_permission'),
				),
			)
		);

		// Unified Checkout transaction result endpoint.
		register_rest_route(
			VISA_ACCEPTANCE_NAMESPACE,
			VISA_ACCEPTANCE_UC_WEBHOOK_ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'handle_uc_request'),
					'permission_callback' => array($this, 'validate_webhook_permission'),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array($this, 'handle_uc_health_check'),
					'permission_callback' => array($this, 'validate_health_check_permission'),
				),
			)
		);

		/*Alternative Payment Methods (APM) async result endpoint.
		register_rest_route(
			VISA_ACCEPTANCE_NAMESPACE,
			VISA_ACCEPTANCE_APM_WEBHOOK_ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_apm_request' ),
					'permission_callback' => array( $this, 'validate_webhook_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_apm_health_check' ),
					'permission_callback' => '__return_true',
				),
			)
		);*/
	}

	/**
	 * Permission callback for the GET health-check endpoints.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool
	 */
	public function validate_health_check_permission($request)
	{
		return 'GET' === $request->get_method();
	}

	/**
	 * Validate webhook permission with rate limiting and basic security checks.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_webhook_permission($request)
	{
		// Check required headers exist.
		$signature = $request->get_header('v-c-signature');
		if (empty($signature)) {
			return new \WP_Error(
				'missing_signature',
				__('Webhook signature required', 'visa-acceptance-solutions'),
				array('status' => VISA_ACCEPTANCE_FOUR_ZERO_ONE)
			);
		}

		// Rate limiting check.
		$ip       = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : VISA_ACCEPTANCE_STRING_EMPTY;
		$rate_key = 'vas_webhook_rate_' . md5($ip);
		$requests = get_transient($rate_key);

		if ($requests && $requests > 100) {
			return new \WP_Error(
				'rate_limit_exceeded',
				__('Too many requests', 'visa-acceptance-solutions'),
				array('status' => 429)
			);
		}

		set_transient($rate_key, ($requests ? $requests + VISA_ACCEPTANCE_VAL_ONE : VISA_ACCEPTANCE_VAL_ONE), VISA_ACCEPTANCE_VAL_SIX_ZERO);

		// Content-type validation.
		$content_type = $request->get_header('content-type');
		if (strpos($content_type, 'application/json') === false) {
			return new \WP_Error(
				'invalid_content_type',
				__('JSON content required', 'visa-acceptance-solutions'),
				array('status' => 415)
			);
		}

		// Full HMAC validation happens in callback.
		return true;
	}

	/**
	 * Health-check for the Fraud Management Webhook webhook endpoint.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_fraud_health_check()
	{
		// Check if either DM or FME webhook is subscribed.
		$dm_id  = get_option(VISA_ACCEPTANCE_FRAUD_WEBHOOK_ID_OPTION, VISA_ACCEPTANCE_STRING_EMPTY);
		$fme_id = get_option(VISA_ACCEPTANCE_FRAUD_WEBHOOK_FME_ID_OPTION, VISA_ACCEPTANCE_STRING_EMPTY);
		$is_subscribed = ! empty($dm_id) || ! empty($fme_id);
		if ($is_subscribed) {
			$this->gateway->add_logs_data(array('Fraud Management Webhook health check received - ' . VISA_ACCEPTANCE_TWO_ZERO_ZERO . ' OK.'), false, 'Fraud Management Webhook');
		}
		return new \WP_REST_Response(array('status' => 'ok', 'type' => 'fraud-webhook'), VISA_ACCEPTANCE_TWO_ZERO_ZERO);
	}

	/**
	 * Health-check for the Unified Checkout webhook endpoint.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_uc_health_check()
	{
		return $this->create_health_check_response('uc-webhook', 'UC Response Webhook');
	}

	/**
	 * Health-check for the APM webhook endpoint.
	 *
	 * @return \WP_REST_Response
	 */
	/*public function handle_apm_health_check() {
		return $this->create_health_check_response( 'apm-webhook', 'APM Webhook' );
	}*/

	/** Full URL of the Fraud Management Webhook webhook endpoint. */
	public static function get_webhook_url()
	{
		return rest_url(VISA_ACCEPTANCE_NAMESPACE . VISA_ACCEPTANCE_FRAUD_WEBHOOK_ROUTE);
	}

	/** Full URL of the UC response webhook endpoint. */
	public static function get_uc_webhook_url()
	{
		return self::build_webhook_url(VISA_ACCEPTANCE_UC_WEBHOOK_ROUTE);
	}

	/* Full URL of the APM webhook endpoint. 
	public static function get_apm_webhook_url() {
		return self::build_webhook_url( VISA_ACCEPTANCE_APM_WEBHOOK_ROUTE );
	}*/

	/**
	 * Handles POST requests on the fraud-webhook endpoint.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_fraud_request(\WP_REST_Request $request)
	{
		$raw_body = $request->get_body();

		// Validate token if configured.
		$stored_token = get_option(VISA_ACCEPTANCE_FRAUD_WEBHOOK_TOKEN_OPTION, VISA_ACCEPTANCE_STRING_EMPTY);
		if (! empty($stored_token)) {
			$incoming_token = sanitize_text_field($request->get_param('token'));
			if (! $incoming_token || ! hash_equals($stored_token, $incoming_token)) {
				$this->gateway->add_logs_data(array('Token validation failed'), false, 'Fraud Management Webhook', true);
				return new \WP_REST_Response(array('error' => 'Forbidden'), VISA_ACCEPTANCE_FOUR_ZERO_THREE);
			}
		}

		// Verify HMAC signature.
		if (! $this->verify_hmac_signature($request, $raw_body, VISA_ACCEPTANCE_FRAUD_WEBHOOK_SECRET_OPTION, VISA_ACCEPTANCE_FRAUD_WEBHOOK_KEY_ID_OPTION, 'Fraud Management Webhook')) {
			return new \WP_REST_Response(array('error' => 'Unauthorized'), VISA_ACCEPTANCE_FOUR_ZERO_ONE);
		}

		// Decode and validate payload.
		$payload = json_decode($raw_body, true);
		if (empty($payload) || ! isset($payload['eventType'])) {
			$this->gateway->add_logs_data(array('Invalid payload structure'), false, 'Fraud Management Webhook', true);
			return new \WP_REST_Response(array('error' => 'Invalid payload'), VISA_ACCEPTANCE_FOUR_ZERO_ZERO);
		}

		// Validate IDs.
		if (! $this->validate_fraud_ids($payload)) {
			return new \WP_REST_Response(array('error' => 'Forbidden'), VISA_ACCEPTANCE_FOUR_ZERO_THREE);
		}

		// De-duplicate redeliveries: CyberSource can dispatch the same case decision event more than once
		// (e.g. from both the Decision Manager and Fraud Management Essentials pipelines) with the same
		// webhookId/case id but a different envelope/transactionTraceId. Skip re-logging and re-dispatching.
		$dedup_key = $this->get_fraud_event_dedup_key($payload);
		if ($dedup_key && get_transient($dedup_key)) {
			return new \WP_REST_Response(array('received' => true, 'duplicate' => true), VISA_ACCEPTANCE_TWO_ZERO_ZERO);
		}
		if ($dedup_key) {
			set_transient($dedup_key, VISA_ACCEPTANCE_VAL_ONE, 5 * MINUTE_IN_SECONDS);
		}

		$this->gateway->add_logs_data(wp_json_encode($payload), false, 'Fraud Management Webhook', false);

		try {
			$this->dispatch_fraud_event($payload);
		} catch (\Exception $e) {
			$this->gateway->add_logs_data(array($e->getMessage()), false, 'Fraud Management Webhook', true);
		}

		return new \WP_REST_Response(array('received' => true), VISA_ACCEPTANCE_TWO_ZERO_ZERO);
	}

	/**
	 * Builds a stable de-duplication key for an incoming Fraud Management webhook event.
	 *
	 * Uses the webhookId together with the underlying case/decision id (and event type) rather
	 * than the transactionTraceId, since CyberSource redeliveries reuse the same webhookId/case id
	 * but generate a new transactionTraceId and a slightly different payload envelope.
	 *
	 * @param array $payload Decoded webhook payload.
	 * @return string|null Transient key, or null if not enough data to build one.
	 */
	private function get_fraud_event_dedup_key(array $payload)
	{
		$webhook_id = $payload['webhookId'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
		$event_type = $payload['eventType'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
		$event_data = $payload['payload']['data'] ?? $payload['payload'] ?? array();
		$case_id    = $event_data['id'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
		$reviewer   = $event_data['reviewerDecision'] ?? VISA_ACCEPTANCE_STRING_EMPTY;

		if (VISA_ACCEPTANCE_STRING_EMPTY === $webhook_id && VISA_ACCEPTANCE_STRING_EMPTY === $case_id) {
			return null;
		}

		return 'vas_fraud_webhook_dedup_' . md5($webhook_id . '|' . $event_type . '|' . $case_id . '|' . $reviewer);
	}


	/**
	 * Handles POST requests on the /apm-webhook endpoint.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	/*public function handle_apm_request( \WP_REST_Request $request ) {
		return $this->handle_encrypted_webhook_request(
			$request,
			VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_OPTION,
			VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_ID_OPTION,
			VISA_ACCEPTANCE_APM_WEBHOOK_PRIVATE_KEY_OPTION,
			array( VISA_ACCEPTANCE_APM_WEBHOOK_ID_OPTION ),
			array( $this, 'process_apm_event' ),
			'APM Webhook'
		);
	}*/

	/**
	 * Handles POST requests on the uc-webhook endpoint.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_uc_request(\WP_REST_Request $request)
	{
		return $this->handle_encrypted_webhook_request(
			$request,
			VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_OPTION,
			VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_ID_OPTION,
			VISA_ACCEPTANCE_UC_WEBHOOK_PRIVATE_KEY_OPTION,
			array(VISA_ACCEPTANCE_UC_WEBHOOK_ID_OPTION),
			array($this, 'process_uc_event'),
			'UC Response Webhook'
		);
	}


	/**
	 * Used by both fraud and UC endpoints with their respective option keys.
	 *
	 * @param \WP_REST_Request $request    Incoming request.
	 * @param string           $raw_body   Raw POST body.
	 * @param string           $key_opt    WP option name for the stored base64 HMAC secret.
	 * @param string           $key_id_opt WP option name for the stored keyId.
	 * @param string           $log_context Log label for logging.
	 * @return bool
	 */
	private function verify_hmac_signature(\WP_REST_Request $request, $raw_body, $key_opt, $key_id_opt, $log_context = 'Webhook')
	{
		$signing_secret   = get_option($key_opt, VISA_ACCEPTANCE_STRING_EMPTY);
		$header_signature = $request->get_header('v-c-signature');

		if (empty($signing_secret)) {
			// No secret stored yet: allow in test environment only; block in production.
			$settings = $this->gateway->get_gateway_settings();
			$is_test  = isset($settings['environment']) && VISA_ACCEPTANCE_ENVIRONMENT_TEST === $settings['environment'];
			if (! $is_test) {
				$this->gateway->add_logs_data(array($log_context . ': signature verification failed — no signing secret stored and environment is production.'), false, $log_context, true);
			}
			$is_valid = $is_test;
		} elseif (empty($header_signature)) {
			$is_valid = false;
		} else {
			// Header format: t=<timestamp>;keyId=<keyId>;sig=<base64sig>.
			$sig_params = array();
			foreach (explode(';', $header_signature) as $part) {
				$kv = explode('=', $part, VISA_ACCEPTANCE_VAL_TWO);
				if (VISA_ACCEPTANCE_VAL_TWO === count($kv)) {
					$sig_params[trim($kv[VISA_ACCEPTANCE_VAL_ZERO])] = trim($kv[VISA_ACCEPTANCE_VAL_ONE]);
				}
			}
			$timestamp       = $sig_params['t'] ?? null;
			$incoming_key_id = $sig_params['keyId'] ?? null;
			$sig             = $sig_params['sig'] ?? null;

			if (! $timestamp || ! $sig) {
				$is_valid = false;
				$this->gateway->add_logs_data(array($log_context . ': signature header missing timestamp or sig'), false, $log_context, true);
			} else {
				$stored_key_id = get_option($key_id_opt, VISA_ACCEPTANCE_STRING_EMPTY);
				$key_id_match  = ! empty($stored_key_id) && ! empty($incoming_key_id) && hash_equals($stored_key_id, $incoming_key_id);

				$this->gateway->add_logs_data(
					array(
						$log_context . ': signature header parsed',
						'timestamp'       => $timestamp,
						'incoming_key_id' => $incoming_key_id ?? '(missing)',
						'stored_key_id'   => $stored_key_id ? $stored_key_id : '(none)',
						'key_id_match'    => $key_id_match ? 'YES' : 'NO',
					),
					false,
					$log_context
				);
				if (empty($signing_secret) || empty($stored_key_id)) {
					$this->gateway->add_logs_data(
						array($log_context . ': stored webhook signature key details are incomplete'),
						false,
						$log_context,
						true
					);
					$is_valid = false;
				} elseif (empty($incoming_key_id)) {
					$this->gateway->add_logs_data(
						array($log_context . ': incoming keyId is missing from signature header'),
						false,
						$log_context,
						true
					);
					$is_valid = false;
				} elseif (! $key_id_match) {
					// keyId mismatch — CyberSource may have rotated the signing key after subscription recreation.
					// Still attempt HMAC verification; if it succeeds the stored keyId will be auto-updated.
					$this->gateway->add_logs_data(
						array(
							$log_context . ': keyId mismatch — attempting HMAC verification with stored key bytes',
							'stored_key_id'   => $stored_key_id,
							'incoming_key_id' => $incoming_key_id,
							'hint'            => 'If HMAC also fails, the webhook subscription was recreated without generating a fresh KMS signing key.',
						),
						false,
						$log_context
					);
					// Continue to signature verification.
				}

				// Attempt signature verification (moved outside of keyId check).
				if (! isset($is_valid) || false !== $is_valid) {
					// Decode the signing secret (with validation).
					$decoded_secret = base64_decode($signing_secret, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					if (false === $decoded_secret) {
						$this->gateway->add_logs_data(
							array($log_context . ': stored webhook digital signature key is not valid base64'),
							false,
							$log_context,
							true
						);
						$is_valid = false;
					} else {
						// CyberSource signs re-encoded JSON (decode then re-encode with JSON_UNESCAPED_SLASHES).
						// For non-JSON bodies (e.g. compact JWE), sign over the raw body as-is.
						$decoded_payload = json_decode($raw_body, true);

						if (null === $decoded_payload) {
							// Body is not JSON (may be a compact JWE). Use raw body for HMAC.
							$this->gateway->add_logs_data(
								array($log_context . ': body is not JSON — using raw body for HMAC (may be compact JWE)'),
								false,
								$log_context
							);
							$reencoded_payload = $raw_body;
						} else {
							// Re-encode with JSON_UNESCAPED_SLASHES.
							$reencoded_payload = json_encode($decoded_payload, JSON_UNESCAPED_SLASHES); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
						}

						// Create signature string: timestamp.payload.
						$signature_string = $timestamp . VISA_ACCEPTANCE_FULL_STOP . $reencoded_payload;

						// Compute HMAC signature.
						$expected_signature = base64_encode(hash_hmac('sha256', $signature_string, $decoded_secret, true)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

						if (hash_equals($expected_signature, $sig)) {
							$is_valid = true;
							$this->gateway->add_logs_data(array($log_context . ': HMAC signature verified successfully'), true, $log_context);
						} else {
							$is_valid = false;
							$this->gateway->add_logs_data(
								array($log_context . ': HMAC signature mismatch'),
								false,
								$log_context,
								true
							);
						}

						if (! empty($incoming_key_id) && ! empty($stored_key_id) && $is_valid && ! hash_equals($stored_key_id, $incoming_key_id)) {
							// Signature verified but keyId was different - auto-update to new keyId.
							update_option($key_id_opt, sanitize_text_field($incoming_key_id));
							$this->gateway->add_logs_data(
								array(
									$log_context . ': Auto-updated keyId after successful signature verification',
								),
								false,
								$log_context
							);
						}
					}
				}
			}
		}

		return $is_valid;
	}


	/**
	 * Validates organizationId and webhookId for Fraud Management Webhook/NT payloads.
	 *
	 * @param array $payload Decoded payload.
	 * @return bool
	 */
	private function validate_fraud_ids(array $payload)
	{
		return $this->validate_webhook_ids(
			$payload,
			array(VISA_ACCEPTANCE_FRAUD_WEBHOOK_ID_OPTION, VISA_ACCEPTANCE_FRAUD_WEBHOOK_FME_ID_OPTION),
			'Fraud Management Webhook'
		);
	}


	/**
	 * Handles an encrypted webhook request for UC or APM.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @param string           $sig_key_opt Signature key option.
	 * @param string           $key_id_opt Key ID option.
	 * @param string           $private_key_opt Private key option.
	 * @param array            $webhook_id_options Webhook ID options for validation.
	 * @param callable         $processor Event processor callback.
	 * @param string           $log_label Log label.
	 * @return \WP_REST_Response
	 */
	private function handle_encrypted_webhook_request($request, $sig_key_opt, $key_id_opt, $private_key_opt, $webhook_id_options, $processor, $log_label)
	{
		$raw_body = $request->get_body();

		// Verify HMAC signature.
		if (! $this->verify_hmac_signature($request, $raw_body, $sig_key_opt, $key_id_opt, $log_label)) {
			return new \WP_REST_Response(array('error' => 'Unauthorized'), VISA_ACCEPTANCE_FOUR_ZERO_ONE);
		}

		// Decrypt payload.
		$payload = $this->decode_payload($raw_body, $private_key_opt);
		if (null === $payload) {
			return new \WP_REST_Response(array('error' => 'Bad payload'), VISA_ACCEPTANCE_FOUR_ZERO_ZERO);
		}

		// Validate IDs.
		if (! $this->validate_webhook_ids($payload, $webhook_id_options, $log_label)) {
			return new \WP_REST_Response(array('error' => 'Forbidden'), VISA_ACCEPTANCE_FOUR_ZERO_THREE);
		}
		try {
			call_user_func($processor, $payload);
		} catch (\Exception $e) {
			$this->gateway->add_logs_data(array($e->getMessage()), false, $log_label, true);
		}

		return new \WP_REST_Response(array('received' => true), VISA_ACCEPTANCE_TWO_ZERO_ZERO);
	}


	/**
	 * Routes the Fraud Management Webhook/NT payload to the correct handler.
	 *
	 * @param array $payload Decoded webhook payload.
	 * @return void
	 */
	private function dispatch_fraud_event(array $payload)
	{
		$event_type = strtolower($payload['eventType'] ?? VISA_ACCEPTANCE_STRING_EMPTY);

		switch ($event_type) {
			// Fired when DM flags a transaction for review.
			case 'risk.profile.decision.review':
				$this->handle_decision_review($payload);
				break;

			// Fired when DM auto-rejects a transaction (profile rule).
			case 'risk.profile.decision.reject':
				$this->handle_profile_reject($payload);
				break;

			// Fired when a case manager manually accepts in EBC.
			case 'risk.casemanagement.decision.accept':
				$this->handle_case_management_accept($payload);
				break;

			// Fired when a case manager manually rejects in EBC.
			case 'risk.casemanagement.decision.reject':
				$this->handle_case_management_reject($payload);
				break;

			default:
				$this->gateway->add_logs_data(
					array('Unhandled webhook event type: ' . $event_type),
					false,
					'Fraud Management Webhook'
				);
				break;
		}
	}

	/**
	 * Handles DECISION_REVIEW event: marks the WooCommerce order as on-hold.
	 *
	 * @param array $payload Webhook payload.
	 * @return void
	 */
	private function handle_decision_review(array $payload)
	{
		$order = $this->find_order_from_payload($payload);

		if (
			$order &&
			($order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING) ||
				$order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD))
		) {

			$order->update_status(
				VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING,
				__('Order set to pending payment: flagged for Fraud Management review via webhook notification.', 'visa-acceptance-solutions')
			);
		}
	}

	/**
	 * Handles risk.profile.decision.reject: DM auto-rejected in EBC.
	 *
	 * @param array $payload Webhook payload.
	 * @return void
	 */
	private function handle_profile_reject(array $payload)
	{
		$order = $this->find_order_from_payload($payload);

		if (
			$order &&
			($order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD) ||
				$order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING))
		) {

			$this->reject_review($order, $payload);
		}
	}

	/**
	 * Handles risk.casemanagement.decision.accept: case manager accepted in EBC.
	 *
	 * @param array $payload Webhook payload.
	 * @return void
	 */
	private function handle_case_management_accept(array $payload)
	{
		$order = $this->find_order_from_payload($payload);

		if (! $order) {
			return;
		}

		if (
			! $order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD) &&
			! $order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING)
		) {
			return;
		}

		// Idempotency: skip if a capture/settlement was already recorded by a prior delivery of this webhook.
		$existing_capture_id  = $order->get_meta(VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID, true);
		$charge_captured      = $order->get_meta(VISA_ACCEPTANCE_CHARGE_CAPTURED, true);
		$accept_already_ran   = ('yes' === $order->get_meta('_vas_dm_accept_processed', true));
		if (
			$accept_already_ran ||
			(! empty($existing_capture_id) && in_array($charge_captured, array(VISA_ACCEPTANCE_YES, 'pending'), true))
		) {
			return;
		}

		// Stamp immediately so a concurrent or retried delivery is blocked even if accept_review() is slow.
		$order->update_meta_data('_vas_dm_accept_processed', 'yes');
		$order->save();

		$this->accept_review($order, $payload);
	}

	/**
	 * Handles risk.casemanagement.decision.reject: case manager rejected in EBC.
	 *
	 * @param array $payload Webhook payload.
	 * @return void
	 */
	private function handle_case_management_reject(array $payload)
	{
		$order = $this->find_order_from_payload($payload);

		if (
			$order &&
			($order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD) ||
				$order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING))
		) {

			$this->reject_review($order, $payload);
		}
	}

	/**
	 * Processes an accepted fraud review: moves order to processing.
	 *
	 * @param \WC_Order $order   Order object.
	 * @param array     $payload Webhook payload (may contain capture transaction data).
	 * @return void
	 */
	private function accept_review(\WC_Order $order, array $payload)
	{
		$payment_gateway_id = $order->get_payment_method(VISA_ACCEPTANCE_EDIT);
		$is_charge          = VISA_ACCEPTANCE_YES === $order->get_meta(VISA_ACCEPTANCE_DM_REVIEW_IS_CHARGE_META);
		$is_echeck          = (VISA_ACCEPTANCE_CHECK === $order->get_meta('_vas_payment_type'));
		$event_data      = $payload['payload']['data'] ?? $payload['payload'] ?? array();
		$reviewer_label  = $event_data['reviewerDecision'] ?? $event_data['applicationInformation']['reasonCodes'][VISA_ACCEPTANCE_VAL_ZERO] ?? 'ACCEPT';
		/* translators: %s: reviewer decision code from CyberSource, e.g. ACCEPT */
		$message = sprintf(__('Order Accepted via Fraud Management webhook. Reviewer decision: %s.', 'visa-acceptance-solutions'), esc_html($reviewer_label));

		$webhook_data = $payload['payload']['data'] ?? array();
		$payment_acceptance_service = $this->get_order_meta($order, VISA_ACCEPTANCE_PAYMENT_ACCEPTANCE_SERVICE);

		// Attempt to extract capture transaction ID from webhook data.
		$capture_transaction_id = $webhook_data['captureTransactionId'] ?? null;

		// Try extracting from embedded capture data (v2 webhook format).
		if (! $capture_transaction_id && isset($webhook_data['_embedded']['capture']['_links']['self']['href'])) {
		$capture_href = $webhook_data['_embedded']['capture']['_links']['self']['href'];
		$href_parts = explode('/', $capture_href);
		$capture_transaction_id = end($href_parts);
		}

		// Fallback to webhook ID (for sale/charge transactions).
		if (! $capture_transaction_id && ($is_charge || $is_echeck)) {
		$capture_transaction_id = $webhook_data['id'] ?? null;
		}

		// Check if webhook contains capture data (applies to SALE or AUTH-ONLY with settlement).
		$has_capture_data = ! empty($capture_transaction_id);

		// Additional check: look for settlement indication in webhook data.
		// EBC may send settlement details in different structures depending on payment type preference.
		$settlement_enabled = false;
		if (isset($webhook_data['processorInformation']['approvalCode']) && ! empty($webhook_data['processorInformation']['approvalCode'])) {
		// If we have an approval code in the acceptance webhook, settlement was enabled.
		$settlement_enabled = true;
		}
		if (isset($webhook_data['orderInformation']['amountDetails']['settledAmount'])) {
		// Explicit settled amount indicates settlement occurred.
		$settlement_enabled = true;
		}
		if ($has_capture_data || $settlement_enabled) {
		// Handle capture/settlement (works for both SALE and AUTH-ONLY transactions).
		$actual_captured_amount = null;
		$is_partial_capture = false;
		if (! empty($capture_transaction_id)) {
			// Fetch actual capture details from CyberSource API.
			$transaction_details = $this->get_capture_transaction_details($capture_transaction_id);

			if ($transaction_details) {
			$actual_captured_amount = $transaction_details['amount'] ?? null;
			}
		}
		if (null === $actual_captured_amount && isset($webhook_data['orderInformation']['amountDetails']['settledAmount'])) {
			$actual_captured_amount = $webhook_data['orderInformation']['amountDetails']['settledAmount'];
		}

		// Compare captured amount with authorized amount.
		if (null !== $actual_captured_amount && (float) $actual_captured_amount < (float) $order->get_total()) {
			$is_partial_capture = true;
			$message = sprintf(
			/* translators: 1: captured amount, 2: original amount, 3: reviewer decision */
			__('Partial Capture: %1$s of %2$s accepted via Fraud Management webhook. Reviewer decision: %3$s.', 'visa-acceptance-solutions'),
			wp_kses_post(wc_price($actual_captured_amount, array('currency' => $order->get_currency()))),
			wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))),
			esc_html($reviewer_label)
			);
		}
		$amount_unknown = (null === $actual_captured_amount);

		if (! empty($capture_transaction_id)) {
			$this->update_order_meta_from_webhook($order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID, $capture_transaction_id, $payment_gateway_id);
		}
		if (! $amount_unknown) {
			$this->update_order_meta_from_webhook($order, VISA_ACCEPTANCE_CAPTURE_TOTAL, $actual_captured_amount, $payment_gateway_id);
		}

		// If the amount couldn't be confirmed at all, CyberSource may still be processing —
		// block manual capture. Move to processing so the Capture button is disabled by status.
		if ($amount_unknown) {
			$this->update_order_meta_from_webhook($order, VISA_ACCEPTANCE_CHARGE_CAPTURED, 'pending', $payment_gateway_id);
			$this->update_order_meta_from_webhook($order, '_vas_capture_status', 'PENDING', $payment_gateway_id);
			$pending_message = sprintf(
			/* translators: 1: reviewer decision code, 2: capture transaction ID */
			__('Order accepted via Fraud Management webhook (Reviewer decision: %1$s). CyberSource capture/settlement pending (ID: %2$s) — manual capture disabled.', 'visa-acceptance-solutions'),
			esc_html($reviewer_label),
			esc_html($capture_transaction_id ?? '')
			);
			// Use processing (not on-hold) so the admin Capture button is disabled by status.
			$order->update_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, $pending_message);
		} elseif ($is_partial_capture) {
			$this->add_partial_capture_history_entry($order, $capture_transaction_id, $actual_captured_amount);
			$this->update_order_meta_from_webhook($order, '_vas_partial_capture', VISA_ACCEPTANCE_YES, $payment_gateway_id);
			$this->update_order_meta_from_webhook($order, '_vas_original_amount', $order->get_total(), $payment_gateway_id);

			// Keep the order actionable (on-hold, not processing) so admins can capture the remainder.
			$order->update_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD, $message);
		} else {
			// Capture/settlement is completed in full.
			$this->update_order_meta_from_webhook($order, VISA_ACCEPTANCE_CHARGE_CAPTURED, VISA_ACCEPTANCE_YES, $payment_gateway_id);

			// Move to processing - settlement is done, no need for manual capture.
			$order->update_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, $message);
		}
		} elseif ($is_charge || $is_echeck) {
		// Sale/charge transaction but no capture data in webhook (shouldn't happen, but handle gracefully).
		$this->update_order_meta_from_webhook($order, VISA_ACCEPTANCE_CHARGE_CAPTURED, VISA_ACCEPTANCE_YES, $payment_gateway_id);
		$order->update_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, $message);
		} elseif (VISA_ACCEPTANCE_STRING_EMPTY !== $payment_acceptance_service) {
		// Auth-only transaction without settlement - keep on hold for manual capture.
		$order->update_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD, $message);
		} else {
		// Fallback: no capture data, not a charge, and no payment acceptance service - keep on hold.
		$order->update_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD, $message);
		}
		$this->gateway->add_logs_data(
		array('Order #' . $order->get_id() . ' accepted via CASE_MANAGEMENT_UPDATE webhook.'),
		false,
		'Fraud Management Webhook'
		);
	}

	/**
	 * Adds an entry to the partial capture history for the given order.
	 *
	 * @param \WC_Order $order The WooCommerce order object.
	 * @param string    $transaction_id The transaction ID of the partial capture.
	 * @param float     $amount The amount captured in this partial capture.
	 */
	private function add_partial_capture_history_entry(\WC_Order $order, $transaction_id, $amount)
	{
		$capture_history = $order->get_meta('_vas_partial_captures', true);
		$capture_history = is_array($capture_history) ? $capture_history : array();
		$capture_history[] = array(
		'sequence' => count($capture_history) + 1,
		'transaction_id' => $transaction_id,
		'amount' => (float) $amount,
		'currency' => $order->get_currency(),
		'items' => array(),
		'items_untracked' => true,
		'shipping' => false,
		'status' => 'COMPLETED',
		'date' => current_time('mysql'),
		'source' => 'ebc_webhook',
		);
		$order->update_meta_data('_vas_partial_captures', $capture_history);
		$order->save();
	}
 
	
	/**
	 * Fetches capture transaction details from CyberSource using the SDK.
	 *
	 * @param string $transaction_id Capture transaction ID.
	 * @return array|null Array with 'amount' and 'status', or null on failure.
	 */
	private function get_capture_transaction_details($transaction_id)
	{
		$max_attempts   = 3;
		$retry_delay_us = 1500000; // 1.5 seconds.

		for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
		try {
			$payment_adapter = new Visa_Acceptance_Payment_Adapter($this->gateway);
			$response = $payment_adapter->get_transaction_details_sdk($transaction_id);

			$http_code = isset($response['http_code']) ? (int) $response['http_code'] : VISA_ACCEPTANCE_VAL_ZERO;

			if (VISA_ACCEPTANCE_TWO_ZERO_ZERO === $http_code) {
			$body = $response['body'];
			if (is_object($body) && method_exists($body, 'swaggerTypes')) {
				$body = \Pymt_Vas\Dependencies\CyberSource\ObjectSerializer::sanitizeForSerialization($body);
			}
			$transaction_data = is_string($body) ? json_decode($body, true) : json_decode(wp_json_encode($body), true);

			if (isset($transaction_data['orderInformation']['amountDetails']['totalAmount'])) {
			$actual_amount = $transaction_data['orderInformation']['amountDetails']['totalAmount'];
			$status = $transaction_data['status'] ?? null;

			return array(
			'amount' => $actual_amount,
			'status' => $status,
			);
			}

			// 200 response but the expected amount field is missing from the body.
			$this->gateway->add_logs_data(
			array(
			'Transaction Details API returned 200 but no totalAmount field',
			'transaction_id' => $transaction_id,
			'body' => $transaction_data,
			'attempt' => $attempt,
			),
			false,
			'Fraud Management Webhook Transaction Details',
			true
			);
			} else {
			$this->gateway->add_logs_data(
			array(
			'Transaction Details API Failed',
			'transaction_id' => $transaction_id,
			'http_code' => $response['http_code'] ?? 'unknown',
			'body' => $response['body'] ?? 'no response',
			'attempt' => $attempt,
			),
			false,
			'Fraud Management Webhook Transaction Details',
			true
			);

			// Only 404 ("not indexed yet") is worth retrying; other errors (auth, 5xx, etc.)
			// won't resolve themselves within the lifetime of this webhook request.
			if (404 !== $http_code || $attempt === $max_attempts) {
			return null;
			}

			usleep($retry_delay_us);
			continue;
			}
		} catch (\Exception $e) {
			$this->gateway->add_logs_data(
			array(
			'Transaction Details Exception',
			'transaction_id' => $transaction_id,
			'error' => $e->getMessage(),
			'attempt' => $attempt,
			),
			false,
			'Fraud Management Webhook Transaction Details',
			true
			);
			return null;
		}

		// 200 response but missing the expected amount field - not something retrying will fix.
		return null;
		}

		return null;
 	}

	/**
	 * Processes a rejected fraud review: cancels the order and triggers reversal or refund.
	 *
	 * @param \WC_Order $order   Order object.
	 * @param array     $payload Webhook payload (used to read reviewerDecision label).
	 * @return void
	 */
	private function reject_review(\WC_Order $order, array $payload = array())
	{
		$event_data     = $payload['payload']['data'] ?? $payload['payload'] ?? array();
		$reviewer_label = $event_data['reviewerDecision'] ?? $event_data['applicationInformation']['reasonCodes'][VISA_ACCEPTANCE_VAL_ZERO] ?? 'REJECT';
		/* translators: %s: reviewer decision code from CyberSource, e.g. REJECT or REJECTED_REVIEW */
		$message = sprintf(__('Order cancelled: Fraud Management review decision %s via webhook notification.', 'visa-acceptance-solutions'), esc_html($reviewer_label));
		$order->update_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED, $message);

		$is_charge = VISA_ACCEPTANCE_YES === $order->get_meta(VISA_ACCEPTANCE_DM_REVIEW_IS_CHARGE_META);
		$is_echeck = (VISA_ACCEPTANCE_CHECK === $order->get_meta('_vas_payment_type'));

		try {
			if ($is_charge || $is_echeck) {
				// Sale/charge transaction: issue a refund.
				$refund = new Visa_Acceptance_Refund($this->gateway);
				$refund->do_refund($order, $order->get_total(), 'Rejected by Fraud Management');
			} else {
				// Auth-only transaction: void/auth-reversal.
				$auth_reversal = new Visa_Acceptance_Auth_Reversal($this->gateway);
				$auth_reversal->process_void($order, $order->get_total(), 'Rejected by Fraud Management');
			}
		} catch (\Exception $e) {
			$this->gateway->add_logs_data(
				array('Reversal/refund failed for order #' . $order->get_id() . ': ' . $e->getMessage()),
				false,
				'Fraud Management Webhook',
				true
			);
		}

		$this->gateway->add_logs_data(
			array('Order #' . $order->get_id() . ' rejected and cancelled via CASE_MANAGEMENT_UPDATE webhook.'),
			false,
			'Fraud Management Webhook'
		);
	}


	/**
	 * Processes an APM webhook event and updates the WooCommerce order status accordingly.
	 *
	 * @param array $payload Decoded payload.
	 * @return void
	 */
	/**
	 * Processes an APM webhook event.
	 *
	 * @param array $payload Decoded payload.
	 * @return void
	 */
	// private function process_apm_event(array $payload)
	// {
	// 	// Skip test pings.
	// 	if (isset($payload['payload']['message']) && ! isset($payload['payload']['transactionResult']) && ! isset	($payload['payload']['data'])) {
	// 		$this->gateway->add_logs_data(array('APM webhook test ping received.'), true, 'APM Webhook');
	// 		return;
	// 	}
	// 	$payload_items = $payload['payload'] ?? array();
	// 	// Support both object-style (associative array) and array[VISA_ACCEPTANCE_VAL_ZERO] style.
	// 	if (isset($payload_items[VISA_ACCEPTANCE_VAL_ZERO]) && is_array($payload_items[VISA_ACCEPTANCE_VAL_ZERO])) {
	// 		$payload_items = $payload_items[VISA_ACCEPTANCE_VAL_ZERO];
	// 	}
	// 	$event_data = isset($payload_items['data']) && is_array($payload_items['data']) ? $payload_items['data'] : $payload_items;

	// 	$tx_id  = $event_data['id'] ?? $event_data['requestId'] ?? null;
	// 	$ref    = $event_data['clientReferenceInformation']['code'] ?? null;
	// 	$status = strtoupper((string) ($event_data['status'] ?? VISA_ACCEPTANCE_STRING_EMPTY));
	// 	// outcome is a secondary fallback some APM methods populate instead of status.
	// 	$outcome = strtoupper((string) ($event_data['outcome'] ?? VISA_ACCEPTANCE_STRING_EMPTY));
	// 	// Use outcome as fallback when status is empty.
	// 	if (VISA_ACCEPTANCE_STRING_EMPTY === $status && VISA_ACCEPTANCE_STRING_EMPTY !== $outcome) {
	// 		$status = $outcome;
	// 	}

	// 	if (empty($tx_id) && empty($ref)) {
	// 		$this->gateway->add_logs_data(array('APM webhook: no transaction ID or reference in payload.'), false, 'APM Webhook', true);
	// 		return;
	// 	}

	// 	$order = $this->find_order($tx_id, $ref);
	// 	if (! $order) {
	// 		$this->gateway->add_logs_data(array('APM webhook: order not found for tx ' . $tx_id . ' ref ' . $ref), true, 'APM Webhook');
	// 		return;
	// 	}

	// 	if ('COMPLETED' === $status) {
	// 		$allowed_statuses = array(
	// 			VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING,
	// 			VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD,
	// 			VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING
	// 		);

	// 		if (in_array($order->get_status(), $allowed_statuses, true)) {
	// 			$previous_status = $order->get_status();

	// 			$order->update_status(
	// 				VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING,
	// 				sprintf(
	// 					/* translators: 1: transaction ID 2: previous status */
	// 					__('APM webhook: payment COMPLETED. Transaction ID: %1$s (updated from %2$s)', 'visa-acceptance-solutions'),
	// 					esc_html($tx_id ?? VISA_ACCEPTANCE_STRING_EMPTY),
	// 					esc_html($previous_status)
	// 				)
	// 			);

	// 		} else {
	// 			$order->add_order_note(
	// 				sprintf(
	// 					/* translators: %s: transaction ID */
	// 					__('APM webhook: payment COMPLETED. Transaction ID: %s (order status not updated - already in final state)', 'visa-acceptance-solutions'),
	// 					esc_html($tx_id ?? VISA_ACCEPTANCE_STRING_EMPTY)
	// 				)
	// 			);
	// 		}
	// 	} elseif (in_array($status, array('PENDING', 'AUTHORIZED', 'PENDING_REVIEW', 'AUTHORIZED_PENDING_REVIEW'), true)) {
	// 		$order->add_order_note(
	// 			sprintf(
	// 				/* translators: %s: status */
	// 				__('APM webhook: payment status %s - awaiting final confirmation.', 'visa-acceptance-solutions'),
	// 				esc_html($status)
	// 			)
	// 		);
	// 	} elseif (! $order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED)) {
	// 		// All other statuses (DECLINED, FAILED, ERROR, etc.) are treated as failures.
	// 		$order->update_status(
	// 			VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED,
	// 			sprintf(
	// 				/* translators: %s: status */
	// 				__('APM webhook: payment failed with status %s.', 'visa-acceptance-solutions'),
	// 				esc_html($status)
	// 			)
	// 		);
	// 	}

	// 	// Store/update transaction ID.
	// 	if ($tx_id) {
	// 		$existing_tx_id = $this->get_order_meta($order, VISA_ACCEPTANCE_TRANSACTION_ID);

	// 		if (! empty($existing_tx_id) && $existing_tx_id !== $tx_id) {
	// 			// Store previous transaction ID for reference.
	// 			$this->add_order_meta($order, '_visa_acceptance_previous_transaction_id', $existing_tx_id);
	// 		}

	// 		$this->update_order_meta($order, VISA_ACCEPTANCE_TRANSACTION_ID, $tx_id);
	// 	}

	// 	// Store the CyberSource status for future comparison.
	// 	$this->update_order_meta($order, '_visa_acceptance_cybersource_status', $status);

	// }

	/**
	 * Decodes the raw webhook request body.
	 *
	 * Used by both UC and APM webhooks (each with their own private key option).
	 *
	 * @param string $raw_body        Raw POST body.
	 * @param string $private_key_opt WP option name for the RSA private key PEM.
	 * @return array|null
	 */
	private function decode_payload($raw_body, $private_key_opt = VISA_ACCEPTANCE_UC_WEBHOOK_PRIVATE_KEY_OPTION)
	{
		$trimmed_body = trim($raw_body);
		$dot_count = substr_count($trimmed_body, VISA_ACCEPTANCE_FULL_STOP);
		$first_char = $trimmed_body[VISA_ACCEPTANCE_VAL_ZERO] ?? VISA_ACCEPTANCE_STRING_EMPTY;
		$is_json = ('{' === $first_char || '[' === $first_char);

		// Compact JWE: 4 dots AND doesn't start with JSON bracket.
		$is_jwe = (VISA_ACCEPTANCE_VAL_FOUR === $dot_count && ! $is_json);

		if ($is_jwe) {
			$jwe_result = $this->decrypt_jwe($trimmed_body, $private_key_opt);
			if (null === $jwe_result) {
				$this->gateway->add_logs_data(array('Compact JWE decryption failed'), false, 'UC Webhook', true);
			} else {
				$this->gateway->add_logs_data(
					array(
						'Webhook decryption successful',
						'eventType' => $jwe_result['eventType'] ?? 'unknown',
					),
					false,
					'UC Webhook'
				);
			}
			return $jwe_result;
		}

		$decoded = json_decode($raw_body, true);
		if (is_array($decoded)) {
			// Check for two-layer encryption: {"encData":"JWE_STRING"}.
			if (isset($decoded['encData']) && is_string($decoded['encData']) && ! empty($decoded['encData'])) {
				$enc_data = $decoded['encData'];
				// Verify encData is a JWE compact serialization (5 parts separated by dots).
				if (VISA_ACCEPTANCE_VAL_FOUR === substr_count($enc_data, VISA_ACCEPTANCE_FULL_STOP)) {
					// Use decrypt_jwe which directly extracts key from .p12 and calls JWEUtility.
					$decrypted_payload = $this->decrypt_jwe($enc_data, $private_key_opt);
				} else {
					$this->gateway->add_logs_data(array('Encrypted payload detected (encData non-JWE) - attempting MLE decryption...'), false, 'UC Webhook');
					$decrypted_payload = $this->decrypt_mle_payload($enc_data, $private_key_opt);
				}
				$this->gateway->add_logs_data(
					array(
						'Webhook decryption successful',
						'eventType' => isset($decrypted_payload['eventType']) ? $decrypted_payload['eventType'] : 'unknown'
					),
					false,
					'UC Webhook'
				);
				return $decrypted_payload;
			}
			return $decoded;
		} else {
			$this->gateway->add_logs_data(
				array(
					'Plain JSON decode failed',
					'json_error' => json_last_error_msg()
				),
				false,
				'UC Webhook',
				true
			);
			return null;
		}
	}

	/**
	 * Decrypts a JWE compact serialization using CyberSource SDK.
	 *
	 * @param string $jwe_input JWE string or JSON envelope with encryptedResponse field.
	 * @param string $private_key_opt  WP option name for the RSA private key file path.
	 * @return array|null
	 */
	private function decrypt_jwe($jwe_input, $private_key_opt)
	{
		// Check if input is JSON envelope with encryptedResponse or encData field (MLEUtility format).
		$jwe_compact = $jwe_input;
		$trimmed_input = trim($jwe_input);

		if (! empty($trimmed_input) && '{' === $trimmed_input[0]) {
			$decoded = json_decode($trimmed_input, true);

			// Check for encryptedResponse field (standard MLEUtility format).
			if (is_array($decoded) && isset($decoded['encryptedResponse']) && is_string($decoded['encryptedResponse'])) {
				$jwe_compact = $decoded['encryptedResponse'];
			} elseif (is_array($decoded) && isset($decoded['encData']) && is_string($decoded['encData'])) {
				// Check for encData field (alternative format used by some webhooks).
				$jwe_compact = $decoded['encData'];
			}
		}

		// Verify compact JWE format (exactly 4 dots = 5 parts).
		if (! is_string($jwe_compact) || 4 !== substr_count($jwe_compact, VISA_ACCEPTANCE_FULL_STOP)) {
			return null;
		}
		$private_key_pem = $this->load_webhook_private_key($private_key_opt);
		if (null === $private_key_pem) {
			return null;
		}

		// Parse JWE header for informational logging only.
		$parts      = explode(VISA_ACCEPTANCE_FULL_STOP, $jwe_compact);
		$header_b64 = $parts[VISA_ACCEPTANCE_VAL_ZERO] ?? VISA_ACCEPTANCE_STRING_EMPTY;
		$header     = json_decode($this->base64url_decode($header_b64), true);
		$alg        = $header['alg'] ?? 'RSA-OAEP';
		$kid        = $header['kid'] ?? null;  // null when JWE has no kid field.
		$kid_log    = $kid ?? '(none)';        // Display-only fallback for logs.

		$this->gateway->add_logs_data(
			array(
				'Webhook JWE Header',
				'algorithm'  => $alg,
				'kid'        => $kid_log,
				'jwe_length' => strlen($jwe_compact),
			),
			false,
			'UC Webhook'
		);

		// Check if OpenSSL extension is loaded.
		if (! extension_loaded('openssl')) {
			$this->gateway->add_logs_data(
				array(
					'FATAL: OpenSSL extension is not loaded',
					'required_for' => 'Private key validation and JWE decryption',
					'check_phpinfo' => 'Run phpinfo() to verify OpenSSL is enabled',
					'solution'      => 'Enable OpenSSL extension in php.ini',
				),
				false,
				'UC Webhook',
				true
			);
			return null;
		}

		// Delegate to the CyberSource SDK's own JWE utility (the same vendored class used
		// by the official CyberSource PrestaShop module) instead of a custom implementation.
		$decrypted_payload = null;
		try {
			$decrypted_payload = \Pymt_Vas\Dependencies\CyberSource\Authentication\Util\JWE\JWEUtility::decryptJWEUsingPrivateKey(
				$private_key_pem,
				trim((string) $jwe_compact)
			);
		} catch (\Throwable $e) {
			$this->gateway->add_logs_data(
				array('Webhook JWE: decryption exception - ' . get_class($e) . ': ' . $e->getMessage()),
				false,
				'UC Webhook',
				true
			);
		}
		if (empty($decrypted_payload)) {
			return null;
		}

		$decoded = json_decode($decrypted_payload, true);
		if (! is_array($decoded)) {
			$this->gateway->add_logs_data(
				array(
					'Webhook JWE: decrypted payload is not valid JSON',
					'payload' => $decrypted_payload,
				),
				false,
				'UC Webhook',
				true
			);
			return null;
		}
		return $decoded;
	}

	/**
	 * Decrypt MLE payload using MLEUtility
	 *
	 * @param string $enc_data The encData JWE string.
	 * @param string $private_key_option WordPress option name for private key file path.
	 * @return array|null Decrypted payload as array, or null on failure.
	 */
	private function decrypt_mle_payload($enc_data, $private_key_option)
	{
		// Try stored private key path first, fall back to .p12 MLE certificate.
		$private_key_path = get_option($private_key_option, VISA_ACCEPTANCE_STRING_EMPTY);

		if (empty($private_key_path) || ! file_exists($private_key_path)) {
			// Fall back to .p12 MLE certificate from gateway settings.
			$settings     = $this->gateway->get_gateway_settings();
			$p12_path     = $settings['mle_certificate_path'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
			$p12_password = $settings['mle_key_password'] ?? VISA_ACCEPTANCE_STRING_EMPTY;

			if (empty($p12_path) || ! file_exists($p12_path)) {
				$this->gateway->add_logs_data(
					array('MLE decryption: no private key path stored and no .p12 MLE certificate configured.'),
					false,
					'UC Webhook',
					true
				);
				return null;
			}

			// Use .p12 via MLEUtility (it natively supports .p12 files).
			$private_key_path = $p12_path;
		}

		try {
			// Create MerchantConfiguration.
			$merchant_config = new \Pymt_Vas\Dependencies\CyberSource\Authentication\Core\MerchantConfiguration();
			$merchant_config->setResponseMlePrivateKeyFilePath($private_key_path);

			// Set .p12 password if available from gateway settings.
			$settings     = $this->gateway->get_gateway_settings();
			$p12_password = $settings['mle_key_password'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
			if (! empty($p12_password)) {
				$merchant_config->setResponseMlePrivateKeyFilePassword($p12_password);
			}

			// Wrap encData in expected format: {"encryptedResponse": "..."}.
			$mle_response_body = wp_json_encode(array('encryptedResponse' => $enc_data));

			// Call MLEUtility.
			$decrypted_json = \Pymt_Vas\Dependencies\CyberSource\Authentication\Util\MLEUtility::decryptMleResponsePayload(
				$merchant_config,
				$mle_response_body
			);

			if (empty($decrypted_json)) {
				$this->gateway->add_logs_data(
					array('MLEUtility returned empty response'),
					false,
					'UC Webhook',
					true
				);
				return null;
			}

			// Decode the JSON response.
			$decrypted_array = json_decode($decrypted_json, true);

			if (! is_array($decrypted_array)) {
				return null;
			}

			return $decrypted_array;
		} catch (\Pymt_Vas\Dependencies\CyberSource\Authentication\Util\MLEException $e) {
			$this->gateway->add_logs_data(
				array(
					'MLEException',
					'message' => $e->getMessage(),
					'code' => $e->getCode(),
					'trace' => $e->getTraceAsString()
				),
				false,
				'UC Webhook',
				true
			);
			return null;
		} catch (\Exception $e) {
			$this->gateway->add_logs_data(
				array(
					'Exception during MLE decryption',
					'type' => get_class($e),
					'message' => $e->getMessage(),
					'code' => $e->getCode()
				),
				false,
				'UC Webhook Response',
				true
			);
			return null;
		}
	}

	/**
	 * URL-safe base64 decode.
	 *
	 * @param string $input URL-safe base64 string.
	 * @return string|false
	 */
	private function base64url_decode($input)
	{
		$remainder = strlen($input) % VISA_ACCEPTANCE_VAL_FOUR;
		if ($remainder) {
			$input .= str_repeat('=', VISA_ACCEPTANCE_VAL_FOUR - $remainder);
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return base64_decode(strtr($input, '-_', '+/'));
	}

	/**
	 * Processes the uc.orders.transactionresults event.
	 *
	 * @param array $payload Decoded payload.
	 */
	private function process_uc_event(array $payload)
	{

		if (isset($payload['payload']['message']) && ! isset($payload['payload']['transactionResult'])) {
			$this->gateway->add_logs_data(array('UC Response Webhook test ping received.'), false, 'UC Response Webhook');
			return;
		}

		$transaction_result = $payload['payload']['transactionResult'] ?? null;

		if (! $transaction_result) {
			return;
		}

		$details = $transaction_result['details'] ?? array();
		$status  = strtoupper($transaction_result['status'] ?? $details['status'] ?? VISA_ACCEPTANCE_STRING_EMPTY);
		$tx_id   = $transaction_result['id'] ?? $details['id'] ?? null;
		$ref     = $details['clientReferenceInformation']['code'] ?? null;
		// For UC, also try transactionId which may contain the actual order reference.
		$transaction_ref = $details['clientReferenceInformation']['transactionId'] ?? null;
		$order = $this->find_order($tx_id, $ref, $transaction_ref);
		if (! $order) {
			return;
		}

		// Get stored CyberSource transaction status to check if it has changed.
		$stored_cybersource_status = $this->get_order_meta($order, '_visa_acceptance_cybersource_status');

		// If status hasn't changed, skip processing.
		if (! empty($stored_cybersource_status) && $stored_cybersource_status === $status) {
			return;
		}

		$note_prefix = __('UC Response Webhook received.', 'visa-acceptance-solutions');
		$has_ics_bill   = $this->detect_ics_bill($details);
		$has_capture_link = isset($details['_links']['capture']['href']);

		// Store additional capture-related details from webhook for authorization transactions.
		if ($has_capture_link) {
			$this->store_authorization_capture_details($order, $details, $tx_id);
		} else {
			// For sale/direct capture: mark as already captured.
			$this->update_order_meta($order, VISA_ACCEPTANCE_CHARGE_CAPTURED, VISA_ACCEPTANCE_YES);
			$this->update_order_meta($order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID, $tx_id);
			$this->update_order_meta($order, VISA_ACCEPTANCE_CAPTURE_TOTAL, $order->get_total());
		}

		// Determine appropriate WooCommerce order status based on CyberSource payment status.
		$should_update = false;
		$new_status = null;
		$status_note = VISA_ACCEPTANCE_STRING_EMPTY;

		switch ($status) {
			case 'AUTHORIZED':
			case 'AUTHORIZED_PENDING_REVIEW':
			case 'AUTHORIZED_RISK_DECLINED':
				if ($order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING)) {
					$new_status  = $has_ics_bill ? VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING : VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD;
					$action_type = $has_ics_bill ? 'CAPTURE' : 'AUTH';

					/* translators: 1: note prefix 2: status 3: payment action type (AUTH or CAPTURE) */
					$status_note = sprintf(__('%1$s Status: %2$s via UC response webhook. Payment Action: %3$s', 'visa-acceptance-solutions'), $note_prefix, $status, $action_type);
					$should_update = true;
				} else {
					/* translators: 1: status 2: transaction ID */
					$status_note = sprintf(__('UC response webhook: %1$s confirmed. Transaction ID: %2$s', 'visa-acceptance-solutions'), esc_html($status), esc_html($tx_id ?? VISA_ACCEPTANCE_STRING_EMPTY));
					$should_update = false;
				}
				break;

			case 'PENDING':
			case 'PENDING_REVIEW':
			case 'SETTLE_INITIATED':
				if ($order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING)) {
					$new_status = VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD;
					/* translators: %s: status */
					$status_note = sprintf(__('UC response webhook: payment %s — awaiting external confirmation.', 'visa-acceptance-solutions'), esc_html($status));
					$should_update = true;
				} else {
					/* translators: 1: status 2: transaction ID */
					$status_note = sprintf(__('UC response webhook: payment status %1$s. Transaction ID: %2$s', 'visa-acceptance-solutions'), esc_html($status), esc_html($tx_id ?? VISA_ACCEPTANCE_STRING_EMPTY));
					$should_update = false;
				}
				break;

			case 'COMPLETED':
				if (! $order->has_status(VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING)) {
					$new_status = VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING;
					$status_note = __('UC response webhook: payment COMPLETED.', 'visa-acceptance-solutions');
					$should_update = true;
				} else {
					$status_note = __('UC response webhook: payment COMPLETED confirmed.', 'visa-acceptance-solutions');
					$should_update = false;
				}
				break;

			default:
				/* translators: %s: status string */
				$status_note = sprintf(__('UC response webhook: unhandled status %s. Order requires manual review.', 'visa-acceptance-solutions'), esc_html($status));
				$should_update = false;
				break;
		}

		// Update order status or add note.
		if ($should_update && $new_status) {
			$order->update_status($new_status, $status_note);
		} else {
			$order->add_order_note($status_note);
		}

		// Store/update transaction ID - always update to ensure we have the latest.
		if ($tx_id) {
			$existing_tx_id = $this->get_order_meta($order, VISA_ACCEPTANCE_TRANSACTION_ID);

			// If transaction ID changed, log it for tracking.
			if (! empty($existing_tx_id) && $existing_tx_id !== $tx_id) {
				$this->add_order_meta($order, '_visa_acceptance_previous_transaction_id', $existing_tx_id);
			}
			$this->update_order_meta($order, VISA_ACCEPTANCE_TRANSACTION_ID, $tx_id);
		}

		$this->update_order_meta($order, '_visa_acceptance_cybersource_status', $status);
	}

	/**
	 * Loads the private key PEM for webhook decryption.
	 *
	 * @param string $private_key_opt WP option name for the stored private key path.
	 * @return string|null Normalized PEM private key string, or null on failure.
	 */
	private function load_webhook_private_key($private_key_opt)
	{
		$private_key_path = get_option($private_key_opt, VISA_ACCEPTANCE_STRING_EMPTY);

		try {
			// 1. Stored option: file path (.p12/.pfx/.pem/.key/.p8) or direct PEM content.
			if (! empty($private_key_path)) {
				if (file_exists($private_key_path)) {
					$this->gateway->add_logs_data(array('Webhook JWE: loading private key from stored file path.'), false, 'UC Webhook');
					return \Pymt_Vas\Dependencies\CyberSource\Authentication\Util\Utility::loadResponseMlePrivateKey($private_key_path, $this->get_p12_password());
				}
				if (strpos($private_key_path, '-----BEGIN') !== false) {
					$this->gateway->add_logs_data(array('Webhook JWE: normalizing stored PEM content (' . strlen($private_key_path) . ' bytes)'), false, 'UC Webhook');
					return \Pymt_Vas\Dependencies\CyberSource\Authentication\Util\Utility::normalizeUnencryptedPem($private_key_path, $private_key_opt);
				}
			}

			// 2. Fall back to the .p12 MLE certificate configured in gateway admin settings.
			$settings     = $this->gateway->get_gateway_settings();
			$p12_path     = $settings['mle_certificate_path'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
			$p12_password = $settings['mle_key_password'] ?? VISA_ACCEPTANCE_STRING_EMPTY;

			if (empty($p12_path) || ! file_exists($p12_path)) {
				$this->gateway->add_logs_data(array('Webhook JWE: no private key available — stored option empty and no .p12 MLE certificate configured.'), false, 'UC Webhook', true);
				return null;
			}

			$this->gateway->add_logs_data(array('Webhook JWE: falling back to .p12 MLE certificate from gateway settings.'), false, 'UC Webhook');
			return \Pymt_Vas\Dependencies\CyberSource\Authentication\Util\Utility::loadResponseMlePrivateKey($p12_path, $p12_password);
		} catch (\Exception $e) {
			$this->gateway->add_logs_data(array('Webhook JWE: private key load failed - ' . $e->getMessage()), false, 'UC Webhook', true);
			return null;
		}
	}

	/**
	 * Gets the .p12 password from gateway settings.
	 *
	 * @return string Password string (may be empty).
	 */
	private function get_p12_password()
	{
		$settings = $this->gateway->get_gateway_settings();
		return $settings['mle_key_password'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
	}

	/**
	 * Stores authorization and capture details from the webhook payload into order meta for later use.
	 *
	 * @param \WC_Order $order   WooCommerce order object.
	 * @param array     $details Webhook transaction details.
	 * @param string    $tx_id   Transaction ID.
	 * @return void
	 */
	private function store_authorization_capture_details($order, $details, $tx_id)
	{
		$amount_details = $details['orderInformation']['amountDetails'] ?? array();
		$authorized_amount = $amount_details['authorizedAmount'] ?? $amount_details['totalAmount'] ?? $order->get_total();
		$currency = $amount_details['currency'] ?? $order->get_currency();

		// Store authorization details for later capture.
		$this->update_order_meta($order, '_visa_acceptance_auth_amount', $authorized_amount);
		$this->update_order_meta($order, '_visa_acceptance_auth_currency', $currency);
		$this->update_order_meta($order, '_visa_acceptance_auth_transaction_id', $tx_id);
		$this->update_order_meta($order, VISA_ACCEPTANCE_CHARGE_CAPTURED, 'no');

		// Store capture link if available.
		if (isset($details['_links']['capture']['href'])) {
			$this->update_order_meta($order, '_visa_acceptance_capture_link', $details['_links']['capture']['href']);
		}

		// Store processor information for reference.
		if (isset($details['processorInformation'])) {
			$proc_info = $details['processorInformation'];
			if (! empty($proc_info['approvalCode'])) {
				$this->update_order_meta($order, '_visa_acceptance_approval_code', $proc_info['approvalCode']);
			}
			if (! empty($proc_info['networkTransactionId'])) {
				$this->update_order_meta($order, '_visa_acceptance_network_transaction_id', $proc_info['networkTransactionId']);
			}
		}
	}

	/**
	 * Finds the WooCommerce order based on the decoded webhook payload.
	 *
	 * @param array $payload Decoded webhook payload.
	 * @return \WC_Order|null
	 */
	private function find_order_from_payload(array $payload)
	{
		$inner      = $payload['payload'] ?? array();
		$data       = $inner['data'] ?? array();
		$request_id = $data['id'] ?? $inner['id'] ?? $data['requestId'] ?? $inner['requestId'] ?? null;
		$ref_number = $data['referenceNumber'] ?? $inner['referenceNumber'] ?? $data['merchantReferenceCode'] ?? $inner['merchantReferenceCode'] ?? null;

		if (! $request_id && ! $ref_number && isset($inner['message']) && str_contains((string) $inner['message'], 'test')) {
			$this->gateway->add_logs_data(array('EBC test ping received — skipping order lookup.'), false, 'Fraud Management Webhook');
			return null;
		}

		return $this->find_order($request_id, $ref_number);
	}

	/**
	 * Finds the WooCommerce order based on various identifiers.
	 *
	 * @param string|null $tx_id         CyberSource transaction / payment ID.
	 * @param string|null $ref           clientReferenceInformation.code (WC order ID).
	 * @param string|null $transaction_ref Optional transactionId field (UC-specific).
	 * @return \WC_Order|null
	 */
	private function find_order($tx_id, $ref, $transaction_ref = null)
	{
		// Sanitize inputs.
		$tx_id           = $tx_id ? sanitize_text_field($tx_id) : null;
		$ref             = $ref ? sanitize_text_field($ref) : null;
		$transaction_ref = $transaction_ref ? sanitize_text_field($transaction_ref) : null;

		// Try by numeric order ID first (fastest lookup).
		if ($ref && is_numeric($ref)) {
			$order = wc_get_order((int) $ref);
			if ($order instanceof \WC_Order) {
				return $order;
			}
		}

		// Try by transaction ID meta.
		if ($tx_id) {
			$orders = wc_get_orders(array(
				'meta_key'   => VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_TRANSACTION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $tx_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'limit'      => 1,
			));
			if (! empty($orders)) {
				return $orders[VISA_ACCEPTANCE_VAL_ZERO];
			}
		}

		// Try by UC transaction ref (fallback).
		if ($transaction_ref) {
			$orders = wc_get_orders(array(
				'meta_key'   => '_visa_acceptance_uc_transaction_ref', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $transaction_ref, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'limit'      => 1,
			));
			if (! empty($orders)) {
				return $orders[VISA_ACCEPTANCE_VAL_ZERO];
			}
		}

		$this->gateway->add_logs_data(
			array('Order not found', 'tx_id' => $tx_id, 'ref' => $ref),
			false,
			'Webhook',
			true
		);

		return null;
	}

	/**
	 * Updates order meta (HPOS-compatible) with input sanitization.
	 *
	 * @param \WC_Order $order              Order object.
	 * @param string    $key                Meta key (without prefix).
	 * @param mixed     $value              Meta value.
	 * @param string    $payment_gateway_id Payment gateway ID.
	 * @return void
	 */
	private function update_order_meta_from_webhook(\WC_Order $order, $key, $value, $payment_gateway_id)
	{
		// Sanitize key and payment gateway ID.
		$key                = sanitize_key($key);
		$payment_gateway_id = sanitize_key($payment_gateway_id);
		$prefixed_key       = VISA_ACCEPTANCE_UNDERSCORE . VISA_ACCEPTANCE_WC_UNDERSCORE . $payment_gateway_id . VISA_ACCEPTANCE_UNDERSCORE . $key;

		// Sanitize value based on type.
		if (is_string($value)) {
			$value = sanitize_text_field($value);
		} elseif (is_array($value)) {
			$value = array_map('sanitize_text_field', $value);
		}

		if (visa_acceptance_handle_hpos_compatibility()) {
			$order->update_meta_data($prefixed_key, $value);
			$order->save_meta_data();
		} else {
			update_post_meta($order->get_id(), $prefixed_key, $value);
		}
	}
}
