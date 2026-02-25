<?php
/**
 * CRUD for the mi_embeddings table.
 *
 * Stores embedding vectors as packed float32 binary blobs.
 */
class MI_Storage_Embedding_Repository {

	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'mi_embeddings';
	}

	/**
	 * Save or update an embedding for a post.
	 *
	 * @param int    $post_id    Post ID.
	 * @param array  $vector     Array of floats.
	 * @param string $provider   Provider name (voyage, openai, tfidf).
	 * @param string $model      Model identifier.
	 * @param string $content_hash MD5 hash of the source text.
	 * @return bool
	 */
	public function save( $post_id, array $vector, $provider, $model, $content_hash ) {
		global $wpdb;

		$binary = pack( 'f*', ...$vector );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->replace(
			$this->table,
			[
				'post_id'      => $post_id,
				'provider'     => $provider,
				'model'        => $model,
				'dimensions'   => count( $vector ),
				'embedding'    => $binary,
				'content_hash' => $content_hash,
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( false !== $result ) {
			update_post_meta( $post_id, '_mi_embedding_status', 'computed' );
			update_post_meta( $post_id, '_mi_embedding_updated', time() );
			update_post_meta( $post_id, '_mi_content_hash', $content_hash );
		}

		return false !== $result;
	}

	/**
	 * Get the embedding vector for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Array of floats, or null if not found.
	 */
	public function get( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT embedding, dimensions FROM {$this->table} WHERE post_id = %d",
				$post_id
			)
		);

		if ( ! $row ) {
			return null;
		}

		return array_values( unpack( 'f*', $row->embedding ) );
	}

	/**
	 * Get the full row (metadata included) for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null
	 */
	public function get_row( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, post_id, provider, model, dimensions, content_hash, created_at, updated_at
				 FROM {$this->table} WHERE post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Load all embeddings. Returns [ post_id => float[] ].
	 *
	 * @param int $limit Max rows (0 = unlimited).
	 * @return array
	 */
	public function get_all( $limit = 0 ) {
		global $wpdb;

		$sql = "SELECT post_id, embedding FROM {$this->table}";
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $sql );

		$result = [];
		foreach ( $rows as $row ) {
			$result[ (int) $row->post_id ] = array_values( unpack( 'f*', $row->embedding ) );
		}
		return $result;
	}

	/**
	 * Load embeddings in chunks for large sites.
	 *
	 * @param int $chunk_size Rows per chunk.
	 * @return \Generator Yields [ post_id => float[] ] arrays.
	 */
	public function get_chunked( $chunk_size = 500 ) {
		global $wpdb;

		$offset = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, embedding FROM {$this->table} ORDER BY post_id ASC LIMIT %d OFFSET %d",
					$chunk_size,
					$offset
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			$chunk = [];
			foreach ( $rows as $row ) {
				$chunk[ (int) $row->post_id ] = array_values( unpack( 'f*', $row->embedding ) );
			}

			yield $chunk;

			$offset += $chunk_size;

			if ( count( $rows ) < $chunk_size ) {
				break;
			}
		}
	}

	/**
	 * Delete embedding for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function delete( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$result = $wpdb->delete( $this->table, [ 'post_id' => $post_id ], [ '%d' ] );

		delete_post_meta( $post_id, '_mi_embedding_status' );
		delete_post_meta( $post_id, '_mi_embedding_updated' );
		delete_post_meta( $post_id, '_mi_content_hash' );

		return false !== $result;
	}

	/**
	 * Count total stored embeddings.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	/**
	 * Find posts whose content_hash no longer matches (stale embeddings).
	 *
	 * @return array Array of post IDs.
	 */
	public function get_stale_post_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $wpdb->get_col(
			"SELECT e.post_id FROM {$this->table} e
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = e.post_id AND pm.meta_key = '_mi_content_hash'
			 WHERE e.content_hash != pm.meta_value"
		);
	}

	/**
	 * Truncate the entire table (used when switching provider).
	 *
	 * @return bool
	 */
	public function truncate() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return false !== $wpdb->query( "TRUNCATE TABLE {$this->table}" );
	}
}
