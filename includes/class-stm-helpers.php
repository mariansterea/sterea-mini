<?php
/**
 * Shared helpers: settings, themes, slot generation.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Helpers {

	const OPTION        = 'stm_settings';
	const CPT_THEME     = 'stm_theme';
	const CPT_BOOKING   = 'stm_booking';
	const STATUS_LABELS = array(
		'pending'   => 'În așteptare',
		'confirmed' => 'Confirmată',
		'cancelled' => 'Anulată',
	);

	public static function defaults() {
		return array(
			'mode'          => 'main',
			'remote_url'    => '',
			'notify_email'  => get_option( 'admin_email' ),
			'auto_confirm'  => 1,
			'contact_phone' => '',
			'gdpr_text'     => 'Sunt de acord ca datele mele să fie folosite pentru gestionarea rezervării.',
			'success_text'  => 'Mulțumim! Rezervarea a fost înregistrată. Vei primi un email cu toate detaliile.',
			'info_text'     => "Te rugăm să ajungi cu 10 minute înainte.\nÎți recomandăm haine în culori neutre, potrivite cu decorul.",
			'reminders'     => 1,
			'accent'        => '#be9656',
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	public static function is_remote() {
		return 'remote' === self::get( 'mode' ) && self::remote_url();
	}

	public static function remote_url() {
		$url = trim( (string) self::get( 'remote_url' ) );
		return $url ? untrailingslashit( esc_url_raw( $url ) ) : '';
	}

	/* ---------- Themes ---------- */

	public static function theme_defaults() {
		return array(
			'location'  => 'Curtea Lipovenesc',
			'address'   => '',
			'map_url'   => '',
			'duration'  => 30,
			'buffer'    => 10,
			'deadline'  => 24,
			'max_people' => 6,
			'open'      => 1,
			'days'      => array(),
			'packages'  => array(
				array(
					'name'  => 'Ședință foto 30 min',
					'price' => '',
					'desc'  => '',
				),
				array(
					'name'  => 'Ședință foto 30 min + album foto',
					'price' => '',
					'desc'  => '',
				),
			),
		);
	}

	public static function theme_meta( $id ) {
		$meta = get_post_meta( $id, '_stm', true );
		return wp_parse_args( is_array( $meta ) ? $meta : array(), self::theme_defaults() );
	}

	public static function now() {
		return current_datetime(); // DateTimeImmutable in the site timezone.
	}

	public static function to_min( $time ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', (string) $time, $m ) ) {
			return null;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	public static function fmt_min( $min ) {
		return sprintf( '%02d:%02d', intdiv( $min, 60 ), $min % 60 );
	}

	/**
	 * Slot start times ("H:i") for one day of a theme.
	 */
	public static function day_slots( $meta, $day ) {
		$from = self::to_min( $day['from'] );
		$to   = self::to_min( $day['to'] );
		$dur  = max( 5, (int) $meta['duration'] );
		$step = $dur + max( 0, (int) $meta['buffer'] );
		$out  = array();
		if ( null === $from || null === $to ) {
			return $out;
		}
		for ( $t = $from; $t + $dur <= $to; $t += $step ) {
			$out[] = self::fmt_min( $t );
		}
		return $out;
	}

	/**
	 * Locked (taken) slot starts for a theme: [ 'Y-m-d H:i' => booking_id ].
	 */
	public static function taken( $theme_id ) {
		global $wpdb;
		$table = STM_Bookings::locks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT slot_start, booking_id FROM {$table} WHERE theme_id = %d", $theme_id ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ substr( $row->slot_start, 0, 16 ) ] = (int) $row->booking_id;
		}
		return $out;
	}

	/**
	 * Public data for one theme, including slot availability.
	 */
	public static function theme_public( $id ) {
		$post = get_post( $id );
		if ( ! $post || self::CPT_THEME !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		$meta   = self::theme_meta( $id );
		$now    = self::now();
		$cutoff = $now->modify( '+' . max( 0, (int) $meta['deadline'] ) . ' hours' )->format( 'Y-m-d H:i' );
		$taken  = self::taken( $id );

		$days = array();
		foreach ( (array) $meta['days'] as $day ) {
			if ( empty( $day['date'] ) || $day['date'] < $now->format( 'Y-m-d' ) ) {
				continue;
			}
			$slots = array();
			foreach ( self::day_slots( $meta, $day ) as $time ) {
				$key = $day['date'] . ' ' . $time;
				if ( isset( $taken[ $key ] ) ) {
					$state = 'taken';
				} elseif ( $key <= $cutoff || empty( $meta['open'] ) ) {
					$state = 'closed';
				} else {
					$state = 'free';
				}
				$slots[] = array(
					'time'  => $time,
					'state' => $state,
				);
			}
			if ( $slots ) {
				$days[] = array(
					'date'  => $day['date'],
					'label' => self::date_label( $day['date'] ),
					'slots' => $slots,
				);
			}
		}
		usort(
			$days,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		$packages = array();
		foreach ( (array) $meta['packages'] as $i => $p ) {
			if ( '' === trim( (string) $p['name'] ) ) {
				continue;
			}
			$packages[] = array(
				'id'    => (int) $i,
				'name'  => $p['name'],
				'price' => $p['price'],
				'desc'  => $p['desc'],
			);
		}

		$image = get_the_post_thumbnail_url( $id, 'large' );

		return array(
			'id'          => (int) $id,
			'title'       => get_the_title( $post ),
			'content'     => wp_kses_post( wpautop( $post->post_content ) ),
			'image'       => $image ? $image : '',
			'location'    => $meta['location'],
			'address'     => $meta['address'],
			'map_url'     => $meta['map_url'],
			'duration'    => (int) $meta['duration'],
			'max_people'  => (int) $meta['max_people'],
			'open'        => (bool) $meta['open'],
			'packages'    => $packages,
			'days'        => $days,
			'gdpr_text'   => wp_kses_post( self::get( 'gdpr_text' ) ),
			'success'     => self::get( 'success_text' ),
		);
	}

	/**
	 * Published themes that still have future dates.
	 */
	public static function open_theme_ids() {
		$ids = get_posts(
			array(
				'post_type'   => self::CPT_THEME,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'orderby'     => 'menu_order date',
				'order'       => 'ASC',
			)
		);
		$today = self::now()->format( 'Y-m-d' );
		return array_values(
			array_filter(
				$ids,
				function ( $id ) use ( $today ) {
					foreach ( (array) self::theme_meta( $id )['days'] as $day ) {
						if ( ! empty( $day['date'] ) && $day['date'] >= $today ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	public static function date_label( $ymd ) {
		$ts = strtotime( $ymd . ' 12:00:00' );
		if ( ! $ts ) {
			return $ymd;
		}
		$days   = array( 'duminică', 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă' );
		$months = array( '', 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie' );
		$day = $days[ (int) gmdate( 'w', $ts ) ];
		return mb_strtoupper( mb_substr( $day, 0, 1 ) ) . mb_substr( $day, 1 ) . ', ' . (int) gmdate( 'j', $ts ) . ' ' . $months[ (int) gmdate( 'n', $ts ) ] . ' ' . gmdate( 'Y', $ts );
	}

	public static function price_label( $price ) {
		$price = trim( (string) $price );
		if ( '' === $price ) {
			return '';
		}
		return is_numeric( $price ) ? $price . ' lei' : $price;
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
