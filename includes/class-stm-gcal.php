<?php
/**
 * Sincronizare cu Google Calendar printr-un cont de serviciu Google:
 * rezervare nouă → eveniment în calendarul ales; mutare / confirmare → actualizare; anulare → ștergere.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_GCal {

	const API    = 'https://www.googleapis.com/calendar/v3/';
	const SCOPE  = 'https://www.googleapis.com/auth/calendar.events';
	const TOKEN  = 'stm_gcal_token';
	const STATUS = 'stm_gcal_status'; // ultima acțiune / eroare, afișată în Setări
	const COLORS = array(
		'confirmed' => '10', // verde (Basil)
		'pending'   => '5',  // galben (Banana)
	);

	public static function init() {
		add_action( 'stm_booking_created', array( __CLASS__, 'sync' ), 20 );
		add_action( 'stm_booking_status', array( __CLASS__, 'on_status' ), 20, 3 );
		add_action( 'save_post_' . STM_Helpers::CPT_BOOKING, array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'admin_post_stm_gcal_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_stm_gcal_sync_all', array( __CLASS__, 'handle_sync_all' ) );
	}

	/* ---------- Configurare ---------- */

	public static function enabled() {
		return STM_Helpers::get( 'gcal_enabled' ) && self::account() && STM_Helpers::get( 'gcal_calendar_id' ) && ! STM_Helpers::is_remote();
	}

	/**
	 * Cheia contului de serviciu (JSON descărcat din Google Cloud), validată.
	 */
	public static function account() {
		$json = json_decode( (string) STM_Helpers::get( 'gcal_json' ), true );
		if ( ! is_array( $json ) || empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
			return null;
		}
		return $json;
	}

	public static function status() {
		$s = get_option( self::STATUS, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function note( $ok, $message ) {
		update_option(
			self::STATUS,
			array(
				'ok'      => (bool) $ok,
				'message' => $message,
				'time'    => STM_Helpers::now()->format( 'd.m.Y H:i' ),
			),
			false
		);
	}

	/* ---------- Autentificare (JWT semnat RS256 → access token) ---------- */

	private static function b64( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public static function token( $force = false ) {
		$cached = get_transient( self::TOKEN );
		if ( ! $force && $cached ) {
			return $cached;
		}
		$acc = self::account();
		if ( ! $acc ) {
			return new WP_Error( 'stm_gcal', 'Cheia contului de serviciu lipsește sau nu este validă.' );
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'stm_gcal', 'Serverul nu are extensia OpenSSL pentru PHP.' );
		}
		$now    = time();
		$header = self::b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::b64(
			wp_json_encode(
				array(
					'iss'   => $acc['client_email'],
					'scope' => self::SCOPE,
					'aud'   => 'https://oauth2.googleapis.com/token',
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$sig = '';
		if ( ! openssl_sign( $header . '.' . $claims, $sig, $acc['private_key'], 'sha256WithRSAEncryption' ) ) {
			return new WP_Error( 'stm_gcal', 'Cheia privată din JSON nu a putut fi folosită la semnare.' );
		}
		$res = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $header . '.' . $claims . '.' . self::b64( $sig ),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['access_token'] ) ) {
			$msg = is_array( $body ) && ! empty( $body['error_description'] ) ? $body['error_description'] : wp_remote_retrieve_body( $res );
			return new WP_Error( 'stm_gcal', 'Google nu a acceptat cheia: ' . $msg );
		}
		set_transient( self::TOKEN, $body['access_token'], max( 60, (int) $body['expires_in'] - 300 ) );
		return $body['access_token'];
	}

	/**
	 * Apel către API. $path relativ la calendars/{id}/ sau absolut (începe cu „calendars/”).
	 */
	private static function call( $method, $path, $body = null ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$cal = rawurlencode( (string) STM_Helpers::get( 'gcal_calendar_id' ) );
		$url = self::API . ( 0 === strpos( $path, 'calendars/' ) ? $path : 'calendars/' . $cal . '/' . $path );
		$res = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => null === $body ? null : wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 ) {
			$msg = is_array( $data ) && ! empty( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			if ( 404 === $code ) {
				$msg = 'Calendarul sau evenimentul nu a fost găsit (verifică ID-ul calendarului și partajarea cu contul de serviciu).';
			} elseif ( 403 === $code ) {
				$msg = 'Fără drepturi: partajează calendarul cu adresa contului de serviciu, cu „Efectuează modificări la evenimente”.';
			}
			return new WP_Error( 'stm_gcal_' . $code, $msg, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/* ---------- Evenimentul ---------- */

	public static function event_body( $b ) {
		$theme = STM_Helpers::theme_meta( $b['theme'] );
		$tz    = wp_timezone_string();
		$start = new DateTimeImmutable( $b['start'], wp_timezone() );
		$end   = $start->modify( '+' . max( 5, (int) $theme['duration'] ) . ' minutes' );
		$lines = array(
			'Telefon: ' . $b['phone'],
			'Email: ' . $b['email'],
			'Persoane: ' . (int) $b['people'],
			'Pachet: ' . $b['package'] . ( $b['price'] ? ' – ' . STM_Helpers::price_label( $b['price'] ) : '' ),
		);
		if ( ! empty( $b['coupon'] ) && '' !== (string) $b['discount'] && (float) $b['discount'] > 0 ) {
			$lines[] = 'Reducere ' . $b['coupon'] . ': −' . STM_Helpers::money( $b['discount'] ) . ' → total ' . STM_Helpers::money( $b['total'] );
		}
		if ( $b['notes'] ) {
			$lines[] = 'Observații: ' . $b['notes'];
		}
		$lines[] = 'Status: ' . ( isset( STM_Helpers::STATUS_LABELS[ $b['status'] ] ) ? STM_Helpers::STATUS_LABELS[ $b['status'] ] : $b['status'] );
		$lines[] = 'Eveniment: ' . get_the_title( $b['theme'] );
		$lines[] = '';
		$lines[] = 'Rezervarea în WordPress: ' . admin_url( 'post.php?post=' . $b['id'] . '&action=edit' );

		return array(
			'summary'            => trim( $b['name'] ) . ' – ' . $b['package'] . ( 'pending' === $b['status'] ? ' (în așteptare)' : '' ),
			'description'        => implode( "\n", $lines ),
			'location'           => trim( $theme['location'] . ( $theme['address'] ? ', ' . $theme['address'] : '' ), ', ' ),
			'start'              => array( 'dateTime' => $start->format( 'Y-m-d\TH:i:s' ), 'timeZone' => $tz ),
			'end'                => array( 'dateTime' => $end->format( 'Y-m-d\TH:i:s' ), 'timeZone' => $tz ),
			'colorId'            => isset( self::COLORS[ $b['status'] ] ) ? self::COLORS[ $b['status'] ] : '8',
			'reminders'          => array( 'useDefault' => true ),
			'extendedProperties' => array( 'private' => array( 'stm_booking' => (string) $b['id'] ) ),
		);
	}

	/**
	 * Creează sau actualizează evenimentul unei rezervări (neanulate).
	 */
	public static function sync( $booking_id ) {
		if ( ! self::enabled() ) {
			return false;
		}
		$b = STM_Bookings::get( $booking_id );
		if ( ! $b || ! $b['start'] || 'publish' !== get_post_status( $booking_id ) ) {
			return false;
		}
		if ( 'cancelled' === $b['status'] ) {
			return self::remove( $booking_id );
		}
		$event_id = (string) get_post_meta( $booking_id, '_stm_gcal_id', true );
		$body     = self::event_body( $b );
		$res      = $event_id ? self::call( 'PUT', 'events/' . rawurlencode( $event_id ), $body ) : null;
		if ( null === $res || ( is_wp_error( $res ) && 'stm_gcal_404' === $res->get_error_code() ) ) {
			$res = self::call( 'POST', 'events', $body );
		}
		if ( is_wp_error( $res ) ) {
			self::note( false, 'Rezervarea #' . $booking_id . ': ' . $res->get_error_message() );
			return false;
		}
		if ( ! empty( $res['id'] ) ) {
			update_post_meta( $booking_id, '_stm_gcal_id', $res['id'] );
		}
		self::note( true, 'Rezervarea #' . $booking_id . ' (' . $b['name'] . ') a fost pusă în calendar.' );
		return true;
	}

	public static function remove( $booking_id ) {
		$event_id = (string) get_post_meta( $booking_id, '_stm_gcal_id', true );
		if ( ! $event_id || ! self::enabled() ) {
			return false;
		}
		$res = self::call( 'DELETE', 'events/' . rawurlencode( $event_id ) );
		if ( is_wp_error( $res ) && ! in_array( $res->get_error_code(), array( 'stm_gcal_404', 'stm_gcal_410' ), true ) ) {
			self::note( false, 'Rezervarea #' . $booking_id . ' nu a putut fi ștearsă din calendar: ' . $res->get_error_message() );
			return false;
		}
		delete_post_meta( $booking_id, '_stm_gcal_id' );
		self::note( true, 'Rezervarea #' . $booking_id . ' a fost scoasă din calendar.' );
		return true;
	}

	/* ---------- Hook-uri ---------- */

	public static function on_status( $id, $status, $old ) {
		self::sync( $id );
	}

	public static function on_save( $post_id, $post ) {
		// După STM_Bookings::save_admin (prioritate 10): mutări de oră, nume, pachet etc.
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $_POST['stm_booking_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificat în save_admin.
			return;
		}
		self::sync( $post_id );
	}

	public static function on_delete( $post_id ) {
		if ( get_post_type( $post_id ) === STM_Helpers::CPT_BOOKING ) {
			self::remove( $post_id );
		}
	}

	/* ---------- Butoane din Setări ---------- */

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( 'stm_gcal_test' );
		delete_transient( self::TOKEN );
		if ( ! self::account() || ! STM_Helpers::get( 'gcal_calendar_id' ) ) {
			self::note( false, 'Completează ID-ul calendarului și cheia JSON, apoi salvează setările.' );
		} else {
			$res = self::call( 'GET', 'calendars/' . rawurlencode( (string) STM_Helpers::get( 'gcal_calendar_id' ) ) );
			if ( is_wp_error( $res ) ) {
				self::note( false, 'Test: ' . $res->get_error_message() );
			} else {
				self::note( true, 'Conexiune OK: calendarul „' . ( isset( $res['summary'] ) ? $res['summary'] : $res['id'] ) . '” acceptă evenimente.' );
			}
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . STM_Helpers::CPT_THEME . '&page=stm-settings' ) );
		exit;
	}

	/**
	 * Pune în calendar toate rezervările viitoare neanulate (ex. după configurare).
	 */
	public static function handle_sync_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( 'stm_gcal_sync_all' );
		$ids = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_BOOKING,
				'post_status' => 'publish',
				'numberposts' => 200,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_stm_status',
						'value'   => 'cancelled',
						'compare' => '!=',
					),
					array(
						'key'     => '_stm_start',
						'value'   => STM_Helpers::now()->format( 'Y-m-d H:i' ),
						'compare' => '>=',
					),
				),
			)
		);
		$n = 0;
		foreach ( $ids as $id ) {
			if ( self::sync( $id ) ) {
				++$n;
			}
		}
		$st = self::status();
		if ( $n === count( $ids ) ) {
			self::note( true, $n . ' rezervări viitoare sunt în calendar.' );
		} else {
			self::note( false, $n . ' din ' . count( $ids ) . ' rezervări au fost puse în calendar. Ultima eroare: ' . ( isset( $st['message'] ) ? $st['message'] : '' ) );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . STM_Helpers::CPT_THEME . '&page=stm-settings' ) );
		exit;
	}
}
