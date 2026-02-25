<?php
/**
 * Contract for embedding providers.
 */
interface MI_Embedding_Provider_Interface {

	/**
	 * Provider identifier (e.g., 'voyage', 'openai', 'tfidf').
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Model identifier (e.g., 'voyage-4-lite').
	 *
	 * @return string
	 */
	public function get_model();

	/**
	 * Number of dimensions in the output vector.
	 *
	 * @return int
	 */
	public function get_dimensions();

	/**
	 * Maximum input tokens supported.
	 *
	 * @return int
	 */
	public function get_max_tokens();

	/**
	 * Whether the provider is ready (API key set, etc.).
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Embed a single text string.
	 *
	 * @param string $text Input text.
	 * @return array Array of floats, or empty on failure.
	 */
	public function embed_single( $text );

	/**
	 * Embed a batch of texts.
	 *
	 * @param array $texts Array of strings.
	 * @return array Array of float arrays, or empty on failure.
	 */
	public function embed_batch( array $texts );
}
