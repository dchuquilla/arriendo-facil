<?php
/**
 * APISaits Serializer — transforma datos de WP al formato Google segun setmatt.xml.
 *
 * @package Arriendo_Facil\APISaits
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APISaits_Serializer {

	private $config;

	public function __construct( APISaits_Config $config ) {
		$this->config = $config;
	}

	/**
	 * Serializa un WP_Post segun el mapeo declarado.
	 * Los campos no reconocidos se buscan como post_meta.
	 */
	public function serialize_post( WP_Post $post ) {
		$mapping = $this->config->get_mapping();
		$output  = array();

		foreach ( $mapping as $wp_field => $google_field ) {
			switch ( $wp_field ) {
				case 'post_title':
					$output[ $google_field ] = sanitize_text_field( $post->post_title );
					break;
				case 'post_content':
					$output[ $google_field ] = wp_strip_all_tags( $post->post_content );
					break;
				case 'post_url':
					$output[ $google_field ] = esc_url_raw( get_permalink( $post ) );
					break;
				case 'post_date':
					$output[ $google_field ] = mysql2date( 'c', $post->post_date_gmt );
					break;
				case 'post_type':
					$output[ $google_field ] = sanitize_key( $post->post_type );
					break;
				default:
					$meta = get_post_meta( $post->ID, $wp_field, true );
					if ( '' !== $meta && null !== $meta ) {
						$output[ $google_field ] = sanitize_text_field( (string) $meta );
					}
			}
		}

		return $output;
	}

	/**
	 * Payload para Google Indexing API.
	 * Formato oficial: { "url": "...", "type": "URL_UPDATED" | "URL_DELETED" }.
	 */
	public function build_indexing_payload( $url, $type = 'URL_UPDATED' ) {
		$allowed = array( 'URL_UPDATED', 'URL_DELETED' );
		if ( ! in_array( $type, $allowed, true ) ) {
			$type = 'URL_UPDATED';
		}

		return array(
			'url'  => esc_url_raw( $url ),
			'type' => $type,
		);
	}
}
