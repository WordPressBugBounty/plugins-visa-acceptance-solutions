<?php
/**
 * Settings Validator for Visa Acceptance Solutions
 *
 * Provides secure validation and sanitization for gateway settings.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visa Acceptance Settings Validator Class
 *
 * Handles validation and sanitization of gateway settings without modifying $_POST.
 */
class Visa_Acceptance_Settings_Validator {

	/**
	 * Collected errors during validation
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Validate a field based on its configuration
	 *
	 * @param string $field_name The field identifier.
	 * @param mixed  $value The value to validate.
	 * @param array  $field_config Field configuration array.
	 * @return mixed|WP_Error Sanitized value on success, WP_Error on failure.
	 */
	public function validate_field( $field_name, $value, $field_config ) {
		$sanitized = $this->sanitize_value( $value, $field_config['type'] ?? 'text' );
		if ( ! empty( $field_config['required'] ) && empty( $sanitized ) ) {
			return new WP_Error(
				'required_field',
				sprintf(
					/* translators: %s: field title */
					__( '%s is required. Please fill out this field.', 'visa-acceptance-solutions' ),
					$field_config['title'] ?? $field_name
				)
			);
		}
		if ( isset( $field_config['pattern'] ) && ! empty( $sanitized ) ) {
			if ( ! preg_match( $field_config['pattern'], $sanitized ) ) {
				return new WP_Error(
					'invalid_format',
					sprintf(
						/* translators: %s: field title */
						__( '%s contains invalid characters.', 'visa-acceptance-solutions' ),
						$field_config['title'] ?? $field_name
					)
				);
			}
		}
		if ( isset( $field_config['validate_callback'] ) && is_callable( $field_config['validate_callback'] ) ) {
			$result = call_user_func( $field_config['validate_callback'], $sanitized, $field_config );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize value based on type.
	 *
	 * @param mixed  $value The value to sanitize.
	 * @param string $type The field type.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_value( $value, $type ) {
		switch ( $type ) {
			case 'text':
			case 'password':
				return sanitize_text_field( trim( $value ) );

			case 'textarea':
				return sanitize_textarea_field( trim( $value ) );

			case 'email':
				return sanitize_email( $value );

			case 'url':
				return esc_url_raw( $value );

			case 'checkbox':
				return VISA_ACCEPTANCE_YES === $value ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO;

			case 'select':
			case 'multiselect':
				if ( is_array( $value ) ) {
					return array_map( 'sanitize_text_field', $value );
				}
				return sanitize_text_field( $value );

			case 'number':
				return is_numeric( $value ) ? $value : VISA_ACCEPTANCE_STRING_EMPTY;

			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Validate merchant ID format
	 *
	 * @param string $merchant_id The merchant ID to validate.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function validate_merchant_id( $merchant_id ) {
		if ( empty( $merchant_id ) ) {
			return new WP_Error(
				'empty_merchant_id',
				__( 'Merchant ID is required.', 'visa-acceptance-solutions' )
			);
		}

		// Merchant ID should be alphanumeric and hyphens only.
		if ( ! preg_match( '/^[a-zA-Z0-9\-]+$/', $merchant_id ) ) {
			return new WP_Error(
				'invalid_merchant_id',
				__( 'Merchant ID contains invalid characters.', 'visa-acceptance-solutions' )
			);
		}

		return true;
	}

	/**
	 * Validate API key format
	 *
	 * @param string $api_key The API key to validate.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function validate_api_key( $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'empty_api_key',
				__( 'API Key is required.', 'visa-acceptance-solutions' )
			);
		}

		// API keys are typically UUID format with hyphens.
		if ( ! preg_match( '/^[a-zA-Z0-9\-]+$/', $api_key ) ) {
			return new WP_Error(
				'invalid_api_key',
				__( 'API Key contains invalid characters.', 'visa-acceptance-solutions' )
			);
		}
		return true;
	}

	/**
	 * Validate file path for MLE certificate
	 *
	 * @param string $file_path The file path to validate.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function validate_file_path( $file_path ) {
        if ( empty( $file_path ) ) {
            return true;
        }
 
        // Block path traversal attempts.
        if ( strpos( $file_path, '..' ) !== false ) {
            return new WP_Error(
                'invalid_path',
                __( 'File path contains invalid characters.', 'visa-acceptance-solutions' )
            );
        }
 
        return true;
    }

	/**
	 * Validate filename
	 *
	 * @param string $filename The filename to validate.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function validate_filename( $filename ) {
		if ( empty( $filename ) ) {
			return true;
		}
		if ( ! preg_match( '/^[a-zA-Z0-9\-_.]+$/', $filename ) ) {
			return new WP_Error(
				'invalid_filename',
				__( 'Filename contains invalid characters.', 'visa-acceptance-solutions' )
			);
		}
		if ( strpos( $filename, '..' ) !== false || strpos( $filename, '/' ) !== false || strpos( $filename, '\\' ) !== false ) {
			return new WP_Error(
				'invalid_filename_format',
				__( 'Filename format is invalid.', 'visa-acceptance-solutions' )
			);
		}
		return true;
	}

	/**
	 * Validate title
	 *
	 * @param string $title The title to validate.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function validate_title( $title ) {
		if ( empty( $title ) ) {
			return new WP_Error(
				'empty_title',
				__( 'Title is required.', 'visa-acceptance-solutions' )
			);
		}
		if ( ! preg_match( '/^[a-zA-Z0-9\s\-_]+$/', $title ) ) {
			return new WP_Error(
				'invalid_title',
				__( 'Title contains invalid characters.', 'visa-acceptance-solutions' )
			);
		}
		return true;
	}

	/**
	 * Get collected errors
	 *
	 * @return array Array of error messages.
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Add error to collection
	 *
	 * @param string $error_message The error message.
	 */
	public function add_error( $error_message ) {
		$this->errors[] = $error_message;
	}

	/**
	 * Check if there are any errors
	 *
	 * @return bool True if errors exist.
	 */
	public function has_errors() {
		return ! empty( $this->errors );
	}

	/**
	 * Clear all errors
	 */
	public function clear_errors() {
		$this->errors = array();
	}
}
