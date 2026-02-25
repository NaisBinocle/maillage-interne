<?php
/**
 * REST endpoints for embedding queue status.
 */
class MI_Api_Rest_Embedding_Status {

	private $namespace = 'maillage-interne/v1';

	public function register_routes() {
		register_rest_route( $this->namespace, '/status/queue', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_queue_status' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );

		register_rest_route( $this->namespace, '/status/post/(?P<post_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_post_status' ],
			'permission_callback' => function ( $request ) {
				return current_user_can( 'edit_post', $request->get_param( 'post_id' ) );
			},
		] );

		register_rest_route( $this->namespace, '/status/post/(?P<post_id>\d+)/refresh', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'refresh_post' ],
			'permission_callback' => function ( $request ) {
				return current_user_can( 'edit_post', $request->get_param( 'post_id' ) );
			},
		] );
	}

	public function get_queue_status() {
		$queue  = new MI_Background_Queue_Manager();
		$status = $queue->get_status();

		$total   = max( 1, $status['total'] );
		$done    = $status['completed'];
		$percent = round( ( $done / $total ) * 100, 1 );

		$status['percent_complete'] = $percent;

		return rest_ensure_response( $status );
	}

	public function get_post_status( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$repo    = new MI_Storage_Embedding_Repository();
		$row     = $repo->get_row( $post_id );

		if ( ! $row ) {
			return rest_ensure_response( [
				'status'   => get_post_meta( $post_id, '_mi_embedding_status', true ) ?: 'none',
				'provider' => null,
				'model'    => null,
			] );
		}

		return rest_ensure_response( [
			'status'     => 'computed',
			'provider'   => $row->provider,
			'model'      => $row->model,
			'dimensions' => (int) $row->dimensions,
			'updated_at' => $row->updated_at,
		] );
	}

	public function refresh_post( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		// Clear existing embedding and cache.
		$embedding_repo = new MI_Storage_Embedding_Repository();
		$embedding_repo->delete( $post_id );

		$cache = new MI_Storage_Similarity_Cache();
		$cache->invalidate_for_post( $post_id );

		// Re-enqueue with high priority.
		$queue = new MI_Background_Queue_Manager();
		$queue->enqueue( $post_id, 1 );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'mi_process_embedding_batch', [], 'maillage-interne' );
		}

		return rest_ensure_response( [ 'success' => true, 'status' => 'pending' ] );
	}
}
