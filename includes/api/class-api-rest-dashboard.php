<?php
/**
 * REST endpoints for the admin dashboard.
 */
class MI_Api_Rest_Dashboard {

	private $namespace = 'maillage-interne/v1';

	public function register_routes() {
		register_rest_route( $this->namespace, '/dashboard/stats', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_stats' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );

		register_rest_route( $this->namespace, '/dashboard/orphans', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_orphans' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );

		register_rest_route( $this->namespace, '/dashboard/top-opportunities', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_top_opportunities' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args' => [
				'limit' => [
					'default'           => 20,
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param >= 1 && $param <= 100;
					},
				],
			],
		] );
	}

	public function get_stats() {
		$embedding_repo = new MI_Storage_Embedding_Repository();
		$link_repo      = new MI_Storage_Link_Graph_Repository();
		$queue          = new MI_Background_Queue_Manager();
		$post_types     = MI_Settings::get( 'post_types', [ 'post', 'page' ] );

		return rest_ensure_response( [
			'total_posts'    => $this->count_posts( $post_types ),
			'embedded_count' => $embedding_repo->count(),
			'link_count'     => $link_repo->count(),
			'orphan_count'   => count( $link_repo->get_orphans( $post_types ) ),
			'queue'          => $queue->get_status(),
		] );
	}

	public function get_orphans() {
		$post_types = MI_Settings::get( 'post_types', [ 'post', 'page' ] );
		$link_repo  = new MI_Storage_Link_Graph_Repository();
		$orphan_ids = $link_repo->get_orphans( $post_types );

		$data = [];
		foreach ( $orphan_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$data[] = [
				'post_id'   => (int) $post_id,
				'title'     => $post->post_title,
				'url'       => get_permalink( $post ),
				'post_type' => $post->post_type,
				'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
			];
		}

		return rest_ensure_response( $data );
	}

	public function get_top_opportunities( $request ) {
		$limit = (int) $request->get_param( 'limit' );
		$cache = new MI_Storage_Similarity_Cache();
		$rows  = $cache->get_top_global( $limit );

		$data = [];
		foreach ( $rows as $row ) {
			$source = get_post( $row->source_post_id );
			$target = get_post( $row->target_post_id );
			if ( ! $source || ! $target ) {
				continue;
			}
			$data[] = [
				'source_post_id'   => (int) $row->source_post_id,
				'source_title'     => $source->post_title,
				'target_post_id'   => (int) $row->target_post_id,
				'target_title'     => $target->post_title,
				'final_score'      => round( (float) $row->final_score, 4 ),
				'suggested_anchor' => $row->suggested_anchor,
			];
		}

		return rest_ensure_response( $data );
	}

	private function count_posts( array $post_types ) {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
				...$post_types
			)
		);
	}
}
