<?php
/**
 * Classic editor metabox for link recommendations.
 */
class MI_Admin_Metabox_Classic {

	public function register() {
		$post_types = MI_Settings::get( 'post_types', [ 'post', 'page' ] );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'mi-recommendations',
				__( 'Maillage Interne', 'maillage-interne' ),
				[ $this, 'render' ],
				$post_type,
				'side',
				'default'
			);
		}
	}

	public function render( $post ) {
		$status = get_post_meta( $post->ID, '_mi_embedding_status', true );

		echo '<div id="mi-metabox" data-post-id="' . esc_attr( $post->ID ) . '">';

		if ( 'publish' !== $post->post_status ) {
			echo '<p>' . esc_html__( 'Publiez l\'article pour voir les suggestions.', 'maillage-interne' ) . '</p>';
		} elseif ( 'computed' === $status ) {
			echo '<p class="mi-loading">' . esc_html__( 'Chargement des suggestions...', 'maillage-interne' ) . '</p>';
			echo '<div id="mi-recommendations-list"></div>';
		} elseif ( 'pending' === $status || 'stale' === $status ) {
			echo '<p>' . esc_html__( 'Analyse en cours...', 'maillage-interne' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Aucune analyse disponible.', 'maillage-interne' ) . '</p>';
		}

		echo '</div>';
	}
}
