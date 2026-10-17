<?php
/**
 * Emails: confirmations, admin notifications, cancellations, reminders; client cancel link.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Emails {

	const CRON = 'stm_send_reminders';

	public static function init() {
		add_action( 'stm_booking_created', array( __CLASS__, 'on_created' ) );
		add_action( 'stm_booking_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( self::CRON, array( __CLASS__, 'reminders' ) );
		add_action( 'template_redirect', array( __CLASS__, 'cancel_page' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/* ---------- Content ---------- */

	public static function cancel_url( $b ) {
		return add_query_arg( 'stm_cancel', $b['token'], home_url( '/' ) );
	}

	private static function gcal_url( $b ) {
		$theme = STM_Helpers::theme_meta( $b['theme'] );
		$tz    = wp_timezone();
		$start = new DateTimeImmutable( $b['start'], $tz );
		$end   = $start->modify( '+' . (int) $theme['duration'] . ' minutes' );
		$utc   = new DateTimeZone( 'UTC' );
		return add_query_arg(
			array(
				'action'   => 'TEMPLATE',
				'text'     => rawurlencode( 'Mini-ședință foto: ' . get_the_title( $b['theme'] ) ),
				'dates'    => $start->setTimezone( $utc )->format( 'Ymd\THis\Z' ) . '/' . $end->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
				'location' => rawurlencode( trim( $theme['location'] . ', ' . $theme['address'], ', ' ) ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/**
	 * Booking summary as HTML rows.
	 */
	private static function summary( $b ) {
		$theme = STM_Helpers::theme_meta( $b['theme'] );
		$rows  = array(
			'Eveniment' => get_the_title( $b['theme'] ),
			'Data'     => STM_Helpers::date_label( substr( $b['start'], 0, 10 ) ),
			'Ora'      => substr( $b['start'], 11, 5 ) . ' (' . (int) $theme['duration'] . ' min)',
			'Pachet'   => $b['package'] . ( $b['price'] ? ' – ' . STM_Helpers::price_label( $b['price'] ) : '' ),
			'Persoane' => (int) $b['people'],
			'Locație'  => trim( $theme['location'] . ( $theme['address'] ? ', ' . $theme['address'] : '' ) ),
		);
		$html = '<table cellpadding="6" style="border-collapse:collapse;font-size:15px">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><td style="color:#667085;padding-right:16px">' . esc_html( $label ) . '</td><td><strong>' . esc_html( $value ) . '</strong></td></tr>';
		}
		if ( $theme['map_url'] ) {
			$html .= '<tr><td></td><td><a href="' . esc_url( $theme['map_url'] ) . '">Deschide în Google Maps</a></td></tr>';
		}
		return $html . '</table>';
	}

	private static function wrap( $title, $body ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1f2328;line-height:1.5">'
			. '<h2 style="margin:0 0 16px">' . esc_html( $title ) . '</h2>' . $body
			. '<p style="margin-top:28px;color:#667085;font-size:13px">' . esc_html( $site ) . '</p></div>';
	}

	private static function send( $to, $subject, $html, $reply_to = '' ) {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		return wp_mail( $to, $subject, $html, $headers );
	}

	private static function admin_emails() {
		return array_filter( array_map( 'trim', explode( ',', (string) STM_Helpers::get( 'notify_email' ) ) ), 'is_email' );
	}

	private static function info_block() {
		$info  = trim( (string) STM_Helpers::get( 'info_text' ) );
		$phone = trim( (string) STM_Helpers::get( 'contact_phone' ) );
		$html  = '';
		if ( $info ) {
			$html .= '<p style="background:#f4f6f8;padding:12px 14px;border-radius:8px">' . nl2br( esc_html( $info ) ) . '</p>';
		}
		if ( $phone ) {
			$html .= '<p>Pentru orice întrebare: <a href="tel:' . esc_attr( preg_replace( '/\s+/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a></p>';
		}
		return $html;
	}

	/* ---------- Events ---------- */

	public static function on_created( $id ) {
		$b = STM_Bookings::get( $id );
		if ( ! $b ) {
			return;
		}
		$theme_title = get_the_title( $b['theme'] );

		// Client.
		$pending = 'pending' === $b['status'];
		$intro   = $pending
			? '<p>Bună, ' . esc_html( $b['name'] ) . '! Am primit cererea ta de rezervare. Te vom contacta în curând pentru confirmare.</p>'
			: '<p>Bună, ' . esc_html( $b['name'] ) . '! Rezervarea ta este confirmată. Te așteptăm!</p>';
		$actions = '<p><a href="' . esc_url( self::gcal_url( $b ) ) . '" style="display:inline-block;background:#1f2328;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Adaugă în calendar</a></p>'
			. '<p style="font-size:13px;color:#667085">Nu mai poți ajunge? <a href="' . esc_url( self::cancel_url( $b ) ) . '">Anulează rezervarea</a>, ca să eliberezi locul pentru altcineva.</p>';
		self::send(
			$b['email'],
			( $pending ? 'Cerere de rezervare: ' : 'Rezervare confirmată: ' ) . $theme_title,
			self::wrap( $pending ? 'Am primit cererea ta' : 'Rezervare confirmată', $intro . self::summary( $b ) . self::info_block() . $actions ),
			self::admin_emails() ? self::admin_emails()[0] : ''
		);

		// Admin.
		$admin  = '<p>Rezervare nouă' . ( $pending ? ' (în așteptarea confirmării)' : '' ) . ':</p>' . self::summary( $b );
		$admin .= '<p><strong>' . esc_html( $b['name'] ) . '</strong><br>Telefon: <a href="tel:' . esc_attr( preg_replace( '/\s+/', '', $b['phone'] ) ) . '">' . esc_html( $b['phone'] ) . '</a><br>Email: ' . esc_html( $b['email'] ) . '</p>';
		if ( $b['notes'] ) {
			$admin .= '<p>Observații: ' . nl2br( esc_html( $b['notes'] ) ) . '</p>';
		}
		$admin .= '<p>Sursa: ' . esc_html( $b['source'] ) . '</p>';
		$admin .= '<p><a href="' . esc_url( admin_url( 'post.php?post=' . $id . '&action=edit' ) ) . '">Vezi rezervarea</a></p>';
		foreach ( self::admin_emails() as $to ) {
			self::send( $to, 'Rezervare nouă: ' . substr( $b['start'], 0, 16 ) . ' – ' . $b['name'], self::wrap( 'Rezervare nouă – ' . $theme_title, $admin ), $b['email'] );
		}
	}

	public static function on_status( $id, $status, $old ) {
		$b = STM_Bookings::get( $id );
		if ( ! $b ) {
			return;
		}
		if ( 'confirmed' === $status && 'pending' === $old ) {
			self::send(
				$b['email'],
				'Rezervare confirmată: ' . get_the_title( $b['theme'] ),
				self::wrap(
					'Rezervare confirmată',
					'<p>Bună, ' . esc_html( $b['name'] ) . '! Rezervarea ta este confirmată. Te așteptăm!</p>' . self::summary( $b ) . self::info_block()
					. '<p><a href="' . esc_url( self::gcal_url( $b ) ) . '">Adaugă în calendar</a></p>'
				)
			);
		} elseif ( 'cancelled' === $status && ! did_action( 'stm_client_cancelled' ) ) {
			self::send(
				$b['email'],
				'Rezervare anulată: ' . get_the_title( $b['theme'] ),
				self::wrap( 'Rezervare anulată', '<p>Bună, ' . esc_html( $b['name'] ) . '. Rezervarea de mai jos a fost anulată. Pentru o altă dată, ne poți scrie oricând.</p>' . self::summary( $b ) )
			);
		}
	}

	public static function reminders() {
		if ( ! STM_Helpers::get( 'reminders' ) ) {
			return;
		}
		$now  = STM_Helpers::now();
		$ids  = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_BOOKING,
				'post_status' => 'publish',
				'numberposts' => 50,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_stm_status',
						'value' => 'confirmed',
					),
					array(
						'key'     => '_stm_start',
						'value'   => array( $now->format( 'Y-m-d H:i' ), $now->modify( '+24 hours' )->format( 'Y-m-d H:i' ) ),
						'compare' => 'BETWEEN',
					),
					array(
						'key'   => '_stm_reminded',
						'value' => '0',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$b = STM_Bookings::get( $id );
			update_post_meta( $id, '_stm_reminded', 1 );
			self::send(
				$b['email'],
				'Reamintire: mini-ședința ta foto, ' . STM_Helpers::date_label( substr( $b['start'], 0, 10 ) ) . ' la ' . substr( $b['start'], 11, 5 ),
				self::wrap(
					'Ne vedem curând!',
					'<p>Bună, ' . esc_html( $b['name'] ) . '! Îți reamintim de mini-ședința foto:</p>' . self::summary( $b ) . self::info_block()
					. '<p style="font-size:13px;color:#667085">Nu mai poți ajunge? <a href="' . esc_url( self::cancel_url( $b ) ) . '">Anulează rezervarea</a>.</p>'
				)
			);
		}
	}

	/* ---------- Client cancel page ---------- */

	public static function cancel_page() {
		// phpcs:disable WordPress.Security.NonceVerification -- the secret token is the authorisation.
		if ( empty( $_GET['stm_cancel'] ) ) {
			return;
		}
		$b = STM_Bookings::find_by_token( sanitize_text_field( wp_unslash( $_GET['stm_cancel'] ) ) );
		if ( ! $b ) {
			self::page( 'Linkul nu este valid', '<p>Rezervarea nu a fost găsită. Pentru ajutor, contactează-ne direct.</p>', 404 );
		}
		if ( 'cancelled' === $b['status'] ) {
			self::page( 'Rezervare anulată', '<p>Această rezervare este deja anulată.</p>' );
		}
		if ( $b['start'] <= STM_Helpers::now()->format( 'Y-m-d H:i' ) ) {
			self::page( 'Rezervarea a trecut', '<p>Această ședință a avut deja loc.</p>' );
		}

		// Cancellation happens only on POST, so link previews / scanners cannot cancel.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['stm_confirm_cancel'] ) ) {
			do_action( 'stm_client_cancelled', $b['id'] );
			STM_Bookings::set_status( $b['id'], 'cancelled' );
			foreach ( self::admin_emails() as $to ) {
				self::send( $to, 'Rezervare anulată de client: ' . substr( $b['start'], 0, 16 ) . ' – ' . $b['name'], self::wrap( 'Clientul a anulat rezervarea', self::summary( $b ) . '<p>' . esc_html( $b['name'] ) . ', ' . esc_html( $b['phone'] ) . '</p><p>Ora a fost eliberată automat.</p>' ) );
			}
			self::send( $b['email'], 'Rezervare anulată: ' . get_the_title( $b['theme'] ), self::wrap( 'Rezervare anulată', '<p>Rezervarea ta a fost anulată. Mulțumim că ne-ai anunțat!</p>' . self::summary( $b ) ) );
			self::page( 'Rezervare anulată', '<p>Mulțumim că ne-ai anunțat! Ora a fost eliberată.</p>' );
		}
		// phpcs:enable

		self::page(
			'Anulezi rezervarea?',
			self::summary( $b ) . '<form method="post"><input type="hidden" name="stm_confirm_cancel" value="1"><p><button type="submit" style="background:#b32d2e;color:#fff;border:0;padding:12px 18px;border-radius:6px;font-size:15px;cursor:pointer">Da, anulează rezervarea</button></p></form>'
		);
	}

	/**
	 * Minimal standalone page. $html is built only from escaped values.
	 */
	private static function page( $title, $html, $code = 200 ) {
		wp_die(
			'<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px"><h2>' . esc_html( $title ) . '</h2>' . $html . '</div>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $title ),
			array( 'response' => (int) $code )
		);
	}
}
