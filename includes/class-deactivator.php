<?php
/**
 * Plugin deactivation: clear scheduled events.
 */
class MI_Deactivator {

	public static function deactivate() {
		wp_clear_scheduled_hook( 'mi_daily_stale_check' );
		wp_clear_scheduled_hook( 'mi_daily_link_scan' );
		wp_clear_scheduled_hook( 'mi_weekly_cluster_recompute' );
		wp_clear_scheduled_hook( 'mi_hourly_process_queue' );
	}
}
