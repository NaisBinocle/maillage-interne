<?php
/**
 * CRUD for the mi_link_graph table.
 *
 * Stores the actual internal link graph parsed from post content.
 */
class MI_Storage_Link_Graph_Repository {

	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'mi_link_graph';
	}

	/**
	 * Replace all outbound links for a source post.
	 *
	 * @param int   $source_id Source post ID.
	 * @param array $links     Array of [ 'target_post_id', 'anchor_text', 'context_snippet' ].
	 */
	public function save_links( $source_id, array $links ) {
		global $wpdb;

		// Clear old outbound links for this source.
		$this->delete_outbound( $source_id );

		foreach ( $links as $link ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$this->table,
				[
					'source_post_id'  => $source_id,
					'target_post_id'  => $link['target_post_id'],
					'anchor_text'     => mb_substr( $link['anchor_text'], 0, 255 ),
					'context_snippet' => mb_substr( $link['context_snippet'] ?? '', 0, 500 ),
				],
				[ '%d', '%d', '%s', '%s' ]
			);
		}
	}

	/**
	 * Get all outbound links from a post.
	 *
	 * @param int $post_id Source post ID.
	 * @return array
	 */
	public function get_outbound( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT target_post_id, anchor_text, context_snippet FROM {$this->table} WHERE source_post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Get all inbound links to a post.
	 *
	 * @param int $post_id Target post ID.
	 * @return array
	 */
	public function get_inbound( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_post_id, anchor_text, context_snippet FROM {$this->table} WHERE target_post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Count inbound links for a post.
	 *
	 * @param int $post_id Target post ID.
	 * @return int
	 */
	public function get_inbound_count( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT source_post_id) FROM {$this->table} WHERE target_post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Check if a link from source to target exists.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $target_id Target post ID.
	 * @return bool
	 */
	public function link_exists( $source_id, $target_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE source_post_id = %d AND target_post_id = %d",
				$source_id,
				$target_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Get orphan posts (zero inbound internal links).
	 *
	 * @param array $post_types Post types to check.
	 * @return array Array of post IDs.
	 */
	public function get_orphans( array $post_types = [ 'post', 'page' ] ) {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$this->table} lg ON lg.target_post_id = p.ID
				 WHERE p.post_status = 'publish'
				 AND p.post_type IN ({$placeholders})
				 AND lg.id IS NULL
				 ORDER BY p.post_date DESC",
				...$post_types
			)
		);
	}

	/**
	 * Delete all outbound links from a post.
	 *
	 * @param int $post_id Source post ID.
	 */
	public function delete_outbound( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $this->table, [ 'source_post_id' => $post_id ], [ '%d' ] );
	}

	/**
	 * Delete all records involving a post (outbound + inbound).
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_for_post( $post_id ) {
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
	 * Count total links in the graph.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	/**
	 * Truncate the entire table.
	 */
	public function truncate() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query( "TRUNCATE TABLE {$this->table}" );
	}
}
