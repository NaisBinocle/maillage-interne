<?php
/**
 * Enqueue admin scripts and styles.
 */
class MI_Admin_Assets {

	public function enqueue( $hook ) {
		// Plugin admin pages.
		if ( strpos( $hook, 'maillage-interne' ) !== false ) {
			wp_enqueue_style(
				'mi-admin-dashboard',
				MI_PLUGIN_URL . 'admin/css/admin-dashboard.css',
				[],
				MI_VERSION
			);

			wp_enqueue_script(
				'mi-admin-dashboard',
				MI_PLUGIN_URL . 'admin/js/dashboard.js',
				[],
				MI_VERSION,
				true
			);

			wp_localize_script( 'mi-admin-dashboard', 'miAdmin', [
				'restUrl' => rest_url( 'maillage-interne/v1/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			] );
		}

		// Gutenberg sidebar (block editor only).
		$screen = get_current_screen();
		if ( $screen && $screen->is_block_editor() ) {
			$asset_file = MI_PLUGIN_DIR . 'gutenberg/build/index.asset.php';

			if ( file_exists( $asset_file ) ) {
				$asset = require $asset_file;

				wp_enqueue_script(
					'mi-gutenberg-sidebar',
					MI_PLUGIN_URL . 'gutenberg/build/index.js',
					$asset['dependencies'],
					$asset['version'],
					true
				);

				if ( file_exists( MI_PLUGIN_DIR . 'gutenberg/build/style-index.css' ) ) {
					wp_enqueue_style(
						'mi-gutenberg-sidebar',
						MI_PLUGIN_URL . 'gutenberg/build/style-index.css',
						[],
						$asset['version']
					);
				}
			}
		}
	}
}
