<?php
/**
 * Orchestrates the full recommendation pipeline for a post.
 *
 * 1. Check similarity cache (or compute if missing)
 * 2. Filter out already-linked targets
 * 3. Apply contextual bonuses
 * 4. Generate anchor suggestions
 * 5. Return top N recommendations sorted by final_score
 */
class MI_Analysis_Recommendation_Engine {

	/**
	 * Get link recommendations for a specific post.
	 *
	 * @param int  $post_id         Source post ID.
	 * @param int  $limit           Max recommendations.
	 * @param bool $exclude_linked  Exclude already-linked targets.
	 * @return array Array of recommendation arrays.
	 */
	public function get_recommendations( $post_id, $limit = 0, $exclude_linked = true ) {
		if ( $limit <= 0 ) {
			$limit = (int) MI_Settings::get( 'max_recommendations', 5 );
		}

		$cache = new MI_Storage_Similarity_Cache();

		// Check if we have cached scores.
		if ( ! $cache->has_scores( $post_id ) ) {
			// Compute scores now (synchronous).
			$engine = new MI_Analysis_Similarity_Engine();
			$engine->compute_for_post( $post_id );
		}

		// Read from cache.
		$rows = $cache->get_top_n( $post_id, $limit, $exclude_linked );

		$results = [];
		foreach ( $rows as $row ) {
			$target = get_post( $row->target_post_id );
			if ( ! $target || 'publish' !== $target->post_status ) {
				continue;
			}

			$link_repo  = new MI_Storage_Link_Graph_Repository();
			$is_orphan  = $link_repo->get_inbound_count( $row->target_post_id ) === 0;

			$results[] = [
				'target_post_id'   => (int) $row->target_post_id,
				'title'            => $target->post_title,
				'url'              => get_permalink( $target ),
				'score'            => round( (float) $row->score, 4 ),
				'bonus_score'      => round( (float) $row->bonus_score, 4 ),
				'final_score'      => round( (float) $row->final_score, 4 ),
				'suggested_anchor' => $row->suggested_anchor,
				'link_exists'      => (bool) $row->link_exists,
				'is_orphan'        => $is_orphan,
				'post_type'        => $target->post_type,
				'edit_url'         => get_edit_post_link( $row->target_post_id, 'raw' ),
			];
		}

		return $results;
	}

	/**
	 * Get site-wide top link opportunities.
	 *
	 * @param int $limit Max results.
	 * @return array
	 */
	public function get_global_recommendations( $limit = 50 ) {
		$cache = new MI_Storage_Similarity_Cache();
		$rows  = $cache->get_top_global( $limit );

		$results = [];
		foreach ( $rows as $row ) {
			$source = get_post( $row->source_post_id );
			$target = get_post( $row->target_post_id );

			if ( ! $source || ! $target ) {
				continue;
			}

			$results[] = [
				'source_post_id'   => (int) $row->source_post_id,
				'source_title'     => $source->post_title,
				'source_url'       => get_permalink( $source ),
				'target_post_id'   => (int) $row->target_post_id,
				'target_title'     => $target->post_title,
				'target_url'       => get_permalink( $target ),
				'final_score'      => round( (float) $row->final_score, 4 ),
				'suggested_anchor' => $row->suggested_anchor,
			];
		}

		return $results;
	}

	/**
	 * Recompute recommendations for a specific post.
	 * Invalidates cache and recomputes from scratch.
	 *
	 * @param int $post_id Post ID.
	 * @return array Fresh recommendations.
	 */
	public function refresh( $post_id ) {
		$cache = new MI_Storage_Similarity_Cache();
		$cache->invalidate_for_post( $post_id );

		$engine = new MI_Analysis_Similarity_Engine();
		$engine->compute_for_post( $post_id );

		return $this->get_recommendations( $post_id );
	}

	/**
	 * Batch recompute recommendations for all embedded posts.
	 * Useful after bulk vectorization.
	 *
	 * @return int Number of posts processed.
	 */
	public function recompute_all() {
		$embedding_repo = new MI_Storage_Embedding_Repository();
		$engine         = new MI_Analysis_Similarity_Engine();
		$count          = 0;

		foreach ( $embedding_repo->get_chunked( 100 ) as $chunk ) {
			foreach ( array_keys( $chunk ) as $post_id ) {
				$engine->compute_for_post( $post_id );
				$count++;
			}
		}

		return $count;
	}
}
