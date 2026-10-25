<?php
/**
 * Cupoane de reducere: tip de conținut, validare, aplicare la rezervare.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Coupons {

	const CPT = 'stm_coupon';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
	}

	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => 'Cupoane',
					'singular_name' => 'Cupon',
					'all_items'     => 'Cupoane',
					'add_new'       => 'Adaugă cupon',
					'add_new_item'  => 'Adaugă cupon',
					'edit_item'     => 'Editează cuponul',
					'not_found'     => 'Niciun cupon încă.',
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

	public static function title_placeholder( $text, $post ) {
		return self::CPT === $post->post_type ? 'Codul cuponului (ex. CRACIUN10)' : $text;
	}

	public static function defaults() {
		return array(
			'type'             => 'percent',
			'value'            => 10,
			'themes'           => array(),
			'packages'         => array(),
			'limit'            => 0,
			'limit_per_client' => 0,
			'from'             => '',
			'to'               => '',
			'emails'           => array(),
			'status'           => 'visible',
			'note'             => '',
		);
	}

	public static function meta( $id ) {
		$m = get_post_meta( $id, '_stm_coupon', true );
		return wp_parse_args( is_array( $m ) ? $m : array(), self::defaults() );
	}

	public static function code( $id ) {
		return self::normalize( get_the_title( $id ) );
	}

	public static function normalize( $code ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $code ) );
	}

	/**
	 * Cuponul după cod (doar publicate).
	 */
	public static function find( $code ) {
		$code = self::normalize( $code );
		if ( '' === $code ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			if ( self::code( $id ) === $code ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * De câte ori a fost folosit (rezervări neanulate), opțional doar de un client.
	 */
	public static function used( $id, $email = '' ) {
		$meta = array(
			array(
				'key'   => '_stm_coupon',
				'value' => self::code( $id ),
			),
			array(
				'key'     => '_stm_status',
				'value'   => 'cancelled',
				'compare' => '!=',
			),
		);
		if ( $email ) {
			$meta[] = array(
				'key'   => '_stm_email',
				'value' => $email,
			);
		}
		$q = new WP_Query(
			array(
				'post_type'      => STM_Helpers::CPT_BOOKING,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Validează un cod pentru un eveniment / pachet / client și calculează reducerea.
	 *
	 * @return array { ok, message, code, discount, total, price, label } (discount și total ca numere).
	 */
	public static function validate( $code, $theme_id, $package_name, $price, $email = '' ) {
		$price = STM_Helpers::to_number( $price );
		$fail  = function ( $msg ) {
			return array( 'ok' => false, 'message' => $msg );
		};
		$id = self::find( $code );
		if ( ! $id ) {
			return $fail( 'Codul nu există.' );
		}
		$m     = self::meta( $id );
		$today = STM_Helpers::now()->format( 'Y-m-d' );
		if ( 'visible' !== $m['status'] ) {
			return $fail( 'Codul nu mai este activ.' );
		}
		if ( $m['from'] && $today < $m['from'] ) {
			return $fail( 'Codul este valabil de la ' . STM_Helpers::date_label( $m['from'] ) . '.' );
		}
		if ( $m['to'] && $today > $m['to'] ) {
			return $fail( 'Codul a expirat.' );
		}
		if ( $m['themes'] && ! in_array( (int) $theme_id, array_map( 'intval', $m['themes'] ), true ) ) {
			return $fail( 'Codul nu este valabil pentru acest eveniment.' );
		}
		if ( $m['packages'] && ! in_array( $package_name, $m['packages'], true ) ) {
			return $fail( 'Codul nu este valabil pentru acest pachet.' );
		}
		if ( $m['emails'] ) {
			$ok = $email && in_array( strtolower( $email ), array_map( 'strtolower', $m['emails'] ), true );
			if ( ! $ok ) {
				return $fail( $email ? 'Codul nu este valabil pentru această adresă de email.' : 'Completează emailul înainte de cod.' );
			}
		}
		if ( $m['limit'] && self::used( $id ) >= (int) $m['limit'] ) {
			return $fail( 'Codul a fost folosit de numărul maxim de ori.' );
		}
		if ( $m['limit_per_client'] && $email && self::used( $id, $email ) >= (int) $m['limit_per_client'] ) {
			return $fail( 'Ai folosit deja acest cod.' );
		}
		if ( $price <= 0 ) {
			return $fail( 'Pachetul nu are preț, codul nu se poate aplica.' );
		}
		$discount = 'percent' === $m['type'] ? round( $price * (float) $m['value'] / 100, 2 ) : (float) $m['value'];
		$discount = max( 0, min( $price, $discount ) );
		return array(
			'ok'       => true,
			'message'  => 'Reducere aplicată.',
			'code'     => self::code( $id ),
			'label'    => 'percent' === $m['type'] ? '-' . (float) $m['value'] . '%' : '-' . STM_Helpers::money( $m['value'] ),
			'price'    => $price,
			'discount' => $discount,
			'total'    => round( $price - $discount, 2 ),
		);
	}

	/* ---------- Admin ---------- */

	public static function meta_boxes() {
		add_meta_box( 'stm_coupon', 'Reducerea', array( __CLASS__, 'box' ), self::CPT, 'normal', 'high' );
	}

	public static function box( $post ) {
		$m      = self::meta( $post->ID );
		$themes = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_THEME,
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
			)
		);
		$packages = array();
		foreach ( $themes as $t ) {
			foreach ( STM_Helpers::theme_meta( $t->ID )['packages'] as $p ) {
				if ( '' !== trim( $p['name'] ) ) {
					$packages[ $p['name'] ] = true;
				}
			}
		}
		$used = 'auto-draft' === $post->post_status ? 0 : self::used( $post->ID );
		wp_nonce_field( 'stm_coupon_save', 'stm_coupon_nonce' );
		?>
		<div class="stm-coupon-grid">
			<div>
				<label class="stm-lbl">Tip reducere</label>
				<label><input type="radio" name="stmc[type]" value="percent" <?php checked( $m['type'], 'percent' ); ?>> procent din preț</label>&nbsp;&nbsp;
				<label><input type="radio" name="stmc[type]" value="fixed" <?php checked( $m['type'], 'fixed' ); ?>> sumă fixă (lei)</label>
			</div>
			<div>
				<label class="stm-lbl" for="stmc-value">Valoare</label>
				<input type="number" id="stmc-value" min="0" step="0.01" name="stmc[value]" value="<?php echo esc_attr( $m['value'] ); ?>" class="small-text">
				<span class="description">ex. 10 (= 10 %) sau 100 (= 100 lei)</span>
			</div>
			<div>
				<label class="stm-lbl">Valabil pentru evenimentele</label>
				<div class="stm-check-list">
					<?php foreach ( $themes as $t ) : ?>
						<label><input type="checkbox" name="stmc[themes][]" value="<?php echo (int) $t->ID; ?>" <?php checked( in_array( $t->ID, array_map( 'intval', $m['themes'] ), true ) ); ?>> <?php echo esc_html( get_the_title( $t ) ); ?></label>
					<?php endforeach; ?>
				</div>
				<p class="description">Nimic bifat = toate evenimentele.</p>
			</div>
			<div>
				<label class="stm-lbl">Valabil pentru pachetele</label>
				<div class="stm-check-list">
					<?php foreach ( array_keys( $packages ) as $name ) : ?>
						<label><input type="checkbox" name="stmc[packages][]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $m['packages'], true ) ); ?>> <?php echo esc_html( $name ); ?></label>
					<?php endforeach; ?>
				</div>
				<p class="description">Nimic bifat = toate pachetele.</p>
			</div>
			<div>
				<label class="stm-lbl" for="stmc-from">Valabil de la</label>
				<input type="date" id="stmc-from" name="stmc[from]" value="<?php echo esc_attr( $m['from'] ); ?>">
				<label for="stmc-to" style="margin-left:10px">până la</label>
				<input type="date" id="stmc-to" name="stmc[to]" value="<?php echo esc_attr( $m['to'] ); ?>">
				<p class="description">Gol = fără limită de timp. Data se referă la ziua în care se face rezervarea.</p>
			</div>
			<div>
				<label class="stm-lbl" for="stmc-limit">Limite de folosire</label>
				<input type="number" id="stmc-limit" min="0" name="stmc[limit]" value="<?php echo (int) $m['limit']; ?>" class="small-text"> în total,
				<input type="number" min="0" name="stmc[limit_per_client]" value="<?php echo (int) $m['limit_per_client']; ?>" class="small-text"> per client (după email)
				<p class="description">0 = nelimitat. Folosit până acum: <strong><?php echo (int) $used; ?></strong>.</p>
			</div>
			<div class="is-wide">
				<label class="stm-lbl" for="stmc-emails">Doar pentru anumiți clienți (opțional)</label>
				<textarea id="stmc-emails" name="stmc[emails]" rows="2" class="large-text" placeholder="o adresă de email pe linie"><?php echo esc_textarea( implode( "\n", $m['emails'] ) ); ?></textarea>
				<p class="description">Dacă scrii adrese aici, codul merge doar cu ele (cupon personal). Gol = oricine.</p>
			</div>
			<div>
				<label class="stm-lbl">Status</label>
				<label><input type="radio" name="stmc[status]" value="visible" <?php checked( $m['status'], 'visible' ); ?>> activ</label>&nbsp;&nbsp;
				<label><input type="radio" name="stmc[status]" value="hidden" <?php checked( $m['status'], 'hidden' ); ?>> oprit</label>
			</div>
			<div>
				<label class="stm-lbl" for="stmc-note">Notă internă</label>
				<input type="text" id="stmc-note" name="stmc[note]" value="<?php echo esc_attr( $m['note'] ); ?>" class="regular-text" placeholder="ex. promoție Instagram decembrie">
			</div>
		</div>
		<p class="description">Codul este titlul de mai sus: litere, cifre, „-” și „_”; nu contează majusculele. Clientul îl scrie la pasul „Confirmare” din formular.</p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['stm_coupon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['stm_coupon_nonce'] ) ), 'stm_coupon_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$in     = isset( $_POST['stmc'] ) && is_array( $_POST['stmc'] ) ? wp_unslash( $_POST['stmc'] ) : array();
		$emails = array();
		foreach ( preg_split( '/[\s,;]+/', (string) ( isset( $in['emails'] ) ? $in['emails'] : '' ) ) as $e ) {
			$e = sanitize_email( $e );
			if ( $e ) {
				$emails[] = strtolower( $e );
			}
		}
		$date = function ( $v ) {
			$v = sanitize_text_field( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		$data = array(
			'type'             => isset( $in['type'] ) && 'fixed' === $in['type'] ? 'fixed' : 'percent',
			'value'            => max( 0, (float) ( isset( $in['value'] ) ? $in['value'] : 0 ) ),
			'themes'           => array_values( array_filter( array_map( 'absint', (array) ( isset( $in['themes'] ) ? $in['themes'] : array() ) ) ) ),
			'packages'         => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( isset( $in['packages'] ) ? $in['packages'] : array() ) ) ) ),
			'limit'            => absint( isset( $in['limit'] ) ? $in['limit'] : 0 ),
			'limit_per_client' => absint( isset( $in['limit_per_client'] ) ? $in['limit_per_client'] : 0 ),
			'from'             => $date( isset( $in['from'] ) ? $in['from'] : '' ),
			'to'               => $date( isset( $in['to'] ) ? $in['to'] : '' ),
			'emails'           => array_values( array_unique( $emails ) ),
			'status'           => isset( $in['status'] ) && 'hidden' === $in['status'] ? 'hidden' : 'visible',
			'note'             => sanitize_text_field( isset( $in['note'] ) ? $in['note'] : '' ),
		);
		if ( 'percent' === $data['type'] ) {
			$data['value'] = min( 100, $data['value'] );
		}
		update_post_meta( $post_id, '_stm_coupon', $data );

		// Titlul = codul normalizat; cod duplicat → se salvează ca ciornă cu avertisment.
		$code = self::normalize( $post->post_title );
		$dupe = $code ? self::find( $code ) : null;
		if ( $code !== $post->post_title || ( $dupe && (int) $dupe !== (int) $post_id ) || '' === $code ) {
			remove_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10 );
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_title'  => $code,
					'post_status' => ( $dupe && (int) $dupe !== (int) $post_id ) || '' === $code ? 'draft' : $post->post_status,
				)
			);
			add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
			if ( '' === $code ) {
				set_transient( 'stm_notice_' . get_current_user_id(), 'Cuponul are nevoie de un cod (litere și cifre). A fost salvat ca ciornă.', 60 );
			} elseif ( $dupe && (int) $dupe !== (int) $post_id ) {
				set_transient( 'stm_notice_' . get_current_user_id(), 'Există deja un cupon cu codul ' . $code . '. Acesta a fost salvat ca ciornă.', 60 );
			}
		}
	}

	public static function columns( $columns ) {
		return array(
			'cb'           => $columns['cb'],
			'title'        => 'Cod',
			'stm_discount' => 'Reducere',
			'stm_scope'    => 'Valabil pentru',
			'stm_period'   => 'Perioadă',
			'stm_used'     => 'Folosit',
			'stm_status'   => 'Status',
		);
	}

	public static function column( $column, $post_id ) {
		$m     = self::meta( $post_id );
		$today = STM_Helpers::now()->format( 'Y-m-d' );
		switch ( $column ) {
			case 'stm_discount':
				echo esc_html( 'percent' === $m['type'] ? (float) $m['value'] . ' %' : STM_Helpers::money( $m['value'] ) );
				break;
			case 'stm_scope':
				$parts = array();
				if ( $m['themes'] ) {
					$parts[] = implode( ', ', array_map( 'get_the_title', $m['themes'] ) );
				}
				if ( $m['packages'] ) {
					$parts[] = implode( ', ', $m['packages'] );
				}
				if ( $m['emails'] ) {
					$parts[] = count( $m['emails'] ) . ' client(i)';
				}
				echo esc_html( $parts ? implode( ' · ', $parts ) : 'toate evenimentele' );
				if ( $m['note'] ) {
					echo '<br><span class="stm-muted">' . esc_html( $m['note'] ) . '</span>';
				}
				break;
			case 'stm_period':
				if ( ! $m['from'] && ! $m['to'] ) {
					echo '<span class="stm-muted">fără limită</span>';
				} else {
					echo esc_html( ( $m['from'] ? wp_date( 'j M Y', strtotime( $m['from'] . ' 12:00' ) ) : '…' ) . ' – ' . ( $m['to'] ? wp_date( 'j M Y', strtotime( $m['to'] . ' 12:00' ) ) : '…' ) );
				}
				break;
			case 'stm_used':
				$used = self::used( $post_id );
				echo (int) $used . ( $m['limit'] ? ' / ' . (int) $m['limit'] : '' );
				break;
			case 'stm_status':
				if ( 'hidden' === $m['status'] ) {
					echo '<span class="stm-badge stm-badge--hidden">oprit</span>';
				} elseif ( ( $m['to'] && $today > $m['to'] ) || ( $m['limit'] && self::used( $post_id ) >= (int) $m['limit'] ) ) {
					echo '<span class="stm-badge stm-badge--expired">expirat</span>';
				} else {
					echo '<span class="stm-badge stm-badge--visible">activ</span>';
				}
				break;
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( self::CPT === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}
}
