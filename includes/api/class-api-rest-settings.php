<?php
/**
 * REST endpoints for settings.
 */
class MI_Api_Rest_Settings {

	private $namespace = 'maillage-interne/v1';

	public function register_routes() {
		register_rest_route( $this->namespace, '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
		] );

		register_rest_route( $this->namespace, '/settings/test-api', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'test_api' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );
	}

	public function get_settings() {
		$settings = wp_parse_args( get_option( 'mi_settings', [] ), MI_Settings::defaults() );

		// Never expose API keys in full.
		$settings['voyage_api_key'] = $this->mask_key( $settings['voyage_api_key'] ?? '' );
		$settings['openai_api_key'] = $this->mask_key( $settings['openai_api_key'] ?? '' );

		return rest_ensure_response( $settings );
	}

	public function update_settings( $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			return new \WP_Error( 'invalid_params', __( 'Paramètres invalides.', 'maillage-interne' ), [ 'status' => 400 ] );
		}

		$allowed = array_keys( MI_Settings::defaults() );
		$updates = [];

		foreach ( $params as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				$updates[ $key ] = $value;
			}
		}

		MI_Settings::set_many( $updates );

		return rest_ensure_response( [ 'success' => true ] );
	}

	public function test_api() {
		$provider_name = MI_Settings::get( 'provider' );

		if ( 'tfidf' === $provider_name ) {
			return rest_ensure_response( [ 'success' => true, 'message' => __( 'Mode TF-IDF local : aucune API nécessaire.', 'maillage-interne' ) ] );
		}

		if ( 'voyage' === $provider_name ) {
			$provider = new MI_Embedding_Voyage_Provider();
		} elseif ( 'openai' === $provider_name ) {
			$provider = new MI_Embedding_Openai_Provider();
		} else {
			return new \WP_Error( 'unknown_provider', __( 'Fournisseur inconnu.', 'maillage-interne' ), [ 'status' => 400 ] );
		}

		$result = $provider->test_connection();

		return rest_ensure_response( $result );
	}

	private function mask_key( $key ) {
		if ( empty( $key ) ) {
			return '';
		}
		if ( strlen( $key ) <= 8 ) {
			return '****';
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', strlen( $key ) - 8 ) . substr( $key, -4 );
	}
}
