<?php
/**
 * Handles post save: update link graph, queue embedding, invalidate cache.
 */
class MI_Hooks_Post_Save_Handler {

	/**
	 * Fired on wp_after_insert_post (priority 20).
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post        Post object.
	 * @param bool     $update      Whether this is an update.
	 * @param \WP_Post $post_before Post before the update (WP 6.0+).
	 */
	public function on_save( $post_id, $post, $update, $post_before = null ) {
		// Skip autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only process configured post types.
		$allowed_types = MI_Settings::get( 'post_types', [ 'post', 'page' ] );
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}

		// Only process published posts.
		if ( 'publish' !== $post->post_status ) {
			// If unpublished, clean up.
			if ( $post_before && 'publish' === $post_before->post_status ) {
				$this->cleanup_post( $post_id );
			}
			return;
		}

		// Check minimum content length.
		$content    = wp_strip_all_tags( $post->post_content );
		$min_length = MI_Settings::get( 'min_content_length', 100 );
		if ( mb_strlen( $content ) < $min_length ) {
			return;
		}

		// Update the internal link graph (synchronous, fast).
		$this->update_link_graph( $post_id, $post->post_content );

		// Check if content changed (via hash comparison).
		$new_hash = $this->compute_content_hash( $post );
		$old_hash = get_post_meta( $post_id, '_mi_content_hash', true );

		if ( $new_hash !== $old_hash ) {
			// Content changed — queue for re-embedding.
			update_post_meta( $post_id, '_mi_content_hash', $new_hash );
			update_post_meta( $post_id, '_mi_embedding_status', 'stale' );

			$this->enqueue_embedding( $post_id );

			// Invalidate similarity cache.
			$cache = new MI_Storage_Similarity_Cache();
			$cache->invalidate_for_post( $post_id );
		}
	}

	/**
	 * Parse internal links from post content and save to link graph.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Post content (HTML).
	 */
	private function update_link_graph( $post_id, $content ) {
		$detector   = new MI_Analysis_Link_Detector();
		$links      = $detector->detect_in_content( $content );
		$repository = new MI_Storage_Link_Graph_Repository();
		$repository->save_links( $post_id, $links );
	}

	/**
	 * Build a content hash from the weighted text that will be embedded.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string MD5 hash.
	 */
	private function compute_content_hash( $post ) {
		$text = MI_Embedding_Manager::prepare_text( $post );
		return md5( $text );
	}

	/**
	 * Add a post to the embedding queue.
	 *
	 * @param int $post_id Post ID.
	 */
	private function enqueue_embedding( $post_id ) {
		if ( ! MI_Settings::is_configured() ) {
			return;
		}

		$queue = new MI_Background_Queue_Manager();
		$queue->enqueue( $post_id, 1 ); // Priority 1 = high (user-initiated save).

		// Schedule immediate processing if possible.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'mi_process_embedding_batch', [], 'maillage-interne' );
		}
	}

	/**
	 * Clean up data when a post is unpublished.
	 *
	 * @param int $post_id Post ID.
	 */
	private function cleanup_post( $post_id ) {
		$cache = new MI_Storage_Similarity_Cache();
		$cache->invalidate_for_post( $post_id );
	}
}
