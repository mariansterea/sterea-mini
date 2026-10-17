<?php
/**
 * "Programul zilei": printable day schedule for a theme.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Schedule {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_stm_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_stm_export_xlsx', array( __CLASS__, 'export_xlsx' ) );
		add_action( 'manage_posts_extra_tablenav', array( __CLASS__, 'list_buttons' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . STM_Helpers::CPT_THEME, 'Programul zilei', 'Programul zilei', 'edit_posts', 'stm-schedule', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		$themes = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_THEME,
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
			)
		);

		// Every (theme, day) pair, upcoming first.
		$options = array();
		$today   = STM_Helpers::now()->format( 'Y-m-d' );
		foreach ( $themes as $t ) {
			foreach ( STM_Helpers::theme_meta( $t->ID )['days'] as $d ) {
				$options[] = array(
					'key'   => $t->ID . '|' . $d['date'],
					'label' => get_the_title( $t ) . ' — ' . STM_Helpers::date_label( $d['date'] ),
					'date'  => $d['date'],
				);
			}
		}
		usort(
			$options,
			function ( $a, $b ) use ( $today ) {
				$fa = $a['date'] >= $today ? 0 : 1;
				$fb = $b['date'] >= $today ? 0 : 1;
				return $fa !== $fb ? $fa - $fb : ( $fa ? strcmp( $b['date'], $a['date'] ) : strcmp( $a['date'], $b['date'] ) );
			}
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		$current = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : ( $options ? $options[0]['key'] : '' );
		?>
		<div class="wrap stm-schedule">
			<h1>Programul zilei</h1>
			<?php if ( ! $options ) : ?>
				<p>Nu există încă evenimente cu zile programate.</p>
			</div>
				<?php
				return;
			endif;
			?>
			<form method="get" class="stm-noprint">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( STM_Helpers::CPT_THEME ); ?>">
				<input type="hidden" name="page" value="stm-schedule">
				<select name="day" onchange="this.form.submit()">
					<?php foreach ( $options as $o ) : ?>
						<option value="<?php echo esc_attr( $o['key'] ); ?>" <?php selected( $current, $o['key'] ); ?>><?php echo esc_html( $o['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" onclick="window.print()">Printează</button>
				<?php $ex = array_pad( explode( '|', $current ), 2, '' ); $ex_base = wp_nonce_url( admin_url( 'admin-post.php?action=stm_export&theme=' . absint( $ex[0] ) ), 'stm_export' ); ?>
				<a class="button button-primary" target="_blank" href="<?php echo esc_url( $ex_base . '&day=' . rawurlencode( $ex[1] ) ); ?>">Descarcă PDF (ziua)</a>
				<a class="button" target="_blank" href="<?php echo esc_url( $ex_base . '&day=all' ); ?>">Descarcă PDF (toate zilele)</a>
				<a class="button" target="_blank" href="<?php echo esc_url( $ex_base . '&day=all&list=1' ); ?>">Lista rezervărilor (PDF)</a>
				<?php $ex_xlsx = wp_nonce_url( add_query_arg( array( 'action' => 'stm_export_xlsx', 'stm_theme' => absint( $ex[0] ), 'stm_when' => 'all' ), admin_url( 'admin-post.php' ) ), 'stm_export' ); ?>
				<a class="button" href="<?php echo esc_url( $ex_xlsx ); ?>">Descarcă Excel</a>
			</form>
			<?php
			list( $theme_id, $date ) = array_pad( explode( '|', $current ), 2, '' );
			$theme_id                = absint( $theme_id );
			$meta                    = STM_Helpers::theme_meta( $theme_id );
			$day                     = null;
			foreach ( $meta['days'] as $d ) {
				if ( $d['date'] === $date ) {
					$day = $d;
				}
			}
			if ( ! $day ) {
				echo '<p>Ziua nu a fost găsită.</p></div>';
				return;
			}
			$taken = STM_Helpers::taken( $theme_id );

			// Bookings of the day by time, including cancelled ones (shown greyed).
			$ids    = get_posts(
				array(
					'post_type'   => STM_Helpers::CPT_BOOKING,
					'post_status' => 'publish',
					'numberposts' => -1,
					'fields'      => 'ids',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'   => '_stm_theme',
							'value' => $theme_id,
						),
						array(
							'key'     => '_stm_start',
							'value'   => $date,
							'compare' => 'LIKE',
						),
					),
				)
			);
			$by_time   = array();
			$cancelled = 0;
			foreach ( $ids as $id ) {
				$b = STM_Bookings::get( $id );
				if ( 'cancelled' === $b['status'] ) {
					++$cancelled;
					continue;
				}
				$by_time[ substr( $b['start'], 11, 5 ) ] = $b;
			}
			$slots  = STM_Helpers::day_slots( $meta, $day );
			$booked = count( $by_time );
			?>
			<h2><?php echo esc_html( get_the_title( $theme_id ) ); ?> — <?php echo esc_html( STM_Helpers::date_label( $date ) ); ?></h2>
			<p><?php echo esc_html( $meta['location'] ); ?> · <?php echo (int) $booked; ?> din <?php echo count( $slots ); ?> sloturi rezervate<?php echo $cancelled ? ' · ' . (int) $cancelled . ' anulate' : ''; ?></p>
			<table class="widefat striped stm-day-table">
				<thead>
					<tr><th>Ora</th><th>Client</th><th>Telefon</th><th>Pachet</th><th>Pers.</th><th>Observații</th><th class="stm-noprint">Status</th></tr>
				</thead>
				<tbody>
				<?php foreach ( $slots as $time ) : ?>
					<?php $b = isset( $by_time[ $time ] ) ? $by_time[ $time ] : null; ?>
					<tr class="<?php echo $b ? '' : 'stm-free'; ?>">
						<td><strong><?php echo esc_html( $time ); ?></strong></td>
						<?php if ( $b ) : ?>
							<td><a href="<?php echo esc_url( get_edit_post_link( $b['id'] ) ); ?>"><?php echo esc_html( $b['name'] ); ?></a></td>
							<td><?php echo esc_html( $b['phone'] ); ?></td>
							<td><?php echo esc_html( $b['package'] ); ?></td>
							<td><?php echo (int) $b['people']; ?></td>
							<td><?php echo esc_html( $b['notes'] ); ?></td>
							<td class="stm-noprint"><span class="stm-badge stm-badge--<?php echo esc_attr( $b['status'] ); ?>"><?php echo esc_html( STM_Helpers::STATUS_LABELS[ $b['status'] ] ); ?></span></td>
						<?php elseif ( isset( $taken[ $date . ' ' . $time ] ) ) : ?>
							<td colspan="6"><em>Ocupat</em></td>
						<?php else : ?>
							<td colspan="6" class="stm-muted">liber</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Export: document A4 (print / salvare ca PDF) cu programările unui eveniment.
	 */
	public static function export() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( 'stm_export' );

		$theme_id = isset( $_GET['theme'] ) ? absint( $_GET['theme'] ) : 0;
		$only_day = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : 'all';
		$list     = ! empty( $_GET['list'] ); // doar rezervările, fără orele libere, într-un singur document
		$meta     = STM_Helpers::theme_meta( $theme_id );
		$title    = get_the_title( $theme_id );
		$phone    = STM_Helpers::get( 'contact_phone' );

		$days = array();
		foreach ( $meta['days'] as $d ) {
			if ( 'all' === $only_day || $d['date'] === $only_day ) {
				$days[] = $d;
			}
		}
		usort(
			$days,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		$ids = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_BOOKING,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_stm_theme',
						'value' => $theme_id,
					),
				),
			)
		);
		$bookings = array();
		foreach ( $ids as $id ) {
			$b = STM_Bookings::get( $id );
			if ( ! $b || 'cancelled' === $b['status'] ) {
				continue;
			}
			$bookings[ substr( $b['start'], 0, 16 ) ] = $b;
		}

		$tot_slots = 0;
		$tot_book  = 0;
		$tot_pers  = 0;
		$tot_sum   = 0;
		$pages     = array();
		foreach ( $days as $day ) {
			$slots = STM_Helpers::day_slots( $meta, $day );
			$rows  = array();
			$n     = 0;
			$p     = 0;
			$s     = 0;
			foreach ( $slots as $time ) {
				$key = $day['date'] . ' ' . $time;
				$b   = isset( $bookings[ $key ] ) ? $bookings[ $key ] : null;
				if ( $b ) {
					++$n;
					$p += (int) $b['people'];
					$s += (float) preg_replace( '/[^0-9.]/', '', (string) $b['price'] );
				}
				if ( $list && ! $b ) {
					continue;
				}
				$rows[] = array( $time, $b );
			}
			// Rezervări din ziua respectivă care nu mai cad pe sloturile curente (ex. programul s-a schimbat).
			foreach ( $bookings as $key => $b ) {
				if ( substr( $key, 0, 10 ) !== $day['date'] ) {
					continue;
				}
				$t = substr( $key, 11, 5 );
				if ( in_array( $t, $slots, true ) ) {
					continue;
				}
				++$n;
				$p += (int) $b['people'];
				$s += (float) preg_replace( '/[^0-9.]/', '', (string) $b['price'] );
				$rows[] = array( $t, $b );
			}
			usort(
				$rows,
				function ( $x, $y ) {
					return strcmp( $x[0], $y[0] );
				}
			);
			$tot_slots += count( $slots );
			$tot_book  += $n;
			$tot_pers  += $p;
			$tot_sum   += $s;
			$pages[]    = array(
				'day'   => $day,
				'rows'  => $rows,
				'n'     => $n,
				'p'     => $p,
				's'     => $s,
				'slots' => count( $slots ),
			);
		}

		$file = sanitize_title( $title ) . ( 'all' === $only_day ? '' : '-' . $only_day ) . ( $list ? '-lista-rezervari' : '' );
		$gen  = STM_Helpers::now()->format( 'd.m.Y H:i' );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $title ); ?> - programări</title>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet">
