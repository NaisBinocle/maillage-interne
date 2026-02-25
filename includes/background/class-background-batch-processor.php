<?php
/**
 * Processes embedding queue batches via Action Scheduler or WP Cron.
 */
class MI_Background_Batch_Processor {

	/**
	 * Initialize Action Scheduler hooks.
	 */
	public static function init() {
		add_action( 'mi_process_embedding_batch', [ __CLASS__, 'process_batch' ] );
		add_action( 'mi_hourly_process_queue', [ __CLASS__, 'process_batch' ] );
	}

	/**
	 * Process a single batch of embeddings.
	 */
	public static function process_batch() {
		$queue      = new MI_Background_Queue_Manager();
		$batch_size = MI_Settings::get( 'api_batch_size', 10 );

		$post_ids = $queue->dequeue_batch( $batch_size );
		if ( empty( $post_ids ) ) {
			return;
		}

		$manager = new MI_Embedding_Manager();
		$result  = $manager->compute_batch( $post_ids );

		// Mark successes.
		foreach ( $result['success'] as $post_id ) {
			$queue->mark_completed( $post_id );
		}

		// Mark failures.
		foreach ( $result['failed'] as $post_id ) {
			$queue->mark_failed( $post_id, 'Embedding computation failed.' );
		}

		// If there are more pending items, schedule the next batch.
		if ( $queue->has_pending() ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + 5,
					'mi_process_embedding_batch',
					[],
					'maillage-interne'
				);
			}
		}

		// Retry failed items that haven't exceeded max attempts.
		$queue->retry_failed( 3 );
	}
}
