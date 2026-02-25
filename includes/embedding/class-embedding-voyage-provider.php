<?php
/**
 * Voyage AI embedding provider.
 *
 * @see https://docs.voyageai.com/docs/embeddings
 */
class MI_Embedding_Voyage_Provider implements MI_Embedding_Provider_Interface {

	private const API_URL = 'https://api.voyageai.com/v1/embeddings';

	public function get_name() {
		return 'voyage';
	}

	public function get_model() {
		return MI_Settings::get( 'voyage_model', 'voyage-4-lite' );
	}

	public function get_dimensions() {
		return 512;
	}

	public function get_max_tokens() {
		return 32000;
	}

	public function is_available() {
		return ! empty( MI_Settings::get( 'voyage_api_key' ) );
	}

	public function embed_single( $text ) {
		$result = $this->embed_batch( [ $text ] );
		return ! empty( $result ) ? $result[0] : [];
	}

	public function embed_batch( array $texts ) {
		$api_key = MI_Settings::get( 'voyage_api_key' );
		if ( empty( $api_key ) ) {
			return [];
		}

		$response = wp_remote_post(
			self::API_URL,
			[
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( [
					'input'  => $texts,
					'model'  => $this->get_model(),
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'API request failed: ' . $response->get_error_message() );
			return [];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code ) {
			$error = $data['detail'] ?? $body;
			$this->log( "API error (HTTP {$code}): {$error}" );
			return [];
		}

		if ( empty( $data['data'] ) ) {
			$this->log( 'API returned no data.' );
			return [];
		}

		$vectors = [];
		foreach ( $data['data'] as $item ) {
			$vectors[] = $item['embedding'];
		}

		return $vectors;
	}

	/**
	 * Test the API connection with a minimal request.
	 *
	 * @return array [ 'success' => bool, 'message' => string ]
	 */
	public function test_connection() {
		if ( ! $this->is_available() ) {
			return [ 'success' => false, 'message' => __( 'Clé API Voyage AI non configurée.', 'maillage-interne' ) ];
		}

		$result = $this->embed_single( 'Test de connexion.' );

		if ( ! empty( $result ) ) {
			return [
				'success' => true,
				'message' => sprintf(
					/* translators: %d: vector dimension count */
					__( 'Connexion réussie. Vecteur de %d dimensions.', 'maillage-interne' ),
					count( $result )
				),
			];
		}

		return [ 'success' => false, 'message' => __( 'Echec de connexion à l\'API Voyage AI.', 'maillage-interne' ) ];
	}

	private function log( $message ) {
		if ( MI_Settings::get( 'debug_logging' ) ) {
			error_log( '[Maillage Interne][Voyage] ' . $message );
		}
	}
}
