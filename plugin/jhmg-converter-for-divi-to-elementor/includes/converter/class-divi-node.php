<?php

namespace DiviElementorConverter\Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiviNode {
	public function __construct(
		public readonly string $tag,
		public readonly array  $attrs,
		public readonly string $content,
		/** @var DiviNode[] */
		public readonly array  $children
	) {}

	public function attr( string $key, string $default = '' ): string {
		return $this->attrs[ $key ] ?? $default;
	}
}
