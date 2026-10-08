<?php
/**
 * Deterministic HTML -> Markdown content extraction.
 *
 * No LLM, no arbitrary shortcode execution. Shortcodes are allowlisted
 * (Divi's own et_pb_* builder tags plus a small core-safe set); anything
 * else is unwrapped with a regex before do_shortcode() ever runs, so a
 * third-party shortcode's PHP callback is never invoked during export.
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orbitali_Content_Extractor {

	/** Tags whose entire subtree is dropped - scripts, chrome, inputs. */
	private static $drop_tags = array(
		'script', 'style', 'noscript', 'nav', 'footer', 'form', 'header',
		'iframe', 'object', 'embed', 'button', 'input', 'select', 'textarea',
		'svg', 'canvas', 'audio', 'video', 'aside', 'figcaption',
	);

	/** Shortcode tags allowed to actually execute their PHP callback. */
	private static $allowed_shortcode_exact = array( 'caption' );
	private static $allowed_shortcode_prefixes = array( 'et_pb_' );

	/**
	 * @return array{markdown:string,body_markdown:string,empty:bool,warnings:string[]}
	 */
	public static function extract( WP_Post $post ) {
		$warnings = array();

		$raw = (string) $post->post_content;
		if ( '' === trim( $raw ) ) {
			return array(
				'markdown'      => '',
				'body_markdown' => '',
				'empty'         => true,
				'warnings'      => array( __( 'This content is empty.', 'orbitali' ) ),
			);
		}

		list( $filtered, $shortcode_warnings ) = self::unwrap_disallowed_shortcodes( $raw );
		$warnings = array_merge( $warnings, $shortcode_warnings );

		// do_blocks() expands block-editor content (including dynamic core
		// blocks); it runs before shortcode handling because WordPress's own
		// content pipeline does the same. wptexturize() is deliberately
		// skipped - its cosmetic quote/dash substitutions aren't needed here
		// and would just make hashes less predictable.
		$html = do_shortcode( do_blocks( $filtered ) );
		$html = wpautop( $html );

		$body_markdown = self::html_to_markdown( $html, $warnings );
		$body_markdown = self::collapse_whitespace( $body_markdown );

		$is_empty = '' === trim( $body_markdown );
		if ( $is_empty ) {
			$warnings[] = __( 'No readable text content could be extracted from this page.', 'orbitali' );
		}

		$title = get_the_title( $post );
		$url   = Orbitali_Document_Naming::build_canonical_url( $post );
		$date  = get_post_modified_time( 'c', true, $post );

		$header = "# {$title}\n\n" .
			sprintf( /* translators: %s: canonical URL */ __( 'Source: %s', 'orbitali' ), $url ) . "\n" .
			sprintf( /* translators: %s: ISO 8601 date */ __( 'Last updated: %s', 'orbitali' ), $date ) . "\n\n";

		return array(
			'markdown'      => $header . $body_markdown,
			'body_markdown' => $body_markdown,
			'empty'         => $is_empty,
			'warnings'      => array_values( array_unique( $warnings ) ),
		);
	}

	/**
	 * Removes shortcode markup for every registered tag that is not on the
	 * allowlist, keeping enclosed text (so authored content inside an
	 * unsupported wrapping shortcode is not silently lost) but never calling
	 * the shortcode's callback.
	 *
	 * @return array{0:string,1:string[]}
	 */
	private static function unwrap_disallowed_shortcodes( $content ) {
		global $shortcode_tags;

		if ( empty( $shortcode_tags ) || ! is_array( $shortcode_tags ) ) {
			return array( $content, array() );
		}

		$disallowed = array();
		foreach ( array_keys( $shortcode_tags ) as $tag ) {
			if ( self::is_allowed_shortcode( $tag ) ) {
				continue;
			}
			$disallowed[] = $tag;
		}

		if ( empty( $disallowed ) ) {
			return array( $content, array() );
		}

		$pattern = get_shortcode_regex( $disallowed );
		$removed = array();

		$result = preg_replace_callback(
			"/{$pattern}/s",
			function ( $m ) use ( &$removed ) {
				$tag = $m[2];
				$inner = isset( $m[5] ) ? $m[5] : '';
				if ( '' === trim( $inner ) ) {
					$removed[] = $tag;
				}
				return $m[1] . $inner . $m[6];
			},
			$content
		);

		$warnings = array();
		$removed  = array_values( array_unique( $removed ) );
		if ( ! empty( $removed ) ) {
			$warnings[] = sprintf(
				/* translators: %s: comma separated shortcode tag names */
				__( 'Excluded unsupported shortcode(s), which were not executed: %s', 'orbitali' ),
				implode( ', ', $removed )
			);
		}

		return array( null === $result ? $content : $result, $warnings );
	}

	private static function is_allowed_shortcode( $tag ) {
		if ( in_array( $tag, self::$allowed_shortcode_exact, true ) ) {
			return true;
		}
		foreach ( self::$allowed_shortcode_prefixes as $prefix ) {
			if ( 0 === strpos( $tag, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	private static function html_to_markdown( $html, array &$warnings ) {
		$dom = new DOMDocument();
		$internal_errors = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?><div id="orbitali-root">' . $html . '</div>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $internal_errors );

		// Not getElementById(): that relies on libxml recognizing "id" as a
		// DTD ID-type attribute, which an arbitrary HTML fragment doesn't
		// guarantee. XPath has no such dependency.
		$matches = ( new DOMXPath( $dom ) )->query( '//*[@id="orbitali-root"]' );
		$root    = $matches->length > 0 ? $matches->item( 0 ) : null;
		if ( ! $root ) {
			return '';
		}

		self::strip_drop_tags( $root );

		return trim( self::node_children_to_markdown( $root ) );
	}

	private static function strip_drop_tags( DOMNode $node ) {
		$xpath   = new DOMXPath( $node->ownerDocument );
		$pattern = './/*[' . implode( ' or ', array_map( function ( $tag ) {
			return "local-name()='{$tag}'";
		}, self::$drop_tags ) ) . ']';

		foreach ( $xpath->query( $pattern, $node ) as $element ) {
			if ( $element->parentNode ) {
				$element->parentNode->removeChild( $element );
			}
		}
	}

	private static function node_children_to_markdown( DOMNode $node ) {
		$parts = array();
		foreach ( $node->childNodes as $child ) {
			$parts[] = self::node_to_markdown( $child );
		}
		return implode( '', $parts );
	}

	private static function node_to_markdown( DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return preg_replace( '/\s+/', ' ', $node->textContent );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag   = strtolower( $node->nodeName );
		$inner = self::node_children_to_markdown( $node );

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				return "\n\n" . str_repeat( '#', $level ) . ' ' . trim( $inner ) . "\n\n";

			case 'p':
			case 'div':
				return "\n\n" . trim( $inner ) . "\n\n";

			case 'br':
				return "\n";

			case 'hr':
				return "\n\n---\n\n";

			case 'strong':
			case 'b':
				return trim( $inner ) === '' ? '' : '**' . trim( $inner ) . '**';

			case 'em':
			case 'i':
				return trim( $inner ) === '' ? '' : '_' . trim( $inner ) . '_';

			case 'a':
				$href = $node->getAttribute( 'href' );
				$text = trim( $inner );
				if ( '' === $text ) {
					return '';
				}
				if ( '' === $href ) {
					return $text;
				}
				return '[' . $text . '](' . self::absolutize_url( $href ) . ')';

			case 'ul':
				return "\n\n" . self::list_items_to_markdown( $node, false ) . "\n\n";

			case 'ol':
				return "\n\n" . self::list_items_to_markdown( $node, true ) . "\n\n";

			case 'li':
				return trim( $inner );

			case 'blockquote':
				$lines = array_filter( array_map( 'trim', explode( "\n", trim( $inner ) ) ) );
				return "\n\n" . implode( "\n", array_map( function ( $line ) {
					return '> ' . $line;
				}, $lines ) ) . "\n\n";

			case 'table':
				return "\n\n" . self::table_to_markdown( $node ) . "\n\n";

			default:
				return $inner;
		}
	}

	private static function list_items_to_markdown( DOMNode $list, $ordered ) {
		$lines = array();
		$index = 1;
		foreach ( $list->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}
			$text    = trim( self::node_to_markdown( $child ) );
			$marker  = $ordered ? ( $index . '.' ) : '-';
			$lines[] = $marker . ' ' . $text;
			$index++;
		}
		return implode( "\n", $lines );
	}

	private static function table_to_markdown( DOMNode $table ) {
		$rows = array();
		$xpath = new DOMXPath( $table->ownerDocument );
		foreach ( $xpath->query( './/tr', $table ) as $tr ) {
			$cells = array();
			foreach ( $xpath->query( './th|./td', $tr ) as $cell ) {
				$cells[] = trim( preg_replace( '/\s+/', ' ', self::node_children_to_markdown( $cell ) ) );
			}
			if ( ! empty( $cells ) ) {
				$rows[] = $cells;
			}
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$column_count = max( array_map( 'count', $rows ) );
		$lines        = array();
		$lines[]      = '| ' . implode( ' | ', self::pad_row( $rows[0], $column_count ) ) . ' |';
		$lines[]      = '| ' . implode( ' | ', array_fill( 0, $column_count, '---' ) ) . ' |';
		for ( $i = 1; $i < count( $rows ); $i++ ) {
			$lines[] = '| ' . implode( ' | ', self::pad_row( $rows[ $i ], $column_count ) ) . ' |';
		}

		return implode( "\n", $lines );
	}

	private static function pad_row( array $row, $column_count ) {
		while ( count( $row ) < $column_count ) {
			$row[] = '';
		}
		return $row;
	}

	private static function absolutize_url( $href ) {
		if ( preg_match( '~^(https?:)?//~i', $href ) || preg_match( '~^(mailto:|tel:|#)~i', $href ) ) {
			return $href;
		}
		if ( 0 === strpos( $href, '/' ) ) {
			return home_url( $href );
		}
		return $href;
	}

	private static function collapse_whitespace( $text ) {
		$text = preg_replace( '/[ \t]+\n/', "\n", $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		return trim( $text );
	}
}
