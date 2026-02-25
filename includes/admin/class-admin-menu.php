<?php
/**
 * Register admin menu pages.
 */
class MI_Admin_Menu {

	public function register() {
		add_menu_page(
			__( 'Maillage Interne', 'maillage-interne' ),
			__( 'Maillage Interne', 'maillage-interne' ),
			'manage_options',
			'maillage-interne',
			[ $this, 'render_dashboard' ],
			'dashicons-networking',
			80
		);

		add_submenu_page(
			'maillage-interne',
			__( 'Tableau de bord', 'maillage-interne' ),
			__( 'Tableau de bord', 'maillage-interne' ),
			'manage_options',
			'maillage-interne',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'maillage-interne',
			__( 'Réglages', 'maillage-interne' ),
			__( 'Réglages', 'maillage-interne' ),
			'manage_options',
			'maillage-interne-settings',
			[ $this, 'render_settings' ]
		);
	}

	public function render_dashboard() {
		$dashboard = new MI_Admin_Dashboard_Page();
		$dashboard->render();
	}

	public function render_settings() {
		$settings = new MI_Admin_Settings_Page();
		$settings->render();
	}
}
