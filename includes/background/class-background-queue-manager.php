<?php
/**
 * Manages the mi_embedding_queue table.
 */
class MI_Background_Queue_Manager {

	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'mi_embedding_queue';
	}

	/**
	 * Add a post to the embedding queue.
	 *
	 * @param int $post_id  Post ID.
	 * @param int $priority 1 (highest) to 10 (lowest).
	 */
	public function enqueue( $post_id, $priority = 5 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			$this->table,
			[
				'post_id'  => $post_id,
				'priority' => $priority,
				'status'   => 'pending',
				'attempts' => 0,
			],
			[ '%d', '%d', '%s', '%d' ]
		);

		update_post_meta( $post_id, '_mi_embedding_status', 'pending' );
	}

	/**
	 * Enqueue multiple posts at once.
	 *
	 * @param array $post_ids Post IDs.
	 * @param int   $priority Priority level.
	 */
	public function enqueue_batch( array $post_ids, $priority = 5 ) {
		foreach ( $post_ids as $post_id ) {
			$this->enqueue( $post_id, $priority );
		}
	}

	/**
	 * Dequeue a batch for processing.
	 *
	 * @param int $batch_size Number of items.
	 * @return array Array of post IDs.
	 */
	public function dequeue_batch( $batch_size = 10 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$this->table}
				 WHERE status = 'pending'
				 ORDER BY priority ASC, created_at ASC
				 LIMIT %d",
				$batch_size
			)
		);

		if ( ! empty( $post_ids ) ) {
			$ids_in = implode( ',', array_map( 'intval', $post_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->query( "UPDATE {$this->table} SET status = 'processing' WHERE post_id IN ({$ids_in})" );
		}

		return array_map( 'intval', $post_ids );
	}

	/**
	 * Mark a post as completed.
	 *
	 * @param int $post_id Post ID.
	 */
	public function mark_completed( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$this->table,
			[
				'status'       => 'completed',
				'processed_at' => current_time( 'mysql' ),
			],
			[ 'post_id' => $post_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Mark a post as failed.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $error   Error message.
	 */
	public function mark_failed( $post_id, $error = '' ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				 SET status = 'failed', error_message = %s, attempts = attempts + 1, processed_at = %s
				 WHERE post_id = %d",
				$error,
				current_time( 'mysql' ),
				$post_id
			)
		);

		update_post_meta( $post_id, '_mi_embedding_status', 'error' );
	}

	/**
	 * Reset failed items that haven't exceeded max attempts.
	 *
	 * @param int $max_attempts Max retry attempts.
	 * @return int Number of items reset.
	 */
	public function retry_failed( $max_attempts = 3 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET status = 'pending' WHERE status = 'failed' AND attempts < %d",
				$max_attempts
			)
		);
	}

	/**
	 * Remove a post from the queue.
	 *
	 * @param int $post_id Post ID.
	 */
	public function remove( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $this->table, [ 'post_id' => $post_id ], [ '%d' ] );
	}

	/**
	 * Get queue status counts.
	 *
	 * @return array [ 'pending' => int, 'processing' => int, 'completed' => int, 'failed' => int, 'total' => int ]
	 */
	public function get_status() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) as cnt FROM {$this->table} GROUP BY status",
			OBJECT_K
		);

		$status = [
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
		];

		foreach ( $rows as $s => $row ) {
			$status[ $s ] = (int) $row->cnt;
		}

		$status['total'] = array_sum( $status );

		return $status;
	}

	/**
	 * Check if the queue has pending items.
	 *
	 * @return bool
	 */
	public function has_pending() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table} WHERE status = 'pending'"
		) > 0;
	}

	/**
	 * Clear the entire queue.
	 */
	public function clear() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query( "TRUNCATE TABLE {$this->table}" );
	}
}
