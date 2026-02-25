<?php
/**
 * Display admin notices.
 */
class MI_Admin_Notices {

	public function display() {
		// Only show on plugin pages or post editors.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// API key not configured.
		if ( ! MI_Settings::is_configured() && 'tfidf' !== MI_Settings::get( 'provider' ) ) {
			$settings_url = admin_url( 'admin.php?page=maillage-interne-settings' );
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Maillage Interne : clé API non configurée.', 'maillage-interne' ),
				esc_url( $settings_url ),
				esc_html__( 'Configurer', 'maillage-interne' )
			);
		}
	}
}
