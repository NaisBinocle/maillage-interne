<?php
/**
 * REST endpoints for bulk actions.
 */
class MI_Api_Rest_Bulk_Actions {

	private $namespace = 'maillage-interne/v1';

	public function register_routes() {
		register_rest_route( $this->namespace, '/bulk/vectorize', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'vectorize_all' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );

		register_rest_route( $this->namespace, '/bulk/scan-links', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'scan_links' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );

		register_rest_route( $this->namespace, '/bulk/recompute-similarities', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'recompute_similarities' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );
	}

	/**
	 * Enqueue all published posts for embedding.
	 */
	public function vectorize_all() {
		$post_types = MI_Settings::get( 'post_types', [ 'post', 'page' ] );
		$min_length = MI_Settings::get( 'min_content_length', 100 );

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				 AND post_type IN ({$placeholders})
				 AND CHAR_LENGTH(post_content) >= %d
				 ORDER BY post_date DESC",
				...array_merge( $post_types, [ $min_length ] )
			)
		);

		if ( empty( $post_ids ) ) {
			return rest_ensure_response( [ 'queued_count' => 0 ] );
		}

		$queue = new MI_Background_Queue_Manager();
		$queue->enqueue_batch( array_map( 'intval', $post_ids ), 5 );

		// Schedule immediate batch processing.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'mi_process_embedding_batch', [], 'maillage-interne' );
		}

		return rest_ensure_response( [
			'queued_count' => count( $post_ids ),
		] );
	}

	/**
	 * Scan all published posts for internal links.
	 */
	public function scan_links() {
		$post_types = MI_Settings::get( 'post_types', [ 'post', 'page' ] );
		$detector   = new MI_Analysis_Link_Detector();
		$link_repo  = new MI_Storage_Link_Graph_Repository();

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
				...$post_types
			)
		);

		$total = 0;
		foreach ( $posts as $post ) {
			$links = $detector->detect_in_content( $post->post_content );
			$link_repo->save_links( $post->ID, $links );
			$total += count( $links );
		}

		return rest_ensure_response( [
			'posts_scanned' => count( $posts ),
			'links_found'   => $total,
		] );
	}

	/**
	 * Invalidate similarity cache and trigger recomputation.
	 */
	public function recompute_similarities() {
		$cache = new MI_Storage_Similarity_Cache();
		$cache->invalidate_all();

		return rest_ensure_response( [ 'success' => true ] );
	}
}
