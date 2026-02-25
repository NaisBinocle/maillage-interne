<?php
/**
 * Detects internal links in post content.
 */
class MI_Analysis_Link_Detector {

	/**
	 * Parse HTML content and extract internal links.
	 *
	 * @param string $content Post HTML content.
	 * @return array Array of [ 'target_post_id', 'anchor_text', 'context_snippet' ].
	 */
	public function detect_in_content( $content ) {
		if ( empty( $content ) ) {
			return [];
		}

		$site_url = wp_parse_url( home_url(), PHP_URL_HOST );
		$links    = [];

		// Use DOMDocument for robust HTML parsing.
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		$anchors = $doc->getElementsByTagName( 'a' );

		foreach ( $anchors as $anchor ) {
			$href = $anchor->getAttribute( 'href' );
			if ( empty( $href ) ) {
				continue;
			}

			// Resolve relative URLs.
			if ( strpos( $href, '/' ) === 0 ) {
				$href = home_url( $href );
			}

			// Skip external links.
			$parsed = wp_parse_url( $href );
			if ( ! empty( $parsed['host'] ) && $parsed['host'] !== $site_url ) {
				continue;
			}

			// Skip anchors, mailto, tel, etc.
			if ( empty( $parsed['host'] ) && empty( $parsed['path'] ) ) {
				continue;
			}

			// Resolve URL to post ID.
			$target_id = url_to_postid( $href );
			if ( ! $target_id ) {
				continue;
			}

			$anchor_text = trim( $anchor->textContent );

			// Extract surrounding context.
			$context = $this->get_context( $anchor, $doc );

			$links[] = [
				'target_post_id'  => $target_id,
				'anchor_text'     => $anchor_text,
				'context_snippet' => $context,
			];
		}

		return $links;
	}

	/**
	 * Detect links for a specific post (from DB).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function detect_for_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$content = apply_filters( 'the_content', $post->post_content );
		return $this->detect_in_content( $content );
	}

	/**
	 * Get text context around a link node.
	 *
	 * @param \DOMElement  $anchor The <a> element.
	 * @param \DOMDocument $doc    The document.
	 * @return string Context snippet.
	 */
	private function get_context( $anchor, $doc ) {
		$parent = $anchor->parentNode;
		if ( ! $parent ) {
			return '';
		}

		$text = trim( $parent->textContent );
		if ( mb_strlen( $text ) > 500 ) {
			$text = mb_substr( $text, 0, 500 );
		}

		return $text;
	}
}
