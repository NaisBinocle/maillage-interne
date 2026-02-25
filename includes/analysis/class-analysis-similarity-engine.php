<?php
/**
 * Computes cosine similarity between post embeddings with contextual bonuses.
 */
class MI_Analysis_Similarity_Engine {

	/**
	 * Cosine similarity between two vectors.
	 *
	 * @param array $a First vector (float[]).
	 * @param array $b Second vector (float[]).
	 * @return float Similarity score between 0 and 1.
	 */
	public function cosine_similarity( array $a, array $b ) {
		$dot    = 0.0;
		$norm_a = 0.0;
		$norm_b = 0.0;
		$n      = count( $a );

		for ( $i = 0; $i < $n; $i++ ) {
			$dot    += $a[ $i ] * $b[ $i ];
			$norm_a += $a[ $i ] * $a[ $i ];
			$norm_b += $b[ $i ] * $b[ $i ];
		}

		$denom = sqrt( $norm_a ) * sqrt( $norm_b );

		return $denom > 0 ? $dot / $denom : 0.0;
	}

	/**
	 * Compute similarity scores between a source post and all other embedded posts.
	 * Applies contextual bonuses and stores results in cache.
	 *
	 * @param int $post_id Source post ID.
	 * @param int $limit   Max results to cache per post.
	 * @return array Sorted array of scored targets.
	 */
	public function compute_for_post( $post_id, $limit = 50 ) {
		$embedding_repo = new MI_Storage_Embedding_Repository();
		$source_vector  = $embedding_repo->get( $post_id );

		if ( empty( $source_vector ) ) {
			return [];
		}

		$threshold = (float) MI_Settings::get( 'similarity_threshold', 0.10 );
		$scores    = [];

		// Load all embeddings and compute similarities.
		// For large sites, use chunked loading.
		foreach ( $embedding_repo->get_chunked( 500 ) as $chunk ) {
			foreach ( $chunk as $target_id => $target_vector ) {
				if ( $target_id === $post_id ) {
					continue;
				}

				$raw_score = $this->cosine_similarity( $source_vector, $target_vector );

				if ( $raw_score < $threshold ) {
					continue;
				}

				$scores[ $target_id ] = $raw_score;
			}
		}

		if ( empty( $scores ) ) {
			return [];
		}

		// Sort by raw score descending, keep top N.
		arsort( $scores );
		$scores = array_slice( $scores, 0, $limit, true );

		// Apply contextual bonuses.
		$link_repo = new MI_Storage_Link_Graph_Repository();
		$anchor    = new MI_Analysis_Anchor_Suggester();
		$results   = [];

		foreach ( $scores as $target_id => $raw_score ) {
			$bonus       = $this->compute_bonus( $post_id, $target_id, $link_repo );
			$final_score = min( 1.0, $raw_score + $bonus );
			$link_exists = $link_repo->link_exists( $post_id, $target_id );

			$results[] = [
				'target_post_id'   => $target_id,
				'score'            => round( $raw_score, 6 ),
				'bonus_score'      => round( $bonus, 6 ),
				'final_score'      => round( $final_score, 6 ),
				'suggested_anchor' => $anchor->suggest( $post_id, $target_id ),
				'link_exists'      => $link_exists,
			];
		}

		// Re-sort by final_score.
		usort( $results, function ( $a, $b ) {
			return $b['final_score'] <=> $a['final_score'];
		} );

		// Cache results.
		$cache = new MI_Storage_Similarity_Cache();
		$cache->save_scores( $post_id, $results );

		return $results;
	}

	/**
	 * Compute contextual bonus score for a source-target pair.
	 *
	 * @param int                              $source_id Source post ID.
	 * @param int                              $target_id Target post ID.
	 * @param MI_Storage_Link_Graph_Repository $link_repo Link graph instance.
	 * @return float Bonus score.
	 */
	private function compute_bonus( $source_id, $target_id, $link_repo ) {
		$bonus = 0.0;

		// Same category bonus.
		$bonus_cat = (float) MI_Settings::get( 'bonus_same_category', 0.05 );
		if ( $bonus_cat > 0 && $this->share_taxonomy( $source_id, $target_id, 'category' ) ) {
			$bonus += $bonus_cat;
		}

		// Shared tags bonus.
		$bonus_tag = (float) MI_Settings::get( 'bonus_shared_tag', 0.02 );
		if ( $bonus_tag > 0 ) {
			$shared_tags = $this->count_shared_terms( $source_id, $target_id, 'post_tag' );
			$bonus      += $bonus_tag * min( $shared_tags, 3 ); // Cap at 3 tags.
		}

		// Orphan target bonus.
		$bonus_orphan = (float) MI_Settings::get( 'bonus_orphan_target', 0.08 );
		if ( $bonus_orphan > 0 && $link_repo->get_inbound_count( $target_id ) === 0 ) {
			$bonus += $bonus_orphan;
		}

		// Fresh content bonus.
		$bonus_fresh  = (float) MI_Settings::get( 'bonus_fresh_content', 0.03 );
		$fresh_days   = (int) MI_Settings::get( 'freshness_days', 30 );
		if ( $bonus_fresh > 0 ) {
			$target_post = get_post( $target_id );
			if ( $target_post ) {
				$age_days = ( time() - strtotime( $target_post->post_date ) ) / DAY_IN_SECONDS;
				if ( $age_days <= $fresh_days ) {
					$bonus += $bonus_fresh;
				}
			}
		}

		return $bonus;
	}

	/**
	 * Check if two posts share at least one term in a taxonomy.
	 *
	 * @param int    $post_a   Post A.
	 * @param int    $post_b   Post B.
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	private function share_taxonomy( $post_a, $post_b, $taxonomy ) {
		$terms_a = wp_get_post_terms( $post_a, $taxonomy, [ 'fields' => 'ids' ] );
		$terms_b = wp_get_post_terms( $post_b, $taxonomy, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $terms_a ) || is_wp_error( $terms_b ) ) {
			return false;
		}

		return ! empty( array_intersect( $terms_a, $terms_b ) );
	}

	/**
	 * Count shared terms between two posts for a taxonomy.
	 *
	 * @param int    $post_a   Post A.
	 * @param int    $post_b   Post B.
	 * @param string $taxonomy Taxonomy name.
	 * @return int
	 */
	private function count_shared_terms( $post_a, $post_b, $taxonomy ) {
		$terms_a = wp_get_post_terms( $post_a, $taxonomy, [ 'fields' => 'ids' ] );
		$terms_b = wp_get_post_terms( $post_b, $taxonomy, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $terms_a ) || is_wp_error( $terms_b ) ) {
			return 0;
		}

		return count( array_intersect( $terms_a, $terms_b ) );
	}
}
