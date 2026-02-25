<?php
/**
 * Plugin Name: Maillage Interne
 * Plugin URI:  https://github.com/NaisBinocle/maillage-interne
 * Description: Recommandations intelligentes de maillage interne SEO basées sur les embeddings sémantiques.
 * Version:     1.0.0
 * Author:      NaisBinocle
 * Author URI:  https://github.com/NaisBinocle
 * License:     GPL-2.0-or-later
 * Text Domain: maillage-interne
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MI_VERSION', '1.0.0' );
define( 'MI_DB_VERSION', 1 );
define( 'MI_PLUGIN_FILE', __FILE__ );
define( 'MI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for plugin classes.
 *
 * Maps class prefixes to directories:
 *   MI_Embedding_*   → includes/embedding/
 *   MI_Analysis_*    → includes/analysis/
 *   MI_Storage_*     → includes/storage/
 *   MI_Background_*  → includes/background/
 *   MI_Api_*         → includes/api/
 *   MI_Admin_*       → includes/admin/
 *   MI_Hooks_*       → includes/hooks/
 *   MI_*             → includes/
 */
spl_autoload_register( function ( $class ) {
	$prefix = 'MI_';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}

	$subdirs = [
		'Embedding'  => 'embedding',
		'Analysis'   => 'analysis',
		'Storage'    => 'storage',
		'Background' => 'background',
		'Api'        => 'api',
		'Admin'      => 'admin',
		'Hooks'      => 'hooks',
	];

	$relative = substr( $class, strlen( $prefix ) );
	$parts    = explode( '_', $relative );
	$subdir   = '';

	if ( isset( $parts[0], $subdirs[ $parts[0] ] ) ) {
		$subdir = $subdirs[ $parts[0] ] . '/';
	}

	$filename = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
	$filepath = MI_PLUGIN_DIR . 'includes/' . $subdir . $filename;

	if ( file_exists( $filepath ) ) {
		require_once $filepath;
	}
} );

// Activation / Deactivation.
register_activation_hook( __FILE__, [ 'MI_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'MI_Deactivator', 'deactivate' ] );

/**
 * Boot the plugin after all plugins are loaded.
 */
add_action( 'plugins_loaded', function () {
	MI_Plugin::instance();
} );
