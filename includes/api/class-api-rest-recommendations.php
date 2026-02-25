<?php
/**
 * REST endpoint: GET /recommendations/{post_id}
 */
class MI_Api_Rest_Recommendations {

	private $namespace = 'maillage-interne/v1';

	public function register_routes() {
		register_rest_route( $this->namespace, '/recommendations/(?P<post_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_recommendations' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'post_id' => [
					'required'          => true,
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
				],
				'limit' => [
					'default'           => 5,
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param >= 1 && $param <= 20;
					},
				],
				'exclude_linked' => [
					'default' => true,
				],
			],
		] );
	}

	public function check_permission( $request ) {
		$post_id = $request->get_param( 'post_id' );
		return current_user_can( 'edit_post', $post_id );
	}

	public function get_recommendations( $request ) {
		$post_id        = (int) $request->get_param( 'post_id' );
		$limit          = (int) $request->get_param( 'limit' );
		$exclude_linked = (bool) $request->get_param( 'exclude_linked' );

		$cache = new MI_Storage_Similarity_Cache();
		$rows  = $cache->get_top_n( $post_id, $limit, $exclude_linked );

		$data = [];
		foreach ( $rows as $row ) {
			$target = get_post( $row->target_post_id );
			if ( ! $target ) {
				continue;
			}

			$link_repo = new MI_Storage_Link_Graph_Repository();

			$data[] = [
				'target_post_id'  => (int) $row->target_post_id,
				'title'           => $target->post_title,
				'url'             => get_permalink( $target ),
				'score'           => round( (float) $row->score, 4 ),
				'bonus_score'     => round( (float) $row->bonus_score, 4 ),
				'final_score'     => round( (float) $row->final_score, 4 ),
				'suggested_anchor' => $row->suggested_anchor,
				'link_exists'     => (bool) $row->link_exists,
				'is_orphan'       => $link_repo->get_inbound_count( $row->target_post_id ) === 0,
				'edit_url'        => get_edit_post_link( $row->target_post_id, 'raw' ),
			];
		}

		return rest_ensure_response( $data );
	}
}
