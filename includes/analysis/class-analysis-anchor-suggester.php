<?php
/**
 * Suggests anchor text for an internal link.
 *
 * Priority: target H1 > target title > common keywords.
 * Ported from the Python tool's suggerer_ancre() logic.
 */
class MI_Analysis_Anchor_Suggester {

	/**
	 * Suggest anchor text for a link from source to target.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $target_id Target post ID.
	 * @return string Suggested anchor text.
	 */
	public function suggest( $source_id, $target_id ) {
		$target = get_post( $target_id );
		if ( ! $target ) {
			return '';
		}

		// 1. Try H1 from target content.
		$h1 = $this->extract_h1( $target->post_content );
		if ( $h1 && mb_strlen( $h1 ) >= 3 && mb_strlen( $h1 ) <= 80 ) {
			return $h1;
		}

		// 2. Fallback to target title.
		$title = trim( $target->post_title );
		if ( $title && mb_strlen( $title ) <= 80 ) {
			return $title;
		}

		// 3. Truncate long title.
		if ( $title ) {
			return mb_substr( $title, 0, 77 ) . '...';
		}

		return '';
	}

	/**
	 * Suggest anchor text using common keywords between source and target.
	 * Used as a more contextual alternative.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $target_id Target post ID.
	 * @param int $max_words  Max words in anchor.
	 * @return string
	 */
	public function suggest_from_keywords( $source_id, $target_id, $max_words = 5 ) {
		$source = get_post( $source_id );
		$target = get_post( $target_id );

		if ( ! $source || ! $target ) {
			return $this->suggest( $source_id, $target_id );
		}

		$source_words = $this->extract_keywords( $source->post_content . ' ' . $source->post_title );
		$target_words = $this->extract_keywords( $target->post_content . ' ' . $target->post_title );

		$common = array_intersect_key( $source_words, $target_words );

		if ( empty( $common ) ) {
			return $this->suggest( $source_id, $target_id );
		}

		// Sort by combined frequency.
		$scored = [];
		foreach ( $common as $word => $freq ) {
			$scored[ $word ] = $freq + ( $target_words[ $word ] ?? 0 );
		}
		arsort( $scored );

		$top = array_slice( array_keys( $scored ), 0, $max_words );

		$anchor = implode( ' ', $top );

		// If too short, fallback to title.
		if ( mb_strlen( $anchor ) < 3 ) {
			return $this->suggest( $source_id, $target_id );
		}

		return $anchor;
	}

	/**
	 * Extract H1 from HTML content.
	 *
	 * @param string $html HTML content.
	 * @return string|null
	 */
	private function extract_h1( $html ) {
		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches ) ) {
			return trim( wp_strip_all_tags( $matches[1] ) );
		}
		return null;
	}

	/**
	 * Extract keyword frequencies from text.
	 *
	 * @param string $text Raw text (HTML allowed, will be stripped).
	 * @return array [ word => frequency ] sorted by frequency desc.
	 */
	private function extract_keywords( $text ) {
		$text = wp_strip_all_tags( $text );
		$text = mb_strtolower( $text );
		$text = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );

		$words = explode( ' ', trim( $text ) );
		$freq  = [];

		$stop_words = self::french_stop_words();

		foreach ( $words as $word ) {
			if ( mb_strlen( $word ) < 3 ) {
				continue;
			}
			if ( isset( $stop_words[ $word ] ) ) {
				continue;
			}
			if ( ! isset( $freq[ $word ] ) ) {
				$freq[ $word ] = 0;
			}
			$freq[ $word ]++;
		}

		arsort( $freq );

		return $freq;
	}

	/**
	 * French stop words set (ported from the Python tool).
	 *
	 * @return array Associative array for O(1) lookup.
	 */
	private static function french_stop_words() {
		static $words = null;
		if ( null !== $words ) {
			return $words;
		}

		$list = [
			'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'est',
			'en', 'que', 'qui', 'dans', 'ce', 'il', 'ne', 'sur', 'se', 'pas',
			'plus', 'par', 'son', 'pour', 'au', 'avec', 'tout', 'faire', 'on',
			'mais', 'ou', 'comme', 'être', 'avoir', 'dit', 'aussi', 'nous',
			'vous', 'ils', 'elle', 'elles', 'leurs', 'cette', 'ces', 'mon',
			'ton', 'notre', 'votre', 'leur', 'ses', 'mes', 'tes', 'nos', 'vos',
			'aux', 'même', 'autres', 'entre', 'sans', 'sous', 'après', 'avant',
			'chez', 'depuis', 'lors', 'très', 'bien', 'peu', 'encore', 'trop',
			'ici', 'là', 'donc', 'car', 'dont', 'sont', 'été', 'fait', 'peut',
			'doit', 'tout', 'tous', 'toute', 'toutes', 'autre', 'quand', 'comment',
			'alors', 'ainsi', 'cela', 'cet', 'était', 'ont', 'été', 'vers',
		];

		$words = array_fill_keys( $list, true );

		return $words;
	}
}
