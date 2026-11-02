<?php
/**
 * Shortcode [sterea_mini] care montează formularul de rezervare (și de card cadou).
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
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'cadou' => 0,
			),
			$atts,
			'sterea_mini'
		);

		wp_enqueue_style( 'stm-front' );
		$accent = sanitize_hex_color( (string) STM_Helpers::get( 'accent' ) );
		if ( $accent ) {
			wp_add_inline_style( 'stm-front', '.stm-app{--stm-accent:' . $accent . '}' );
		}
		wp_enqueue_script( 'stm-front' );

		$api    = add_query_arg( 'rest_route', '/' . STM_Rest::NS, home_url( '/' ) );
		// Pe site-ul principal contează setarea locală; pe un site secundar decide site-ul principal.
		// Formularul ia oricum valorile la zi din /themes (pagina poate fi în cache), acestea sunt doar rezerva.
		$gift   = STM_Helpers::is_remote() || STM_Helpers::get( 'gift_enabled' );
		$models = array();
		foreach ( array( 'craciun', 'negru' ) as $style ) {
			$model            = STM_Giftcards::model( $style );
			$models[ $style ] = array(
				'src'   => $model['src'],
				'small' => $model['small'],
				'alt'   => $model['alt'],
			);
		}
		$config = array(
			'phone'       => (string) STM_Helpers::get( 'contact_phone' ),
			'gift'        => (bool) $gift,
			'giftFirst'   => (bool) absint( $atts['cadou'] ),
			'giftInstant' => 'instant' === STM_Helpers::get( 'gift_send' ),
			'occasions'   => STM_Giftcards::occasions(),
			'model'       => array(
				'w'      => STM_Giftcards::MODEL_W,
				'h'      => STM_Giftcards::MODEL_H,
				'styles' => $models,
			),
		);

		return sprintf(
			'<div class="stm-app" data-api="%s" data-theme="%d" data-config="%s" aria-live="polite"><p class="stm-loading"><span class="stm-spinner"></span>Se încarcă programul…</p><noscript>Pentru rezervare este nevoie de JavaScript activat în browser.</noscript></div>',
			esc_url( $api ),
			absint( $atts['id'] ),
			esc_attr( wp_json_encode( $config ) )
		);
	}
}
