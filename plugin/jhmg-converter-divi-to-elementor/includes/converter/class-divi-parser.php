<?php

namespace DiviElementorConverter\Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiviParser {
	/** Tags that must be explicitly closed; leaf modules are auto-closed if left open. */
	private const STRUCTURAL_TAGS = [ 'section', 'row', 'row_inner', 'column', 'column_inner' ];
	/**
	 * Parse a Divi export JSON file and return top-level DiviNode array.
	 *
	 * Handles both et_builder (standard page export) and
	 * et_theme_builder (theme template export) contexts.
	 *
	 * @return DiviNode[]
	 * @throws \InvalidArgumentException on invalid JSON.
	 */
	public function parse_json( string $json ): array {
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \InvalidArgumentException( 'Invalid JSON: ' . esc_html( json_last_error_msg() ) );
		}

		$nodes = [];

		if ( ( $data['context'] ?? '' ) === 'et_theme_builder' ) {
			foreach ( $data['layouts'] ?? [] as $layout ) {
				foreach ( $layout['data'] ?? [] as $shortcode_str ) {
					$nodes = array_merge( $nodes, $this->parse_shortcodes( (string) $shortcode_str ) );
				}
			}
			return $nodes;
		}

		// Divi library layout export (et_builder_layouts): data values are post objects.
		if ( ( $data['context'] ?? '' ) === 'et_builder_layouts' ) {
			foreach ( $data['data'] ?? [] as $post_obj ) {
				if ( is_array( $post_obj ) && isset( $post_obj['post_content'] ) ) {
					$nodes = array_merge( $nodes, $this->parse_shortcodes( (string) $post_obj['post_content'] ) );
				}
			}
			return $nodes;
		}

		// Standard et_builder export: data values are shortcode strings.
		foreach ( $data['data'] ?? [] as $shortcode_str ) {
			$nodes = array_merge( $nodes, $this->parse_shortcodes( (string) $shortcode_str ) );
		}

