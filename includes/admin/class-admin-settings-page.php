<?php
/**
 * Plugin settings page using the WordPress Settings API.
 */
class MI_Admin_Settings_Page {

	private $option_group = 'mi_settings_group';
	private $option_name  = 'mi_settings';

	public function register_settings() {
		register_setting( $this->option_group, $this->option_name, [
			'sanitize_callback' => [ $this, 'sanitize' ],
		] );

		// Section: Embedding Provider.
		add_settings_section(
			'mi_section_provider',
			__( 'Fournisseur d\'embeddings', 'maillage-interne' ),
			function () {
				echo '<p>' . esc_html__( 'Choisissez le fournisseur pour le calcul des embeddings sémantiques.', 'maillage-interne' ) . '</p>';
			},
			'maillage-interne-settings'
		);

		add_settings_field( 'provider', __( 'Fournisseur', 'maillage-interne' ), [ $this, 'render_provider_field' ], 'maillage-interne-settings', 'mi_section_provider' );
		add_settings_field( 'voyage_api_key', __( 'Clé API Voyage AI', 'maillage-interne' ), [ $this, 'render_voyage_key_field' ], 'maillage-interne-settings', 'mi_section_provider' );
		add_settings_field( 'openai_api_key', __( 'Clé API OpenAI', 'maillage-interne' ), [ $this, 'render_openai_key_field' ], 'maillage-interne-settings', 'mi_section_provider' );

		// Section: Analysis.
		add_settings_section(
			'mi_section_analysis',
			__( 'Paramètres d\'analyse', 'maillage-interne' ),
			null,
			'maillage-interne-settings'
		);

		add_settings_field( 'similarity_threshold', __( 'Seuil de similarité', 'maillage-interne' ), [ $this, 'render_threshold_field' ], 'maillage-interne-settings', 'mi_section_analysis' );
		add_settings_field( 'max_recommendations', __( 'Recommandations max', 'maillage-interne' ), [ $this, 'render_max_reco_field' ], 'maillage-interne-settings', 'mi_section_analysis' );

		// Section: Content.
		add_settings_section(
			'mi_section_content',
			__( 'Contenu à analyser', 'maillage-interne' ),
			null,
			'maillage-interne-settings'
		);

		add_settings_field( 'post_types', __( 'Types de contenu', 'maillage-interne' ), [ $this, 'render_post_types_field' ], 'maillage-interne-settings', 'mi_section_content' );
		add_settings_field( 'min_content_length', __( 'Longueur minimale', 'maillage-interne' ), [ $this, 'render_min_length_field' ], 'maillage-interne-settings', 'mi_section_content' );
	}

