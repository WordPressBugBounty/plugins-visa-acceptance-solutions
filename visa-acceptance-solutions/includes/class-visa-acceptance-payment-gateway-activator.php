<?php
/**
 * Fired during plugin activation
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visa Acceptance Activator Class
 *
 * Handles Plugin Activation functionality
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Payment_Gateway_Activator {

	/**
	 * Adds data to the logs
	 *
	 * @param string $data request/response data.
	 *
	 * @return void
	 */
	public function add_logs_data( $data ) {
		$logger  = wc_get_logger();
		$context = array( 'source' => 'visa-acceptance-solutions-data-migration' );
		$log     = $data;
		$logger->info( $log, $context );
	}

	/**
	 * Sets token as users default token
	 *
	 * @param string $user_id users id.
	 * @param string $token_id payment token id.
	 */
	public function set_users_default( $user_id, $token_id ) {
		$data_store   = WC_Data_Store::load( 'payment-token' );
		$users_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, VISA_ACCEPTANCE_UC_ID );
		foreach ( $users_tokens as $token ) {
			if ( $token_id === $token->get_id() ) {
				$data_store->set_default_status( $token_id, true );
			} else {
				$data_store->set_default_status( $token->get_id(), false );
			}
		}
	}

	/**
	 * Gives tokens based on user id.
	 *
	 * @param array $visa_core_tokens visa saved tokens.
	 * @return array
	 */
	public function tokens_based_user_id( $visa_core_tokens ) {
		$tokens_based_user_id = array();
		foreach ( $visa_core_tokens as $token ) {
			$token_data = $this->build_token_data( $token );
			$tokens_based_user_id[ $token->get_user_id() ][ $token_data['token_information']['payment_instrument_id'] ] = $token;
		}
		return $tokens_based_user_id;
	}

	/**
	 * Get data of token from transaction details to save
	 *
	 * @param array $tokens tokens array.
	 *
	 * @return array
	 */
	public function get_data_to_save_migrated( $tokens ) {
		$data = array();
		if ( 'amex' === strtolower( $tokens['card_type'] ) || 'jcb' === strtolower( $tokens['card_type'] ) ) {
			$tokens['card_type'] = strtoupper( ( $tokens['card_type'] ) );
		} else {
			$tokens['card_type'] = ( 'dinersclub' === strtolower( $tokens['card_type'] ) ) ? 'DinersClub' : ucfirst( strtolower( $tokens['card_type'] ) );
		}
		$token_identifier['id']                    = get_user_meta( $tokens['user_id'], 'wc_cybersource_customer_id_' . $tokens['environment'], true );
		$token_identifier['payment_instrument_id'] = $tokens['token'];
		$token_identifier['state']                 = $tokens['instrument_identifier']['state'];
		$token_identifier['new']                   = $tokens['instrument_identifier']['new'];

		$data['first_six']         = $tokens['first_six'];
		$data['last_four']         = $tokens['last4'];
		$data['token_information'] = $token_identifier;
		$data['card_type']         = $tokens['card_type'];
		$data['exp_month']         = $tokens['expiry_month'];
		$data['exp_year']          = $tokens['expiry_year'];
		$data['gateway_id']        = VISA_ACCEPTANCE_UC_ID;
		$data['user_id']           = $tokens['user_id'];
		$data['environment']       = $tokens['environment'];
		$data['default']           = false;
		$data['sv_token_id']       = $tokens['id'];
		return $data;
	}

	/**
	 * Saves token to database
	 *
	 * @param \WC_Payment_Token_CC $token_obj token object.
	 * @param array                $data data to be saved.
	 * @param array                $props properties.
	 *
	 * @return boolean
	 */
	public function save_token_to_database( $token_obj, $data, $props ) {
		$return_result = false;
		foreach ( $data as $key => $value ) {
			$core_key = array_search( $key, $props, true );

			/** \WC_Payment_Token does not define a set_is_default method */
			if ( 'is_default' === $core_key ) {
				$token_obj->set_default( $value );
			} elseif ( false !== $core_key ) {
				$token_obj->set_props( array( $core_key => $value ) );
			} else {
				$token_obj->update_meta_data( $key, $value, true );
			}
		}
		try {
			$saved_token = $token_obj->save();
			if ( $saved_token ) {
				$return_result = true;
			} else {
				$return_result = false;
			}
		} catch ( \Exception $e ) {
			$return_result = false;
			$this->add_logs_data( 'Unable to migrate card information. Please try again later.' . $e->getMessage() );
		} finally {
			$this->add_logs_data( 'Visa Acceptance Solutions Credit Card data migration process started' );
			if ( $return_result ) {
				$this->add_logs_data( 'Success: Card ' . $data['sv_token_id'] . ' migrated' );
			} else {
				$this->add_logs_data( 'Failure: Card ' . $data['sv_token_id'] . ' for customer ' . $data['user_id'] . ' migration failed.' );
			}
			$this->add_logs_data( 'Visa Acceptance Solutions Payments Credit Card data migration process completed' );
		}
		return $return_result;
	}

	/**
	 * Gets properties of card
	 *
	 * @return array
	 */
	public function get_props() {
		$props = array(
			'gateway_id'   => 'gateway_id',
			'user_id'      => 'user_id',
			'is_default'   => 'default',
			'last4'        => 'last_four',
			'expiry_year'  => 'exp_year',
			'expiry_month' => 'exp_month',
			'card_type'    => 'card_type',
		);
		return $props;
	}

	/**
	 * Builds the token data
	 *
	 * @param \WC_Payment_Token $core_token token object.
	 *
	 * @return array
	 */
	public function build_token_data( $core_token ) {
		$props           = $this->get_props();
		$data            = array();
		$core_token_data = $core_token->get_data();
		$meta_data       = $core_token_data['meta_data'];
		foreach ( $meta_data as $meta_datum ) {
			$data[ $meta_datum->key ] = $meta_datum->value;
		}
		foreach ( $core_token_data as $core_key => $value ) {
			if ( array_key_exists( $core_key, $props ) ) {
				$framework_key          = $props[ $core_key ];
				$data[ $framework_key ] = $value;
			} elseif ( ! isset( $this->data[ $core_key ] ) ) {
				$data[ $core_key ] = $value;
			}
		}
		return $data;
	}

	/**
	 * Migrates the token data accross plugin.
	 */
	public function token_migration() {
		$tokens_based_user_id = array();
		$data                 = array();
		$token_obj 			  = new \WC_Payment_Token_CC();
		try {
			$sv_core_tokens                        = \WC_Payment_Tokens::get_tokens( array( 'gateway_id' => VISA_ACCEPTANCE_SV_GATEWAY_ID ) );
			$visa_acceptance_solutions_core_tokens = \WC_Payment_Tokens::get_tokens( array( 'gateway_id' => VISA_ACCEPTANCE_UC_ID ) );
			$tokens_based_user_id                  = $this->tokens_based_user_id( $visa_acceptance_solutions_core_tokens );
			foreach ( $sv_core_tokens as $token ) {
				$return_result                   = false;
				$token_data                      = $this->build_token_data( $token );
				$data                            = $this->get_data_to_save_migrated( $token_data );
				$visa_acceptance_solutions_token = $tokens_based_user_id[ $data['user_id'] ][ $data['token_information']['payment_instrument_id'] ] ?? false;
				if ( $visa_acceptance_solutions_token instanceof \WC_Payment_Token ) {
					continue;
				} else {
					$token_obj->set_token( $token_data['instrument_identifier']['id'] );
					$props         = $this->get_props();
					$return_result = $this->save_token_to_database( $token_obj, $data, $props );
				}
				if ( $return_result && $token->is_default() ) {
						$this->set_users_default( $data['user_id'], $token_obj->get_id() );
				}
			}
		} catch ( \Exception $e ) {
			$this->add_logs_data( 'Unable to migrate card information. Please try again later.' . $e->getMessage() );
		}
	}

	/**
	 * Performs plugin activation task.
	 */
	public static function activate() {
		$wc_payment_gateway_activator = new Visa_Acceptance_Payment_Gateway_Activator();
		$wc_payment_gateway_activator->token_migration();
	}
}