<style>
	@page { size: A4; margin: 14mm 12mm 16mm; }
	* { box-sizing: border-box; }
	html, body { margin: 0; padding: 0; }
	body { font-family: Lato, "Helvetica Neue", Arial, sans-serif; color: #1f2a24; font-size: 11pt; background: #e9ebe8; }
	.sheet { background: #fff; width: 210mm; min-height: 297mm; margin: 10mm auto; padding: 14mm 12mm 16mm; position: relative; page-break-after: always; box-shadow: 0 2px 14px rgba(0,0,0,.12); }
	.sheet:last-child { page-break-after: auto; }
	.top { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 3px solid #1f5e45; padding-bottom: 8px; }
	.brand { font-size: 9pt; letter-spacing: .28em; text-transform: uppercase; color: #6b7a72; }
	.brand strong { display: block; font-size: 10pt; letter-spacing: .32em; color: #1f2a24; margin-top: 2px; }
	.doc { text-align: right; font-size: 9pt; color: #6b7a72; }
	h1 { font-size: 20pt; font-weight: 900; margin: 16px 0 2px; line-height: 1.15; }
	.date { font-size: 15pt; color: #1f5e45; font-weight: 700; margin: 0 0 4px; text-transform: capitalize; }
	.place { color: #4f5d56; font-size: 10.5pt; margin: 0 0 14px; }
	.stats { display: flex; gap: 10px; margin: 0 0 16px; }
	.stat { flex: 1; background: #f1f6f3; border-left: 4px solid #1f5e45; padding: 8px 10px; }
	.stat b { display: block; font-size: 16pt; line-height: 1.1; }
	.stat span { font-size: 8.5pt; color: #4f5d56; text-transform: uppercase; letter-spacing: .08em; }
	table { width: 100%; border-collapse: collapse; }
	th { text-align: left; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .08em; color: #4f5d56; padding: 6px 8px; border-bottom: 2px solid #1f2a24; }
	td { padding: 7px 8px; border-bottom: 1px solid #dfe5e1; vertical-align: top; }
	tr:nth-child(even) td { background: #fafbfa; }
	td.time { font-weight: 900; white-space: nowrap; width: 15mm; }
	td.free { color: #a3ada7; font-style: italic; }
	td.name { font-weight: 700; }
	td small { display: block; color: #6b7a72; font-weight: 400; font-size: 9pt; }
	td.notes { color: #4f5d56; font-size: 9.5pt; max-width: 50mm; }
	.badge { display: inline-block; font-size: 8pt; padding: 2px 7px; border-radius: 10px; background: #e3f1e9; color: #1f5e45; font-weight: 700; white-space: nowrap; }
	.badge.pending { background: #fff2d6; color: #8a5a00; }
	.foot { position: absolute; left: 12mm; right: 12mm; bottom: 8mm; display: flex; justify-content: space-between; font-size: 8.5pt; color: #8a948f; border-top: 1px solid #dfe5e1; padding-top: 6px; }
	.summary { margin-top: 18px; padding: 12px 14px; background: #1f5e45; color: #fff; display: flex; gap: 24px; }
	.summary div b { display: block; font-size: 15pt; }
	.summary div span { font-size: 8.5pt; text-transform: uppercase; letter-spacing: .08em; opacity: .85; }
	.dayhead { font-size: 13pt; margin: 18px 0 6px; padding: 6px 10px; background: #f1f6f3; border-left: 4px solid #1f5e45; page-break-after: avoid; }
	.dayhead small { font-weight: 400; color: #4f5d56; font-size: 9pt; margin-left: 8px; }
	.none { color: #a3ada7; font-style: italic; margin: 0 0 8px 10px; }
	.sheet table { page-break-inside: auto; }
	.sheet tr { page-break-inside: avoid; }
	.toolbar { position: fixed; top: 12px; right: 12px; display: flex; gap: 8px; z-index: 9; }
	.toolbar button { font: 700 11pt Lato, Arial, sans-serif; padding: 10px 16px; border: 0; border-radius: 6px; cursor: pointer; background: #1f5e45; color: #fff; }
	.toolbar button.secondary { background: #fff; color: #1f2a24; border: 1px solid #c7cfca; }
	@media print {
		body { background: #fff; }
		.sheet { margin: 0; padding: 0; box-shadow: none; width: auto; min-height: 0; }
		.foot { position: fixed; left: 0; right: 0; bottom: 0; }
		.toolbar { display: none; }
	}
</style>
</head>
<body>
<div class="toolbar">
	<button type="button" class="secondary" onclick="window.close()">Închide</button>
	<button type="button" onclick="window.print()">Salvează ca PDF / Printează</button>
</div>
<?php if ( ! $pages ) : ?>
	<div class="sheet"><p>Nu există zile pentru acest eveniment.</p></div>
<?php endif; ?>
<?php if ( $list ) : ?>
	<div class="sheet">
		<div class="top">
			<div class="brand">Lista rezervărilor<strong>Marian Sterea</strong></div>
			<div class="doc">Generat <?php echo esc_html( $gen ); ?></div>
		</div>
		<h1><?php echo esc_html( $title ); ?></h1>
		<p class="place"><?php echo esc_html( $meta['location'] ); ?><?php echo $meta['address'] ? ', ' . esc_html( $meta['address'] ) : ''; ?><?php echo $phone ? ' · Tel. ' . esc_html( $phone ) : ''; ?></p>
		<div class="stats">
			<div class="stat"><b><?php echo (int) $tot_book; ?></b><span>Rezervări</span></div>
			<div class="stat"><b><?php echo (int) $tot_pers; ?></b><span>Persoane</span></div>
			<div class="stat"><b><?php echo number_format( $tot_sum, 0, ',', '.' ); ?> lei</b><span>Valoare</span></div>
			<div class="stat"><b><?php echo count( array_filter( $pages, function ( $p ) { return ! empty( $p['rows'] ); } ) ); ?></b><span>Zile cu rezervări</span></div>
		</div>
		<?php if ( ! $tot_book ) : ?><p class="none">Nicio rezervare încă.</p><?php endif; ?>
		<?php foreach ( $pages as $pg ) : ?>
			<?php
			if ( ! $pg['rows'] ) {
				continue; // doar zilele cu rezervări
			}
			$day = $pg['day'];
			?>
			<h2 class="dayhead"><?php echo esc_html( STM_Helpers::date_label( $day['date'] ) ); ?> <small><?php echo (int) $pg['n']; ?> rezervări · <?php echo (int) $pg['p']; ?> persoane · <?php echo number_format( $pg['s'], 0, ',', '.' ); ?> lei</small></h2>
			<?php if ( ! $pg['rows'] ) : ?>
				<p class="none">Nicio rezervare.</p>
			<?php else : ?>
			<table>
				<thead><tr><th>Ora</th><th>Client</th><th>Telefon</th><th>Pachet</th><th>Pers.</th><th>Observații</th><th>Status</th></tr></thead>
				<tbody>
				<?php foreach ( $pg['rows'] as $r ) : ?>
					<?php list( $time, $b ) = $r; ?>
					<tr>
						<td class="time"><?php echo esc_html( $time ); ?></td>
						<td class="name"><?php echo esc_html( $b['name'] ); ?><?php echo $b['email'] ? '<small>' . esc_html( $b['email'] ) . '</small>' : ''; ?></td>
						<td><?php echo esc_html( $b['phone'] ); ?></td>
						<td><?php echo esc_html( $b['package'] ); ?><?php echo $b['price'] ? '<small>' . esc_html( $b['price'] ) . ' lei</small>' : ''; ?></td>
						<td><?php echo (int) $b['people']; ?></td>
						<td class="notes"><?php echo esc_html( $b['notes'] ); ?></td>
						<td><span class="badge <?php echo esc_attr( $b['status'] ); ?>"><?php echo esc_html( STM_Helpers::STATUS_LABELS[ $b['status'] ] ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		<?php endforeach; ?>
		<div class="foot"><span><?php echo esc_html( $title ); ?></span><span>blog.mariansterea.ro · <?php echo esc_html( $file ); ?></span></div>
	</div>
<?php else : ?>
<?php foreach ( $pages as $i => $pg ) : ?>
	<?php $day = $pg['day']; ?>
	<div class="sheet">
		<div class="top">
			<div class="brand">Programări mini-ședințe<strong>Marian Sterea</strong></div>
			<div class="doc">Generat <?php echo esc_html( $gen ); ?><br>Pagina <?php echo (int) ( $i + 1 ); ?> din <?php echo count( $pages ); ?></div>
		</div>
		<h1><?php echo esc_html( $title ); ?></h1>
		<p class="date"><?php echo esc_html( STM_Helpers::date_label( $day['date'] ) ); ?> · <?php echo esc_html( $day['from'] ); ?> - <?php echo esc_html( $day['to'] ); ?></p>
		<p class="place"><?php echo esc_html( $meta['location'] ); ?><?php echo $meta['address'] ? ', ' . esc_html( $meta['address'] ) : ''; ?><?php echo $phone ? ' · Tel. ' . esc_html( $phone ) : ''; ?></p>
		<div class="stats">
			<div class="stat"><b><?php echo (int) $pg['n']; ?> / <?php echo (int) $pg['slots']; ?></b><span>Sloturi rezervate</span></div>
			<div class="stat"><b><?php echo (int) ( $pg['slots'] - $pg['n'] ); ?></b><span>Sloturi libere</span></div>
			<div class="stat"><b><?php echo (int) $pg['p']; ?></b><span>Persoane</span></div>
			<div class="stat"><b><?php echo number_format( $pg['s'], 0, ',', '.' ); ?> lei</b><span>Valoare rezervări</span></div>
		</div>
		<table>
			<thead><tr><th>Ora</th><th>Client</th><th>Telefon</th><th>Pachet</th><th>Pers.</th><th>Observații</th><th>Status</th></tr></thead>
			<tbody>
			<?php foreach ( $pg['rows'] as $r ) : ?>
				<?php list( $time, $b ) = $r; ?>
				<tr>
					<td class="time"><?php echo esc_html( $time ); ?></td>
					<?php if ( $b ) : ?>
						<td class="name"><?php echo esc_html( $b['name'] ); ?><?php echo $b['email'] ? '<small>' . esc_html( $b['email'] ) . '</small>' : ''; ?></td>
						<td><?php echo esc_html( $b['phone'] ); ?></td>
						<td><?php echo esc_html( $b['package'] ); ?><?php echo $b['price'] ? '<small>' . esc_html( $b['price'] ) . ' lei</small>' : ''; ?></td>
						<td><?php echo (int) $b['people']; ?></td>
						<td class="notes"><?php echo esc_html( $b['notes'] ); ?></td>
						<td><span class="badge <?php echo esc_attr( $b['status'] ); ?>"><?php echo esc_html( STM_Helpers::STATUS_LABELS[ $b['status'] ] ); ?></span></td>
					<?php else : ?>
						<td class="free" colspan="6">liber</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( 'all' === $only_day && $i === count( $pages ) - 1 && count( $pages ) > 1 ) : ?>
			<div class="summary">
				<div><b><?php echo (int) $tot_book; ?> / <?php echo (int) $tot_slots; ?></b><span>Total sloturi rezervate</span></div>
				<div><b><?php echo (int) $tot_pers; ?></b><span>Total persoane</span></div>
				<div><b><?php echo number_format( $tot_sum, 0, ',', '.' ); ?> lei</b><span>Total valoare</span></div>
				<div><b><?php echo count( $pages ); ?></b><span>Zile</span></div>
			</div>
		<?php endif; ?>
		<div class="foot"><span><?php echo esc_html( $title ); ?></span><span>blog.mariansterea.ro · <?php echo esc_html( $file ); ?></span></div>
	</div>
<?php endforeach; ?>
<?php endif; ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });</script>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Butoane „Descarcă PDF” / „Descarcă Excel” deasupra listei de rezervări.
	 * Respectă filtrele active (eveniment, status, viitoare/trecute).
	 */
	public static function list_buttons( $which ) {
		global $typenow;
		if ( 'top' !== $which || STM_Helpers::CPT_BOOKING !== $typenow ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$theme  = isset( $_GET['stm_theme'] ) ? absint( $_GET['stm_theme'] ) : 0;
		$status = isset( $_GET['stm_status'] ) ? sanitize_key( $_GET['stm_status'] ) : '';
		$when   = isset( $_GET['stm_when'] ) ? sanitize_key( $_GET['stm_when'] ) : 'upcoming';
		// phpcs:enable

		// PDF-ul are nevoie de un eveniment: cel filtrat sau cel cu prima zi viitoare.
		$pdf_theme = $theme;
		if ( ! $pdf_theme ) {
			$today  = STM_Helpers::now()->format( 'Y-m-d' );
			$best   = null;
			$themes = get_posts(
				array(
					'post_type'   => STM_Helpers::CPT_THEME,
					'post_status' => array( 'publish', 'draft' ),
					'numberposts' => -1,
				)
			);
			foreach ( $themes as $t ) {
				foreach ( STM_Helpers::theme_meta( $t->ID )['days'] as $d ) {
					if ( $d['date'] >= $today && ( null === $best || $d['date'] < $best[1] ) ) {
						$best = array( $t->ID, $d['date'] );
					}
				}
			}
			if ( ! $best && $themes ) {
				$best = array( $themes[0]->ID, '' );
			}
			$pdf_theme = $best ? $best[0] : 0;
		}

		$xlsx = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'stm_export_xlsx',
					'stm_theme'  => $theme,
					'stm_status' => $status,
					'stm_when'   => $when,
				),
				admin_url( 'admin-post.php' )
			),
			'stm_export'
		);
		echo '<div class="alignleft actions stm-export-actions">';
		if ( $pdf_theme ) {
			$pdf = wp_nonce_url( admin_url( 'admin-post.php?action=stm_export&theme=' . $pdf_theme . '&day=all' ), 'stm_export' );
			echo '<a class="button" target="_blank" href="' . esc_url( $pdf ) . '">Descarcă PDF (' . esc_html( get_the_title( $pdf_theme ) ) . ')</a> ';
			$lst = wp_nonce_url( admin_url( 'admin-post.php?action=stm_export&theme=' . $pdf_theme . '&day=all&list=1' ), 'stm_export' );
			echo '<a class="button" target="_blank" href="' . esc_url( $lst ) . '">Lista rezervărilor (PDF)</a> ';
		}
		echo '<a class="button" href="' . esc_url( $xlsx ) . '">Descarcă Excel</a>';
		echo '</div>';
	}

	/**
	 * Export Excel (.xlsx) al rezervărilor, cu filtrele din listă.
	 */
	public static function export_xlsx() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( 'stm_export' );

		$theme  = isset( $_GET['stm_theme'] ) ? absint( $_GET['stm_theme'] ) : 0;
		$status = isset( $_GET['stm_status'] ) ? sanitize_key( $_GET['stm_status'] ) : '';
		$when   = isset( $_GET['stm_when'] ) ? sanitize_key( $_GET['stm_when'] ) : 'upcoming';
		$today  = STM_Helpers::now()->format( 'Y-m-d' );

		$meta_query = array();
		if ( $theme ) {
			$meta_query[] = array(
				'key'   => '_stm_theme',
				'value' => $theme,
			);
		}
		if ( $status && isset( STM_Helpers::STATUS_LABELS[ $status ] ) ) {
			$meta_query[] = array(
				'key'   => '_stm_status',
				'value' => $status,
			);
		}
		$ids = get_posts(
			array(
				'post_type'   => STM_Helpers::CPT_BOOKING,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$rows   = array();
		$titles = array();
		foreach ( $ids as $id ) {
			$b = STM_Bookings::get( $id );
			if ( ! $b ) {
				continue;
			}
			$day = substr( (string) $b['start'], 0, 10 );
			if ( 'upcoming' === $when && $day < $today ) {
				continue;
			}
			if ( 'past' === $when && $day >= $today ) {
				continue;
			}
			if ( ! isset( $titles[ $b['theme'] ] ) ) {
				$titles[ $b['theme'] ] = get_the_title( $b['theme'] );
			}
			$rows[] = array(
				$titles[ $b['theme'] ],
				$day,
				substr( (string) $b['start'], 11, 5 ),
				$b['name'],
				$b['phone'],
				$b['email'],
				$b['package'],
				$b['price'],
				(int) $b['people'],
				$b['notes'],
				isset( STM_Helpers::STATUS_LABELS[ $b['status'] ] ) ? STM_Helpers::STATUS_LABELS[ $b['status'] ] : $b['status'],
				$b['source'],
				(int) $b['id'],
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( $a[1] . $a[2], $b[1] . $b[2] );
			}
		);
		$head = array( 'Eveniment', 'Data', 'Ora', 'Client', 'Telefon', 'Email', 'Pachet', 'Preț (lei)', 'Persoane', 'Observații', 'Status', 'Sursă', 'ID' );
		$name = 'rezervari-' . ( $theme ? sanitize_title( get_the_title( $theme ) ) : 'toate' ) . '-' . STM_Helpers::now()->format( 'Y-m-d' );

		$xlsx = self::xlsx_build( $head, $rows );
		if ( $xlsx ) {
			header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			header( 'Content-Disposition: attachment; filename="' . $name . '.xlsx"' );
			header( 'Content-Length: ' . strlen( $xlsx ) );
			echo $xlsx; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fișier binar.
			exit;
		}

		// Fallback: CSV pentru Excel (UTF-8 cu BOM, separator ;).
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, $head, ';' );
		foreach ( $rows as $r ) {
			fputcsv( $out, $r, ';' );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Construiește un .xlsx minimal (o foaie, antet colorat, coloane late) fără librării externe.
	 */
	private static function xlsx_build( $head, $rows ) {
		$esc = function ( $v ) {
			return htmlspecialchars( (string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		};
		$col = function ( $i ) {
			$s = '';
			$i++;
			while ( $i > 0 ) {
				$m  = ( $i - 1 ) % 26;
				$s  = chr( 65 + $m ) . $s;
				$i  = intdiv( $i - 1, 26 );
			}
			return $s;
		};
		$sheet  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$sheet .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
		$sheet .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
		$widths = array( 34, 12, 8, 26, 16, 30, 30, 10, 10, 40, 14, 10, 8 );
		$sheet .= '<cols>';
		foreach ( $widths as $i => $w ) {
			$sheet .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . $w . '" customWidth="1"/>';
		}
		$sheet .= '</cols><sheetData>';
		$all = array_merge( array( $head ), $rows );
		foreach ( $all as $r => $cells ) {
			$sheet .= '<row r="' . ( $r + 1 ) . '">';
			foreach ( $cells as $c => $v ) {
				$ref = $col( $c ) . ( $r + 1 );
				if ( 0 === $r ) {
					$sheet .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . $esc( $v ) . '</t></is></c>';
				} elseif ( is_int( $v ) || ( 7 === $c && is_numeric( $v ) ) ) {
					$sheet .= '<c r="' . $ref . '"><v>' . $esc( $v ) . '</v></c>';
				} else {
					$sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $esc( $v ) . '</t></is></c>';
				}
			}
			$sheet .= '</row>';
		}
		$sheet .= '</sheetData><autoFilter ref="A1:' . $col( count( $head ) - 1 ) . max( 1, count( $all ) ) . '"/></worksheet>';

		$files = array(
			'[Content_Types].xml'        => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
			'_rels/.rels'                => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
			'xl/workbook.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Rezervari" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
			'xl/styles.xml'              => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F5E45"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>',
			'xl/worksheets/sheet1.xml'   => $sheet,
		);
		return self::zip_store( $files );
	}

	/**
	 * Arhivă ZIP minimală (metoda "store"), fără extensia ZipArchive.
	 */
	private static function zip_store( $files ) {
		$local   = '';
		$central = '';
		$count   = 0;
		$ts      = STM_Helpers::now();
		$dos_t   = ( (int) $ts->format( 'G' ) << 11 ) | ( (int) $ts->format( 'i' ) << 5 ) | ( (int) ( (int) $ts->format( 's' ) / 2 ) );
		$dos_d   = ( ( (int) $ts->format( 'Y' ) - 1980 ) << 9 ) | ( (int) $ts->format( 'n' ) << 5 ) | (int) $ts->format( 'j' );
		foreach ( $files as $name => $data ) {
			$crc    = crc32( $data );
			$len    = strlen( $data );
			$offset = strlen( $local );
			$local .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dos_t, $dos_d, $crc, $len, $len, strlen( $name ), 0 ) . $name . $data;
			$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dos_t, $dos_d, $crc, $len, $len, strlen( $name ), 0, 0, 0, 0, 0, $offset ) . $name;
			++$count;
		}
		$eocd = pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen( $central ), strlen( $local ), 0 );
		return $local . $central . $eocd;
	}
}
