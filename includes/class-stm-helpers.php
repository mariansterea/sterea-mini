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
			'days_off'      => array(),
			'gcal_enabled'  => 0,
			'gcal_calendar_id' => '',
			'gcal_json'     => '',
			'gift_enabled'  => 0,
			'gift_min'      => 300,
			'gift_months'   => 12,
			'gift_payment_text' => '',
			'gift_terms'    => 'Rezervarea se face pe blog.mariansterea.ro, cu codul de pe card',
			'gift_success_text' => 'Mulțumim! Ai primit pe email detaliile de plată. După confirmarea plății, cardul cadou ajunge pe email, gata de dăruit.',
			'gift_book_url' => '',
			'gift_send'     => 'paid',
			'mail_from_name' => 'Marian Sterea',
			'mail_from'     => '',
			'mail_sender_all' => 0,
			'wa_text'       => "Bună ziua, {prenume}! Vă confirmăm rezervarea la {eveniment}: {data}, ora {ora}, {persoane}.\nNe vedem la {locatie}.\n{harta}\nDacă intervine ceva, scrieți-ne aici. Mulțumim, {semnatura}",
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
			'client_info' => '',
			'duration'  => 30,
			'buffer'    => 10,
			'deadline'  => 24,
			'max_people' => 6,
			'open'      => 1,
			'days'      => array(),
			'blocked'   => array(),
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

	/**
	 * Zile libere globale (din Setări): [ { name, from, to, yearly } ].
	 */
	public static function days_off() {
		$list = self::get( 'days_off' );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Ziua este liberă (închisă pentru toate evenimentele)?
	 */
	public static function is_day_off( $ymd ) {
		foreach ( self::days_off() as $d ) {
			$from = isset( $d['from'] ) ? $d['from'] : '';
			$to   = isset( $d['to'] ) && $d['to'] ? $d['to'] : $from;
			if ( ! $from ) {
				continue;
			}
			if ( ! empty( $d['yearly'] ) ) {
				$md = substr( $ymd, 5 );
				if ( $md >= substr( $from, 5 ) && $md <= substr( $to, 5 ) ) {
					return true;
				}
			} elseif ( $ymd >= $from && $ymd <= $to ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ore blocate manual ale unui eveniment: [ 'Y-m-d H:i', ... ].
	 */
	public static function blocked( $theme_id ) {
		$meta = self::theme_meta( $theme_id );
		return is_array( $meta['blocked'] ) ? array_values( $meta['blocked'] ) : array();
	}

	public static function set_blocked( $theme_id, $list ) {
		$meta            = self::theme_meta( $theme_id );
		$meta['blocked'] = array_values( array_unique( array_filter( array_map( 'strval', (array) $list ) ) ) );
		sort( $meta['blocked'] );
		update_post_meta( $theme_id, '_stm', $meta );
	}

	/**
	 * Număr dintr-un preț scris liber („600”, „600 lei”, „1.100,50”).
	 */
	public static function to_number( $price ) {
		$p = str_replace( array( ' ', "\xc2\xa0" ), '', (string) $price );
		if ( preg_match( '/^\d{1,3}(\.\d{3})+(,\d+)?$/', $p ) ) {
			$p = str_replace( '.', '', $p );
		}
		$p = str_replace( ',', '.', $p );
		return (float) preg_replace( '/[^0-9.]/', '', $p );
	}

	/**
	 * Suma de plată a unei rezervări: totalul cu reducere, dacă există, altfel prețul.
	 */
	public static function amount( $b ) {
		if ( isset( $b['total'] ) && '' !== (string) $b['total'] ) {
			return (float) $b['total'];
		}
		return self::to_number( isset( $b['price'] ) ? $b['price'] : '' );
	}

	public static function money( $n ) {
		$n = (float) $n;
		return ( floor( $n ) === $n ? number_format( $n, 0, ',', '.' ) : number_format( $n, 2, ',', '.' ) ) . ' lei';
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
			if ( empty( $day['date'] ) || $day['date'] < $now->format( 'Y-m-d' ) || self::is_day_off( $day['date'] ) ) {
				continue;
			}
			$blocked = is_array( $meta['blocked'] ) ? $meta['blocked'] : array();
			$slots   = array();
			foreach ( self::day_slots( $meta, $day ) as $time ) {
				$key = $day['date'] . ' ' . $time;
				if ( isset( $taken[ $key ] ) ) {
					$state = 'taken';
				} elseif ( $key <= $cutoff || empty( $meta['open'] ) || in_array( $key, $blocked, true ) ) {
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

	/* ---------- Email ---------- */

	const MAIL_LOG = 'stm_mail_log';

	/** @var string Ultima eroare raportată de wp_mail (acțiunea wp_mail_failed). */
	private static $mail_error = '';

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'phpmailer_all' ), 999 );
	}

	/**
	 * Adresa expeditorului din Setări (o căsuță reală de pe domeniu) sau ''.
	 */
	public static function mail_from() {
		return (string) sanitize_email( (string) self::get( 'mail_from' ) );
	}

	public static function mail_from_name() {
		return trim( str_replace( array( '"', "\r", "\n" ), '', (string) self::get( 'mail_from_name' ) ) );
	}

	/**
	 * Trimite un email HTML cu expeditorul din Setări și îl trece în jurnal (ultimele 30).
	 *
	 * Expeditorul tehnic (envelope sender / Return-Path) devine aceeași adresă: altfel serverul
	 * pune utilizatorul de sistem (ex. user@server-0387.whmpanels.com), iar releul SMTP al
	 * hostingului respinge emailul cu „550 Invalid sender”.
	 */
	public static function mail( $to, $subject, $html, $reply_to = '', $attachments = array(), $embeds = array() ) {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$name    = self::mail_from_name();
		$from    = self::mail_from();
		if ( $from ) {
			$headers[] = 'From: ' . ( $name ? '"' . $name . '" ' : '' ) . '<' . $from . '>';
		}
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		$name_filter = function () use ( $name ) {
			return $name;
		};
		$sender = function ( $phpmailer ) use ( $from ) {
			$phpmailer->Sender = $from; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		// Imagini în corpul emailului (cid:...), ca să se vadă fără să fie încărcate de pe site.
		$embed = function ( $phpmailer ) use ( $embeds ) {
			if ( ! is_object( $phpmailer ) || ! method_exists( $phpmailer, 'addEmbeddedImage' ) ) {
				return;
			}
			foreach ( (array) $embeds as $e ) {
				if ( ! empty( $e['path'] ) && ! empty( $e['cid'] ) && is_readable( $e['path'] ) ) {
					try {
						$phpmailer->addEmbeddedImage( $e['path'], $e['cid'], isset( $e['name'] ) ? $e['name'] : basename( $e['path'] ), 'base64', isset( $e['type'] ) ? $e['type'] : 'image/jpeg' );
					} catch ( Exception $ex ) {
						unset( $ex );
					}
				}
			}
		};
		$fail = function ( $error ) {
			self::$mail_error = is_wp_error( $error ) ? $error->get_error_message() : 'eroare necunoscută';
		};
		self::$mail_error = '';
		if ( $name && ! $from ) {
			add_filter( 'wp_mail_from_name', $name_filter, 99 );
		}
		if ( $from ) {
			add_action( 'phpmailer_init', $sender, 1000 );
		}
		if ( $embeds ) {
			add_action( 'phpmailer_init', $embed, 1001 );
		}
		add_action( 'wp_mail_failed', $fail );
		$ok = wp_mail( $to, $subject, $html, $headers, $attachments );
		remove_action( 'wp_mail_failed', $fail );
		remove_action( 'phpmailer_init', $embed, 1001 );
		remove_action( 'phpmailer_init', $sender, 1000 );
		remove_filter( 'wp_mail_from_name', $name_filter, 99 );
		self::log_mail( $to, $subject, $ok, self::$mail_error );
		return $ok;
	}

	/**
	 * Opțiunea „Folosește această adresă pentru toate emailurile site-ului”: orice email trimis
	 * prin wp_mail (formulare de contact, resetare parolă etc.) pleacă cu expeditorul tehnic din
	 * Setări; dacă expeditorul vizibil este încă cel implicit (wordpress@…), îl înlocuiește și pe el.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Obiectul folosit de wp_mail.
	 */
	public static function phpmailer_all( $phpmailer ) {
		$from = self::mail_from();
		if ( ! $from || ! self::get( 'mail_sender_all' ) ) {
			return;
		}
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- proprietăți PHPMailer.
		$phpmailer->Sender = $from;
		if ( '' === (string) $phpmailer->From || 0 === stripos( (string) $phpmailer->From, 'wordpress@' ) ) {
			$name = self::mail_from_name();
			try {
				$phpmailer->setFrom( $from, $name ? $name : (string) $phpmailer->FromName, false );
			} catch ( Exception $e ) {
				// Adresa e deja validată cu sanitize_email; dacă PHPMailer tot o refuză, rămâne expeditorul vechi.
				unset( $e );
			}
		}
		// phpcs:enable
	}

	/**
	 * Blocul „Unde și cum” pentru emailuri: locația evenimentului, butonul Google Maps și
	 * informațiile pentru client (ale evenimentului sau, dacă lipsesc, textul general din Setări).
	 *
	 * @param int  $theme_id   Evenimentul (0 = doar textul general).
	 * @param bool $with_place Afișează și rândul „Unde:” (nu e nevoie când locația e deja în rezumat).
	 */
	public static function event_block( $theme_id, $with_place = true ) {
		$html  = '';
		$theme = $theme_id ? self::theme_meta( $theme_id ) : null;
		if ( $theme ) {
			$place = trim( $theme['location'] . ( $theme['address'] ? ', ' . $theme['address'] : '' ), ', ' );
			if ( $with_place && $place ) {
				$html .= '<p style="margin:18px 0 6px"><strong>Unde:</strong> ' . esc_html( $place ) . '</p>';
			}
			if ( $theme['map_url'] ) {
				$html .= '<p style="margin:' . ( $with_place && $place ? '0' : '14px' ) . ' 0 14px"><a href="' . esc_url( $theme['map_url'] ) . '" style="display:inline-block;background:#f4f6f8;color:#1f2328;border:1px solid #d0d5dd;padding:8px 14px;border-radius:6px;text-decoration:none">Deschide în Google Maps</a></p>';
			}
		}
		$info = $theme && '' !== trim( (string) $theme['client_info'] ) ? trim( (string) $theme['client_info'] ) : trim( (string) self::get( 'info_text' ) );
		if ( $info ) {
			$html .= '<div style="background:#f4f6f8;padding:12px 16px;border-radius:8px;margin:14px 0"><p style="margin:0 0 6px"><strong>Bine de știut</strong></p><p style="margin:0">' . nl2br( esc_html( $info ) ) . '</p></div>';
		}
		return $html;
	}

	/**
	 * Contactul fotografului pentru emailuri: nume, telefon (apel și WhatsApp).
	 */
	public static function contact_block() {
		$phone = trim( (string) self::get( 'contact_phone' ) );
		if ( ! $phone ) {
			return '';
		}
		$name   = self::mail_from_name();
		$digits = preg_replace( '/\D+/', '', $phone );
		$wa     = 0 === strpos( $digits, '0' ) ? '4' . $digits : $digits; // 07xx → 407xx (România).
		$label  = preg_replace( '/^(\d{4})(\d{3})(\d{3})$/', '$1 $2 $3', $digits );
		return '<p><strong>Contact:</strong> ' . ( $name ? esc_html( $name ) . ', ' : '' )
			. '<a href="tel:' . esc_attr( $digits ) . '">' . esc_html( $label ? $label : $phone ) . '</a>'
			. ' · <a href="https://wa.me/' . esc_attr( $wa ) . '">WhatsApp</a></p>';
	}

	/* ---------- Calendar și WhatsApp pentru fotograf ---------- */

	/**
	 * Număr pentru wa.me: doar cifre, cu prefixul țării (07xx → 407xx, 0040 → 40).
	 */
	public static function wa_number( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( 0 === strpos( $digits, '0' ) ) {
			$digits = '4' . $digits;
		} elseif ( 9 === strlen( $digits ) && '7' === $digits[0] ) {
			$digits = '40' . $digits;
		}
		return strlen( $digits ) >= 8 ? $digits : '';
	}

	public static function people_label( $n ) {
		$n = max( 1, (int) $n );
		return 1 === $n ? '1 persoană' : ( $n < 20 ? $n . ' persoane' : $n . ' de persoane' );
	}

	public static function place( $theme ) {
		return trim( $theme['location'] . ( $theme['address'] ? ', ' . $theme['address'] : '' ), ', ' );
	}

	/**
	 * Link Google Maps al evenimentului (cel din eveniment sau o căutare după adresă).
	 */
	public static function map_link( $theme ) {
		if ( ! empty( $theme['map_url'] ) ) {
			return $theme['map_url'];
		}
		$place = self::place( $theme );
		return $place ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $place ) : '';
	}

	/**
	 * „Adaugă în Google Calendar” pentru fotograf: evenimentul vine completat cu toate datele clientului.
	 * Merge pe calculator și pe telefon (se deschide Google Calendar, apeși Salvează).
	 */
	public static function admin_gcal_url( $b ) {
		if ( empty( $b['start'] ) ) {
			return '';
		}
		$theme = self::theme_meta( $b['theme'] );
		$tz    = wp_timezone();
		$utc   = new DateTimeZone( 'UTC' );
		$start = new DateTimeImmutable( $b['start'], $tz );
		$end   = $start->modify( '+' . max( 5, (int) $theme['duration'] ) . ' minutes' );
		$lines = array( trim( (string) $b['name'] ) );
		if ( $b['phone'] ) {
			$lines[] = $b['phone'];
		}
		if ( $b['email'] ) {
			$lines[] = $b['email'];
		}
		$lines[] = '';
		$lines[] = self::people_label( $b['people'] );
		if ( $b['package'] ) {
			$lines[] = 'Pachet: ' . $b['package'] . ( $b['price'] ? ' – ' . self::price_label( $b['price'] ) : '' );
		}
		if ( ! empty( $b['coupon'] ) && '' !== (string) $b['discount'] && (float) $b['discount'] > 0 ) {
			$lines[] = 'Reducere ' . $b['coupon'] . ': −' . self::money( $b['discount'] ) . ', total ' . self::money( $b['total'] );
		}
		if ( $b['notes'] ) {
			$lines[] = 'Observații: ' . $b['notes'];
		}
		if ( 'pending' === $b['status'] ) {
			$lines[] = 'Status: în așteptarea confirmării';
		}
		if ( ! empty( $b['id'] ) ) {
			$lines[] = '';
			$lines[] = 'Rezervarea: ' . admin_url( 'post.php?post=' . (int) $b['id'] . '&action=edit' );
		}
		$args = array(
			'action'   => 'TEMPLATE',
			'text'     => 'Mini-ședință foto: ' . wp_specialchars_decode( get_the_title( $b['theme'] ), ENT_QUOTES ),
			'dates'    => $start->setTimezone( $utc )->format( 'Ymd\THis\Z' ) . '/' . $end->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
			'details'  => implode( "\n", $lines ),
			'location' => self::place( $theme ),
			'ctz'      => wp_timezone_string(),
		);
		return 'https://calendar.google.com/calendar/render?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Textul de confirmare pentru WhatsApp, din Setări, cu datele rezervării.
	 */
	public static function wa_message( $b ) {
		$theme = self::theme_meta( $b['theme'] );
		$name  = trim( (string) $b['name'] );
		$first = $name ? preg_split( '/\s+/', $name )[0] : '';
		$date  = self::date_label( substr( $b['start'], 0, 10 ) );
		$date  = mb_strtolower( mb_substr( $date, 0, 1 ) ) . mb_substr( $date, 1 );
		$text  = (string) self::get( 'wa_text' );
		if ( '' === trim( $text ) ) {
			$text = self::defaults()['wa_text'];
		}
		$map  = array(
			'{prenume}'   => $first,
			'{nume}'      => $name,
			'{eveniment}' => wp_specialchars_decode( get_the_title( $b['theme'] ), ENT_QUOTES ),
			'{data}'      => $date,
			'{ora}'       => substr( $b['start'], 11, 5 ),
			'{durata}'    => (int) $theme['duration'] . ' min',
			'{persoane}'  => self::people_label( $b['people'] ),
			'{pachet}'    => (string) $b['package'],
			'{total}'     => self::money( self::amount( $b ) ),
			'{locatie}'   => self::place( $theme ),
			'{harta}'     => self::map_link( $theme ),
			'{semnatura}' => self::mail_from_name(),
		);
		$text = strtr( $text, $map );
		// Rânduri rămase goale (ex. fără hartă) și spații în plus.
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( preg_replace( "/^\s*\n/m", '', $text ) );
	}

	public static function wa_url( $b ) {
		$num = self::wa_number( isset( $b['phone'] ) ? $b['phone'] : '' );
		return $num ? 'https://wa.me/' . $num . '?text=' . rawurlencode( self::wa_message( $b ) ) : '';
	}

	/**
	 * Butoanele „Adaugă în calendar” și „Confirmă pe WhatsApp” din admin. HTML escapat.
	 *
	 * @param array  $b    Rezervarea.
	 * @param string $size 'small' (liste) sau 'big' (pagina rezervării).
	 */
	public static function booking_actions( $b, $size = 'small' ) {
		if ( empty( $b['start'] ) || 'cancelled' === $b['status'] ) {
			return '';
		}
		$cal  = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm13 8H4v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9ZM8 13h3v3H8v-3Z"/></svg>';
		$wa   = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2c-1.5 0-3-.4-4.3-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.4.1-.7.3-.2.3-.9.9-.9 2.2s.9 2.5 1 2.7c.1.2 1.8 2.8 4.4 3.9 1.6.7 2.3.8 3.1.7.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z"/></svg>';
		$big  = 'big' === $size;
		$html = '<a class="stm-act stm-act--cal" href="' . esc_url( self::admin_gcal_url( $b ) ) . '" target="_blank" rel="noopener" title="Se deschide Google Calendar cu ședința completată; apeși Salvează">' . $cal . '<span>' . ( $big ? 'Adaugă în Google Calendar' : 'Calendar' ) . '</span></a>';
		$wurl = self::wa_url( $b );
		if ( $wurl ) {
			$html .= '<a class="stm-act stm-act--wa" href="' . esc_url( $wurl ) . '" target="_blank" rel="noopener" title="Se deschide WhatsApp cu mesajul de confirmare gata scris; apeși Trimite">' . $wa . '<span>' . ( $big ? 'Trimite confirmarea pe WhatsApp' : 'WhatsApp' ) . '</span></a>';
		}
		return '<div class="stm-acts' . ( $big ? ' stm-acts--big' : '' ) . '">' . $html . '</div>';
	}

	private static function log_mail( $to, $subject, $ok, $error ) {
		$log = get_option( self::MAIL_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'    => self::now()->format( 'Y-m-d H:i:s' ),
				'to'      => $to,
				'subject' => mb_substr( (string) $subject, 0, 120 ),
				'ok'      => $ok ? 1 : 0,
				'error'   => mb_substr( (string) $error, 0, 300 ),
			)
		);
		update_option( self::MAIL_LOG, array_slice( $log, 0, 30 ), false );
	}

	public static function mail_log() {
		$log = get_option( self::MAIL_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
