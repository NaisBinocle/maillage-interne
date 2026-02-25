<?php
/**
 * Handles post deletion: clean up all related data.
 */
class MI_Hooks_Post_Delete_Handler {

	/**
	 * Fired on before_delete_post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete( $post_id ) {
		// Remove embedding.
		$embedding_repo = new MI_Storage_Embedding_Repository();
		$embedding_repo->delete( $post_id );

		// Remove from similarity cache.
		$similarity_cache = new MI_Storage_Similarity_Cache();
		$similarity_cache->invalidate_for_post( $post_id );

		// Remove from link graph.
		$link_graph = new MI_Storage_Link_Graph_Repository();
		$link_graph->delete_for_post( $post_id );

		// Remove from queue.
		$queue = new MI_Background_Queue_Manager();
		$queue->remove( $post_id );
	}
}
