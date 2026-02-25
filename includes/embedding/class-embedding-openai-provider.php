<?php
/**
 * OpenAI embedding provider.
 *
 * @see https://platform.openai.com/docs/api-reference/embeddings
 */
class MI_Embedding_Openai_Provider implements MI_Embedding_Provider_Interface {

	private const API_URL = 'https://api.openai.com/v1/embeddings';

	public function get_name() {
		return 'openai';
	}

	public function get_model() {
		return MI_Settings::get( 'openai_model', 'text-embedding-3-small' );
	}

	public function get_dimensions() {
		return (int) MI_Settings::get( 'openai_dimensions', 512 );
	}

	public function get_max_tokens() {
		return 8191;
	}

	public function is_available() {
		return ! empty( MI_Settings::get( 'openai_api_key' ) );
	}

	public function embed_single( $text ) {
		$result = $this->embed_batch( [ $text ] );
		return ! empty( $result ) ? $result[0] : [];
	}

	public function embed_batch( array $texts ) {
		$api_key = MI_Settings::get( 'openai_api_key' );
		if ( empty( $api_key ) ) {
			return [];
		}

		$body = [
			'input' => $texts,
			'model' => $this->get_model(),
		];

		// OpenAI text-embedding-3 models support custom dimensions.
		$dimensions = $this->get_dimensions();
		if ( $dimensions && strpos( $this->get_model(), 'text-embedding-3' ) === 0 ) {
			$body['dimensions'] = $dimensions;
		}

		$response = wp_remote_post(
			self::API_URL,
			[
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'API request failed: ' . $response->get_error_message() );
			return [];
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data         = json_decode( $response_body, true );

		if ( 200 !== $code ) {
			$error = $data['error']['message'] ?? $response_body;
			$this->log( "API error (HTTP {$code}): {$error}" );
			return [];
		}

		if ( empty( $data['data'] ) ) {
			$this->log( 'API returned no data.' );
			return [];
		}

		// Sort by index to ensure correct ordering.
		usort( $data['data'], function ( $a, $b ) {
			return $a['index'] - $b['index'];
		} );

		$vectors = [];
		foreach ( $data['data'] as $item ) {
			$vectors[] = $item['embedding'];
		}

		return $vectors;
	}

	/**
	 * Test the API connection.
	 *
	 * @return array [ 'success' => bool, 'message' => string ]
	 */
	public function test_connection() {
		if ( ! $this->is_available() ) {
			return [ 'success' => false, 'message' => __( 'Clé API OpenAI non configurée.', 'maillage-interne' ) ];
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

		return [ 'success' => false, 'message' => __( 'Echec de connexion à l\'API OpenAI.', 'maillage-interne' ) ];
	}

	private function log( $message ) {
		if ( MI_Settings::get( 'debug_logging' ) ) {
			error_log( '[Maillage Interne][OpenAI] ' . $message );
		}
	}
}