	public function render() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Maillage Interne — Réglages', 'maillage-interne' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( $this->option_group );
				do_settings_sections( 'maillage-interne-settings' );
				submit_button();
				?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Test de connexion', 'maillage-interne' ); ?></h2>
			<p>
				<button type="button" class="button" id="mi-btn-test-api">
					<?php esc_html_e( 'Tester la connexion API', 'maillage-interne' ); ?>
				</button>
				<span id="mi-test-result"></span>
			</p>
		</div>
		<?php
	}

	// --- Field renderers ---

	public function render_provider_field() {
		$value = MI_Settings::get( 'provider', 'voyage' );
		$options = [
			'voyage' => 'Voyage AI',
			'openai' => 'OpenAI',
			'tfidf'  => __( 'TF-IDF local (sans API)', 'maillage-interne' ),
		];

		foreach ( $options as $key => $label ) {
			printf(
				'<label style="display:block;margin-bottom:5px"><input type="radio" name="%s[provider]" value="%s" %s> %s</label>',
				esc_attr( $this->option_name ),
				esc_attr( $key ),
				checked( $value, $key, false ),
				esc_html( $label )
			);
		}
	}

	public function render_voyage_key_field() {
		$value = MI_Settings::get( 'voyage_api_key', '' );
		printf(
			'<input type="password" name="%s[voyage_api_key]" value="%s" class="regular-text" autocomplete="off">',
			esc_attr( $this->option_name ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Obtenez votre clé sur dash.voyageai.com', 'maillage-interne' ) . '</p>';
	}

	public function render_openai_key_field() {
		$value = MI_Settings::get( 'openai_api_key', '' );
		printf(
			'<input type="password" name="%s[openai_api_key]" value="%s" class="regular-text" autocomplete="off">',
			esc_attr( $this->option_name ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Obtenez votre clé sur platform.openai.com', 'maillage-interne' ) . '</p>';
	}

	public function render_threshold_field() {
		$value = MI_Settings::get( 'similarity_threshold', 0.10 );
		printf(
			'<input type="number" name="%s[similarity_threshold]" value="%s" min="0.01" max="0.99" step="0.01" class="small-text">',
			esc_attr( $this->option_name ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Score minimum pour recommander un lien (0.01 - 0.99).', 'maillage-interne' ) . '</p>';
	}

	public function render_max_reco_field() {
		$value = MI_Settings::get( 'max_recommendations', 5 );
		printf(
			'<input type="number" name="%s[max_recommendations]" value="%s" min="1" max="20" class="small-text">',
			esc_attr( $this->option_name ),
			esc_attr( $value )
		);
	}

	public function render_post_types_field() {
		$selected    = MI_Settings::get( 'post_types', [ 'post', 'page' ] );
		$post_types  = get_post_types( [ 'public' => true ], 'objects' );

		foreach ( $post_types as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			printf(
				'<label style="display:block;margin-bottom:3px"><input type="checkbox" name="%s[post_types][]" value="%s" %s> %s</label>',
				esc_attr( $this->option_name ),
				esc_attr( $pt->name ),
				checked( in_array( $pt->name, $selected, true ), true, false ),
				esc_html( $pt->label )
			);
		}
	}

	public function render_min_length_field() {
		$value = MI_Settings::get( 'min_content_length', 100 );
		printf(
			'<input type="number" name="%s[min_content_length]" value="%s" min="0" max="10000" class="small-text">',
			esc_attr( $this->option_name ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Nombre minimum de caractères (texte brut) pour analyser un article.', 'maillage-interne' ) . '</p>';
	}

	// --- Sanitization ---

	public function sanitize( $input ) {
		$defaults  = MI_Settings::defaults();
		$sanitized = [];

		$sanitized['provider']             = in_array( $input['provider'] ?? '', [ 'voyage', 'openai', 'tfidf' ], true ) ? $input['provider'] : $defaults['provider'];
		$sanitized['voyage_api_key']       = sanitize_text_field( $input['voyage_api_key'] ?? '' );
		$sanitized['openai_api_key']       = sanitize_text_field( $input['openai_api_key'] ?? '' );
		$sanitized['similarity_threshold'] = max( 0.01, min( 0.99, floatval( $input['similarity_threshold'] ?? $defaults['similarity_threshold'] ) ) );
		$sanitized['max_recommendations']  = max( 1, min( 20, absint( $input['max_recommendations'] ?? $defaults['max_recommendations'] ) ) );
		$sanitized['min_content_length']   = max( 0, min( 10000, absint( $input['min_content_length'] ?? $defaults['min_content_length'] ) ) );

		// Post types: validate against actual registered types.
		$valid_types = array_keys( get_post_types( [ 'public' => true ] ) );
		$sanitized['post_types'] = ! empty( $input['post_types'] )
			? array_intersect( array_map( 'sanitize_text_field', $input['post_types'] ), $valid_types )
			: $defaults['post_types'];

		// Preserve existing settings that aren't on this form.
		$existing = get_option( 'mi_settings', [] );
		$sanitized = array_merge( $existing, $sanitized );

		MI_Settings::flush_cache();

		return $sanitized;
	}
}
