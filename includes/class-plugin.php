<?php
/**
 * Main plugin orchestrator.
 */
class MI_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_textdomain();
		$this->check_db_version();
		$this->init_hooks();
		$this->init_background();
		$this->init_admin();
		$this->init_rest_api();
	}

	private function load_textdomain() {
		load_plugin_textdomain(
			'maillage-interne',
			false,
			dirname( MI_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Run DB migrations if the stored version is behind.
	 */
	private function check_db_version() {
		$current = (int) get_option( 'mi_db_version', 0 );
		if ( $current < MI_DB_VERSION ) {
			MI_Activator::create_tables();
			update_option( 'mi_db_version', MI_DB_VERSION );
		}
	}

	private function init_hooks() {
		$save_handler   = new MI_Hooks_Post_Save_Handler();
		$delete_handler = new MI_Hooks_Post_Delete_Handler();

		add_action( 'wp_after_insert_post', [ $save_handler, 'on_save' ], 20, 4 );
		add_action( 'before_delete_post', [ $delete_handler, 'on_delete' ], 10, 1 );
	}

	/**
	 * Register background processing hooks (must run on every request).
	 */
	private function init_background() {
		MI_Background_Batch_Processor::init();
	}

	private function init_admin() {
		if ( ! is_admin() ) {
			return;
		}

		$menu     = new MI_Admin_Menu();
		$assets   = new MI_Admin_Assets();
		$notices  = new MI_Admin_Notices();
		$settings = new MI_Admin_Settings_Page();

		add_action( 'admin_menu', [ $menu, 'register' ] );
		add_action( 'admin_init', [ $settings, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $assets, 'enqueue' ] );
		add_action( 'admin_notices', [ $notices, 'display' ] );

		// Classic editor metabox.
		add_action( 'add_meta_boxes', [ new MI_Admin_Metabox_Classic(), 'register' ] );
	}

	private function init_rest_api() {
		add_action( 'rest_api_init', function () {
			( new MI_Api_Rest_Recommendations() )->register_routes();
			( new MI_Api_Rest_Dashboard() )->register_routes();
			( new MI_Api_Rest_Settings() )->register_routes();
			( new MI_Api_Rest_Bulk_Actions() )->register_routes();
			( new MI_Api_Rest_Embedding_Status() )->register_routes();
		} );
	}
}
