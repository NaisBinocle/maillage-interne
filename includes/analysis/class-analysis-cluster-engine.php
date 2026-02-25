<?php
/**
 * K-Means clustering on embeddings.
 *
 * Groups pages by thematic similarity and assigns human-readable labels.
 * Pure PHP implementation (no external dependencies).
 */
class MI_Analysis_Cluster_Engine {

	private $max_iterations = 50;

	/**
	 * Compute clusters for all embedded posts.
	 *
	 * @param int $k Number of clusters (0 = auto).
	 * @return array [
	 *   'clusters'    => [ cluster_id => [ 'label' => string, 'post_ids' => int[] ] ],
	 *   'assignments' => [ post_id => cluster_id ],
	 * ]
	 */
	public function compute( $k = 0 ) {
		$embedding_repo = new MI_Storage_Embedding_Repository();
		$all            = $embedding_repo->get_all();

		$n = count( $all );
		if ( $n < 2 ) {
			return [ 'clusters' => [], 'assignments' => [] ];
		}

		// Auto-determine K.
		if ( $k <= 0 ) {
			$k = max( 2, (int) floor( sqrt( $n / 2 ) ) );
		}
		$k = min( $k, $n );

		$post_ids = array_keys( $all );
		$vectors  = array_values( $all );
		$dims     = count( $vectors[0] );

		// Initialize centroids using K-means++ for better convergence.
		$centroids = $this->init_centroids_pp( $vectors, $k );

		// Iterate.
		$assignments = array_fill( 0, $n, 0 );

		for ( $iter = 0; $iter < $this->max_iterations; $iter++ ) {
			$changed = false;

			// Assign each point to nearest centroid.
			for ( $i = 0; $i < $n; $i++ ) {
				$best_cluster  = 0;
				$best_distance = PHP_FLOAT_MAX;

				for ( $c = 0; $c < $k; $c++ ) {
					$dist = $this->euclidean_distance( $vectors[ $i ], $centroids[ $c ] );
					if ( $dist < $best_distance ) {
						$best_distance = $dist;
						$best_cluster  = $c;
					}
				}

				if ( $assignments[ $i ] !== $best_cluster ) {
					$assignments[ $i ] = $best_cluster;
					$changed           = true;
				}
			}

			if ( ! $changed ) {
				break;
			}

			// Recompute centroids.
			$centroids = $this->compute_centroids( $vectors, $assignments, $k, $dims );
		}

		// Build result.
		$clusters        = [];
		$post_assignments = [];

		for ( $i = 0; $i < $n; $i++ ) {
			$cluster_id = $assignments[ $i ];
			$pid        = $post_ids[ $i ];

			if ( ! isset( $clusters[ $cluster_id ] ) ) {
				$clusters[ $cluster_id ] = [
					'label'    => '',
					'post_ids' => [],
				];
			}

			$clusters[ $cluster_id ]['post_ids'][] = $pid;
			$post_assignments[ $pid ]              = $cluster_id;
		}

		// Generate labels from post titles.
		foreach ( $clusters as $cid => &$cluster ) {
			$cluster['label'] = $this->generate_label( $cluster['post_ids'] );
		}
		unset( $cluster );

		// Store cluster assignments in postmeta.
		foreach ( $post_assignments as $pid => $cid ) {
			update_post_meta( $pid, '_mi_cluster_id', $cid );
		}

		return [
			'clusters'    => $clusters,
			'assignments' => $post_assignments,
		];
	}

	/**
	 * Get the cluster ID for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int|null
	 */
	public function get_cluster_for_post( $post_id ) {
		$cluster = get_post_meta( $post_id, '_mi_cluster_id', true );
		return '' !== $cluster ? (int) $cluster : null;
	}

