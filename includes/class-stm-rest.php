<?php
/**
 * REST API used by the booking form. On a "remote" site it forwards to the main site.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Rest {

	const NS = 'stm/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/themes',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'themes' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/coupon',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'coupon' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/book',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'book' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Cerere către site-ul principal (modul „afișare de pe alt site”). Erorile de acolo (cod +
	 * mesaj) se întorc neschimbate, ca formularul să le poată arăta lângă câmpul potrivit.
	 */
	public static function remote( $path, $args = array(), $query = array() ) {
		$url      = add_query_arg( array( 'rest_route' => '/' . self::NS . $path ) + $query, STM_Helpers::remote_url() . '/' );
		$response = isset( $args['body'] ) ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			$msg = isset( $args['body'] ) ? 'Cererea nu a putut fi trimisă acum. Încearcă din nou peste câteva minute.' : 'Programul nu poate fi încărcat acum. Încearcă din nou peste câteva minute.';
			return new WP_Error( 'stm_remote', $msg, array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'Eroare la server. Încearcă din nou.';
			return new WP_Error( is_array( $data ) && ! empty( $data['code'] ) ? $data['code'] : 'stm_remote', $message, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : new WP_Error( 'stm_remote', 'Răspuns invalid de la server.', array( 'status' => 502 ) );
	}

	/**
	 * Răspuns care nu se păstrează în cache (LiteSpeed Cache, CDN, browser): programul și
	 * setările se schimbă de la o rezervare la alta.
	 */
	public static function no_cache( $data ) {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		do_action( 'litespeed_control_set_nocache', 'stm: program live' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- hook LiteSpeed Cache.
		return $response;
	}

	/**
	 * Setările de card cadou de care are nevoie formularul (de pe site-ul principal).
	 */
	public static function gift_config() {
		return array(
			'on'      => (bool) STM_Helpers::get( 'gift_enabled' ) && STM_Giftcard_Image::available(),
			'instant' => 'instant' === STM_Helpers::get( 'gift_send' ),
		);
	}

	public static function themes( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( STM_Helpers::is_remote() ) {
			$key    = 'stm_remote_' . $id;
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return self::no_cache( $cached );
			}
			$data = self::remote( '/themes', array( 'timeout' => 10 ), $id ? array( 'id' => $id ) : array() );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			// The page renders these as HTML; re-filter what came from the other site.
			foreach ( (array) ( isset( $data['themes'] ) ? $data['themes'] : array() ) as $i => $theme ) {
				$data['themes'][ $i ]['content']   = wp_kses_post( isset( $theme['content'] ) ? (string) $theme['content'] : '' );
				$data['themes'][ $i ]['gdpr_text'] = wp_kses_post( isset( $theme['gdpr_text'] ) ? (string) $theme['gdpr_text'] : '' );
				$data['themes'][ $i ]['image']     = esc_url_raw( isset( $theme['image'] ) ? (string) $theme['image'] : '' );
			}
			if ( isset( $data['gift'] ) && is_array( $data['gift'] ) ) {
				$data['gift'] = array(
					'on'      => ! empty( $data['gift']['on'] ),
					'instant' => ! empty( $data['gift']['instant'] ),
				);
			}
			set_transient( $key, $data, 30 );
			return self::no_cache( $data );
		}

		$ids    = $id ? array( $id ) : STM_Helpers::open_theme_ids();
		$themes = array();
		foreach ( $ids as $theme_id ) {
			$theme = STM_Helpers::theme_public( $theme_id );
			if ( $theme ) {
				$themes[] = $theme;
			}
		}
		return self::no_cache(
			array(
				'themes' => $themes,
				'gift'   => self::gift_config(),
			)
		);
	}

	/**
	 * Limită de cereri pe oră, pe vizitator. Pe site-ul principal, cererile venite de la un site
	 * secundar au IP-ul vizitatorului în X-STM-Client-IP; antetul poate fi falsificat, așa că mai
	 * există și o limită mare pe IP-ul care face cererea (serverul site-ului secundar).
	 *
	 * @param string $bucket Tipul cererii (book = rezervări și cupoane, gift = carduri cadou).
	 * @param int    $max    Cereri pe oră pentru un vizitator.
	 */
	public static function rate_limited( $bucket = 'book', $max = 8 ) {
		$ip  = STM_Helpers::client_ip();
		$fwd = isset( $_SERVER['HTTP_X_STM_CLIENT_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_STM_CLIENT_IP'] ) ) : '';
		$fwd = filter_var( $fwd, FILTER_VALIDATE_IP ) ? $fwd : '';
		$one = 'stm_rl_' . $bucket . '_' . md5( $ip . '|' . $fwd );
		$all = 'stm_rl_' . $bucket . '_ip_' . md5( $ip );
		$n   = (int) get_transient( $one );
		$t   = (int) get_transient( $all );
		if ( $n >= $max || $t >= $max * 25 ) {
			return true;
		}
		set_transient( $one, $n + 1, HOUR_IN_SECONDS );
		set_transient( $all, $t + 1, HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * Verifică un cod de reducere pentru eveniment + pachet (+ email) și întoarce reducerea.
	 */
	public static function coupon( WP_REST_Request $request ) {
		$in = $request->get_json_params();
		if ( ! is_array( $in ) ) {
			$in = $request->get_body_params();
		}
		$clean = array();
		foreach ( array( 'code', 'theme', 'package', 'email' ) as $f ) {
			$clean[ $f ] = isset( $in[ $f ] ) && is_scalar( $in[ $f ] ) ? sanitize_text_field( (string) $in[ $f ] ) : '';
		}
		if ( self::rate_limited( 'book', 8 ) ) {
			return new WP_Error( 'stm_limit', 'Prea multe încercări. Încearcă din nou mai târziu.', array( 'status' => 429 ) );
		}
		if ( STM_Helpers::is_remote() ) {
			$result = self::remote(
				'/coupon',
				array(
					'timeout' => 10,
					'headers' => array(
						'Content-Type'    => 'application/json',
						'X-STM-Client-IP' => STM_Helpers::client_ip(),
					),
					'body'    => wp_json_encode( $clean ),
				)
			);
			return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
		}
		$theme = STM_Helpers::theme_public( absint( $clean['theme'] ) );
		if ( ! $theme ) {
			return new WP_Error( 'stm_theme', 'Evenimentul nu mai este disponibil.', array( 'status' => 404 ) );
		}
		$package = null;
		foreach ( $theme['packages'] as $p ) {
			if ( $p['id'] === (int) $clean['package'] ) {
				$package = $p;
			}
		}
		if ( ! $package ) {
			return new WP_Error( 'stm_package', 'Alege un pachet.', array( 'status' => 400 ) );
		}
		$c = STM_Coupons::validate( $clean['code'], $theme['id'], $package['name'], $package['price'], sanitize_email( $clean['email'] ) );
		if ( empty( $c['ok'] ) ) {
			return new WP_Error( 'stm_coupon', $c['message'], array( 'status' => 400 ) );
		}
		return rest_ensure_response(
			array(
				'ok'       => true,
				'code'     => $c['code'],
				'label'    => $c['label'],
				'price'    => $c['price'],
				'discount' => $c['discount'],
				'total'    => $c['total'],
				'message'  => $c['message'],
			)
		);
	}

	public static function book( WP_REST_Request $request ) {
		$in = $request->get_json_params();
		if ( ! is_array( $in ) ) {
			$in = $request->get_body_params();
		}

		// Honeypot: real visitors never fill this field.
		if ( ! empty( $in['website'] ) ) {
			return new WP_Error( 'stm_spam', 'Cererea nu a putut fi trimisă.', array( 'status' => 400 ) );
		}
		if ( self::rate_limited( 'book', 8 ) ) {
			return new WP_Error( 'stm_limit', 'Prea multe încercări. Încearcă din nou mai târziu sau sună-ne.', array( 'status' => 429 ) );
		}

		$fields = array( 'theme', 'date', 'time', 'package', 'name', 'phone', 'email', 'people', 'notes', 'consent', 'coupon' );
		$clean  = array();
		foreach ( $fields as $f ) {
			$clean[ $f ] = isset( $in[ $f ] ) && is_scalar( $in[ $f ] ) ? (string) $in[ $f ] : '';
		}

		if ( STM_Helpers::is_remote() ) {
			$clean['source'] = wp_parse_url( home_url(), PHP_URL_HOST );
			$result          = self::remote(
				'/book',
				array(
					'timeout' => 15,
					'headers' => array(
						'Content-Type'    => 'application/json',
						'X-STM-Client-IP' => STM_Helpers::client_ip(),
					),
					'body'    => wp_json_encode( $clean ),
				)
			);
			delete_transient( 'stm_remote_0' );
			delete_transient( 'stm_remote_' . absint( $clean['theme'] ) );
			return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
		}

		$clean['source'] = isset( $in['source'] ) && is_scalar( $in['source'] ) ? sanitize_text_field( (string) $in['source'] ) : wp_parse_url( home_url(), PHP_URL_HOST );
		$id              = STM_Bookings::create( $clean );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$b     = STM_Bookings::get( $id );
		$theme = STM_Helpers::theme_meta( $b['theme'] );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'status'  => $b['status'],
				'message' => STM_Helpers::get( 'success_text' ),
				'summary' => array(
					'theme'    => get_the_title( $b['theme'] ),
					'date'     => STM_Helpers::date_label( substr( $b['start'], 0, 10 ) ),
					'time'     => substr( $b['start'], 11, 5 ),
					'package'  => $b['package'],
					'price'    => STM_Helpers::price_label( $b['price'] ),
					'coupon'   => $b['coupon'],
					'discount' => '' !== (string) $b['discount'] ? STM_Helpers::money( $b['discount'] ) : '',
					'total'    => STM_Helpers::money( STM_Helpers::amount( $b ) ),
					'location' => $theme['location'],
				),
			)
		);
	}
}
