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
	}

	public static function register() {
		wp_register_style( 'stm-front', STM_URL . 'assets/css/front.css', array(), STM_VERSION );
		wp_register_script( 'stm-front', STM_URL . 'assets/js/front.js', array(), STM_VERSION, true );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'sterea_mini' );

		wp_enqueue_style( 'stm-front' );
		$accent = sanitize_hex_color( (string) STM_Helpers::get( 'accent' ) );
		if ( $accent ) {
			wp_add_inline_style( 'stm-front', '.stm-app{--stm-accent:' . $accent . '}' );
		}
		wp_add_inline_style( 'stm-front', self::extra_css() );
		wp_enqueue_script( 'stm-front' );

		$api = add_query_arg( 'rest_route', '/' . STM_Rest::NS, home_url( '/' ) );

		return sprintf(
			'<div class="stm-app" data-api="%s" data-theme="%d" aria-live="polite"><p class="stm-loading">Se încarcă programul…</p><noscript>Pentru rezervare este nevoie de JavaScript activat în browser.</noscript></div>',
			esc_url( $api ),
			absint( $atts['id'] )
		);
	}

	/**
	 * Delimitare cromatica pasi + pachete (2026-10-17).
	 */
	public static function extra_css() {
		return <<<'CSS'
.stm-app{--stm-p1:#b08d57;--stm-p1-soft:#faf6ee;--stm-p2:#2f5d50;--stm-p2-soft:#eef4f1;--stm-day:#1f4f7a;--stm-day-soft:#eef3f8}
.stm-app .stm-step h4{display:flex;align-items:center;gap:10px;font-size:1.1em;margin-bottom:14px}
.stm-app .stm-step h4::before{content:"";display:inline-block;width:5px;height:22px;border-radius:3px;background:var(--stm-p2);flex:0 0 5px}
.stm-app .stm-step:has(.stm-days) h4::before,.stm-app .stm-step:has(.stm-slots) h4::before{background:var(--stm-day)}
.stm-app .stm-package{position:relative;padding:18px 56px 18px 18px;border-top-width:5px;gap:6px;transition:border-color .15s,background .15s,box-shadow .15s,transform .15s}
.stm-app .stm-package::after{content:"";position:absolute;top:18px;right:16px;width:24px;height:24px;border-radius:50%;border:2px solid #cfd4d9;background:#fff;box-sizing:border-box;transition:all .15s}
.stm-app .stm-package:nth-child(1){border-top-color:var(--stm-p1)}
.stm-app .stm-package:nth-child(1) .stm-package-price{color:var(--stm-p1)}
.stm-app .stm-package:nth-child(2){border-top-color:var(--stm-p2)}
.stm-app .stm-package:nth-child(2) .stm-package-price{color:var(--stm-p2)}
.stm-app .stm-package:hover:not(.is-on){box-shadow:0 6px 18px rgba(0,0,0,.08);transform:translateY(-1px)}
.stm-app .stm-package:nth-child(1):hover:not(.is-on){border-color:var(--stm-p1);background:var(--stm-p1-soft)}
.stm-app .stm-package:nth-child(2):hover:not(.is-on){border-color:var(--stm-p2);background:var(--stm-p2-soft)}
.stm-app .stm-package:hover:not(.is-on)::after{border-color:currentColor}
.stm-app .stm-package:nth-child(1).is-on{border-color:var(--stm-p1);background:var(--stm-p1-soft);box-shadow:0 0 0 4px rgba(176,141,87,.18)}
.stm-app .stm-package:nth-child(2).is-on{border-color:var(--stm-p2);background:var(--stm-p2-soft);box-shadow:0 0 0 4px rgba(47,93,80,.18)}
.stm-app .stm-package.is-on::after{content:"✓";color:#fff;font-size:14px;font-weight:700;line-height:20px;text-align:center;border-color:transparent}
.stm-app .stm-package:nth-child(1).is-on::after{background:var(--stm-p1)}
.stm-app .stm-package:nth-child(2).is-on::after{background:var(--stm-p2)}
.stm-app .stm-step:has(.stm-days),.stm-app .stm-step:has(.stm-slots){margin:16px 16px 8px;padding:18px 16px 16px;background:var(--stm-day-soft);border-radius:var(--stm-radius)}
.stm-app .stm-step:has(.stm-days)+.stm-step:has(.stm-slots){margin-top:0}
.stm-app .stm-day{border-color:#c9d6e2;border-radius:999px;padding:10px 18px;transition:border-color .15s,background .15s,box-shadow .15s,transform .15s}
.stm-app .stm-day:hover:not(:disabled):not(.is-on){border-color:var(--stm-day);background:#fff;box-shadow:0 6px 18px rgba(31,79,122,.12);transform:translateY(-1px)}
.stm-app .stm-day.is-on{background:var(--stm-day);border-color:var(--stm-day);color:#fff;box-shadow:0 0 0 4px rgba(31,79,122,.15)}
.stm-app .stm-day.is-on .stm-day-label::before{content:"✓ "}
.stm-app .stm-day .stm-day-free{font-size:.85em;opacity:.85}
.stm-app .stm-day:disabled{opacity:.45;text-decoration:line-through;cursor:not-allowed}
.stm-app .stm-slot.is-on,.stm-app .stm-slot[aria-pressed="true"]{background:var(--stm-day);border-color:var(--stm-day);color:#fff}
@media (max-width:600px){.stm-app .stm-step:has(.stm-days),.stm-app .stm-step:has(.stm-slots){margin:12px 10px 6px;padding:14px 12px 12px}.stm-app .stm-day{width:100%}}
.stm-app .stm-field.is-invalid input,.stm-app .stm-field.is-invalid textarea,.stm-app .stm-field.is-invalid select{border-color:#c0392b;box-shadow:0 0 0 3px rgba(192,57,43,.15);background:#fff8f7}
.stm-app .stm-field.is-invalid>span{color:#c0392b;font-weight:700}
.stm-app .stm-consent.is-invalid{color:#c0392b}
.stm-app .stm-consent.is-invalid input{outline:2px solid #c0392b;outline-offset:2px}
.stm-app .stm-field-error{display:block;color:#c0392b;font-size:.85em;font-style:normal;margin-top:4px}
.stm-app .stm-error{background:#fdecea;border-left:4px solid #c0392b;padding:10px 12px;border-radius:6px;color:#8a1f12;font-weight:600}
@media (max-width:600px){.stm-app .stm-head--img img{display:none}.stm-app .stm-head .stm-title{display:none}.stm-app .stm-head-text{padding-top:4px}.stm-app .stm-desc{font-size:.92em}.stm-app .stm-step{padding-top:12px}}
CSS;
	}
}
