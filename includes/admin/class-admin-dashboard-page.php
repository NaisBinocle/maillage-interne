<?php
/**
 * Main dashboard admin page.
 */
class MI_Admin_Dashboard_Page {

	public function render() {
		$embedding_repo = new MI_Storage_Embedding_Repository();
		$link_repo      = new MI_Storage_Link_Graph_Repository();
		$queue          = new MI_Background_Queue_Manager();
		$cache          = new MI_Storage_Similarity_Cache();

		$post_types     = MI_Settings::get( 'post_types', [ 'post', 'page' ] );
		$total_posts    = $this->count_published_posts( $post_types );
		$embedded_count = $embedding_repo->count();
		$link_count     = $link_repo->count();
		$orphan_ids     = $link_repo->get_orphans( $post_types );
		$queue_status   = $queue->get_status();

		?>
		<div class="wrap mi-dashboard">
			<h1><?php esc_html_e( 'Maillage Interne — Tableau de bord', 'maillage-interne' ); ?></h1>

			<!-- Stats Cards -->
			<div class="mi-stats-grid">
				<div class="mi-stat-card">
					<span class="mi-stat-number"><?php echo esc_html( $embedded_count ); ?> / <?php echo esc_html( $total_posts ); ?></span>
					<span class="mi-stat-label"><?php esc_html_e( 'Articles analysés', 'maillage-interne' ); ?></span>
				</div>
				<div class="mi-stat-card mi-stat-warning">
					<span class="mi-stat-number"><?php echo esc_html( count( $orphan_ids ) ); ?></span>
					<span class="mi-stat-label"><?php esc_html_e( 'Pages orphelines', 'maillage-interne' ); ?></span>
				</div>
				<div class="mi-stat-card">
					<span class="mi-stat-number"><?php echo esc_html( $link_count ); ?></span>
					<span class="mi-stat-label"><?php esc_html_e( 'Liens internes détectés', 'maillage-interne' ); ?></span>
				</div>
				<div class="mi-stat-card">
					<span class="mi-stat-number"><?php echo esc_html( $cache->count() ); ?></span>
					<span class="mi-stat-label"><?php esc_html_e( 'Scores en cache', 'maillage-interne' ); ?></span>
				</div>
			</div>

			<!-- Queue Progress -->
			<?php if ( $queue_status['pending'] > 0 || $queue_status['processing'] > 0 ) : ?>
				<div class="mi-progress-section">
					<h2><?php esc_html_e( 'File d\'attente', 'maillage-interne' ); ?></h2>
					<div class="mi-progress-bar">
						<?php
						$total    = max( 1, $queue_status['total'] );
						$done     = $queue_status['completed'];
						$percent  = round( ( $done / $total ) * 100 );
						?>
						<div class="mi-progress-fill" style="width: <?php echo esc_attr( $percent ); ?>%"></div>
					</div>
					<p>
						<?php
						printf(
							/* translators: 1: completed count, 2: total count */
							esc_html__( '%1$d / %2$d articles traités', 'maillage-interne' ),
							$done,
							$total
						);
						?>
						— <?php echo esc_html( $queue_status['pending'] ); ?> en attente,
						<?php echo esc_html( $queue_status['failed'] ); ?> en erreur
					</p>
				</div>
			<?php endif; ?>

			<!-- Orphan Pages -->
			<?php if ( ! empty( $orphan_ids ) ) : ?>
				<div class="mi-section">
					<h2><?php esc_html_e( 'Pages orphelines', 'maillage-interne' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Ces pages ne reçoivent aucun lien interne.', 'maillage-interne' ); ?></p>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Titre', 'maillage-interne' ); ?></th>
								<th><?php esc_html_e( 'Type', 'maillage-interne' ); ?></th>
								<th><?php esc_html_e( 'Date', 'maillage-interne' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'maillage-interne' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $orphan_ids, 0, 20 ) as $orphan_id ) : ?>
								<?php $orphan_post = get_post( $orphan_id ); ?>
								<?php if ( ! $orphan_post ) continue; ?>
								<tr>
									<td><strong><?php echo esc_html( $orphan_post->post_title ); ?></strong></td>
									<td><?php echo esc_html( $orphan_post->post_type ); ?></td>
									<td><?php echo esc_html( get_the_date( '', $orphan_post ) ); ?></td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $orphan_id ) ); ?>">
											<?php esc_html_e( 'Modifier', 'maillage-interne' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( count( $orphan_ids ) > 20 ) : ?>
						<p><?php printf( esc_html__( '... et %d autres.', 'maillage-interne' ), count( $orphan_ids ) - 20 ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<!-- Bulk Actions -->
			<div class="mi-section">
				<h2><?php esc_html_e( 'Actions', 'maillage-interne' ); ?></h2>
				<p>
					<button type="button" class="button button-primary" id="mi-btn-vectorize">
						<?php esc_html_e( 'Vectoriser tout le contenu', 'maillage-interne' ); ?>
					</button>
					<button type="button" class="button" id="mi-btn-scan-links">
						<?php esc_html_e( 'Scanner tous les liens', 'maillage-interne' ); ?>
					</button>
					<button type="button" class="button" id="mi-btn-recompute">
						<?php esc_html_e( 'Recalculer les similarités', 'maillage-interne' ); ?>
					</button>
				</p>
				<div id="mi-bulk-progress" style="display:none;">
					<div class="mi-progress-bar"><div class="mi-progress-fill" style="width:0%"></div></div>
					<p id="mi-bulk-status"></p>
				</div>
			</div>
		</div>
		<?php
	}

	private function count_published_posts( array $post_types ) {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
				...$post_types
			)
		);
	}
}
