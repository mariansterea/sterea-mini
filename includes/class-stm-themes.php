<?php
/**
 * Themes (seasonal mini-session events): post type + meta box.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Themes {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'hide_reorder' ), 99 );
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . STM_Helpers::CPT_THEME, array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . STM_Helpers::CPT_THEME . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . STM_Helpers::CPT_THEME . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function hide_reorder() {
		remove_submenu_page( 'edit.php?post_type=' . STM_Helpers::CPT_THEME, 'order-post-types-' . STM_Helpers::CPT_THEME );
	}

	public static function register() {
		register_post_type(
			STM_Helpers::CPT_THEME,
			array(
				'labels'          => array(
					'name'               => 'Mini-ședințe',
					'singular_name'      => 'Eveniment',
					'menu_name'          => 'Mini-ședințe',
					'all_items'          => 'Evenimente (ședințe)',
					'add_new'            => 'Adaugă eveniment',
					'add_new_item'       => 'Adaugă eveniment nou',
					'edit_item'          => 'Editează evenimentul',
					'not_found'          => 'Niciun eveniment încă.',
					'featured_image'     => 'Imaginea evenimentului',
					'set_featured_image' => 'Setează imaginea',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_position'   => 57,
				'menu_icon'       => 'dashicons-camera',
				'supports'        => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
				'show_in_rest'    => false,
			)
		);
	}

	public static function assets() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( STM_Helpers::CPT_THEME, STM_Helpers::CPT_BOOKING ), true ) ) {
			return;
		}
		wp_enqueue_style( 'stm-admin', STM_URL . 'assets/css/admin.css', array(), STM_VERSION );
		wp_enqueue_script( 'stm-admin', STM_URL . 'assets/js/admin.js', array(), STM_VERSION, true );
	}

	public static function meta_boxes() {
		add_meta_box( 'stm_dates', 'Date și program', array( __CLASS__, 'box_dates' ), STM_Helpers::CPT_THEME, 'normal', 'high' );
		add_meta_box( 'stm_packages', 'Pachete', array( __CLASS__, 'box_packages' ), STM_Helpers::CPT_THEME, 'normal', 'high' );
		add_meta_box( 'stm_details', 'Locație și reguli', array( __CLASS__, 'box_details' ), STM_Helpers::CPT_THEME, 'normal', 'default' );
		add_meta_box( 'stm_shortcode', 'Afișare pe site', array( __CLASS__, 'box_shortcode' ), STM_Helpers::CPT_THEME, 'side', 'default' );
	}

	public static function box_dates( $post ) {
		$m = STM_Helpers::theme_meta( $post->ID );
		wp_nonce_field( 'stm_theme_save', 'stm_theme_nonce' );
		$days = array();
		foreach ( $m['days'] as $d ) {
			$days[ $d['date'] ] = array( 'from' => $d['from'], 'to' => $d['to'] );
		}
		$last = $m['days'] ? end( $m['days'] ) : array( 'from' => '12:00', 'to' => '17:00' );
		$off  = array();
		foreach ( STM_Helpers::days_off() as $d ) {
			$off[] = array( 'from' => $d['from'], 'to' => $d['to'] ? $d['to'] : $d['from'], 'yearly' => ! empty( $d['yearly'] ), 'name' => $d['name'] );
		}
		$taken = array();
		foreach ( array_keys( STM_Helpers::taken( $post->ID ) ) as $k ) {
			$taken[ substr( $k, 0, 10 ) ] = isset( $taken[ substr( $k, 0, 10 ) ] ) ? $taken[ substr( $k, 0, 10 ) ] + 1 : 1;
		}
		?>
		<div class="stm-avail" id="stm-avail"
			data-days="<?php echo esc_attr( wp_json_encode( $days ) ); ?>"
			data-off="<?php echo esc_attr( wp_json_encode( $off ) ); ?>"
			data-taken="<?php echo esc_attr( wp_json_encode( $taken ) ); ?>"
			data-blocked="<?php echo esc_attr( wp_json_encode( array_values( (array) $m['blocked'] ) ) ); ?>">
			<div class="stm-avail-tools">
				<div class="stm-avail-default">
					<strong>Ore implicite</strong>
					<label>de la <input type="time" id="stm-def-from" value="<?php echo esc_attr( $last['from'] ); ?>"></label>
					<label>până la <input type="time" id="stm-def-to" value="<?php echo esc_attr( $last['to'] ); ?>"></label>
					<span class="description">Click pe o zi din calendar o pornește cu aceste ore; încă un click o oprește.</span>
				</div>
				<details class="stm-avail-range">
					<summary class="button">+ Adaugă interval de zile</summary>
					<div class="stm-avail-range-body">
						<label>De la <input type="date" id="stm-rg-from"></label>
						<label>până la <input type="date" id="stm-rg-to"></label>
						<span class="stm-avail-wd">
							<?php foreach ( array( 1 => 'Lu', 2 => 'Ma', 3 => 'Mi', 4 => 'Jo', 5 => 'Vi', 6 => 'Sâ', 0 => 'Du' ) as $n => $l ) : ?>
								<label><input type="checkbox" class="stm-rg-wd" value="<?php echo (int) $n; ?>" <?php checked( in_array( $n, array( 6, 0 ), true ) ); ?>> <?php echo esc_html( $l ); ?></label>
							<?php endforeach; ?>
						</span>
						<label>ore <input type="time" id="stm-rg-start" value="<?php echo esc_attr( $last['from'] ); ?>"> – <input type="time" id="stm-rg-end" value="<?php echo esc_attr( $last['to'] ); ?>"></label>
						<button type="button" class="button button-primary" id="stm-rg-add">Adaugă zilele</button>
					</div>
				</details>
			</div>
			<div class="stm-cal-wrap" id="stm-cal-wrap"></div>
			<div class="stm-avail-legend">
				<span><i class="stm-lg stm-lg-on"></i> zi cu ședințe</span>
				<span><i class="stm-lg stm-lg-taken"></i> are rezervări</span>
				<span><i class="stm-lg stm-lg-off"></i> zi liberă (din Setări)</span>
				<span><i class="stm-lg stm-lg-past"></i> trecut</span>
			</div>
			<div class="stm-avail-summary" id="stm-avail-summary"></div>
			<div id="stm-days-inputs"></div>
		</div>
		<table class="form-table">
			<tr>
				<th><label for="stm-duration">Durata unei ședințe</label></th>
				<td><input type="number" id="stm-duration" min="5" step="5" name="stm[duration]" value="<?php echo (int) $m['duration']; ?>" class="small-text"> minute</td>
			</tr>
			<tr>
				<th><label for="stm-buffer">Pauză între ședințe</label></th>
				<td><input type="number" id="stm-buffer" min="0" step="5" name="stm[buffer]" value="<?php echo (int) $m['buffer']; ?>" class="small-text"> minute
					<p class="description">Exemplu: 30 min ședință + 10 min pauză = sloturi la 10:00, 10:40, 11:20…</p></td>
			</tr>
			<tr>
				<th><label for="stm-deadline">Rezervări cu cel puțin</label></th>
				<td><input type="number" id="stm-deadline" min="0" name="stm[deadline]" value="<?php echo (int) $m['deadline']; ?>" class="small-text"> ore înainte</td>
			</tr>
			<tr>
				<th>Rezervări</th>
				<td><label><input type="hidden" name="stm[open]" value="0"><input type="checkbox" name="stm[open]" value="1" <?php checked( $m['open'] ); ?>> Deschise (debifează ca să oprești temporar rezervările)</label>
				<?php if ( ! empty( $m['blocked'] ) ) : ?>
					<p class="description"><?php echo count( (array) $m['blocked'] ); ?> ore blocate manual (se gestionează din „Programul zilei”).</p>
				<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function box_packages( $post ) {
		$m = STM_Helpers::theme_meta( $post->ID );
		?>
		<table class="widefat stm-rows" data-stm-rows="packages">
			<thead><tr><th>Pachet</th><th>Preț</th><th>Ce include</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $m['packages'] as $i => $p ) : ?>
				<tr>
					<td><input type="text" class="regular-text" name="stm[packages][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $p['name'] ); ?>"></td>
					<td><input type="text" class="small-text" name="stm[packages][<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( $p['price'] ); ?>" placeholder="350"> lei</td>
					<td><textarea rows="2" class="large-text" name="stm[packages][<?php echo (int) $i; ?>][desc]" placeholder="Ex: 10 fotografii editate, livrare online în 14 zile"><?php echo esc_textarea( $p['desc'] ); ?></textarea></td>
					<td><button type="button" class="button-link stm-remove" aria-label="Șterge rândul">✕</button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button stm-add" data-stm-target="packages">+ Adaugă pachet</button></p>
		<?php
	}

	public static function box_details( $post ) {
		$m = STM_Helpers::theme_meta( $post->ID );
		?>
		<table class="form-table">
			<tr>
				<th><label for="stm-location">Locație</label></th>
				<td><input type="text" id="stm-location" class="regular-text" name="stm[location]" value="<?php echo esc_attr( $m['location'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="stm-address">Adresă</label></th>
				<td><input type="text" id="stm-address" class="large-text" name="stm[address]" value="<?php echo esc_attr( $m['address'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="stm-map">Link Google Maps</label></th>
				<td><input type="url" id="stm-map" class="large-text" name="stm[map_url]" value="<?php echo esc_attr( $m['map_url'] ); ?>" placeholder="https://maps.app.goo.gl/..."></td>
			</tr>
			<tr>
				<th><label for="stm-people">Persoane într-o ședință</label></th>
				<td><input type="number" id="stm-people" min="1" name="stm[max_people]" value="<?php echo (int) $m['max_people']; ?>" class="small-text"> maximum</td>
			</tr>
		</table>
		<p class="description">Descrierea evenimentului (ce decor, ce se întâmplă) se scrie în editorul de mai sus, iar imaginea din caseta „Imaginea evenimentului”.</p>
		<?php
	}

	public static function box_shortcode( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>Salvează evenimentul ca să primești codul.</p>';
			return;
		}
		?>
		<p>Doar acest eveniment:</p>
		<p><input type="text" readonly class="widefat code" onfocus="this.select()" value="<?php echo esc_attr( '[sterea_mini id="' . $post->ID . '"]' ); ?>"></p>
		<p>Toate evenimentele cu date viitoare:</p>
		<p><input type="text" readonly class="widefat code" onfocus="this.select()" value="[sterea_mini]"></p>
		<p class="description">Pune codul într-o pagină (bloc „Shortcode”).</p>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['stm_theme_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['stm_theme_nonce'] ) ), 'stm_theme_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$in = isset( $_POST['stm'] ) && is_array( $_POST['stm'] ) ? wp_unslash( $_POST['stm'] ) : array();

		$data = array(
			'location'   => sanitize_text_field( isset( $in['location'] ) ? $in['location'] : '' ),
			'address'    => sanitize_text_field( isset( $in['address'] ) ? $in['address'] : '' ),
			'map_url'    => esc_url_raw( isset( $in['map_url'] ) ? $in['map_url'] : '' ),
			'duration'   => max( 5, absint( isset( $in['duration'] ) ? $in['duration'] : 30 ) ),
			'buffer'     => absint( isset( $in['buffer'] ) ? $in['buffer'] : 0 ),
			'deadline'   => absint( isset( $in['deadline'] ) ? $in['deadline'] : 0 ),
			'max_people' => max( 1, absint( isset( $in['max_people'] ) ? $in['max_people'] : 1 ) ),
			'open'       => empty( $in['open'] ) ? 0 : 1,
			'days'       => array(),
			'packages'   => array(),
			'blocked'    => STM_Helpers::blocked( $post_id ), // se editează doar din Programul zilei
		);

		foreach ( (array) ( isset( $in['days'] ) ? $in['days'] : array() ) as $d ) {
			$date = isset( $d['date'] ) ? sanitize_text_field( $d['date'] ) : '';
			$from = isset( $d['from'] ) ? sanitize_text_field( $d['from'] ) : '';
			$to   = isset( $d['to'] ) ? sanitize_text_field( $d['to'] ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && null !== STM_Helpers::to_min( $from ) && null !== STM_Helpers::to_min( $to ) ) {
				$data['days'][] = array(
					'date' => $date,
					'from' => $from,
					'to'   => $to,
				);
			}
		}
		usort(
			$data['days'],
			function ( $a, $b ) {
				return strcmp( $a['date'] . $a['from'], $b['date'] . $b['from'] );
			}
		);

		// Bookings keep their own copy of the package name and price, so editing packages never changes them.
		foreach ( (array) ( isset( $in['packages'] ) ? $in['packages'] : array() ) as $p ) {
			$name = sanitize_text_field( isset( $p['name'] ) ? $p['name'] : '' );
			if ( '' === $name ) {
				continue;
			}
			$data['packages'][] = array(
				'name'  => $name,
				'price' => sanitize_text_field( isset( $p['price'] ) ? $p['price'] : '' ),
				'desc'  => sanitize_textarea_field( isset( $p['desc'] ) ? $p['desc'] : '' ),
			);
		}

		update_post_meta( $post_id, '_stm', $data );
	}

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['stm_dates']    = 'Zile';
				$new['stm_bookings'] = 'Rezervări';
				$new['stm_code']     = 'Shortcode';
			}
		}
		unset( $new['date'] );
		return $new;
	}

	public static function column( $column, $post_id ) {
		$m = STM_Helpers::theme_meta( $post_id );
		if ( 'stm_dates' === $column ) {
			// Rezumat, nu toate zilele: „32 zile · 10 nov – 13 dec 2026 · 12:00–18:00”.
			$days = $m['days'];
			if ( ! $days ) {
				echo '—';
			} else {
				$dates = wp_list_pluck( $days, 'date' );
				sort( $dates );
				$today  = STM_Helpers::now()->format( 'Y-m-d' );
				$future = count( array_filter( $dates, function ( $d ) use ( $today ) { return $d >= $today; } ) );
				$first  = reset( $dates );
				$last   = end( $dates );
				$hours  = array();
				foreach ( $days as $d ) {
					$hours[ $d['from'] . '–' . $d['to'] ] = true;
				}
				$same_year = substr( $first, 0, 4 ) === substr( $last, 0, 4 );
				$range     = $first === $last
					? wp_date( 'j M Y', strtotime( $first . ' 12:00' ) )
					: wp_date( $same_year ? 'j M' : 'j M Y', strtotime( $first . ' 12:00' ) ) . ' – ' . wp_date( 'j M Y', strtotime( $last . ' 12:00' ) );
				echo '<strong>' . count( $dates ) . ' zile</strong>' . ( $future < count( $dates ) ? ' <span class="stm-muted">(' . (int) $future . ' viitoare)</span>' : '' );
				echo '<br>' . esc_html( $range );
				echo '<br><span class="stm-muted">' . esc_html( count( $hours ) === 1 ? key( $hours ) : 'ore diferite pe zile' ) . '</span>';
			}
			if ( empty( $m['open'] ) ) {
				echo '<br><em>Rezervări oprite</em>';
			}
		} elseif ( 'stm_bookings' === $column ) {
			$total = 0;
			foreach ( $m['days'] as $d ) {
				$total += count( STM_Helpers::day_slots( $m, $d ) );
			}
			$taken = count( STM_Helpers::taken( $post_id ) );
			printf(
				'<a href="%s">%d / %d sloturi ocupate</a>',
				esc_url( admin_url( 'edit.php?post_type=' . STM_Helpers::CPT_BOOKING . '&stm_theme=' . $post_id ) ),
				(int) $taken,
				(int) $total
			);
		} elseif ( 'stm_code' === $column ) {
			echo '<code>' . esc_html( '[sterea_mini id="' . $post_id . '"]' ) . '</code>';
		}
	}
}
