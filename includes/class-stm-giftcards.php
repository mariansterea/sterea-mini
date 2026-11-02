<?php
/**
 * Carduri cadou: cumpărate online de oricine, plătite prin transfer / WhatsApp, aprobate din admin,
 * trimise pe email ca JPG + PDF, folosite la rezervare prin câmpul de cod (ca un cupon).
 *
 * Flux: formular [sterea_mini_card_cadou] → card „În așteptarea plății” (email cu instrucțiuni) →
 * admin „Marchează plătit” → cardul se generează și pleacă pe email (cumpărător + destinatar, imediat
 * sau la data aleasă) → destinatarul scrie codul la pasul „Confirmare” → rezervarea scade valoarea →
 * cardul devine „Folosit”; dacă rezervarea se anulează, cardul redevine „Plătit” (valabil).
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Giftcards {

	const CPT      = 'stm_giftcard';
	const META     = '_stm_gift';
	const CRON     = 'stm_gift_deliveries';
	const STATUSES = array(
		'pending'   => 'În așteptarea plății',
		'paid'      => 'Plătit, valabil',
		'used'      => 'Folosit',
		'expired'   => 'Expirat',
		'cancelled' => 'Anulat',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_shortcode( 'sterea_mini_card_cadou', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'sterea_mini_card_model', array( __CLASS__, 'model_shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'download' ) );
		add_action( self::CRON, array( __CLASS__, 'deliver_scheduled' ) );

		// Folosirea cardului la rezervare.
		add_action( 'stm_booking_created', array( __CLASS__, 'on_booking_created' ) );
		add_action( 'stm_booking_status', array( __CLASS__, 'on_booking_status' ), 10, 3 );

		// Admin.
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'admin_post_stm_gift', array( __CLASS__, 'handle_admin' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_query' ) );
	}

	/* ---------- Setup ---------- */

	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => 'Carduri cadou',
					'singular_name' => 'Card cadou',
					'all_items'     => 'Carduri cadou',
					'add_new'       => 'Adaugă card cadou',
					'add_new_item'  => 'Adaugă card cadou (vândut direct)',
					'edit_item'     => 'Card cadou',
					'not_found'     => 'Niciun card cadou încă.',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . STM_Helpers::CPT_THEME,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function defaults() {
		return array(
			'kind'            => 'package', // package | amount
			'theme'           => 0,
			'package'         => '',
			'value'           => 0,
			'remaining'       => 0,
			'occasion'        => 'Cu drag',
			'to'              => '',
			'from'            => '',
			'message'         => '',
			'buyer_name'      => '',
			'buyer_email'     => '',
			'buyer_phone'     => '',
			'recipient_email' => '',
			'send_at'         => '',
			'status'          => 'pending',
			'issued'          => '',
			'expires'         => '',
			'token'           => '',
			'sent_buyer'      => 0,
			'sent_recipient'  => 0,
			'booking'         => 0,
			'note'            => '',
			'file'            => '',
			'auto'            => 0,
			'paid_at'         => '',
		);
	}

	public static function meta( $id ) {
		$m = get_post_meta( $id, self::META, true );
		return wp_parse_args( is_array( $m ) ? $m : array(), self::defaults() );
	}

	public static function code( $id ) {
		return STM_Coupons::normalize( get_the_title( $id ) );
	}

	/**
	 * Cod unic, ușor de dictat: MS-LLAA-XXXX (luna + an + 4 caractere fără 0/O/1/I).
	 */
	public static function new_code() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		do {
			$rand = '';
			for ( $i = 0; $i < 4; $i++ ) {
				$rand .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
			}
			$code = 'MS-' . STM_Helpers::now()->format( 'ym' ) . '-' . $rand;
		} while ( self::find( $code ) || STM_Coupons::find( $code ) );
		return $code;
	}

	/**
	 * Cardul după cod (orice status, în afară de coș).
	 */
	public static function find( $code ) {
		$code = STM_Coupons::normalize( $code );
		if ( '' === $code ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
				'title'       => $code,
			)
		);
		return $ids ? (int) $ids[0] : null;
	}

	public static function occasions() {
		return array( 'Crăciun', 'La mulți ani', 'Aniversare', 'Pentru bebeluș', 'Cu drag' );
	}

	/**
	 * Valabilitatea unui card: implicit 12 luni de la plată; pentru un eveniment cu date fixe,
	 * ultima zi din program (nu are sens după).
	 */
	public static function expiry_for( $m ) {
		$now = STM_Helpers::now();
		$exp = $now->modify( '+' . max( 1, (int) STM_Helpers::get( 'gift_months' ) ) . ' months' )->format( 'Y-m-d' );
		if ( 'package' === $m['kind'] && $m['theme'] ) {
			$last = '';
			foreach ( (array) STM_Helpers::theme_meta( $m['theme'] )['days'] as $day ) {
				if ( ! empty( $day['date'] ) && $day['date'] > $last ) {
					$last = $day['date'];
				}
			}
			if ( $last && $last < $exp ) {
				$exp = $last;
			}
		}
		return $exp;
	}

	/* ---------- Creare (formular public sau admin) ---------- */

	/**
	 * Creează un card din datele formularului. Întoarce ID-ul sau WP_Error.
	 */
	public static function create( $in, $from_admin = false ) {
		$kind = isset( $in['kind'] ) && 'amount' === $in['kind'] ? 'amount' : 'package';
		$m    = self::defaults();

		if ( 'package' === $kind ) {
			$theme = STM_Helpers::theme_public( absint( isset( $in['theme'] ) ? $in['theme'] : 0 ) );
			if ( ! $theme ) {
				return new WP_Error( 'stm_gift_theme', 'Alege ședința pe care o oferi.', array( 'status' => 400 ) );
			}
			$package = null;
			foreach ( $theme['packages'] as $p ) {
				if ( $p['id'] === (int) ( isset( $in['package'] ) ? $in['package'] : -1 ) ) {
					$package = $p;
				}
			}
			if ( ! $package || STM_Helpers::to_number( $package['price'] ) <= 0 ) {
				return new WP_Error( 'stm_gift_package', 'Alege pachetul.', array( 'status' => 400 ) );
			}
			// Un eveniment fără zile viitoare: cardul ar expira înainte să poată fi folosit.
			if ( empty( $theme['days'] ) ) {
				return new WP_Error( 'stm_gift_theme', 'Ședințele acestui eveniment s-au încheiat, nu mai poate fi oferit cadou.', array( 'status' => 400 ) );
			}
			if ( empty( $theme['open'] ) && ! $from_admin ) {
				return new WP_Error( 'stm_gift_theme', 'Comenzile pentru acest eveniment sunt închise momentan.', array( 'status' => 400 ) );
			}
			$m['theme']   = (int) $theme['id'];
			$m['package'] = $package['name'];
			$m['value']   = STM_Helpers::to_number( $package['price'] );
		} else {
			$min        = max( 1, (float) STM_Helpers::get( 'gift_min' ) );
			$m['value'] = round( STM_Helpers::to_number( isset( $in['amount'] ) ? $in['amount'] : 0 ), 2 );
			if ( $m['value'] < $min ) {
				return new WP_Error( 'stm_gift_amount', 'Suma minimă pentru un card cadou este ' . STM_Helpers::money( $min ) . '.', array( 'status' => 400 ) );
			}
			if ( $m['value'] > 20000 ) {
				return new WP_Error( 'stm_gift_amount', 'Suma este prea mare. Scrie-ne direct pentru un card de valoare mare.', array( 'status' => 400 ) );
			}
		}
		$m['kind']            = $kind;
		$m['remaining']       = $m['value'];
		$m['occasion']        = sanitize_text_field( isset( $in['occasion'] ) ? $in['occasion'] : '' );
		$m['to']              = sanitize_text_field( isset( $in['to'] ) ? $in['to'] : '' );
		$m['from']            = sanitize_text_field( isset( $in['from'] ) ? $in['from'] : '' );
		$m['message']         = mb_substr( sanitize_textarea_field( isset( $in['message'] ) ? $in['message'] : '' ), 0, 150 );
		$m['buyer_name']      = sanitize_text_field( isset( $in['buyer_name'] ) ? $in['buyer_name'] : '' );
		$m['buyer_email']     = sanitize_email( isset( $in['buyer_email'] ) ? $in['buyer_email'] : '' );
		$m['buyer_phone']     = trim( preg_replace( '/[^\d+ ]/', '', (string) ( isset( $in['buyer_phone'] ) ? $in['buyer_phone'] : '' ) ) );
		$m['recipient_email'] = sanitize_email( isset( $in['recipient_email'] ) ? $in['recipient_email'] : '' );
		$send_at              = sanitize_text_field( isset( $in['send_at'] ) ? $in['send_at'] : '' );
		$m['send_at']         = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $send_at ) && $send_at > STM_Helpers::now()->format( 'Y-m-d' ) ? $send_at : '';
		$m['note']            = $from_admin ? sanitize_text_field( isset( $in['note'] ) ? $in['note'] : '' ) : '';
		$m['token']           = wp_generate_password( 32, false, false );

		if ( ! in_array( $m['occasion'], self::occasions(), true ) ) {
			$m['occasion'] = 'Cu drag';
		}
		if ( mb_strlen( $m['to'] ) < 2 || mb_strlen( $m['to'] ) > 40 ) {
			return new WP_Error( 'stm_gift_to', 'Scrie numele celui care primește cardul (2–40 de caractere).', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $m['from'] ) < 2 || mb_strlen( $m['from'] ) > 40 ) {
			return new WP_Error( 'stm_gift_from', 'Scrie din partea cui este cadoul (2–40 de caractere).', array( 'status' => 400 ) );
		}
		if ( ! $from_admin ) {
			if ( mb_strlen( $m['buyer_name'] ) < 3 ) {
				return new WP_Error( 'stm_gift_name', 'Scrie numele tău complet.', array( 'status' => 400 ) );
			}
			if ( strlen( preg_replace( '/\D/', '', $m['buyer_phone'] ) ) < 9 ) {
				return new WP_Error( 'stm_gift_phone', 'Numărul de telefon nu pare corect.', array( 'status' => 400 ) );
			}
			if ( ! is_email( $m['buyer_email'] ) ) {
				return new WP_Error( 'stm_gift_email', 'Adresa ta de email nu pare corectă.', array( 'status' => 400 ) );
			}
			if ( empty( $in['consent'] ) ) {
				return new WP_Error( 'stm_gift_consent', 'Bifează acordul pentru prelucrarea datelor.', array( 'status' => 400 ) );
			}
		}
		if ( $m['recipient_email'] && ! is_email( $m['recipient_email'] ) ) {
			return new WP_Error( 'stm_gift_remail', 'Adresa de email a destinatarului nu pare corectă.', array( 'status' => 400 ) );
		}
		if ( ! $m['recipient_email'] ) {
			$m['send_at'] = '';
		}
		// Cardul trebuie să ajungă la destinatar cât încă se poate folosi.
		$expires = self::expiry_for( $m );
		if ( $m['send_at'] && $m['send_at'] > $expires ) {
			return new WP_Error( 'stm_gift_sendat', 'Alege o dată până la ' . preg_replace( '/^[^,]+,\s*/', '', STM_Helpers::date_label( $expires ) ) . ': după această zi cardul nu se mai poate folosi.', array( 'status' => 400 ) );
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => self::new_code(),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return new WP_Error( 'stm_gift_save', 'Cardul nu a putut fi salvat. Încearcă din nou.', array( 'status' => 500 ) );
		}
		update_post_meta( $id, self::META, $m );
		do_action( 'stm_giftcard_created', $id );
		return (int) $id;
	}

	/**
	 * Marchează plata: emite cardul (dată, valabilitate, fișiere) și trimite emailurile.
	 */
	public static function mark_paid( $id ) {
		$m = self::meta( $id );
		if ( 'paid' === $m['status'] && ! empty( $m['auto'] ) && empty( $m['paid_at'] ) ) {
			// Card emis automat la comandă: doar se notează plata, fără să-l retrimitem.
			$m['paid_at'] = STM_Helpers::now()->format( 'Y-m-d H:i' );
			update_post_meta( $id, self::META, $m );
			return true;
		}
		if ( ! in_array( $m['status'], array( 'pending', 'cancelled' ), true ) ) {
			return false;
		}
		self::issue( $id, false );
		return true;
	}

	/**
	 * Emite cardul (status activ, dată, valabilitate, fișiere) și îl trimite pe email.
	 *
	 * @param bool $auto true = emis automat la comandă, înainte de confirmarea plății.
	 */
	public static function issue( $id, $auto ) {
		$m              = self::meta( $id );
		$m['status']    = 'paid';
		$m['issued']    = STM_Helpers::now()->format( 'Y-m-d' );
		$m['expires']   = $m['expires'] ? $m['expires'] : self::expiry_for( $m );
		$m['remaining'] = $m['value'];
		$m['auto']      = $auto ? 1 : 0;
		$m['paid_at']   = $auto ? '' : STM_Helpers::now()->format( 'Y-m-d H:i' );
		update_post_meta( $id, self::META, $m );
		self::build_files( $id );
		self::send_card( $id, 'buyer' );
		if ( $m['recipient_email'] && ! $m['send_at'] ) {
			self::send_card( $id, 'recipient' );
		}
	}

	public static function set_status( $id, $status ) {
		if ( ! isset( self::STATUSES[ $status ] ) ) {
			return;
		}
		$m           = self::meta( $id );
		$m['status'] = $status;
		if ( 'paid' === $status && ! $m['issued'] ) {
			$m['issued']  = STM_Helpers::now()->format( 'Y-m-d' );
			$m['expires'] = self::expiry_for( $m );
		}
		update_post_meta( $id, self::META, $m );
	}

	/* ---------- Fișiere (JPG + PDF) ---------- */

	private static function dir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'stm-giftcards';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Fișierele se descarcă doar prin link cu token, nu direct din folder.
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	public static function image_data( $id ) {
		$m   = self::meta( $id );
		$now = STM_Helpers::now();
		$ro  = array( '', 'ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'nov', 'dec' );
		$is_xmas = 'Crăciun' === $m['occasion'] || ( $m['theme'] && preg_match( '/cr(ă|a)ciun/iu', get_the_title( $m['theme'] ) ) );

		if ( 'package' === $m['kind'] ) {
			$kicker = 'Primești o ședință foto' . ( $is_xmas ? ' de Crăciun' : '' );
			$pack   = $m['package'] . ' · ' . STM_Helpers::money( $m['value'] );
			$first  = '';
			foreach ( (array) STM_Helpers::theme_meta( $m['theme'] )['days'] as $day ) {
				if ( ! empty( $day['date'] ) && $day['date'] >= $now->format( 'Y-m-d' ) && ( '' === $first || $day['date'] < $first ) ) {
					$first = $day['date'];
				}
			}
			$date = $first ? $first : ( $m['issued'] ? $m['issued'] : $now->format( 'Y-m-d' ) );
			$dl   = $first ? 'Valabil din' : 'Emis';
		} else {
			$kicker = 'Primești o ședință foto, la alegere';
			$pack   = 'Card cadou în valoare de ' . STM_Helpers::money( $m['value'] );
			$date   = $m['issued'] ? $m['issued'] : $now->format( 'Y-m-d' );
			$dl     = 'Emis';
		}
		$occ = $m['occasion'];
		if ( 'Crăciun' === $occ ) {
			$occ .= ' ' . substr( $date, 0, 4 );
		}
		$exp = $m['expires'] ? $m['expires'] : self::expiry_for( $m );
		// date_label dă „Luni, 20 decembrie 2026”; păstrăm doar „20 decembrie 2026”.
		$terms = $pack . ' · valabil până la ' . preg_replace( '/^[^,]+,\s*/', '', STM_Helpers::date_label( $exp ) ) . ' · ' . trim( (string) STM_Helpers::get( 'gift_terms' ) );

		return array(
			'style'      => $is_xmas ? 'craciun' : 'negru',
			'kicker'     => $kicker,
			'occasion'   => $occ,
			'date_label' => $dl,
			'date_day'   => (string) (int) substr( $date, 8, 2 ),
			'date_year'  => $ro[ (int) substr( $date, 5, 2 ) ] . ' ' . substr( $date, 0, 4 ),
			'to'         => $m['to'],
			'from'       => $m['from'],
			'message'    => $m['message'],
			'terms'      => rtrim( $terms, ' ·' ),
			'code'       => self::code( $id ),
		);
	}

	/**
	 * Generează (sau regenerează) JPG-ul și PDF-ul cardului. Întoarce calea JPG sau ''.
	 */
	public static function build_files( $id ) {
		$m    = self::meta( $id );
		$base = self::dir() . '/' . sanitize_file_name( self::code( $id ) . '-' . substr( md5( $m['token'] ), 0, 10 ) );
		if ( ! STM_Giftcard_Image::render( self::image_data( $id ), $base . '.jpg' ) ) {
			return '';
		}
		STM_Giftcard_Image::pdf( $base . '.jpg', $base . '.pdf' );
		$m['file'] = basename( $base );
		update_post_meta( $id, self::META, $m );
		return $base . '.jpg';
	}

	public static function file( $id, $ext ) {
		$m = self::meta( $id );
		if ( ! $m['file'] ) {
			return '';
		}
		$path = self::dir() . '/' . $m['file'] . '.' . $ext;
		return file_exists( $path ) ? $path : '';
	}

	public static function download_url( $id, $ext = 'pdf' ) {
		$m = self::meta( $id );
		return add_query_arg(
			array(
				'stm_card' => $m['token'],
				'f'        => 'jpg' === $ext ? 'jpg' : 'pdf',
			),
			home_url( '/' )
		);
	}

	/**
	 * ?stm_card=<token>&f=pdf|jpg → trimite fișierul (doar pentru carduri plătite/folosite).
	 */
	public static function download() {
		// phpcs:disable WordPress.Security.NonceVerification -- tokenul secret este autorizarea.
		if ( empty( $_GET['stm_card'] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['stm_card'] ) );
		$ext   = isset( $_GET['f'] ) && 'jpg' === $_GET['f'] ? 'jpg' : 'pdf';
		// phpcs:enable
		$ids = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => self::META,
						'value'   => '"' . $token . '"',
						'compare' => 'LIKE',
					),
				),
			)
		);
		$id = $ids ? (int) $ids[0] : 0;
		$m  = $id ? self::meta( $id ) : null;
		if ( ! $m || ! hash_equals( (string) $m['token'], $token ) || 'pending' === $m['status'] ) {
			wp_die( 'Cardul nu a fost găsit.', 'Card cadou', array( 'response' => 404 ) );
		}
		$path = self::file( $id, $ext );
		if ( ! $path ) {
			$path = self::build_files( $id ) ? self::file( $id, $ext ) : '';
		}
		if ( ! $path ) {
			wp_die( 'Cardul nu a putut fi generat.', 'Card cadou', array( 'response' => 500 ) );
		}
		// Fără cache de pagină (LiteSpeed etc.): după „Regenerează fișierele” trebuie să vină cardul nou.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'stm: card cadou' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- hook LiteSpeed Cache.
		nocache_headers();
		header( 'Content-Type: ' . ( 'jpg' === $ext ? 'image/jpeg' : 'application/pdf' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="card-cadou-' . self::code( $id ) . '.' . $ext . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ---------- Folosirea codului la rezervare ---------- */

	/**
	 * Apelată din STM_Coupons::validate înainte de cupoane: dacă nu e card cadou, întoarce null.
	 */
	public static function validate( $code, $theme_id, $package_name, $price ) {
		$id = self::find( $code );
		if ( ! $id ) {
			return null;
		}
		$m     = self::meta( $id );
		$fail  = function ( $msg ) {
			return array( 'ok' => false, 'message' => $msg );
		};
		$today = STM_Helpers::now()->format( 'Y-m-d' );
		if ( 'pending' === $m['status'] ) {
			return $fail( 'Cardul cadou nu este încă activat (plata nu a fost confirmată).' );
		}
		if ( 'used' === $m['status'] ) {
			return $fail( 'Cardul cadou a fost deja folosit.' );
		}
		if ( 'cancelled' === $m['status'] ) {
			return $fail( 'Cardul cadou nu mai este valabil.' );
		}
		if ( 'expired' === $m['status'] || ( $m['expires'] && $today > $m['expires'] ) ) {
			return $fail( 'Cardul cadou a expirat.' );
		}
		if ( 'package' === $m['kind'] && (int) $m['theme'] !== (int) $theme_id ) {
			return $fail( 'Cardul cadou este pentru „' . get_the_title( $m['theme'] ) . '”.' );
		}
		$price = STM_Helpers::to_number( $price );
		if ( $price <= 0 ) {
			return $fail( 'Pachetul nu are preț, cardul nu se poate aplica.' );
		}
		$discount = round( min( $price, (float) $m['remaining'] ), 2 );
		return array(
			'ok'       => true,
			'message'  => 'Card cadou aplicat.',
			'code'     => self::code( $id ),
			'label'    => 'card cadou, −' . STM_Helpers::money( $discount ),
			'price'    => $price,
			'discount' => $discount,
			'total'    => round( $price - $discount, 2 ),
		);
	}

	public static function on_booking_created( $booking_id ) {
		$b = STM_Bookings::get( $booking_id );
		if ( ! $b || empty( $b['coupon'] ) ) {
			return;
		}
		$id = self::find( $b['coupon'] );
		if ( ! $id ) {
			return;
		}
		$m = self::meta( $id );
		if ( 'paid' !== $m['status'] ) {
			return;
		}
		$m['remaining'] = max( 0, round( (float) $m['remaining'] - (float) $b['discount'], 2 ) );
		$m['booking']   = (int) $booking_id;
		$m['status']    = $m['remaining'] > 0 ? 'paid' : 'used';
		update_post_meta( $id, self::META, $m );
	}

	public static function on_booking_status( $booking_id, $status, $old ) {
		if ( 'cancelled' !== $status ) {
			return;
		}
		$b = STM_Bookings::get( $booking_id );
		if ( ! $b || empty( $b['coupon'] ) ) {
			return;
		}
		$id = self::find( $b['coupon'] );
		if ( ! $id ) {
			return;
		}
		$m = self::meta( $id );
		if ( (int) $m['booking'] !== (int) $booking_id || ! in_array( $m['status'], array( 'used', 'paid' ), true ) ) {
			return;
		}
		// Rezervarea anulată eliberează valoarea folosită.
		$m['remaining'] = min( (float) $m['value'], round( (float) $m['remaining'] + (float) $b['discount'], 2 ) );
		$m['booking']   = 0;
		$m['status']    = 'paid';
		update_post_meta( $id, self::META, $m );
	}

	/* ---------- Emailuri ---------- */

	private static function admin_emails() {
		return array_filter( array_map( 'trim', explode( ',', (string) STM_Helpers::get( 'notify_email' ) ) ), 'is_email' );
	}

	private static function send( $to, $subject, $html, $attachments = array(), $reply_to = '' ) {
		return STM_Helpers::mail( $to, $subject, $html, $reply_to, $attachments );
	}

	private static function instant() {
		return 'instant' === STM_Helpers::get( 'gift_send' );
	}

	private static function payment_block() {
		$pay = trim( (string) STM_Helpers::get( 'gift_payment_text' ) );
		return '<p><strong>Cum plătești:</strong><br>' . nl2br( esc_html( $pay ? $pay : 'Îți trimitem detaliile de plată în cel mai scurt timp.' ) ) . '</p>';
	}

	private static function wrap( $title, $body ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1f2328;line-height:1.5">'
			. '<h2 style="margin:0 0 16px">' . esc_html( $title ) . '</h2>' . $body
			. '<p style="margin-top:28px;color:#667085;font-size:13px">' . esc_html( $site ) . '</p></div>';
	}

	private static function summary( $id ) {
		$m    = self::meta( $id );
		$rows = array(
			'Card'       => 'package' === $m['kind'] ? get_the_title( $m['theme'] ) . ' – ' . $m['package'] : 'Ședință foto la alegere',
			'Valoare'    => STM_Helpers::money( $m['value'] ),
			'Pentru'     => $m['to'],
			'Din partea' => $m['from'],
			'Cod'        => self::code( $id ),
		);
		if ( $m['message'] ) {
			$rows['Mesaj'] = $m['message'];
		}
		if ( $m['expires'] ) {
			$rows['Valabil până la'] = preg_replace( '/^[^,]+,\s*/', '', STM_Helpers::date_label( $m['expires'] ) );
		}
		$html = '<table cellpadding="6" style="border-collapse:collapse;font-size:15px">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><td style="color:#667085;padding-right:16px;vertical-align:top">' . esc_html( $label ) . '</td><td><strong>' . esc_html( $value ) . '</strong></td></tr>';
		}
		return $html . '</table>';
	}

	/**
	 * La crearea cardului: cumpărătorul primește instrucțiunile de plată, voi primiți notificarea.
	 */
	public static function on_created_emails( $id ) {
		$admins = self::admin_emails();
		if ( self::instant() ) {
			self::issue( $id, true );
			$m      = self::meta( $id );
			$admin  = '<p>Card cadou nou, emis și trimis automat. <strong>Verifică plata</strong> și scrie în nota cardului când a intrat.</p>' . self::summary( $id );
			$admin .= '<p><strong>' . esc_html( $m['buyer_name'] ) . '</strong><br>Telefon: ' . esc_html( $m['buyer_phone'] ) . '<br>Email: ' . esc_html( $m['buyer_email'] )
				. ( $m['recipient_email'] ? '<br>Email destinatar: ' . esc_html( $m['recipient_email'] ) . ( $m['send_at'] ? ' (de trimis pe ' . esc_html( $m['send_at'] ) . ')' : '' ) : '' ) . '</p>';
			$admin .= '<p><a href="' . esc_url( admin_url( 'post.php?post=' . $id . '&action=edit' ) ) . '">Vezi cardul</a></p>';
			foreach ( $admins as $to ) {
				self::send( $to, 'Card cadou nou: ' . STM_Helpers::money( $m['value'] ) . ' – ' . $m['buyer_name'], self::wrap( 'Card cadou nou', $admin ), array(), $m['buyer_email'] );
			}
			return;
		}
		$m     = self::meta( $id );
		$phone = trim( (string) STM_Helpers::get( 'contact_phone' ) );
		$body  = '<p>Bună, ' . esc_html( $m['buyer_name'] ) . '! Am primit comanda ta pentru un card cadou:</p>' . self::summary( $id );
		$body .= self::payment_block();
		$body .= '<p>Scrie la plată codul <strong>' . esc_html( self::code( $id ) ) . '</strong>. Imediat ce confirmăm plata, primești cardul pe email (JPG și PDF, gata de trimis sau de printat)'
			. ( $m['recipient_email'] ? ', iar ' . esc_html( $m['to'] ) . ' îl primește ' . ( $m['send_at'] ? 'pe ' . esc_html( preg_replace( '/^[^,]+,\s*/', '', STM_Helpers::date_label( $m['send_at'] ) ) ) : 'în același timp' ) : '' ) . '.</p>';
		if ( $phone ) {
			$body .= '<p>Întrebări? <a href="tel:' . esc_attr( preg_replace( '/\s+/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a></p>';
		}
		self::send( $m['buyer_email'], 'Card cadou ' . self::code( $id ) . ': cum plătești', self::wrap( 'Comanda ta de card cadou', $body ), array(), $admins ? $admins[0] : '' );

		$admin  = '<p>Comandă nouă de card cadou (în așteptarea plății):</p>' . self::summary( $id );
		$admin .= '<p><strong>' . esc_html( $m['buyer_name'] ) . '</strong><br>Telefon: ' . esc_html( $m['buyer_phone'] ) . '<br>Email: ' . esc_html( $m['buyer_email'] )
			. ( $m['recipient_email'] ? '<br>Email destinatar: ' . esc_html( $m['recipient_email'] ) . ( $m['send_at'] ? ' (de trimis pe ' . esc_html( $m['send_at'] ) . ')' : '' ) : '' ) . '</p>';
		$admin .= '<p><a href="' . esc_url( self::action_url( 'paid', $id ) ) . '">Marchează plătit și trimite cardul</a> · <a href="' . esc_url( admin_url( 'post.php?post=' . $id . '&action=edit' ) ) . '">Vezi cardul</a></p>';
		foreach ( $admins as $to ) {
			self::send( $to, 'Card cadou nou: ' . STM_Helpers::money( $m['value'] ) . ' – ' . $m['buyer_name'], self::wrap( 'Card cadou nou', $admin ), array(), $m['buyer_email'] );
		}
	}

	/**
	 * Trimite cardul (JPG în email + PDF atașat) cumpărătorului sau destinatarului.
	 */
	public static function send_card( $id, $who = 'buyer' ) {
		$m   = self::meta( $id );
		$to  = 'recipient' === $who ? $m['recipient_email'] : $m['buyer_email'];
		if ( ! is_email( $to ) || 'pending' === $m['status'] ) {
			return false;
		}
		if ( ! self::file( $id, 'pdf' ) ) {
			self::build_files( $id );
		}
		$pdf  = self::file( $id, 'pdf' );
		$jpg  = self::download_url( $id, 'jpg' );
		$code = self::code( $id );
		$book = trim( (string) STM_Helpers::get( 'gift_book_url' ) );
		$book = $book ? $book : home_url( '/' );
		$img  = '<p><a href="' . esc_url( self::download_url( $id, 'pdf' ) ) . '"><img src="' . esc_url( $jpg ) . '" alt="Card cadou ' . esc_attr( $code ) . '" style="width:100%;max-width:600px;height:auto;border-radius:6px"></a></p>';
		$how  = '<p><strong>Cum se folosește:</strong> pe <a href="' . esc_url( $book ) . '">' . esc_html( wp_parse_url( $book, PHP_URL_HOST ) ) . '</a> se alege ședința, ziua și ora, iar la pasul „Confirmare” se scrie codul <strong>' . esc_html( $code ) . '</strong>. Valoarea cardului se scade automat.'
			. ( $m['expires'] ? ' Cardul este valabil până la ' . esc_html( preg_replace( '/^[^,]+,\s*/', '', STM_Helpers::date_label( $m['expires'] ) ) ) . '.' : '' ) . '</p>';
		$links = '<p><a href="' . esc_url( self::download_url( $id, 'pdf' ) ) . '" style="display:inline-block;background:#1f2328;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Descarcă PDF pentru print</a> &nbsp; <a href="' . esc_url( $jpg ) . '">Descarcă imaginea</a></p>';

		if ( 'recipient' === $who ) {
			$body = '<p>Bună, ' . esc_html( $m['to'] ) . '! Ai primit un card cadou din partea ' . esc_html( $m['from'] ) . ': o ședință foto cu Marian Sterea.</p>'
				. ( $m['message'] ? '<p style="font-style:italic">„' . esc_html( $m['message'] ) . '”</p>' : '' ) . $img . $how . $links;
			$subject = 'Un cadou pentru tine din partea ' . $m['from'] . ': ședință foto';
		} else {
			$auto = ! empty( $m['auto'] ) && empty( $m['paid_at'] );
			$body = '<p>Bună, ' . esc_html( $m['buyer_name'] ) . '! ' . ( $auto ? 'Mulțumim pentru comandă, cardul cadou este gata.' : 'Plata a fost confirmată, cardul cadou este gata.' ) . ' Îl poți trimite mai departe (imaginea sau PDF-ul) sau îl poți printa.</p>'
				. $img . self::summary( $id ) . ( $auto ? self::payment_block() : '' ) . $how . $links;
			$subject = 'Cardul tău cadou ' . $code . ' este gata';
		}
		$admins = self::admin_emails();
		$ok     = self::send( $to, $subject, self::wrap( 'recipient' === $who ? 'Un cadou pentru tine' : 'Cardul cadou este gata', $body ), $pdf ? array( $pdf ) : array(), $admins ? $admins[0] : '' );
		if ( $ok ) {
			$m = self::meta( $id );
			$m[ 'recipient' === $who ? 'sent_recipient' : 'sent_buyer' ] = STM_Helpers::now()->format( 'Y-m-d H:i' );
			update_post_meta( $id, self::META, $m );
		}
		return $ok;
	}

	/**
	 * Cron: trimite destinatarilor cardurile programate la o dată, și marchează expirările.
	 */
	public static function deliver_scheduled() {
		$today = STM_Helpers::now()->format( 'Y-m-d' );
		$ids   = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'numberposts' => 200,
				'fields'      => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			$m = self::meta( $id );
			if ( 'paid' === $m['status'] && $m['recipient_email'] && $m['send_at'] && ! $m['sent_recipient'] && $m['send_at'] <= $today ) {
				self::send_card( $id, 'recipient' );
			}
			if ( 'paid' === $m['status'] && $m['expires'] && $m['expires'] < $today ) {
				self::set_status( $id, 'expired' );
			}
		}
	}

	/* ---------- REST ---------- */

	public static function routes() {
		register_rest_route(
			STM_Rest::NS,
			'/giftcard',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_create' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function rest_create( WP_REST_Request $request ) {
		$in = $request->get_json_params();
		if ( ! is_array( $in ) ) {
			$in = $request->get_body_params();
		}
		if ( ! empty( $in['website'] ) ) {
			return new WP_Error( 'stm_spam', 'Cererea nu a putut fi trimisă.', array( 'status' => 400 ) );
		}
		// Pe site-ul secundar limita e pe IP-ul vizitatorului; pe cel principal, pe IP + X-STM-Client-IP.
		if ( STM_Rest::rate_limited( 'gift', 5 ) ) {
			return new WP_Error( 'stm_limit', 'Prea multe încercări. Încearcă din nou mai târziu sau sună-ne.', array( 'status' => 429 ) );
		}
		$fields = array( 'kind', 'theme', 'package', 'amount', 'occasion', 'to', 'from', 'message', 'buyer_name', 'buyer_email', 'buyer_phone', 'recipient_email', 'send_at', 'consent' );
		$clean  = array();
		foreach ( $fields as $f ) {
			$clean[ $f ] = isset( $in[ $f ] ) && is_scalar( $in[ $f ] ) ? (string) $in[ $f ] : '';
		}
		if ( STM_Helpers::is_remote() ) {
			// Generarea cardului și emailurile pot dura pe site-ul principal: timeout mai mare decât la rezervare.
			$data = STM_Rest::remote(
				'/giftcard',
				array(
					'timeout' => 30,
					'headers' => array(
						'Content-Type'    => 'application/json',
						'X-STM-Client-IP' => STM_Helpers::client_ip(),
					),
					'body'    => wp_json_encode( $clean ),
				)
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			// Ce vine de pe celălalt site se afișează în pagină: se filtrează din nou.
			return STM_Rest::no_cache(
				array(
					'ok'      => ! empty( $data['ok'] ),
					'issued'  => ! empty( $data['issued'] ),
					'code'    => sanitize_text_field( isset( $data['code'] ) ? (string) $data['code'] : '' ),
					'value'   => sanitize_text_field( isset( $data['value'] ) ? (string) $data['value'] : '' ),
					'message' => sanitize_text_field( isset( $data['message'] ) ? (string) $data['message'] : '' ),
					'payment' => wp_kses_post( isset( $data['payment'] ) ? (string) $data['payment'] : '' ),
					'preview' => esc_url_raw( isset( $data['preview'] ) ? (string) $data['preview'] : '' ),
					'image'   => esc_url_raw( isset( $data['image'] ) ? (string) $data['image'] : '' ),
				)
			);
		}
		if ( ! STM_Rest::gift_config()['on'] ) {
			return new WP_Error( 'stm_gift_off', 'Cardurile cadou nu sunt disponibile momentan.', array( 'status' => 403 ) );
		}

		$id = self::create( $clean );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::on_created_emails( $id );
		$m      = self::meta( $id );
		$issued = 'pending' !== $m['status'];
		// Linkurile sunt absolute (home_url al acestui site), deci merg și când comanda vine de pe un site secundar.
		return STM_Rest::no_cache(
			array(
				'ok'      => true,
				'issued'  => $issued,
				'code'    => self::code( $id ),
				'value'   => STM_Helpers::money( $m['value'] ),
				'message' => $issued
					? 'Am trimis cardul pe ' . $m['buyer_email'] . ', împreună cu detaliile de plată. Dacă nu-l vezi în câteva minute, uită-te și în Spam.'
					: (string) STM_Helpers::get( 'gift_success_text' ),
				'payment' => wp_kses_post( nl2br( esc_html( (string) STM_Helpers::get( 'gift_payment_text' ) ) ) ),
				'preview' => $issued ? self::download_url( $id, 'pdf' ) : '',
				'image'   => $issued && self::file( $id, 'jpg' ) ? self::download_url( $id, 'jpg' ) : '',
			)
		);
	}

	/* ---------- Shortcode ---------- */

	/**
	 * [sterea_mini_card_cadou id="…"] = formularul de rezervare, pornit direct pe „Oferă cadou”.
	 */
	public static function shortcode( $atts ) {
		if ( ! STM_Helpers::get( 'gift_enabled' ) && ! STM_Helpers::is_remote() ) {
			return '';
		}
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'sterea_mini_card_cadou' );
		return STM_Frontend::shortcode(
			array(
				'id'    => absint( $atts['id'] ),
				'cadou' => 1,
			)
		);
	}

	/* ---------- Modelul cardului (imagine cu date de exemplu) ---------- */

	const MODEL_W = 1400;
	const MODEL_H = 540;

	/**
	 * Imaginile-model din assets/img (generate o dată cu STM_Giftcard_Image::render, cu date de
	 * exemplu): giftcard-model-{stil}.webp (1400 × 540) și giftcard-model-{stil}-700.webp (700 × 270).
	 * Doar biletul, cu colțuri rotunjite transparente, fără ramă și fără umbră (umbra vine din CSS).
	 */
	public static function model( $style ) {
		$style = 'negru' === $style ? 'negru' : 'craciun';
		$base  = STM_URL . 'assets/img/giftcard-model-' . $style;
		return array(
			'src'    => $base . '.webp?ver=' . STM_VERSION,
			'small'  => $base . '-700.webp?ver=' . STM_VERSION,
			'width'  => self::MODEL_W,
			'height' => self::MODEL_H,
			'alt'    => 'craciun' === $style
				? 'Model de card cadou pentru o ședință foto de Crăciun cu Marian Sterea: numele celui care îl primește, din partea cui, mesajul și codul de rezervare'
				: 'Model de card cadou pentru o ședință foto cu Marian Sterea: numele celui care îl primește, din partea cui, mesajul și codul de rezervare',
		);
	}

	/**
	 * [sterea_mini_card_model stil="craciun|negru" link="" alt="" latime=""] = imaginea-model a
	 * cardului, opțional ca link (ex. spre pagina de comandă). Merge și pe un site secundar:
	 * imaginile sunt în pluginul local.
	 */
	public static function model_shortcode( $atts ) {
		$atts  = shortcode_atts(
			array(
				'stil'   => 'craciun',
				'link'   => '',
				'alt'    => '',
				'latime' => '',
			),
			$atts,
			'sterea_mini_card_model'
		);
		$model = self::model( sanitize_key( $atts['stil'] ) );
		$alt   = '' !== trim( $atts['alt'] ) ? sanitize_text_field( $atts['alt'] ) : $model['alt'];
		$max   = absint( $atts['latime'] );
		$img   = sprintf(
			'<img class="stm-card-model" src="%1$s" srcset="%2$s 700w, %1$s 1400w" sizes="(max-width: 760px) 100vw, %3$s" width="%4$d" height="%5$d" alt="%6$s" loading="lazy" decoding="async" style="display:block;width:100%%;max-width:%7$s;height:auto">',
			esc_url( $model['src'] ),
			esc_url( $model['small'] ),
			esc_attr( $max ? $max . 'px' : '900px' ),
			$model['width'],
			$model['height'],
			esc_attr( $alt ),
			esc_attr( $max ? $max . 'px' : '100%' )
		);
		$link = esc_url( trim( $atts['link'] ) );
		return $link ? '<a class="stm-card-model-link" href="' . $link . '" style="display:inline-block;max-width:100%">' . $img . '</a>' : $img;
	}

	/* ---------- Admin ---------- */

	public static function action_url( $do, $id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=stm_gift&do=' . $do . '&id=' . (int) $id ), 'stm_gift_' . $do . '_' . (int) $id );
	}

	public static function handle_admin() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		check_admin_referer( 'stm_gift_' . $do . '_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) || self::CPT !== get_post_type( $id ) ) {
			wp_die( 'Nu ai voie.' );
		}
		$msg = '';
		switch ( $do ) {
			case 'paid':
				$before = self::meta( $id );
				$auto   = 'paid' === $before['status'] && ! empty( $before['auto'] ) && empty( $before['paid_at'] );
				if ( ! self::mark_paid( $id ) ) {
					$msg = 'Cardul nu era în așteptarea plății.';
				} else {
					$msg = $auto ? 'Plata pentru cardul ' . self::code( $id ) . ' a fost notată (cardul fusese deja trimis).' : 'Cardul ' . self::code( $id ) . ' este plătit; a fost generat și trimis pe email.';
				}
				break;
			case 'resend':
				$msg = self::send_card( $id, 'buyer' ) ? 'Cardul a fost retrimis cumpărătorului.' : 'Nu s-a putut trimite (cardul nu e plătit sau emailul lipsește).';
				break;
			case 'resend_recipient':
				$msg = self::send_card( $id, 'recipient' ) ? 'Cardul a fost trimis destinatarului.' : 'Nu s-a putut trimite (lipsește emailul destinatarului sau cardul nu e plătit).';
				break;
			case 'rebuild':
				$msg = self::build_files( $id ) ? 'Fișierele cardului au fost regenerate.' : 'Fișierele nu au putut fi generate (verifică extensia GD).';
				break;
			case 'cancel':
				self::set_status( $id, 'cancelled' );
				$msg = 'Cardul a fost anulat.';
				break;
		}
		set_transient( 'stm_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . self::CPT ) );
		exit;
	}

	public static function title_placeholder( $text, $post ) {
		return self::CPT === $post->post_type ? 'Codul se generează automat la salvare' : $text;
	}

	public static function meta_boxes() {
		add_meta_box( 'stm_gift', 'Cardul cadou', array( __CLASS__, 'box' ), self::CPT, 'normal', 'high' );
		add_meta_box( 'stm_gift_side', 'Status și acțiuni', array( __CLASS__, 'box_side' ), self::CPT, 'side', 'high' );
	}

	public static function box( $post ) {
		$m      = self::meta( $post->ID );
		$themes = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_THEME,
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);
		wp_nonce_field( 'stm_gift_save', 'stm_gift_nonce' );
		?>
		<div class="stm-coupon-grid">
			<div>
				<label class="stm-lbl">Ce oferă</label>
				<label><input type="radio" name="stmg[kind]" value="package" <?php checked( $m['kind'], 'package' ); ?>> o ședință dintr-un eveniment</label>&nbsp;&nbsp;
				<label><input type="radio" name="stmg[kind]" value="amount" <?php checked( $m['kind'], 'amount' ); ?>> o sumă (lei)</label>
			</div>
			<div>
				<label class="stm-lbl" for="stmg-theme">Eveniment și pachet</label>
				<select id="stmg-theme" name="stmg[theme]">
					<option value="0">–</option>
					<?php foreach ( $themes as $t ) : ?>
						<option value="<?php echo (int) $t->ID; ?>" <?php selected( (int) $m['theme'], $t->ID ); ?>><?php echo esc_html( get_the_title( $t ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="stmg[package]" value="<?php echo esc_attr( $m['package'] ); ?>" class="regular-text" placeholder="numele pachetului (ex. Ședință foto 30 min + album foto)">
			</div>
			<div>
				<label class="stm-lbl" for="stmg-value">Valoare (lei)</label>
				<input type="number" id="stmg-value" min="0" step="0.01" name="stmg[value]" value="<?php echo esc_attr( $m['value'] ); ?>" class="small-text">
				&nbsp; rămas: <strong><?php echo esc_html( STM_Helpers::money( $m['remaining'] ) ); ?></strong>
			</div>
			<div>
				<label class="stm-lbl" for="stmg-occasion">Ocazia</label>
				<select id="stmg-occasion" name="stmg[occasion]">
					<?php foreach ( self::occasions() as $o ) : ?>
						<option value="<?php echo esc_attr( $o ); ?>" <?php selected( $m['occasion'], $o ); ?>><?php echo esc_html( $o ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label class="stm-lbl" for="stmg-to">Pentru</label>
				<input type="text" id="stmg-to" name="stmg[to]" value="<?php echo esc_attr( $m['to'] ); ?>" class="regular-text" maxlength="40">
			</div>
			<div>
				<label class="stm-lbl" for="stmg-from">Din partea</label>
				<input type="text" id="stmg-from" name="stmg[from]" value="<?php echo esc_attr( $m['from'] ); ?>" class="regular-text" maxlength="40">
			</div>
			<div class="is-wide">
				<label class="stm-lbl" for="stmg-message">Mesaj (maximum 150 de caractere)</label>
				<textarea id="stmg-message" name="stmg[message]" rows="2" class="large-text" maxlength="150"><?php echo esc_textarea( $m['message'] ); ?></textarea>
			</div>
			<div>
				<label class="stm-lbl" for="stmg-bname">Cumpărător</label>
				<input type="text" id="stmg-bname" name="stmg[buyer_name]" value="<?php echo esc_attr( $m['buyer_name'] ); ?>" class="regular-text" placeholder="nume">
				<input type="text" name="stmg[buyer_phone]" value="<?php echo esc_attr( $m['buyer_phone'] ); ?>" class="regular-text" placeholder="telefon">
				<input type="email" name="stmg[buyer_email]" value="<?php echo esc_attr( $m['buyer_email'] ); ?>" class="regular-text" placeholder="email">
			</div>
			<div>
				<label class="stm-lbl" for="stmg-remail">Destinatar (opțional)</label>
				<input type="email" id="stmg-remail" name="stmg[recipient_email]" value="<?php echo esc_attr( $m['recipient_email'] ); ?>" class="regular-text" placeholder="email destinatar">
				<label for="stmg-send">trimis pe</label> <input type="date" id="stmg-send" name="stmg[send_at]" value="<?php echo esc_attr( $m['send_at'] ); ?>">
				<p class="description">Gol = odată cu cumpărătorul. Trimis: <?php echo esc_html( $m['sent_recipient'] ? $m['sent_recipient'] : 'nu încă' ); ?>.</p>
			</div>
			<div>
				<label class="stm-lbl" for="stmg-expires">Valabil până la</label>
				<input type="date" id="stmg-expires" name="stmg[expires]" value="<?php echo esc_attr( $m['expires'] ); ?>">
				<p class="description">Se completează automat la plată (<?php echo (int) STM_Helpers::get( 'gift_months' ); ?> luni, sau ultima zi a evenimentului).</p>
			</div>
			<div>
				<label class="stm-lbl" for="stmg-note">Notă internă</label>
				<input type="text" id="stmg-note" name="stmg[note]" value="<?php echo esc_attr( $m['note'] ); ?>" class="regular-text" placeholder="ex. plătit cash la studio">
			</div>
		</div>
		<?php if ( $m['file'] && 'pending' !== $m['status'] ) : ?>
			<p><a href="<?php echo esc_url( self::download_url( $post->ID, 'jpg' ) ); ?>" target="_blank"><img src="<?php echo esc_url( self::download_url( $post->ID, 'jpg' ) ); ?>" alt="" style="max-width:100%;height:auto;border:1px solid #ddd"></a></p>
		<?php endif; ?>
		<p class="description">După ce modifici textele unui card deja plătit, apasă „Regenerează fișierele” din dreapta și, dacă e cazul, „Retrimite”.</p>
		<?php
	}

	public static function box_side( $post ) {
		$m = self::meta( $post->ID );
		?>
		<p><strong>Cod:</strong> <code><?php echo esc_html( self::code( $post->ID ) ); ?></code></p>
		<p><label for="stmg-status"><strong>Status</strong></label><br>
		<select id="stmg-status" name="stmg[status]">
			<?php foreach ( self::STATUSES as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $m['status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select></p>
		<?php if ( $m['issued'] ) : ?>
			<p class="description">Emis: <?php echo esc_html( $m['issued'] ); ?>. Trimis cumpărătorului: <?php echo esc_html( $m['sent_buyer'] ? $m['sent_buyer'] : 'nu încă' ); ?>.
			<?php if ( ! empty( $m['auto'] ) ) : ?>
				Plata: <?php echo esc_html( $m['paid_at'] ? 'confirmată pe ' . $m['paid_at'] : 'neconfirmată încă (card trimis automat la comandă)' ); ?>.
			<?php endif; ?></p>
		<?php endif; ?>
		<?php if ( $m['booking'] ) : ?>
			<p>Folosit la <a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $m['booking'] . '&action=edit' ) ); ?>">rezervarea #<?php echo (int) $m['booking']; ?></a>.</p>
		<?php endif; ?>
		<?php if ( 'auto-draft' !== $post->post_status ) : ?>
			<p>
				<?php if ( in_array( $m['status'], array( 'pending', 'cancelled' ), true ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( self::action_url( 'paid', $post->ID ) ); ?>">Marchează plătit și trimite</a>
				<?php else : ?>
					<?php if ( 'paid' === $m['status'] && ! empty( $m['auto'] ) && empty( $m['paid_at'] ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( self::action_url( 'paid', $post->ID ) ); ?>">Am primit plata</a>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( self::action_url( 'resend', $post->ID ) ); ?>">Retrimite cumpărătorului</a>
					<?php if ( $m['recipient_email'] ) : ?>
						<a class="button" href="<?php echo esc_url( self::action_url( 'resend_recipient', $post->ID ) ); ?>">Trimite destinatarului</a>
					<?php endif; ?>
				<?php endif; ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( self::action_url( 'rebuild', $post->ID ) ); ?>">Regenerează fișierele</a>
				<?php if ( $m['file'] ) : ?>
					<a class="button" href="<?php echo esc_url( self::download_url( $post->ID, 'pdf' ) ); ?>" target="_blank">PDF</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['stm_gift_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['stm_gift_nonce'] ) ), 'stm_gift_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$in   = isset( $_POST['stmg'] ) && is_array( $_POST['stmg'] ) ? wp_unslash( $_POST['stmg'] ) : array();
		$m    = self::meta( $post_id );
		$date = function ( $v ) {
			$v = sanitize_text_field( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		$was_new = '' === $m['token'];
		$old_val = (float) $m['value'];

		$m['kind']            = isset( $in['kind'] ) && 'amount' === $in['kind'] ? 'amount' : 'package';
		$m['theme']           = absint( isset( $in['theme'] ) ? $in['theme'] : 0 );
		$m['package']         = sanitize_text_field( isset( $in['package'] ) ? $in['package'] : '' );
		$m['value']           = max( 0, round( (float) ( isset( $in['value'] ) ? $in['value'] : 0 ), 2 ) );
		$m['occasion']        = sanitize_text_field( isset( $in['occasion'] ) ? $in['occasion'] : 'Cu drag' );
		$m['to']              = mb_substr( sanitize_text_field( isset( $in['to'] ) ? $in['to'] : '' ), 0, 40 );
		$m['from']            = mb_substr( sanitize_text_field( isset( $in['from'] ) ? $in['from'] : '' ), 0, 40 );
		$m['message']         = mb_substr( sanitize_textarea_field( isset( $in['message'] ) ? $in['message'] : '' ), 0, 150 );
		$m['buyer_name']      = sanitize_text_field( isset( $in['buyer_name'] ) ? $in['buyer_name'] : '' );
		$m['buyer_phone']     = sanitize_text_field( isset( $in['buyer_phone'] ) ? $in['buyer_phone'] : '' );
		$m['buyer_email']     = sanitize_email( isset( $in['buyer_email'] ) ? $in['buyer_email'] : '' );
		$m['recipient_email'] = sanitize_email( isset( $in['recipient_email'] ) ? $in['recipient_email'] : '' );
		$m['send_at']         = $date( isset( $in['send_at'] ) ? $in['send_at'] : '' );
		$m['expires']         = $date( isset( $in['expires'] ) ? $in['expires'] : '' );
		$m['note']            = sanitize_text_field( isset( $in['note'] ) ? $in['note'] : '' );
		$status               = isset( $in['status'] ) ? sanitize_key( $in['status'] ) : $m['status'];
		if ( ! in_array( $m['occasion'], self::occasions(), true ) ) {
			$m['occasion'] = 'Cu drag';
		}
		if ( $was_new || $old_val !== (float) $m['value'] ) {
			$m['remaining'] = $m['value'];
		}
		if ( $was_new ) {
			$m['token'] = wp_generate_password( 32, false, false );
		}
		if ( isset( self::STATUSES[ $status ] ) ) {
			if ( 'paid' === $status && 'paid' !== $m['status'] && ! $m['issued'] ) {
				$m['issued'] = STM_Helpers::now()->format( 'Y-m-d' );
			}
			$m['status'] = $status;
		}
		if ( 'paid' === $m['status'] && ! $m['expires'] ) {
			$m['expires'] = self::expiry_for( $m );
		}
		update_post_meta( $post_id, self::META, $m );

		// Titlul = codul; nou → cod generat; schimbat manual → normalizat, unic.
		$code = STM_Coupons::normalize( $post->post_title );
		$dupe = $code ? self::find( $code ) : null;
		if ( '' === $code || ( $dupe && (int) $dupe !== (int) $post_id ) || STM_Coupons::find( $code ) ) {
			$code = self::new_code();
		}
		if ( $code !== $post->post_title ) {
			remove_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10 );
			wp_update_post( array( 'ID' => $post_id, 'post_title' => $code ) );
			add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
		}
		if ( 'pending' !== $m['status'] && $m['file'] ) {
			self::build_files( $post_id );
		}
	}

	public static function columns( $columns ) {
		return array(
			'cb'         => $columns['cb'],
			'title'      => 'Cod',
			'stm_gift'   => 'Card',
			'stm_people' => 'Pentru / din partea',
			'stm_buyer'  => 'Cumpărător',
			'stm_status' => 'Status',
			'stm_dates'  => 'Date',
		);
	}

	public static function column( $column, $post_id ) {
		$m = self::meta( $post_id );
		switch ( $column ) {
			case 'stm_gift':
				echo '<strong>' . esc_html( STM_Helpers::money( $m['value'] ) ) . '</strong><br><span class="stm-muted">' . esc_html( 'package' === $m['kind'] ? get_the_title( $m['theme'] ) . ' · ' . $m['package'] : 'sumă, orice ședință' ) . '</span>';
				if ( (float) $m['remaining'] !== (float) $m['value'] ) {
					echo '<br><span class="stm-muted">rămas ' . esc_html( STM_Helpers::money( $m['remaining'] ) ) . '</span>';
				}
				break;
			case 'stm_people':
				echo esc_html( $m['to'] ) . '<br><span class="stm-muted">din partea ' . esc_html( $m['from'] ) . ( $m['occasion'] ? ' · ' . esc_html( $m['occasion'] ) : '' ) . '</span>';
				break;
			case 'stm_buyer':
				echo esc_html( $m['buyer_name'] ) . '<br><span class="stm-muted">' . esc_html( $m['buyer_email'] ) . ( $m['buyer_phone'] ? ' · ' . esc_html( $m['buyer_phone'] ) : '' ) . '</span>';
				if ( $m['recipient_email'] ) {
					echo '<br><span class="stm-muted">→ ' . esc_html( $m['recipient_email'] ) . ( $m['send_at'] ? ' pe ' . esc_html( $m['send_at'] ) : '' ) . '</span>';
				}
				break;
			case 'stm_status':
				$cls = array( 'pending' => 'pending', 'paid' => 'confirmed', 'used' => 'hidden', 'expired' => 'expired', 'cancelled' => 'cancelled' );
				echo '<span class="stm-badge stm-badge--' . esc_attr( $cls[ $m['status'] ] ) . '">' . esc_html( self::STATUSES[ $m['status'] ] ) . '</span>';
				if ( in_array( $m['status'], array( 'pending', 'cancelled' ), true ) ) {
					echo '<br><a href="' . esc_url( self::action_url( 'paid', $post_id ) ) . '">Marchează plătit</a>';
				} elseif ( ! empty( $m['auto'] ) && empty( $m['paid_at'] ) && 'paid' === $m['status'] ) {
					echo '<br><span class="stm-muted">plata neconfirmată</span> · <a href="' . esc_url( self::action_url( 'paid', $post_id ) ) . '">Am primit plata</a>';
				}
				if ( $m['booking'] ) {
					echo '<br><a href="' . esc_url( admin_url( 'post.php?post=' . (int) $m['booking'] . '&action=edit' ) ) . '">rezervarea #' . (int) $m['booking'] . '</a>';
				}
				break;
			case 'stm_dates':
				echo esc_html( get_the_date( 'j M Y', $post_id ) );
				if ( $m['expires'] ) {
					echo '<br><span class="stm-muted">până la ' . esc_html( wp_date( 'j M Y', strtotime( $m['expires'] . ' 12:00' ) ) ) . '</span>';
				}
				break;
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( self::CPT !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'] );
		$m = self::meta( $post->ID );
		if ( 'pending' !== $m['status'] && $m['file'] ) {
			$actions['stm_pdf'] = '<a href="' . esc_url( self::download_url( $post->ID, 'pdf' ) ) . '" target="_blank">PDF</a>';
			$actions['stm_resend'] = '<a href="' . esc_url( self::action_url( 'resend', $post->ID ) ) . '">Retrimite</a>';
		}
		if ( in_array( $m['status'], array( 'pending', 'paid' ), true ) ) {
			$actions['stm_cancel'] = '<a href="' . esc_url( self::action_url( 'cancel', $post->ID ) ) . '" onclick="return confirm(\'Anulezi acest card cadou?\')">Anulează</a>';
		}
		return $actions;
	}

	public static function filters( $post_type ) {
		if ( self::CPT !== $post_type ) {
			return;
		}
		$cur = isset( $_GET['stm_gstatus'] ) ? sanitize_key( $_GET['stm_gstatus'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="stm_gstatus"><option value="">Toate statusurile</option>';
		foreach ( self::STATUSES as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $cur, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function list_query( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || self::CPT !== $q->get( 'post_type' ) ) {
			return;
		}
		$st = isset( $_GET['stm_gstatus'] ) ? sanitize_key( $_GET['stm_gstatus'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $st && isset( self::STATUSES[ $st ] ) ) {
			$q->set(
				'meta_query',
				array(
					array(
						'key'     => self::META,
						'value'   => '"status";s:' . strlen( $st ) . ':"' . $st . '"',
						'compare' => 'LIKE',
					),
				)
			);
		}
	}
}
