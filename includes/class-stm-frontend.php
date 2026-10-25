<?php
/**
 * Shortcode [sterea_mini] that mounts the booking app.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Frontend {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_shortcode( 'sterea_mini', array( __CLASS__, 'shortcode' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 10, 2 );
	}

	public static function register() {
		wp_register_style( 'stm-front', STM_URL . 'assets/css/front.css', array(), STM_VERSION );
		wp_register_script( 'stm-front', STM_URL . 'assets/js/front.js', array(), STM_VERSION, true );
	}

	/**
	 * Fără „delay/defer JS” din LiteSpeed Cache, WP Rocket etc.: altfel formularul rămâne pe
	 * „Se încarcă programul…” până când vizitatorul dă scroll sau atinge ecranul.
	 */
	public static function script_tag( $tag, $handle ) {
		if ( 'stm-front' === $handle ) {
			$tag = str_replace( '<script ', '<script data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false" ', $tag );
		}
		return $tag;
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'sterea_mini' );

		wp_enqueue_style( 'stm-front' );
		$accent = sanitize_hex_color( (string) STM_Helpers::get( 'accent' ) );
		if ( $accent ) {
			wp_add_inline_style( 'stm-front', '.stm-app{--stm-accent:' . $accent . '}' );
		}
		wp_enqueue_script( 'stm-front' );

		$api    = add_query_arg( 'rest_route', '/' . STM_Rest::NS, home_url( '/' ) );
		$config = array(
			'phone' => (string) STM_Helpers::get( 'contact_phone' ),
		);

		return sprintf(
			'<div class="stm-app" data-api="%s" data-theme="%d" data-config="%s" aria-live="polite"><p class="stm-loading"><span class="stm-spinner"></span>Se încarcă programul…</p><noscript>Pentru rezervare este nevoie de JavaScript activat în browser.</noscript></div>',
			esc_url( $api ),
			absint( $atts['id'] ),
			esc_attr( wp_json_encode( $config ) )
		);
	}
}
