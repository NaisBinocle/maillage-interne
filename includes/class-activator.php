<?php
/**
 * Plugin activation: create tables, set defaults, schedule cron.
 */
class MI_Activator {

	public static function activate() {
		self::create_tables();
		self::set_defaults();
		self::schedule_cron();

		update_option( 'mi_db_version', MI_DB_VERSION );
	}

	public static function create_tables() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Embeddings storage.
		dbDelta( "CREATE TABLE {$wpdb->prefix}mi_embeddings (
			id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id         bigint(20) unsigned NOT NULL,
			provider        varchar(20)  NOT NULL DEFAULT 'voyage',
			model           varchar(50)  NOT NULL DEFAULT '',
			dimensions      smallint unsigned NOT NULL DEFAULT 512,
			embedding       longblob     NOT NULL,
			content_hash    char(32)     NOT NULL DEFAULT '',
			created_at      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY provider (provider),
			KEY content_hash (content_hash)
		) {$charset};" );

		// Similarity cache.
		dbDelta( "CREATE TABLE {$wpdb->prefix}mi_similarity_cache (
			id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_post_id  bigint(20) unsigned NOT NULL,
			target_post_id  bigint(20) unsigned NOT NULL,
			score           float        NOT NULL DEFAULT 0,
			bonus_score     float        NOT NULL DEFAULT 0,
			final_score     float        NOT NULL DEFAULT 0,
			suggested_anchor varchar(255) NOT NULL DEFAULT '',
			link_exists     tinyint(1)   NOT NULL DEFAULT 0,
			computed_at     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY source_target (source_post_id, target_post_id),
			KEY target_post_id (target_post_id),
			KEY source_final (source_post_id, final_score)
		) {$charset};" );

		// Internal link graph.
		dbDelta( "CREATE TABLE {$wpdb->prefix}mi_link_graph (
			id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_post_id  bigint(20) unsigned NOT NULL,
			target_post_id  bigint(20) unsigned NOT NULL,
			anchor_text     varchar(255) NOT NULL DEFAULT '',
			context_snippet varchar(500) NOT NULL DEFAULT '',
			detected_at     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY source_target_anchor (source_post_id, target_post_id, anchor_text(100)),
			KEY target_post_id (target_post_id),
			KEY source_post_id (source_post_id)
		) {$charset};" );

		// Embedding processing queue.
		dbDelta( "CREATE TABLE {$wpdb->prefix}mi_embedding_queue (
			id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id         bigint(20) unsigned NOT NULL,
			priority        tinyint unsigned NOT NULL DEFAULT 5,
			status          varchar(20)  NOT NULL DEFAULT 'pending',
			attempts        tinyint unsigned NOT NULL DEFAULT 0,
			error_message   text,
			created_at      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			processed_at    datetime     DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY status_priority (status, priority),
			KEY created_at (created_at)
		) {$charset};" );
	}

	private static function set_defaults() {
		if ( false === get_option( 'mi_settings' ) ) {
			update_option( 'mi_settings', MI_Settings::defaults() );
		}
	}

	private static function schedule_cron() {
		if ( ! wp_next_scheduled( 'mi_daily_stale_check' ) ) {
			wp_schedule_event( time(), 'daily', 'mi_daily_stale_check' );
		}
		if ( ! wp_next_scheduled( 'mi_daily_link_scan' ) ) {
			wp_schedule_event( time(), 'daily', 'mi_daily_link_scan' );
		}
		if ( ! wp_next_scheduled( 'mi_weekly_cluster_recompute' ) ) {
			wp_schedule_event( time(), 'weekly', 'mi_weekly_cluster_recompute' );
		}
		if ( ! wp_next_scheduled( 'mi_hourly_process_queue' ) ) {
			wp_schedule_event( time(), 'hourly', 'mi_hourly_process_queue' );
		}
	}
}