	/**
	 * K-means++ centroid initialization.
	 *
	 * @param array $vectors All vectors.
	 * @param int   $k       Number of centroids.
	 * @return array Centroid vectors.
	 */
	private function init_centroids_pp( array $vectors, $k ) {
		$n         = count( $vectors );
		$centroids = [];

		// First centroid: random.
		$centroids[] = $vectors[ wp_rand( 0, $n - 1 ) ];

		for ( $c = 1; $c < $k; $c++ ) {
			// Compute distances to nearest existing centroid.
			$distances = [];
			$total     = 0.0;

			for ( $i = 0; $i < $n; $i++ ) {
				$min_dist = PHP_FLOAT_MAX;
				foreach ( $centroids as $centroid ) {
					$dist = $this->euclidean_distance( $vectors[ $i ], $centroid );
					if ( $dist < $min_dist ) {
						$min_dist = $dist;
					}
				}
				$d2           = $min_dist * $min_dist;
				$distances[]  = $d2;
				$total       += $d2;
			}

			// Weighted random selection.
			if ( $total <= 0 ) {
				$centroids[] = $vectors[ wp_rand( 0, $n - 1 ) ];
				continue;
			}

			$threshold = ( wp_rand( 0, 10000 ) / 10000.0 ) * $total;
			$cumulative = 0.0;

			for ( $i = 0; $i < $n; $i++ ) {
				$cumulative += $distances[ $i ];
				if ( $cumulative >= $threshold ) {
					$centroids[] = $vectors[ $i ];
					break;
				}
			}
		}

		return $centroids;
	}

	/**
	 * Recompute centroids from current assignments.
	 *
	 * @param array $vectors     All vectors.
	 * @param array $assignments Current cluster assignments.
	 * @param int   $k           Number of clusters.
	 * @param int   $dims        Vector dimensions.
	 * @return array New centroid vectors.
	 */
	private function compute_centroids( array $vectors, array $assignments, $k, $dims ) {
		$sums   = array_fill( 0, $k, array_fill( 0, $dims, 0.0 ) );
		$counts = array_fill( 0, $k, 0 );

		foreach ( $vectors as $i => $vec ) {
			$c = $assignments[ $i ];
			$counts[ $c ]++;
			for ( $d = 0; $d < $dims; $d++ ) {
				$sums[ $c ][ $d ] += $vec[ $d ];
			}
		}

		$centroids = [];
		for ( $c = 0; $c < $k; $c++ ) {
			$centroid = [];
			for ( $d = 0; $d < $dims; $d++ ) {
				$centroid[ $d ] = $counts[ $c ] > 0 ? $sums[ $c ][ $d ] / $counts[ $c ] : 0.0;
			}
			$centroids[] = $centroid;
		}

		return $centroids;
	}

	/**
	 * Euclidean distance between two vectors.
	 *
	 * @param array $a Vector A.
	 * @param array $b Vector B.
	 * @return float
	 */
	private function euclidean_distance( array $a, array $b ) {
		$sum = 0.0;
		$n   = count( $a );
		for ( $i = 0; $i < $n; $i++ ) {
			$diff = $a[ $i ] - $b[ $i ];
			$sum += $diff * $diff;
		}
		return sqrt( $sum );
	}

	/**
	 * Generate a human-readable label for a cluster from post titles.
	 *
	 * @param array $post_ids Post IDs in the cluster.
	 * @return string Cluster label.
	 */
	private function generate_label( array $post_ids ) {
		$all_words  = [];
		$stop_words = $this->french_stop_words();

		foreach ( array_slice( $post_ids, 0, 20 ) as $pid ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}

			$title = mb_strtolower( $post->post_title );
			$title = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $title );
			$words = array_filter( explode( ' ', $title ), function ( $w ) {
				return mb_strlen( $w ) >= 3;
			} );

			foreach ( $words as $word ) {
				if ( isset( $stop_words[ $word ] ) ) {
					continue;
				}
				if ( ! isset( $all_words[ $word ] ) ) {
					$all_words[ $word ] = 0;
				}
				$all_words[ $word ]++;
			}
		}

		arsort( $all_words );

		$top = array_slice( array_keys( $all_words ), 0, 3 );

		return ! empty( $top ) ? implode( ', ', $top ) : __( 'Cluster', 'maillage-interne' );
	}

	/**
	 * French stop words for label generation.
	 *
	 * @return array
	 */
	private function french_stop_words() {
		return array_fill_keys( [
			'les', 'des', 'une', 'pour', 'dans', 'avec', 'sur', 'par',
			'que', 'qui', 'est', 'son', 'tout', 'plus', 'pas', 'mais',
			'comme', 'sont', 'aux', 'entre', 'sans', 'sous', 'cette',
			'ces', 'nos', 'vos', 'très', 'bien', 'comment', 'faire',
		], true );
	}
}
