<?php
/**
 * Dashboard: cifre cheie, ocuparea pe zile, următoarele rezervări.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Dashboard {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_menu', array( __CLASS__, 'reorder' ), 100 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . STM_Helpers::CPT_THEME, 'Mini-ședințe – Dashboard', 'Dashboard', 'edit_posts', 'stm-dashboard', array( __CLASS__, 'render' ) );
	}

	/**
	 * Dashboard primul, apoi Evenimente, Rezervări, Programul zilei, Cupoane, Setări.
	 */
	public static function reorder() {
		global $submenu;
		$parent = 'edit.php?post_type=' . STM_Helpers::CPT_THEME;
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}
		$order = array( 'stm-dashboard', $parent, 'post-new.php?post_type=' . STM_Helpers::CPT_THEME, 'edit.php?post_type=' . STM_Helpers::CPT_BOOKING, 'post-new.php?post_type=' . STM_Helpers::CPT_BOOKING, 'stm-schedule', 'edit.php?post_type=' . STM_Coupons::CPT, 'stm-settings' );
		usort(
			$submenu[ $parent ],
			function ( $a, $b ) use ( $order ) {
				$ia = array_search( $a[2], $order, true );
				$ib = array_search( $b[2], $order, true );
				$ia = false === $ia ? 99 : $ia;
				$ib = false === $ib ? 99 : $ib;
				return $ia - $ib;
			}
		);
	}

	public static function assets( $hook ) {
		if ( false !== strpos( $hook, 'stm-dashboard' ) || false !== strpos( $hook, 'stm-schedule' ) || false !== strpos( $hook, 'stm-settings' ) ) {
			wp_enqueue_style( 'stm-admin', STM_URL . 'assets/css/admin.css', array(), STM_VERSION );
		}
	}

	/**
	 * Datele pentru o listă de evenimente: sloturi, rezervări, sume.
	 */
	public static function stats( $theme_ids, $from, $to ) {
		$out = array(
			'slots'     => 0,
			'free'      => 0,
			'blocked'   => 0,
			'confirmed' => 0,
			'pending'   => 0,
			'cancelled' => 0,
			'people'    => 0,
			'revenue'   => 0,
			'days'      => array(), // theme|date => [theme, date, slots, booked]
			'upcoming'  => array(),
			'clients'   => array(),
		);
		$now = STM_Helpers::now()->format( 'Y-m-d H:i' );
		foreach ( $theme_ids as $tid ) {
			$meta  = STM_Helpers::theme_meta( $tid );
			$taken = STM_Helpers::taken( $tid );
			foreach ( $meta['days'] as $d ) {
				if ( $d['date'] < $from || $d['date'] > $to ) {
					continue;
				}
				$slots = STM_Helpers::day_slots( $meta, $d );
				$k     = $tid . '|' . $d['date'];
				$row   = array(
					'theme'  => $tid,
					'date'   => $d['date'],
					'slots'  => count( $slots ),
					'booked' => 0,
					'off'    => STM_Helpers::is_day_off( $d['date'] ),
				);
				foreach ( $slots as $t ) {
					$key = $d['date'] . ' ' . $t;
					if ( isset( $taken[ $key ] ) ) {
						++$row['booked'];
					} elseif ( in_array( $key, (array) $meta['blocked'], true ) ) {
						++$out['blocked'];
					} elseif ( $key > $now && ! $row['off'] ) {
						++$out['free'];
					}
				}
				$out['slots']     += $row['slots'];
				$out['days'][ $k ] = $row;
			}
		}
		if ( ! $theme_ids ) {
			return $out;
		}
		$ids = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_BOOKING,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_stm_theme',
						'value'   => array_map( 'intval', $theme_ids ),
						'compare' => 'IN',
					),
					array(
						'key'     => '_stm_start',
						'value'   => array( $from . ' 00:00', $to . ' 23:59' ),
						'compare' => 'BETWEEN',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$b = STM_Bookings::get( $id );
			if ( 'cancelled' === $b['status'] ) {
				++$out['cancelled'];
				continue;
			}
			++$out[ $b['status'] ];
			$out['people']  += (int) $b['people'];
			$out['revenue'] += STM_Helpers::amount( $b );
			$out['clients'][ strtolower( $b['email'] ) ] = true;
			if ( $b['start'] >= $now ) {
				$out['upcoming'][ $b['start'] . '|' . $id ] = $b;
			}
		}
		ksort( $out['upcoming'] );
		ksort( $out['days'] );
		return $out;
	}

	public static function render() {
		$themes = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_THEME,
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtre read-only.
		$theme  = isset( $_GET['stm_theme'] ) ? absint( $_GET['stm_theme'] ) : 0;
		$period = isset( $_GET['stm_period'] ) ? sanitize_key( $_GET['stm_period'] ) : 'upcoming';
		// phpcs:enable
		$today = STM_Helpers::now()->format( 'Y-m-d' );
		$range = array(
			'upcoming' => array( $today, '9999-12-31', 'Toate zilele viitoare' ),
			'30'       => array( $today, STM_Helpers::now()->modify( '+30 days' )->format( 'Y-m-d' ), 'Următoarele 30 de zile' ),
			'month'    => array( STM_Helpers::now()->format( 'Y-m-01' ), STM_Helpers::now()->format( 'Y-m-t' ), 'Luna aceasta' ),
			'past'     => array( '2000-01-01', STM_Helpers::now()->modify( '-1 day' )->format( 'Y-m-d' ), 'Trecut' ),
			'all'      => array( '2000-01-01', '9999-12-31', 'Tot' ),
		);
		if ( ! isset( $range[ $period ] ) ) {
			$period = 'upcoming';
		}
		$ids = $theme ? array( $theme ) : wp_list_pluck( $themes, 'ID' );
		$s   = self::stats( $ids, $range[ $period ][0], $range[ $period ][1] );
		$occ = $s['slots'] ? round( 100 * ( $s['confirmed'] + $s['pending'] ) / $s['slots'] ) : 0;
		$base = admin_url( 'edit.php?post_type=' . STM_Helpers::CPT_THEME );
		?>
		<div class="wrap stm-dash">
			<h1>Mini-ședințe</h1>
			<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="stm-dash-filters">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( STM_Helpers::CPT_THEME ); ?>">
				<input type="hidden" name="page" value="stm-dashboard">
				<select name="stm_theme" onchange="this.form.submit()">
					<option value="">Toate evenimentele</option>
					<?php foreach ( $themes as $t ) : ?>
						<option value="<?php echo (int) $t->ID; ?>" <?php selected( $theme, $t->ID ); ?>><?php echo esc_html( get_the_title( $t ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="stm_period" onchange="this.form.submit()">
					<?php foreach ( $range as $k => $r ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $period, $k ); ?>><?php echo esc_html( $r[2] ); ?></option>
					<?php endforeach; ?>
				</select>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . STM_Helpers::CPT_THEME ) ); ?>">+ Eveniment</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . STM_Helpers::CPT_BOOKING ) ); ?>">+ Rezervare manuală</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . STM_Coupons::CPT ) ); ?>">+ Cupon</a>
			</form>

			<?php if ( ! $themes ) : ?>
				<div class="stm-panel"><p>Niciun eveniment încă. <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . STM_Helpers::CPT_THEME ) ); ?>">Adaugă primul eveniment</a>, pune zilele în calendar și pachetele, apoi shortcode-ul în pagină.</p></div>
				</div>
				<?php
				return;
			endif;
			?>

			<div class="stm-kpis">
				<div class="stm-kpi stm-kpi--ok"><b><?php echo (int) $s['confirmed']; ?></b><span>Rezervări confirmate</span><small><?php echo (int) $s['people']; ?> persoane</small></div>
				<div class="stm-kpi stm-kpi--warn"><b><?php echo (int) $s['pending']; ?></b><span>În așteptare</span><small><?php echo $s['pending'] ? '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . STM_Helpers::CPT_BOOKING . '&stm_status=pending&stm_when=all' ) ) . '">de confirmat</a>' : 'nimic de confirmat'; ?></small></div>
				<div class="stm-kpi"><b><?php echo (int) $s['free']; ?></b><span>Ore libere</span><small><?php echo (int) $s['slots']; ?> în total<?php echo $s['blocked'] ? ', ' . (int) $s['blocked'] . ' blocate' : ''; ?></small></div>
				<div class="stm-kpi"><b><?php echo (int) $occ; ?>%</b><span>Grad de ocupare</span><small><?php echo (int) ( $s['confirmed'] + $s['pending'] ); ?> din <?php echo (int) $s['slots']; ?> ore</small></div>
				<div class="stm-kpi"><b><?php echo esc_html( STM_Helpers::money( $s['revenue'] ) ); ?></b><span>Încasări estimate</span><small>confirmate + în așteptare, cu reduceri</small></div>
				<div class="stm-kpi"><b><?php echo count( $s['clients'] ); ?></b><span>Clienți</span><small><?php echo (int) $s['cancelled']; ?> anulări</small></div>
			</div>

			<div class="stm-dash-two">
				<div class="stm-panel">
					<h2>Ocuparea pe zile</h2>
					<?php if ( ! $s['days'] ) : ?>
						<p class="stm-dash-empty">Nicio zi în program pentru perioada aleasă.</p>
					<?php else : ?>
						<div class="stm-heat">
							<?php foreach ( $s['days'] as $k => $d ) : ?>
								<?php
								$pct   = $d['slots'] ? $d['booked'] / $d['slots'] : 0;
								$cls   = $d['off'] ? 'h0' : ( $pct >= 1 ? 'h4' : ( $pct >= .66 ? 'h3' : ( $pct >= .33 ? 'h2' : ( $pct > 0 ? 'h1' : 'h0' ) ) ) );
								$url   = add_query_arg( array( 'page' => 'stm-schedule', 'day' => $k ), $base );
								$title = get_the_title( $d['theme'] );
								?>
								<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $title ); ?>">
									<span class="d"><?php echo esc_html( wp_date( 'D j M', strtotime( $d['date'] . ' 12:00' ) ) ); ?></span>
									<span class="n"><?php echo $d['off'] ? 'zi liberă' : (int) $d['booked'] . ' / ' . (int) $d['slots']; ?></span>
									<?php if ( ! $theme ) : ?><span class="t"><?php echo esc_html( $title ); ?></span><?php endif; ?>
								</a>
							<?php endforeach; ?>
						</div>
						<p class="description">Click pe o zi deschide „Programul zilei” (rezervări, ore libere, blocare ore).</p>
					<?php endif; ?>
				</div>

				<div class="stm-panel">
					<h2>Următoarele rezervări</h2>
					<?php if ( ! $s['upcoming'] ) : ?>
						<p class="stm-dash-empty">Nicio rezervare viitoare în perioada aleasă.</p>
					<?php else : ?>
						<table class="widefat striped stm-dash-table">
							<thead><tr><th>Când</th><th>Client</th><th>Pachet</th><th>Status</th></tr></thead>
							<tbody>
							<?php foreach ( array_slice( $s['upcoming'], 0, 12 ) as $b ) : ?>
								<tr>
									<td><strong><?php echo esc_html( wp_date( 'D j M', strtotime( substr( $b['start'], 0, 10 ) . ' 12:00' ) ) ); ?></strong> <?php echo esc_html( substr( $b['start'], 11, 5 ) ); ?></td>
									<td><a href="<?php echo esc_url( get_edit_post_link( $b['id'] ) ); ?>"><?php echo esc_html( $b['name'] ); ?></a><br><span class="stm-muted"><?php echo esc_html( $b['phone'] ); ?></span></td>
									<td><?php echo esc_html( $b['package'] ); ?><br><span class="stm-muted"><?php echo esc_html( STM_Helpers::money( STM_Helpers::amount( $b ) ) ); ?><?php echo ! empty( $b['coupon'] ) ? ' · ' . esc_html( $b['coupon'] ) : ''; ?></span></td>
									<td><?php echo STM_Bookings::status_cell( $b ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped în funcție. ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . STM_Helpers::CPT_BOOKING ) ); ?>">Toate rezervările →</a></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
