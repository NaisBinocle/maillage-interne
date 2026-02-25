<?php
/**
 * Orchestrates embedding computation: text preparation, provider calls, storage.
 */
class MI_Embedding_Manager {

	/**
	 * Prepare weighted text from a post for embedding.
	 *
	 * Replicates the Python tool's construire_texte_pondere() logic:
	 *   - Title: 3x weight
	 *   - H1 (from content): 2x weight
	 *   - Excerpt / meta description: 2x weight
	 *   - Body text: 1x weight
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Prepared text.
	 */
	public static function prepare_text( $post ) {
		$parts = [];

		// Title (3x weight).
		$title = trim( $post->post_title );
		if ( $title ) {
			$parts[] = $title;
			$parts[] = $title;
			$parts[] = $title;
		}

		// Extract H1 from content (2x weight).
		$h1 = self::extract_first_h1( $post->post_content );
		if ( $h1 && $h1 !== $title ) {
			$parts[] = $h1;
			$parts[] = $h1;
		}

		// Excerpt / meta description (2x weight).
		$excerpt = trim( $post->post_excerpt );
		if ( ! $excerpt ) {
			// Try Yoast/RankMath meta description.
			$excerpt = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
			if ( ! $excerpt ) {
				$excerpt = get_post_meta( $post->ID, 'rank_math_description', true );
			}
		}
		if ( $excerpt ) {
			$parts[] = $excerpt;
			$parts[] = $excerpt;
		}

		// Body text (1x weight) — strip HTML, shortcodes, extra whitespace.
		$body = $post->post_content;
		$body = strip_shortcodes( $body );
		$body = wp_strip_all_tags( $body );
		$body = preg_replace( '/\s+/', ' ', $body );
		$body = trim( $body );

		// Cap body at 50,000 chars to avoid exceeding API token limits.
		if ( mb_strlen( $body ) > 50000 ) {
			$body = mb_substr( $body, 0, 50000 );
		}

		if ( $body ) {
			$parts[] = $body;
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Extract the first H1 tag text from HTML content.
	 *
	 * @param string $html HTML content.
	 * @return string|null
	 */
	private static function extract_first_h1( $html ) {
		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches ) ) {
			return trim( wp_strip_all_tags( $matches[1] ) );
		}
		return null;
	}

	/**
	 * Compute and store embedding for a single post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True on success.
	 */
	public function compute_for_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$text = self::prepare_text( $post );
		if ( empty( $text ) ) {
			return false;
		}

		$provider = $this->get_provider();
		if ( ! $provider || ! $provider->is_available() ) {
			return false;
		}

		$vector = $provider->embed_single( $text );
		if ( empty( $vector ) ) {
			return false;
		}

		$content_hash = md5( $text );
		$repo         = new MI_Storage_Embedding_Repository();

		return $repo->save(
			$post_id,
			$vector,
			$provider->get_name(),
			$provider->get_model(),
			$content_hash
		);
	}

	/**
	 * Compute and store embeddings for a batch of posts.
	 *
	 * @param array $post_ids Array of post IDs.
	 * @return array [ 'success' => int[], 'failed' => int[] ]
	 */
	public function compute_batch( array $post_ids ) {
		$provider = $this->get_provider();
		if ( ! $provider || ! $provider->is_available() ) {
			return [ 'success' => [], 'failed' => $post_ids ];
		}

		$texts   = [];
		$valid   = [];
		$failed  = [];

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				$failed[] = $post_id;
				continue;
			}

			$text = self::prepare_text( $post );
			if ( empty( $text ) ) {
				$failed[] = $post_id;
				continue;
			}

			$texts[]  = $text;
			$valid[]  = $post_id;
		}

		if ( empty( $texts ) ) {
			return [ 'success' => [], 'failed' => $failed ];
		}

		$vectors = $provider->embed_batch( $texts );
		if ( empty( $vectors ) || count( $vectors ) !== count( $valid ) ) {
			return [ 'success' => [], 'failed' => array_merge( $failed, $valid ) ];
		}

		$repo    = new MI_Storage_Embedding_Repository();
		$success = [];

		foreach ( $valid as $i => $post_id ) {
			$content_hash = md5( $texts[ $i ] );
			$saved = $repo->save(
				$post_id,
				$vectors[ $i ],
				$provider->get_name(),
				$provider->get_model(),
				$content_hash
			);

			if ( $saved ) {
				$success[] = $post_id;
			} else {
				$failed[] = $post_id;
			}
		}

		return [ 'success' => $success, 'failed' => $failed ];
	}

	/**
	 * Get the active embedding provider instance.
	 *
	 * @return MI_Embedding_Provider_Interface|null
	 */
	private function get_provider() {
		$provider_name = MI_Settings::get( 'provider', 'voyage' );

		switch ( $provider_name ) {
			case 'voyage':
				return new MI_Embedding_Voyage_Provider();
			case 'openai':
				return new MI_Embedding_Openai_Provider();
			case 'tfidf':
				return new MI_Embedding_Tfidf_Provider();
			default:
				return null;
		}
	}
}
