<?php
/**
 * Bookings: post type, slot locks, creation, admin list and edit screen.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Bookings {

	const DB_VERSION = '1';

	const FIELDS = array( 'theme', 'start', 'status', 'package', 'price', 'name', 'phone', 'email', 'people', 'notes', 'token', 'source', 'reminded' );

	public static function locks_table() {
		global $wpdb;
		return $wpdb->prefix . 'stm_locks';
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . STM_Helpers::CPT_BOOKING, array( __CLASS__, 'save_admin' ), 10, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_trash' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_untrash' ) );
		add_filter( 'manage_' . STM_Helpers::CPT_BOOKING . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . STM_Helpers::CPT_BOOKING . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . STM_Helpers::CPT_BOOKING . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_query' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_stm_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/* ---------- Setup ---------- */

	public static function register() {
		register_post_type(
			STM_Helpers::CPT_BOOKING,
			array(
				'labels'          => array(
					'name'          => 'Rezervări',
					'singular_name' => 'Rezervare',
					'all_items'     => 'Rezervări',
					'add_new'       => 'Adaugă rezervare',
					'add_new_item'  => 'Adaugă rezervare (telefonică)',
					'edit_item'     => 'Editează rezervarea',
					'not_found'     => 'Nicio rezervare.',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . STM_Helpers::CPT_THEME,
				'supports'        => array( 'custom-fields' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
		remove_post_type_support( STM_Helpers::CPT_BOOKING, 'custom-fields' );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::locks_table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				theme_id bigint(20) unsigned NOT NULL,
				slot_start datetime NOT NULL,
				booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (theme_id,slot_start),
				KEY booking_id (booking_id)
			) {$charset};"
		);
		update_option( 'stm_db_version', self::DB_VERSION, false );
		STM_Emails::schedule();
	}

	public static function maybe_install() {
		if ( get_option( 'stm_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ---------- Data ---------- */

	public static function get( $id ) {
		$post = get_post( $id );
		if ( ! $post || STM_Helpers::CPT_BOOKING !== $post->post_type ) {
			return null;
		}
		$b = array( 'id' => (int) $id );
		foreach ( self::FIELDS as $f ) {
			$b[ $f ] = get_post_meta( $id, '_stm_' . $f, true );
		}
		$b['theme']  = (int) $b['theme'];
		$b['people'] = (int) $b['people'];
		return $b;
	}

	private static function lock( $theme_id, $start, $booking_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::locks_table() . ' (theme_id, slot_start, booking_id) VALUES (%d, %s, %d)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$theme_id,
				$start . ':00',
				$booking_id
			)
		);
		return 1 === (int) $ok;
	}

	private static function unlock( $booking_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::locks_table(), array( 'booking_id' => (int) $booking_id ), array( '%d' ) );
	}

	private static function lock_owner( $theme_id, $start ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT booking_id FROM ' . self::locks_table() . ' WHERE theme_id = %d AND slot_start = %s', $theme_id, $start . ':00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function title( $b ) {
		$time = substr( $b['start'], 11, 5 );
		return trim( $b['name'] ) . ' – ' . wp_date( 'j M Y', strtotime( substr( $b['start'], 0, 10 ) . ' 12:00' ) ) . ' ' . $time;
	}

	/**
	 * Public booking. $in: theme, date, time, package, name, phone, email, people, notes, source.
	 *
	 * @return int|WP_Error Booking ID.
	 */
	public static function create( $in ) {
		$theme_id = absint( isset( $in['theme'] ) ? $in['theme'] : 0 );
		$theme    = STM_Helpers::theme_public( $theme_id );
		if ( ! $theme ) {
			return new WP_Error( 'stm_theme', 'Evenimentul nu mai este disponibil.', array( 'status' => 404 ) );
		}

		$date = sanitize_text_field( isset( $in['date'] ) ? $in['date'] : '' );
		$time = sanitize_text_field( isset( $in['time'] ) ? $in['time'] : '' );
		$slot = null;
		foreach ( $theme['days'] as $day ) {
			if ( $day['date'] === $date ) {
				foreach ( $day['slots'] as $s ) {
					if ( $s['time'] === $time ) {
						$slot = $s;
					}
				}
			}
		}
		if ( ! $slot ) {
			return new WP_Error( 'stm_slot', 'Ora aleasă nu există.', array( 'status' => 400 ) );
		}
		if ( 'taken' === $slot['state'] ) {
			return new WP_Error( 'stm_taken', 'Ne pare rău, ora aceasta tocmai a fost rezervată. Te rugăm să alegi alta.', array( 'status' => 409 ) );
		}
		if ( 'free' !== $slot['state'] ) {
			return new WP_Error( 'stm_closed', 'Rezervările pentru această oră s-au închis.', array( 'status' => 400 ) );
		}

		$package = null;
		$pid     = isset( $in['package'] ) ? (int) $in['package'] : -1;
		foreach ( $theme['packages'] as $p ) {
			if ( $p['id'] === $pid ) {
				$package = $p;
			}
		}
		if ( ! $package ) {
			return new WP_Error( 'stm_package', 'Alege un pachet.', array( 'status' => 400 ) );
		}

		$name   = sanitize_text_field( isset( $in['name'] ) ? $in['name'] : '' );
		$phone  = preg_replace( '/[^\d+ ]/', '', (string) ( isset( $in['phone'] ) ? $in['phone'] : '' ) );
		$email  = sanitize_email( isset( $in['email'] ) ? $in['email'] : '' );
		$people = max( 1, min( (int) $theme['max_people'], absint( isset( $in['people'] ) ? $in['people'] : 1 ) ) );
		$notes  = sanitize_textarea_field( isset( $in['notes'] ) ? $in['notes'] : '' );

		if ( mb_strlen( $name ) < 3 ) {
			return new WP_Error( 'stm_name', 'Scrie numele complet.', array( 'status' => 400 ) );
		}
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 9 ) {
			return new WP_Error( 'stm_phone', 'Numărul de telefon nu pare corect.', array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'stm_email', 'Adresa de email nu pare corectă.', array( 'status' => 400 ) );
		}
		if ( empty( $in['consent'] ) ) {
			return new WP_Error( 'stm_consent', 'Bifează acordul pentru prelucrarea datelor.', array( 'status' => 400 ) );
		}

		$start = $date . ' ' . $time;
		if ( ! self::lock( $theme_id, $start, 0 ) ) {
			return new WP_Error( 'stm_taken', 'Ne pare rău, ora aceasta tocmai a fost rezervată. Te rugăm să alegi alta.', array( 'status' => 409 ) );
		}

		$b = array(
			'theme'    => $theme_id,
			'start'    => $start,
			'status'   => STM_Helpers::get( 'auto_confirm' ) ? 'confirmed' : 'pending',
			'package'  => $package['name'],
			'price'    => $package['price'],
			'name'     => $name,
			'phone'    => trim( $phone ),
			'email'    => $email,
			'people'   => $people,
			'notes'    => $notes,
			'token'    => wp_generate_password( 32, false, false ),
			'source'   => sanitize_text_field( isset( $in['source'] ) ? $in['source'] : wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'reminded' => 0,
		);

		$id = wp_insert_post(
			array(
				'post_type'   => STM_Helpers::CPT_BOOKING,
				'post_status' => 'publish',
				'post_title'  => self::title( $b ),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete(
				self::locks_table(),
				array(
					'theme_id'   => $theme_id,
					'slot_start' => $start . ':00',
					'booking_id' => 0,
				)
			);
			return new WP_Error( 'stm_save', 'Rezervarea nu a putut fi salvată. Încearcă din nou.', array( 'status' => 500 ) );
		}

		foreach ( $b as $k => $v ) {
			update_post_meta( $id, '_stm_' . $k, $v );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::locks_table(),
			array( 'booking_id' => $id ),
			array(
				'theme_id'   => $theme_id,
				'slot_start' => $start . ':00',
			)
		);

		do_action( 'stm_booking_created', $id );
		return $id;
	}

	public static function set_status( $id, $status ) {
		$b = self::get( $id );
		if ( ! $b || ! isset( STM_Helpers::STATUS_LABELS[ $status ] ) || $b['status'] === $status ) {
			return false;
		}
		if ( 'cancelled' === $status ) {
			self::unlock( $id );
		} elseif ( 'cancelled' === $b['status'] ) {
			// Re-activating: the slot must still be free.
			if ( ! self::lock( $b['theme'], $b['start'], $id ) && self::lock_owner( $b['theme'], $b['start'] ) !== $id ) {
				return false;
			}
		}
		update_post_meta( $id, '_stm_status', $status );
		do_action( 'stm_booking_status', $id, $status, $b['status'] );
		return true;
	}

	public static function find_by_token( $token ) {
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', (string) $token ) ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_BOOKING,
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_stm_token', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $token, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? self::get( $ids[0] ) : null;
	}

	public static function on_trash( $post_id ) {
		if ( get_post_type( $post_id ) === STM_Helpers::CPT_BOOKING ) {
			self::unlock( $post_id );
		}
	}

	public static function on_untrash( $post_id ) {
		$b = self::get( $post_id );
		if ( $b && 'cancelled' !== $b['status'] && ! self::lock( $b['theme'], $b['start'], $post_id ) ) {
			update_post_meta( $post_id, '_stm_status', 'cancelled' );
			set_transient( 'stm_notice_' . get_current_user_id(), 'Rezervarea a fost restaurată ca „Anulată”, pentru că ora ei a fost ocupată între timp.', 60 );
		}
	}

	/* ---------- Admin edit screen (manual / phone bookings) ---------- */

	public static function meta_boxes() {
		add_meta_box( 'stm_booking', 'Detalii rezervare', array( __CLASS__, 'box' ), STM_Helpers::CPT_BOOKING, 'normal', 'high' );
	}

	public static function box( $post ) {
		$b = self::get( $post->ID );
		if ( ! $b['status'] ) {
			$b['status'] = 'confirmed';
			$b['people'] = 1;
		}
		$themes = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_THEME,
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
			)
		);
		wp_nonce_field( 'stm_booking_save', 'stm_booking_nonce' );
		$date = $b['start'] ? substr( $b['start'], 0, 10 ) : '';
		$time = $b['start'] ? substr( $b['start'], 11, 5 ) : '';
		?>
		<table class="form-table">
			<tr>
				<th><label for="stm-b-theme">Eveniment</label></th>
				<td><select id="stm-b-theme" name="stmb[theme]">
					<?php foreach ( $themes as $t ) : ?>
						<option value="<?php echo (int) $t->ID; ?>" <?php selected( $b['theme'], $t->ID ); ?>><?php echo esc_html( get_the_title( $t ) ); ?></option>
					<?php endforeach; ?>
				</select></td>
			</tr>
			<tr>
				<th>Data și ora</th>
				<td><input type="date" name="stmb[date]" value="<?php echo esc_attr( $date ); ?>" required> <input type="time" name="stmb[time]" value="<?php echo esc_attr( $time ); ?>" required>
				<p class="description">Ora trebuie să fie una dintre orele de început ale evenimentului (ex. 12:00, 13:00…).</p></td>
			</tr>
			<tr>
				<th><label for="stm-b-package">Pachet</label></th>
				<td><input type="text" id="stm-b-package" class="regular-text" name="stmb[package]" value="<?php echo esc_attr( $b['package'] ); ?>"> Preț <input type="text" class="small-text" name="stmb[price]" value="<?php echo esc_attr( $b['price'] ); ?>"> lei</td>
			</tr>
			<tr>
				<th><label for="stm-b-name">Nume</label></th>
				<td><input type="text" id="stm-b-name" class="regular-text" name="stmb[name]" value="<?php echo esc_attr( $b['name'] ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="stm-b-phone">Telefon</label></th>
				<td><input type="tel" id="stm-b-phone" class="regular-text" name="stmb[phone]" value="<?php echo esc_attr( $b['phone'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="stm-b-email">Email</label></th>
				<td><input type="email" id="stm-b-email" class="regular-text" name="stmb[email]" value="<?php echo esc_attr( $b['email'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="stm-b-people">Persoane</label></th>
				<td><input type="number" id="stm-b-people" min="1" class="small-text" name="stmb[people]" value="<?php echo (int) $b['people']; ?>"></td>
			</tr>
			<tr>
				<th><label for="stm-b-notes">Observații</label></th>
				<td><textarea id="stm-b-notes" rows="3" class="large-text" name="stmb[notes]"><?php echo esc_textarea( $b['notes'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="stm-b-status">Status</label></th>
				<td><select id="stm-b-status" name="stmb[status]">
					<?php foreach ( STM_Helpers::STATUS_LABELS as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $b['status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( in_array( $post->post_status, array( 'auto-draft', 'draft' ), true ) ) : ?>
					<p><label><input type="checkbox" name="stmb[send_email]" value="1"> Trimite clientului emailul de confirmare</label></p>
				<?php endif; ?>
				</td>
			</tr>
			<?php if ( $b['source'] ) : ?>
			<tr><th>Rezervat de pe</th><td><?php echo esc_html( $b['source'] ); ?></td></tr>
			<?php endif; ?>
		</table>
		<?php
	}

	public static function save_admin( $post_id, $post ) {
		if ( ! isset( $_POST['stm_booking_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['stm_booking_nonce'] ) ), 'stm_booking_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$in  = isset( $_POST['stmb'] ) && is_array( $_POST['stmb'] ) ? wp_unslash( $_POST['stmb'] ) : array();
		$old = self::get( $post_id );
		$new = $old;
		$is_new = empty( $old['token'] );

		$new['theme']   = absint( isset( $in['theme'] ) ? $in['theme'] : 0 );
		$date           = sanitize_text_field( isset( $in['date'] ) ? $in['date'] : '' );
		$time           = sanitize_text_field( isset( $in['time'] ) ? $in['time'] : '' );
		$new['package'] = sanitize_text_field( isset( $in['package'] ) ? $in['package'] : '' );
		$new['price']   = sanitize_text_field( isset( $in['price'] ) ? $in['price'] : '' );
		$new['name']    = sanitize_text_field( isset( $in['name'] ) ? $in['name'] : '' );
		$new['phone']   = preg_replace( '/[^\d+ ]/', '', (string) ( isset( $in['phone'] ) ? $in['phone'] : '' ) );
		$new['email']   = sanitize_email( isset( $in['email'] ) ? $in['email'] : '' );
		$new['people']  = max( 1, absint( isset( $in['people'] ) ? $in['people'] : 1 ) );
		$new['notes']   = sanitize_textarea_field( isset( $in['notes'] ) ? $in['notes'] : '' );
		$status         = isset( $in['status'] ) && isset( STM_Helpers::STATUS_LABELS[ $in['status'] ] ) ? $in['status'] : 'confirmed';

		if ( $is_new ) {
			$new['token']    = wp_generate_password( 32, false, false );
			$new['source']   = 'admin';
			$new['reminded'] = 0;
		}

		// A booking that already had a valid slot keeps it on error; a new one is kept as a draft
		// (nothing typed is lost, no slot is blocked) until a free time is chosen.
		$notice   = '';
		$draft    = false;
		$had_slot = (bool) $old['start'] && 'publish' === $post->post_status;
		$valid    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && null !== STM_Helpers::to_min( $time ) && $new['theme'];
		if ( $valid ) {
			$new['start'] = $date . ' ' . $time;
		} elseif ( $had_slot ) {
			$notice = 'Data sau ora nu sunt valide; rezervarea nu a fost mutată.';
		} else {
			$notice = 'Data sau ora nu sunt valide. Rezervarea a fost salvată ca ciornă: completează data și ora, apoi salvează din nou.';
			$draft  = true;
		}

		// Keep the slot lock in sync with the booking.
		if ( $valid && 'cancelled' !== $status ) {
			$owner = self::lock_owner( $new['theme'], $new['start'] );
			if ( $owner && $owner !== $post_id ) {
				if ( $had_slot ) {
					$notice       = 'Ora aleasă este deja ocupată de altă rezervare. Rezervarea nu a fost mutată.';
					$new['start'] = $old['start'];
					$new['theme'] = $old['theme'];
				} else {
					$notice = 'Ora aleasă este deja ocupată. Rezervarea a fost salvată ca ciornă: alege altă oră și salvează din nou.';
					$draft  = true;
					self::unlock( $post_id );
				}
			} elseif ( ! $owner ) {
				self::unlock( $post_id );
				self::lock( $new['theme'], $new['start'], $post_id );
			}
		} elseif ( 'cancelled' === $status || $draft ) {
			self::unlock( $post_id );
		}
		if ( $draft ) {
			$status = 'pending';
		}

		if ( $new['start'] !== $old['start'] ) {
			$new['reminded'] = 0;
		}

		$prev_status   = $old['status'];
		$new['status'] = $status;
		foreach ( self::FIELDS as $f ) {
			update_post_meta( $post_id, '_stm_' . $f, $new[ $f ] );
		}

		remove_action( 'save_post_' . STM_Helpers::CPT_BOOKING, array( __CLASS__, 'save_admin' ), 10 );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_title'  => self::title( $new ),
				'post_status' => $draft ? 'draft' : 'publish',
			)
		);
		add_action( 'save_post_' . STM_Helpers::CPT_BOOKING, array( __CLASS__, 'save_admin' ), 10, 2 );

		if ( $notice ) {
			set_transient( 'stm_notice_' . get_current_user_id(), $notice, 60 );
		}
		if ( $draft ) {
			return;
		}
		// The confirmation email is sent once, the first time a manual booking gets a real slot.
		if ( ( $is_new || ! $had_slot ) && ! empty( $in['send_email'] ) ) {
			do_action( 'stm_booking_created', $post_id );
		} elseif ( $had_slot && $prev_status !== $status ) {
			do_action( 'stm_booking_status', $post_id, $status, $prev_status );
		}
	}

	public static function notices() {
		$key    = 'stm_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['stm_done'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Statusul rezervării a fost actualizat.</p></div>';
		}
	}

	/* ---------- Admin list ---------- */

	public static function columns( $columns ) {
		return array(
			'cb'          => $columns['cb'],
			'stm_start'   => 'Data și ora',
			'title'       => 'Client',
			'stm_contact' => 'Contact',
			'stm_theme'   => 'Eveniment',
			'stm_package' => 'Pachet',
			'stm_people'  => 'Pers.',
			'stm_status'  => 'Status',
			'stm_source'  => 'Sursă',
		);
	}

	public static function column( $column, $post_id ) {
		$b = self::get( $post_id );
		switch ( $column ) {
			case 'stm_start':
				if ( $b['start'] ) {
					echo '<strong>' . esc_html( wp_date( 'D, j M Y', strtotime( substr( $b['start'], 0, 10 ) . ' 12:00' ) ) ) . '</strong><br>' . esc_html( substr( $b['start'], 11, 5 ) );
				}
				break;
			case 'stm_contact':
				if ( $b['phone'] ) {
					echo '<a href="tel:' . esc_attr( preg_replace( '/\s+/', '', $b['phone'] ) ) . '">' . esc_html( $b['phone'] ) . '</a><br>';
				}
				if ( $b['email'] ) {
					echo '<a href="mailto:' . esc_attr( $b['email'] ) . '">' . esc_html( $b['email'] ) . '</a>';
				}
				break;
			case 'stm_theme':
				echo esc_html( $b['theme'] ? get_the_title( $b['theme'] ) : '—' );
				break;
			case 'stm_package':
				echo esc_html( $b['package'] );
				if ( $b['price'] ) {
					echo '<br><span class="stm-muted">' . esc_html( STM_Helpers::price_label( $b['price'] ) ) . '</span>';
				}
				if ( $b['notes'] ) {
					echo '<br><span class="stm-muted" title="' . esc_attr( $b['notes'] ) . '">📝 ' . esc_html( wp_trim_words( $b['notes'], 8 ) ) . '</span>';
				}
				break;
			case 'stm_people':
				echo (int) $b['people'];
				break;
			case 'stm_status':
				$label = isset( STM_Helpers::STATUS_LABELS[ $b['status'] ] ) ? STM_Helpers::STATUS_LABELS[ $b['status'] ] : $b['status'];
				echo '<span class="stm-badge stm-badge--' . esc_attr( $b['status'] ) . '">' . esc_html( $label ) . '</span>';
				break;
			case 'stm_source':
				echo esc_html( 'admin' === $b['source'] ? 'Adăugată manual' : $b['source'] );
				break;
		}
	}

	public static function sortable( $columns ) {
		$columns['stm_start'] = 'stm_start';
		return $columns;
	}

	public static function filters( $post_type ) {
		if ( STM_Helpers::CPT_BOOKING !== $post_type ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$theme  = isset( $_GET['stm_theme'] ) ? absint( $_GET['stm_theme'] ) : 0;
		$status = isset( $_GET['stm_status'] ) ? sanitize_key( $_GET['stm_status'] ) : '';
		$when   = isset( $_GET['stm_when'] ) ? sanitize_key( $_GET['stm_when'] ) : 'upcoming';
		// phpcs:enable
		$themes = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_THEME,
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
			)
		);
		echo '<select name="stm_theme"><option value="">Toate evenimentele</option>';
		foreach ( $themes as $t ) {
			printf( '<option value="%d" %s>%s</option>', (int) $t->ID, selected( $theme, $t->ID, false ), esc_html( get_the_title( $t ) ) );
		}
		echo '</select><select name="stm_status"><option value="">Toate statusurile</option>';
		foreach ( STM_Helpers::STATUS_LABELS as $k => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $status, $k, false ), esc_html( $label ) );
		}
		echo '</select><select name="stm_when">';
		printf( '<option value="upcoming" %s>Viitoare</option>', selected( $when, 'upcoming', false ) );
		printf( '<option value="past" %s>Trecute</option>', selected( $when, 'past', false ) );
		printf( '<option value="all" %s>Toate datele</option>', selected( $when, 'all', false ) );
		echo '</select>';
	}

	public static function list_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || STM_Helpers::CPT_BOOKING !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$meta = array();
		if ( ! empty( $_GET['stm_theme'] ) ) {
			$meta[] = array(
				'key'   => '_stm_theme',
				'value' => absint( $_GET['stm_theme'] ),
			);
		}
		if ( ! empty( $_GET['stm_status'] ) ) {
			$meta[] = array(
				'key'   => '_stm_status',
				'value' => sanitize_key( $_GET['stm_status'] ),
			);
		}
		$when = isset( $_GET['stm_when'] ) ? sanitize_key( $_GET['stm_when'] ) : 'upcoming';
		$past = 'past' === $when;
		// phpcs:enable
		if ( 'all' !== $when ) {
			$meta[] = array(
				'key'     => '_stm_start',
				'value'   => STM_Helpers::now()->format( 'Y-m-d 00:00' ),
				'compare' => $past ? '<' : '>=',
			);
		}
		$meta['stm_start_clause'] = array(
			'key'     => '_stm_start',
			'compare' => 'EXISTS',
		);
		$query->set( 'meta_query', $meta );
		$orderby = $query->get( 'orderby' );
		if ( ! $orderby || 'stm_start' === $orderby ) {
			$query->set( 'orderby', 'stm_start_clause' );
			$query->set( 'order', $query->get( 'order' ) ? $query->get( 'order' ) : ( $past ? 'DESC' : 'ASC' ) );
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( STM_Helpers::CPT_BOOKING !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'] );
		$status = get_post_meta( $post->ID, '_stm_status', true );
		$url    = function ( $to ) use ( $post ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=stm_status&id=' . $post->ID . '&to=' . $to ), 'stm_status_' . $post->ID );
		};
		if ( 'pending' === $status ) {
			$actions['stm_confirm'] = '<a href="' . esc_url( $url( 'confirmed' ) ) . '">Confirmă</a>';
		}
		if ( 'cancelled' !== $status ) {
			$actions['stm_cancel'] = '<a href="' . esc_url( $url( 'cancelled' ) ) . '" onclick="return confirm(\'Anulezi rezervarea? Clientul primește un email.\')">Anulează</a>';
		}
		return $actions;
	}

	public static function handle_status() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'stm_status_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'Nu ai drepturi pentru această acțiune.' );
		}
		$to = isset( $_GET['to'] ) ? sanitize_key( $_GET['to'] ) : '';
		if ( ! self::set_status( $id, $to ) && 'cancelled' !== $to ) {
			set_transient( 'stm_notice_' . get_current_user_id(), 'Statusul nu a putut fi schimbat (ora poate fi ocupată de altă rezervare).', 60 );
		}
		wp_safe_redirect( add_query_arg( 'stm_done', 1, wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . STM_Helpers::CPT_BOOKING ) ) );
		exit;
	}
}