		return $nodes;
	}

	/**
	 * Parse a raw Divi shortcode string into a DiviNode tree.
	 *
	 * @return DiviNode[]
	 */
	public function parse_shortcodes( string $input ): array {
		// Divi encodes newlines as <!-- [et_pb_line_break_holder] --> (inside HTML
		// content) or bare [et_pb_line_break_holder] (in some attribute contexts).
		// Decode both before tokenizing so they don't get parsed as Divi modules
		// and accidentally split content like <script> blocks.
		$input = str_replace( '<!-- [et_pb_line_break_holder] -->', "\n", $input );
		$input = str_replace( '[et_pb_line_break_holder]', "\n", $input );

		$tokens = $this->tokenize( $input );
		return $this->build_tree( $tokens );
	}

	/**
	 * Parse a Divi JSON export into an array of named layouts.
	 *
	 * Handles all three Divi export contexts and always returns the same shape:
	 *   [['title' => string, 'nodes' => DiviNode[]], ...]
	 *
	 * - et_builder_layouts: one entry per layout in data[].
	 * - et_theme_builder:   one entry per template layout (header/footer/body).
	 * - et_builder (default): single entry with empty title.
	 *
	 * @return array<int, array{title: string, nodes: DiviNode[]}>
	 * @throws \InvalidArgumentException on invalid JSON.
	 */
	public function parse_layouts( string $json ): array {
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \InvalidArgumentException( 'Invalid JSON: ' . esc_html( json_last_error_msg() ) );
		}

		$context = $data['context'] ?? '';

		// Divi Builder Library export — one entry per saved layout.
		if ( $context === 'et_builder_layouts' ) {
			$results = [];
			foreach ( $data['data'] ?? [] as $post_obj ) {
				if ( is_array( $post_obj ) && isset( $post_obj['post_content'] ) ) {
					$results[] = [
						'title' => (string) ( $post_obj['post_title'] ?? '' ),
						'nodes' => $this->parse_shortcodes( (string) $post_obj['post_content'] ),
					];
				}
			}
			return $results;
		}

		// Theme Builder export — one entry per layout template.
		if ( $context === 'et_theme_builder' ) {
			$role_map = [];
			foreach ( $data['templates'] ?? [] as $template ) {
				foreach ( $template['layouts'] ?? [] as $role => $info ) {
					$id = (string) ( $info['id'] ?? 0 );
					if ( $id !== '0' && ! isset( $role_map[ $id ] ) ) {
						$role_map[ $id ] = $role;
					}
				}
			}
			$results = [];
			$seen    = [];
			foreach ( $data['layouts'] ?? [] as $id => $layout ) {
				$id = (string) $id;
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$nodes       = [];
				foreach ( $layout['data'] ?? [] as $shortcode_str ) {
					if ( is_string( $shortcode_str ) ) {
						$nodes = array_merge( $nodes, $this->parse_shortcodes( $shortcode_str ) );
					}
				}
				$results[] = [
					'title' => ucfirst( $role_map[ $id ] ?? 'Layout' ),
					'nodes' => $nodes,
				];
			}
			return $results;
		}

		// Standard single-layout export.
		$nodes = [];
		foreach ( $data['data'] ?? [] as $shortcode_str ) {
			$nodes = array_merge( $nodes, $this->parse_shortcodes( (string) $shortcode_str ) );
		}
		return [ [ 'title' => '', 'nodes' => $nodes ] ];
	}

	/**
	 * Parse an et_theme_builder export and return per-layout data with role info.
	 *
	 * Each entry in the returned array describes one layout (header, footer, body,
	 * or unknown) and the DiviNode tree parsed from its shortcode content.
	 *
	 * @return array<int, array{role: string, id: string, nodes: DiviNode[]}>
	 * @throws \InvalidArgumentException on invalid JSON or wrong context.
	 */
	public function parse_theme_builder_layouts( string $json ): array {
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \InvalidArgumentException( 'Invalid JSON: ' . esc_html( json_last_error_msg() ) );
		}

		if ( ( $data['context'] ?? '' ) !== 'et_theme_builder' ) {
			throw new \InvalidArgumentException(
				'JSON context is not et_theme_builder (got: ' . esc_html( $data['context'] ?? 'none' ) . ')'
			);
		}

		// Build layout_id → role map from the templates list.
		$role_map = [];
		foreach ( $data['templates'] ?? [] as $template ) {
			foreach ( $template['layouts'] ?? [] as $role => $info ) {
				$id = (string) ( $info['id'] ?? 0 );
				if ( $id !== '0' && ! isset( $role_map[ $id ] ) ) {
					$role_map[ $id ] = $role; // 'header', 'footer', 'body'
				}
			}
		}

		$results = [];
		$seen    = [];
		foreach ( $data['layouts'] ?? [] as $id => $layout ) {
			$id = (string) $id;
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$nodes = [];
			foreach ( $layout['data'] ?? [] as $shortcode_str ) {
				if ( is_string( $shortcode_str ) ) {
					$nodes = array_merge( $nodes, $this->parse_shortcodes( $shortcode_str ) );
				}
			}

			$results[] = [
				'role'  => $role_map[ $id ] ?? 'unknown',
				'id'    => $id,
				'nodes' => $nodes,
			];
		}

		return $results;
	}

	// -----------------------------------------------------------------------
	// Tokenizer
	// -----------------------------------------------------------------------

	/**
	 * Split the shortcode string into typed tokens.
	 *
	 * Token shapes:
	 *   ['type'=>'open',  'tag'=>string, 'attrs'=>array]
	 *   ['type'=>'close', 'tag'=>string]
	 *   ['type'=>'text',  'content'=>string]
	 */
	private function tokenize( string $input ): array {
		// Match opening tags [et_pb_tag attr="val" ...] and closing tags [/et_pb_tag].
		// Attribute values may contain any character except an unescaped double quote.
		$pattern = '/
			\[et_pb_([a-z0-9_]+)           # (1) opening tag name
			((?:\s+[\w-]+="[^"]*")*)        # (2) attributes  key="val" … (keys may contain hyphens)
			\s*\]
			|
			\[\/et_pb_([a-z0-9_]+)\]        # (3) closing tag name
		/x';

		$tokens   = [];
		$last_end = 0;

		preg_match_all( $pattern, $input, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		foreach ( $matches as $match ) {
			$full   = $match[0][0];
			$offset = (int) $match[0][1];

			// Collect any text/HTML between previous token and this one.
			if ( $offset > $last_end ) {
				$text = substr( $input, $last_end, $offset - $last_end );
				if ( $text !== '' ) {
					$tokens[] = [ 'type' => 'text', 'content' => $text ];
				}
			}

			// Opening vs closing tag.
			if ( isset( $match[1] ) && $match[1][0] !== '' ) {
				$tokens[] = [
					'type'  => 'open',
					'tag'   => $match[1][0],
					'attrs' => $this->parse_attrs( $match[2][0] ?? '' ),
				];
			} else {
				$tokens[] = [ 'type' => 'close', 'tag' => $match[3][0] ];
			}

			$last_end = $offset + strlen( $full );
		}

		// Any trailing text after the last tag.
		if ( $last_end < strlen( $input ) ) {
			$text = substr( $input, $last_end );
			if ( $text !== '' ) {
				$tokens[] = [ 'type' => 'text', 'content' => $text ];
			}
		}

		return $tokens;
	}

	/** Parse the attribute portion of a shortcode tag into a key→value array. */
	private function parse_attrs( string $attrs_string ): array {
		$attrs = [];
		preg_match_all( '/\s+([\w-]+)="([^"]*)"/', $attrs_string, $matches, PREG_SET_ORDER );
		foreach ( $matches as $m ) {
			// Decode HTML entities that Divi writes via esc_attr(), then decode the
			// || double-pipe line-break encoding Divi uses in CSS attribute values.
			$value          = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$attrs[ $m[1] ] = str_replace( '||', "\n", $value );
		}
		return $attrs;
	}

	// -----------------------------------------------------------------------
	// Tree builder
	// -----------------------------------------------------------------------

	/**
	 * Convert a flat token list into a nested DiviNode tree.
	 *
	 * @return DiviNode[]
	 */
	private function build_tree( array $tokens ): array {
		// Each stack frame: ['tag'=>string, 'attrs'=>[], 'children'=>[], 'text_parts'=>[]]
		$stack = [ [ 'tag' => '__root__', 'attrs' => [], 'children' => [], 'text_parts' => [] ] ];

		foreach ( $tokens as $token ) {
			switch ( $token['type'] ) {
				case 'open':
					$stack[] = [
						'tag'        => $token['tag'],
						'attrs'      => $token['attrs'],
						'children'   => [],
						'text_parts' => [],
					];
					break;

				case 'close':
					if ( count( $stack ) < 2 ) {
						break;
					}
					// Some Divi leaf modules (e.g. et_pb_line_break_holder) have no
					// closing tag. Auto-close them so structural tags match correctly.
					while ( count( $stack ) >= 2 ) {
						$top = $stack[ count( $stack ) - 1 ]['tag'];
						if ( $top === $token['tag'] ) {
							break;
						}
						if ( in_array( $top, self::STRUCTURAL_TAGS, true ) ) {
							break; // Never skip a structural tag looking for a match.
						}
						$frame = array_pop( $stack );
						$stack[ count( $stack ) - 1 ]['children'][] = new DiviNode(
							$frame['tag'],
							$frame['attrs'],
							implode( '', $frame['text_parts'] ),
							$frame['children']
						);
					}
					if ( count( $stack ) < 2 || $stack[ count( $stack ) - 1 ]['tag'] !== $token['tag'] ) {
						break; // Still no match; skip the close tag.
					}
					$frame = array_pop( $stack );
					$stack[ count( $stack ) - 1 ]['children'][] = new DiviNode(
						$frame['tag'],
						$frame['attrs'],
						implode( '', $frame['text_parts'] ),
						$frame['children']
					);
					break;

				case 'text':
					$stack[ count( $stack ) - 1 ]['text_parts'][] = $token['content'];
					break;
			}
		}

		return $stack[0]['children'];
	}
}
