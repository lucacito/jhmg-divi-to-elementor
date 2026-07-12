<?php

namespace DiviElementorConverter\Admin;

use DiviElementorConverter\Converter\DiviParser;
use DiviElementorConverter\Converter\ElementorBuilder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BatchImporter {
    private DiviParser       $parser;
    private ElementorBuilder $builder;

    public function __construct() {
        $this->parser  = new DiviParser();
        $this->builder = new ElementorBuilder();
    }

    /**
     * Parse a Divi JSON export and convert every layout into an Elementor post.
     *
     * @param  string $json     Raw JSON content.
     * @param  string $filename Original file name (used as title fallback).
     * @param  array  $options  Accepts: post_type ('page'|'post'), post_status ('draft'|'publish').
     * @return array[]          Per-layout results: [title, post_id, success, error].
     */
    public function import( string $json, string $filename, array $options = [] ): array {
        $post_type     = $options['post_type']   ?? 'page';
        $post_status   = $options['post_status'] ?? 'draft';
        $fallback_title = pathinfo( $filename, PATHINFO_FILENAME );

        try {
            $layouts = $this->parser->parse_layouts( $json );
        } catch ( \InvalidArgumentException $e ) {
            return [ $this->fail_result( $fallback_title, $e->getMessage() ) ];
        }

        if ( empty( $layouts ) ) {
            return [ $this->fail_result( $fallback_title, __( 'No layouts found in the file.', 'jhmg-converter-for-divi-to-elementor' ) ) ];
        }

        $max     = function_exists( 'apply_filters' ) ? (int) apply_filters( 'jhmgcofo_max_layouts', 1 ) : 1;
        $skipped = max( 0, count( $layouts ) - $max );
        $layouts = array_slice( $layouts, 0, $max );

        $results = [];
        foreach ( $layouts as $layout ) {
            $title     = $layout['title'] !== '' ? $layout['title'] : $fallback_title;
            $results[] = $this->import_layout( $layout['nodes'], $title, $post_type, $post_status );
        }

        if ( $skipped > 0 ) {
            $results[ array_key_last( $results ) ]['report']['warnings'][] = sprintf(
                'This export contains %d more layout(s). The Pro add-on converts every layout in one run — https://divi5lab.com/plugins/divi-to-elementor?utm_source=plugin&utm_medium=upsell',
                $skipped
            );
        }

        return $results;
    }

    private function import_layout( array $nodes, string $title, string $post_type, string $post_status ): array {
        try {
            $elementor_data  = $this->builder->build( $nodes );
            $module_warnings = $this->builder->get_warnings();

            if ( empty( $elementor_data ) ) {
                return $this->fail_result(
                    $title,
                    __( 'No Elementor sections were generated. The layout may be empty.', 'jhmg-converter-for-divi-to-elementor' )
                );
            }

            $post_id = wp_insert_post(
                [
                    'post_title'   => $title !== '' ? $title : ( 'Converted from Divi – ' . current_time( 'Y-m-d H:i' ) ),
                    'post_status'  => $post_status,
                    'post_type'    => $post_type,
                    'post_content' => '',
                ],
                true
            );

            if ( is_wp_error( $post_id ) ) {
                return $this->fail_result( $title, $post_id->get_error_message() );
            }

            $post_id = (int) $post_id;

            update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) );
            update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
            update_post_meta( $post_id, '_elementor_version', '3.21.0' );
            update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );

            $result = [
                'title'   => $title,
                'post_id' => $post_id,
                'success' => true,
                'error'   => '',
            ];
            if ( ! empty( $module_warnings ) ) {
                $result['report']['warnings'] = $module_warnings;
            }
            return $result;
        } catch ( \Throwable $e ) {
            return $this->fail_result( $title, $e->getMessage() );
        }
    }

    private function fail_result( string $title, string $error ): array {
        return [
            'title'   => $title,
            'post_id' => 0,
            'success' => false,
            'error'   => $error,
        ];
    }
}
