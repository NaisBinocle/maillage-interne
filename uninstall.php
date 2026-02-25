<?php
/**
 * Uninstall handler — runs when the plugin is deleted via WP admin.
 *
 * Drops all custom tables and removes all options/postmeta.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop custom tables.
$tables = [
	$wpdb->prefix . 'mi_embeddings',
	$wpdb->prefix . 'mi_similarity_cache',
	$wpdb->prefix . 'mi_link_graph',
	$wpdb->prefix . 'mi_embedding_queue',
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Remove plugin options.
delete_option( 'mi_settings' );
delete_option( 'mi_db_version' );

// Remove all postmeta.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_mi_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL
