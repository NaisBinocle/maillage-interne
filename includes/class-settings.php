<?php
/**
 * Centralized settings access.
 */
class MI_Settings {

	private static $cache = null;

	public static function defaults() {
		return [
			// Provider.
			'provider'              => 'voyage',
			'voyage_api_key'        => '',
			'openai_api_key'        => '',
			'voyage_model'          => 'voyage-4-lite',
			'openai_model'          => 'text-embedding-3-small',
			'openai_dimensions'     => 512,

			// Analysis.
			'similarity_threshold'  => 0.10,
			'max_recommendations'   => 5,
			'bonus_same_category'   => 0.05,
			'bonus_shared_tag'      => 0.02,
			'bonus_orphan_target'   => 0.08,
			'bonus_fresh_content'   => 0.03,
			'freshness_days'        => 30,

			// Content.
			'post_types'            => [ 'post', 'page' ],
			'post_statuses'         => [ 'publish' ],
			'min_content_length'    => 100,

			// Performance.
			'api_batch_size'        => 10,
			'enable_similarity_cache' => true,

			// Features.
			'auto_insert_links'     => false,
			'auto_insert_max'       => 3,
			'debug_logging'         => false,
		];
	}

	/**
	 * Get a setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if key does not exist.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		if ( null === self::$cache ) {
			self::$cache = wp_parse_args(
				get_option( 'mi_settings', [] ),
				self::defaults()
			);
		}
		return self::$cache[ $key ] ?? $default;
	}

	/**
	 * Update a single setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 * @return bool
	 */
	public static function set( $key, $value ) {
		$settings         = get_option( 'mi_settings', [] );
		$settings[ $key ] = $value;
		self::$cache      = null;
		return update_option( 'mi_settings', $settings );
	}

	/**
	 * Update multiple settings at once.
	 *
	 * @param array $values Key-value pairs.
	 * @return bool
	 */
	public static function set_many( array $values ) {
		$settings    = get_option( 'mi_settings', [] );
		$settings    = array_merge( $settings, $values );
		self::$cache = null;
		return update_option( 'mi_settings', $settings );
	}

	/**
	 * Get the active API key for the current provider.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		$provider = self::get( 'provider' );
		if ( 'voyage' === $provider ) {
			return self::get( 'voyage_api_key', '' );
		}
		if ( 'openai' === $provider ) {
			return self::get( 'openai_api_key', '' );
		}
		return '';
	}

	/**
	 * Check if the plugin is properly configured (API key set or TF-IDF mode).
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$provider = self::get( 'provider' );
		if ( 'tfidf' === $provider ) {
			return true;
		}
		return ! empty( self::get_api_key() );
	}

	/**
	 * Reset the internal cache (useful after direct option updates).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
