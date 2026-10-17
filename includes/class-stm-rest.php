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
			'/book',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'book' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function remote( $path, $args = array(), $query = array() ) {
		$url      = add_query_arg( array( 'rest_route' => '/' . self::NS . $path ) + $query, STM_Helpers::remote_url() . '/' );
		$response = isset( $args['body'] ) ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stm_remote', 'Programul nu poate fi încărcat acum. Încearcă din nou peste câteva minute.', array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'Eroare la server. Încearcă din nou.';
			return new WP_Error( is_array( $data ) && ! empty( $data['code'] ) ? $data['code'] : 'stm_remote', $message, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : new WP_Error( 'stm_remote', 'Răspuns invalid de la server.', array( 'status' => 502 ) );
	}

	public static function themes( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( STM_Helpers::is_remote() ) {
			$key    = 'stm_remote_' . $id;
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return rest_ensure_response( $cached );
			}
			$data = self::remote( '/themes', array( 'timeout' => 10 ), $id ? array( 'id' => $id ) : array() );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			// The page renders these as HTML; re-filter what came from the other site.
			foreach ( (array) ( isset( $data['themes'] ) ? $data['themes'] : array() ) as $i => $theme ) {
				$data['themes'][ $i ]['content']   = wp_kses_post( isset( $theme['content'] ) ? (string) $theme['content'] : '' );
				$data['themes'][ $i ]['gdpr_text'] = wp_kses_post( isset( $theme['gdpr_text'] ) ? (string) $theme['gdpr_text'] : '' );
			}
			set_transient( $key, $data, 30 );
			return rest_ensure_response( $data );
		}

		$ids    = $id ? array( $id ) : STM_Helpers::open_theme_ids();
		$themes = array();
		foreach ( $ids as $theme_id ) {
			$theme = STM_Helpers::theme_public( $theme_id );
			if ( $theme ) {
				$themes[] = $theme;
			}
		}
		$response = rest_ensure_response( array( 'themes' => $themes ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function rate_limited() {
		$ip = STM_Helpers::client_ip();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$fwd = isset( $_SERVER['HTTP_X_STM_CLIENT_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_STM_CLIENT_IP'] ) ) : '';
		$key = 'stm_rl_' . md5( $ip . '|' . $fwd );
		$n   = (int) get_transient( $key );
		if ( $n >= 8 ) {
			return true;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return false;
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
		if ( self::rate_limited() ) {
			return new WP_Error( 'stm_limit', 'Prea multe încercări. Încearcă din nou mai târziu sau sună-ne.', array( 'status' => 429 ) );
		}

		$fields = array( 'theme', 'date', 'time', 'package', 'name', 'phone', 'email', 'people', 'notes', 'consent' );
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
						'Content-Type'      => 'application/json',
						'X-STM-Client-IP'   => STM_Helpers::client_ip(),
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
					'location' => $theme['location'],
				),
			)
		);
	}
}
