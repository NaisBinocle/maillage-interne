<?php
/**
 * CRUD for the mi_similarity_cache table.
 */
class MI_Storage_Similarity_Cache {

	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'mi_similarity_cache';
	}

	/**
	 * Save similarity scores for a source post.
	 *
	 * @param int   $source_id       Source post ID.
	 * @param array $scored_targets  Array of arrays: [ 'target_post_id', 'score', 'bonus_score', 'final_score', 'suggested_anchor', 'link_exists' ].
	 */
	public function save_scores( $source_id, array $scored_targets ) {
		global $wpdb;

		foreach ( $scored_targets as $target ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->replace(
				$this->table,
				[
					'source_post_id'  => $source_id,
					'target_post_id'  => $target['target_post_id'],
					'score'           => $target['score'],
					'bonus_score'     => $target['bonus_score'],
					'final_score'     => $target['final_score'],
					'suggested_anchor' => $target['suggested_anchor'],
					'link_exists'     => $target['link_exists'] ? 1 : 0,
				],
				[ '%d', '%d', '%f', '%f', '%f', '%s', '%d' ]
			);
		}
	}

	/**
	 * Get top N recommendations for a source post.
	 *
	 * @param int  $source_id            Source post ID.
	 * @param int  $limit                Max results.
	 * @param bool $exclude_existing     Exclude targets where link already exists.
	 * @return array
	 */
	public function get_top_n( $source_id, $limit = 5, $exclude_existing = true ) {
		global $wpdb;

		$where_clause = $exclude_existing ? 'AND link_exists = 0' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT target_post_id, score, bonus_score, final_score, suggested_anchor, link_exists
				 FROM {$this->table}
				 WHERE source_post_id = %d {$where_clause}
				 ORDER BY final_score DESC
				 LIMIT %d",
				$source_id,
				$limit
			)
		);
	}

	/**
	 * Get the top site-wide opportunities (highest scores, no existing link).
	 *
	 * @param int $limit Max results.
	 * @return array
	 */
	public function get_top_global( $limit = 20 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_post_id, target_post_id, score, bonus_score, final_score, suggested_anchor
				 FROM {$this->table}
				 WHERE link_exists = 0
				 ORDER BY final_score DESC
				 LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Invalidate cache for a specific post (as source or target).
	 *
	 * @param int $post_id Post ID.
	 */
	public function invalidate_for_post( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE source_post_id = %d OR target_post_id = %d",
				$post_id,
				$post_id
			)
		);
	}

	/**
	 * Truncate all cached scores.
	 */
	public function invalidate_all() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query( "TRUNCATE TABLE {$this->table}" );
	}

	/**
	 * Count total cached scores.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	/**
	 * Check if a source post has cached scores.
	 *
	 * @param int $source_id Post ID.
	 * @return bool
	 */
	public function has_scores( $source_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE source_post_id = %d",
				$source_id
			)
		);

		return (int) $count > 0;
	}
}
