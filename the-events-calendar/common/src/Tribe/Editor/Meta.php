<?php

/**
 * Class Tribe__Editor__Meta
 *
 * @since 4.8
 */
abstract class Tribe__Editor__Meta
	implements Tribe__Editor__Meta_Interface {

	/**
	 * Default definition for an attribute of type text.
	 *
	 * @since 4.8
	 *
	 * @return array The definition for the text attribute.
	 */
	protected function text() {
		return [
			'auth_callback'     => [ $this, 'auth_callback' ],
			'sanitize_callback' => 'sanitize_text_field',
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
		];
	}

	/**
	 * Add arguments to escape a textarea field.
	 *
	 * @since 4.8
	 *
	 * @return array The definition for the textarea attribute.
	 */
	protected function textarea() {
		return [
			'auth_callback'     => [ $this, 'auth_callback' ],
			'sanitize_callback' => 'sanitize_textarea_field',
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
		];
	}

	/**
	 * Add arguments to escape a field of URL type.
	 *
	 * @since 4.8
	 *
	 * @return array The definition for the URL attribute.
	 */
	protected function url() {
		return [
			'auth_callback'     => [ $this, 'auth_callback' ],
			'sanitize_callback' => 'esc_url_raw',
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
		];
	}

	/**
	 * Default definition for an attribute of type numeric.
	 *
	 * @since 4.8
	 *
	 * @return array The definition for the numeric attribute.
	 */
	protected function numeric() {
		return [
			'auth_callback'     => [ $this, 'auth_callback' ],
			'sanitize_callback' => 'absint',
			'type'              => 'number',
			'single'            => true,
			'show_in_rest'      => true,
		];
	}

	/***
	 * Default definition for an attribute of type boolean.
	 *
	 * @since 4.8
	 *
	 * @return array The definition for the boolean attribute.
	 */
	protected function boolean() {
		return [
			'auth_callback'     => [ $this, 'auth_callback' ],
			'sanitize_callback' => [ $this, 'sanitize_boolean' ],
			'type'              => 'boolean',
			'single'            => true,
			'show_in_rest'      => true,
		];
	}

	/**
	 * Register a numeric type of array.
	 *
	 * @since 4.8
	 *
	 * @return array The definition for the numeric array attribute.
	 */
	protected function numeric_array() {
		return [
			'description'       => __( 'Numeric Array', 'tribe-common' ),
			'auth_callback'     => [ $this, 'auth_callback' ],
			'sanitize_callback' => [ $this, 'sanitize_numeric_array' ],
			'type'              => 'number',
			'single'            => false,
			'show_in_rest'      => true,
		];
	}

	/**
	 * Register a text type of array.
	 *
	 * @since 4.8
	 *
	 * @return array The definition for the text array attribute.
	 */
	protected function text_array() {
		return [
			'description'       => __( 'Text Array', 'tribe-common' ),
			'auth_callback'     => [ $this, 'auth_callback' ],
			'sanitize_callback' => [ $this, 'sanitize_text_array' ],
			'type'              => 'string',
			'single'            => false,
			'show_in_rest'      => true,
		];
	}

	/**
	 * Sanitize an array of text.
	 *
	 * @since 4.8
	 *
	 * @param string|array $value The value to sanitize.
	 *
	 * @return array|string The sanitized value.
	 */
	public function sanitize_text_array( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', $value );
		} else {
			return sanitize_text_field( $value );
		}
	}

	/**
	 * Checks and sanitize a given value to a numeric array or a numeric string.
	 *
	 * @since 4.8
	 *
	 * @param mixed $value Check against this value.
	 *
	 * @return array|bool|int The sanitized value.
	 */
	public function sanitize_numeric_array( $value ) {
		if ( is_array( $value ) ) {
			return wp_parse_id_list( $value );
		} elseif ( is_numeric( $value ) ) {
			return absint( $value );
		} else {
			return false;
		}
	}

	/**
	 * Make sure sanitization on boolean does not triggered warnings when multiple values are passed
	 * to the function.
	 *
	 * @since 4.8
	 *
	 * @param mixed $value The value to sanitize.
	 *
	 * @return bool The sanitized value.
	 */
	public function sanitize_boolean( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Sanitize strings allowing the usage of white spaces before or after the separators, as
	 * - sanitize_text_field removes any whitespace
	 *
	 * @since 4.8
	 *
	 * @param string $value The value to sanitize.
	 *
	 * @return string The sanitized value.
	 */
	public function sanitize_separator( $value ) {
		return tec_sanitize_string( $value );
	}

	/**
	 * Verify if the current user can edit or not this Post
	 *
	 * @since 4.8
	 *
	 * @param bool   $allowed Whether the user can add the post meta. Default false.
	 * @param string $meta_key The meta key.
	 * @param int    $post_id Post ID.
	 * @param int    $user_id User ID.
	 * @param string $cap Capability name.
	 * @param array  $caps User capabilities.
	 *
	 * @return bool Whether the user can edit the post meta.
	 */
	public function auth_callback( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ) {
		$post             = get_post( $post_id );
		$post_type_obj    = get_post_type_object( $post->post_type );
		$current_user_can = current_user_can( $post_type_obj->cap->edit_post, $post_id );

		return $current_user_can;
	}
}
