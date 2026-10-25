<?php
/**
 * Settings page.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Settings {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) {
				if ( false !== strpos( $hook, 'stm-settings' ) ) {
					wp_enqueue_script( 'stm-admin', STM_URL . 'assets/js/admin.js', array(), STM_VERSION, true );
				}
			}
		);
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . STM_Helpers::CPT_THEME, 'Setări mini-ședințe', 'Setări', 'manage_options', 'stm-settings', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting(
			'stm_settings_group',
			STM_Helpers::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = array(
			'mode'          => isset( $in['mode'] ) && 'remote' === $in['mode'] ? 'remote' : 'main',
			'remote_url'    => esc_url_raw( trim( isset( $in['remote_url'] ) ? (string) $in['remote_url'] : '' ) ),
			'notify_email'  => implode( ', ', array_filter( array_map( 'sanitize_email', explode( ',', isset( $in['notify_email'] ) ? (string) $in['notify_email'] : '' ) ) ) ),
			'auto_confirm'  => empty( $in['auto_confirm'] ) ? 0 : 1,
			'contact_phone' => sanitize_text_field( isset( $in['contact_phone'] ) ? $in['contact_phone'] : '' ),
			'gdpr_text'     => wp_kses_post( isset( $in['gdpr_text'] ) ? $in['gdpr_text'] : '' ),
			'success_text'  => sanitize_text_field( isset( $in['success_text'] ) ? $in['success_text'] : '' ),
			'info_text'     => sanitize_textarea_field( isset( $in['info_text'] ) ? $in['info_text'] : '' ),
			'reminders'     => empty( $in['reminders'] ) ? 0 : 1,
			'accent'        => sanitize_hex_color( isset( $in['accent'] ) ? $in['accent'] : '' ),
			'days_off'      => array(),
			'gcal_enabled'  => empty( $in['gcal_enabled'] ) ? 0 : 1,
			'gcal_calendar_id' => sanitize_text_field( isset( $in['gcal_calendar_id'] ) ? $in['gcal_calendar_id'] : '' ),
			'gcal_json'     => '',
		);
		// Cheia JSON a contului de serviciu: se păstrează cea veche dacă nu s-a lipit alta.
		$json_in = trim( (string) ( isset( $in['gcal_json'] ) ? $in['gcal_json'] : '' ) );
		$old     = STM_Helpers::settings();
		if ( '' === $json_in ) {
			$out['gcal_json'] = (string) $old['gcal_json'];
		} elseif ( '-' === $json_in ) {
			$out['gcal_json'] = '';
		} else {
			$decoded = json_decode( $json_in, true );
			if ( is_array( $decoded ) && ! empty( $decoded['client_email'] ) && ! empty( $decoded['private_key'] ) ) {
				$out['gcal_json'] = wp_json_encode( $decoded );
				delete_transient( STM_GCal::TOKEN );
			} else {
				$out['gcal_json'] = (string) $old['gcal_json'];
				add_settings_error( STM_Helpers::OPTION, 'stm_gcal', 'Cheia JSON nu este validă (trebuie să conțină client_email și private_key). Am păstrat cheia anterioară.' );
			}
		}
		foreach ( (array) ( isset( $in['days_off'] ) ? $in['days_off'] : array() ) as $d ) {
			$from = sanitize_text_field( isset( $d['from'] ) ? $d['from'] : '' );
			$to   = sanitize_text_field( isset( $d['to'] ) ? $d['to'] : '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
				continue;
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) {
				$to = $from;
			}
			$out['days_off'][] = array(
				'name'   => sanitize_text_field( isset( $d['name'] ) ? $d['name'] : '' ),
				'from'   => $from,
				'to'     => $to,
				'yearly' => empty( $d['yearly'] ) ? 0 : 1,
			);
		}
		usort(
			$out['days_off'],
			function ( $a, $b ) {
				return strcmp( $a['from'], $b['from'] );
			}
		);
		if ( ! $out['accent'] ) {
			$out['accent'] = STM_Helpers::defaults()['accent'];
		}
		if ( 'remote' === $out['mode'] && ! $out['remote_url'] ) {
			$out['mode'] = 'main';
			add_settings_error( STM_Helpers::OPTION, 'stm_remote', 'Pentru modul „afișare de pe alt site” trebuie completată adresa site-ului principal.' );
		}
		if ( $out['remote_url'] && untrailingslashit( $out['remote_url'] ) === untrailingslashit( home_url() ) ) {
			$out['mode'] = 'main';
			add_settings_error( STM_Helpers::OPTION, 'stm_self', 'Adresa site-ului principal nu poate fi chiar acest site.' );
		}
		delete_transient( 'stm_remote_0' );
		return $out;
	}

	private static function name( $key ) {
		return STM_Helpers::OPTION . '[' . $key . ']';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = STM_Helpers::settings();
		?>
		<div class="wrap">
			<h1>Setări mini-ședințe</h1>
			<?php settings_errors( STM_Helpers::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'stm_settings_group' ); ?>

				<h2>Unde se salvează rezervările</h2>
				<table class="form-table">
					<tr>
						<th>Rolul acestui site</th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( self::name( 'mode' ) ); ?>" value="main" <?php checked( $s['mode'], 'main' ); ?>> <strong>Site principal</strong>: evenimentele și rezervările se gestionează aici (site-ul fotografului).</label><br><br>
							<label><input type="radio" name="<?php echo esc_attr( self::name( 'mode' ) ); ?>" value="remote" <?php checked( $s['mode'], 'remote' ); ?>> <strong>Afișare de pe alt site</strong>: acest site doar afișează programul, iar rezervările ajung pe site-ul principal (ex. pe lipovenesc.ro).</label>
						</td>
					</tr>
					<tr>
						<th><label for="stm-remote">Adresa site-ului principal</label></th>
						<td><input type="url" id="stm-remote" class="regular-text" name="<?php echo esc_attr( self::name( 'remote_url' ) ); ?>" value="<?php echo esc_attr( $s['remote_url'] ); ?>" placeholder="https://www.mariansterea.ro">
						<p class="description">Doar pentru „Afișare de pe alt site”. Pe site-ul principal trebuie instalat și activat același plugin.</p></td>
					</tr>
				</table>

				<h2>Rezervări și emailuri</h2>
				<p class="description">Pe un site setat ca „Afișare de pe alt site”, aceste opțiuni nu se folosesc: contează cele de pe site-ul principal.</p>
				<table class="form-table">
					<tr>
						<th><label for="stm-notify">Notificări rezervări noi</label></th>
						<td><input type="text" id="stm-notify" class="regular-text" name="<?php echo esc_attr( self::name( 'notify_email' ) ); ?>" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
						<p class="description">Una sau mai multe adrese, separate prin virgulă. Clienții pot răspunde direct la emailul de confirmare, către prima adresă.</p></td>
					</tr>
					<tr>
						<th>Confirmare</th>
						<td>
							<label><input type="hidden" name="<?php echo esc_attr( self::name( 'auto_confirm' ) ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( self::name( 'auto_confirm' ) ); ?>" value="1" <?php checked( $s['auto_confirm'] ); ?>> Confirmă automat rezervările</label>
							<p class="description">Dacă debifezi, rezervările intră „În așteptare” (ora rămâne blocată), iar clientul primește confirmarea când apeși „Confirmă” în lista de rezervări.</p>
						</td>
					</tr>
					<tr>
						<th>Reamintiri</th>
						<td><label><input type="hidden" name="<?php echo esc_attr( self::name( 'reminders' ) ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( self::name( 'reminders' ) ); ?>" value="1" <?php checked( $s['reminders'] ); ?>> Trimite clientului un email de reamintire cu o zi înainte</label></td>
					</tr>
					<tr>
						<th><label for="stm-phone">Telefon de contact</label></th>
						<td><input type="text" id="stm-phone" class="regular-text" name="<?php echo esc_attr( self::name( 'contact_phone' ) ); ?>" value="<?php echo esc_attr( $s['contact_phone'] ); ?>" placeholder="0724 000 000">
						<p class="description">Apare în emailurile către client.</p></td>
					</tr>
					<tr>
						<th><label for="stm-info">Informații utile pentru client</label></th>
						<td><textarea id="stm-info" rows="4" class="large-text" name="<?php echo esc_attr( self::name( 'info_text' ) ); ?>"><?php echo esc_textarea( $s['info_text'] ); ?></textarea>
						<p class="description">Apar în emailul de confirmare și în reamintire (ce să aducă, ținută, parcare etc.).</p></td>
					</tr>
				</table>

				<h2>Formularul de pe site</h2>
				<table class="form-table">
					<tr>
						<th><label for="stm-gdpr">Text acord GDPR</label></th>
						<td><textarea id="stm-gdpr" rows="2" class="large-text" name="<?php echo esc_attr( self::name( 'gdpr_text' ) ); ?>"><?php echo esc_textarea( $s['gdpr_text'] ); ?></textarea>
						<p class="description">Poți pune link: &lt;a href="/politica-de-confidentialitate"&gt;politica de confidențialitate&lt;/a&gt;</p></td>
					</tr>
					<tr>
						<th><label for="stm-success">Mesaj după rezervare</label></th>
						<td><input type="text" id="stm-success" class="large-text" name="<?php echo esc_attr( self::name( 'success_text' ) ); ?>" value="<?php echo esc_attr( $s['success_text'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="stm-accent">Culoare principală</label></th>
						<td><input type="color" id="stm-accent" name="<?php echo esc_attr( self::name( 'accent' ) ); ?>" value="<?php echo esc_attr( $s['accent'] ); ?>"></td>
					</tr>
				</table>

				<h2>Zile libere</h2>
				<p class="description">Zile în care nu se fac ședințe la niciun eveniment (sărbători, concediu). Nu apar în formular chiar dacă sunt în programul unui eveniment.</p>
				<?php $days_off = $s['days_off'] ? $s['days_off'] : array( array( 'name' => '', 'from' => '', 'to' => '', 'yearly' => 0 ) ); ?>
				<table class="widefat stm-rows stm-off-rows" data-stm-rows="days_off" style="max-width:760px">
					<thead><tr><th>Nume</th><th>De la</th><th>Până la</th><th>În fiecare an</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $days_off as $i => $d ) : ?>
						<tr>
							<td><input type="text" name="<?php echo esc_attr( self::name( 'days_off' ) ); ?>[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $d['name'] ); ?>" placeholder="Crăciun"></td>
							<td><input type="date" name="<?php echo esc_attr( self::name( 'days_off' ) ); ?>[<?php echo (int) $i; ?>][from]" value="<?php echo esc_attr( $d['from'] ); ?>"></td>
							<td><input type="date" name="<?php echo esc_attr( self::name( 'days_off' ) ); ?>[<?php echo (int) $i; ?>][to]" value="<?php echo esc_attr( $d['to'] ); ?>"></td>
							<td><input type="checkbox" name="<?php echo esc_attr( self::name( 'days_off' ) ); ?>[<?php echo (int) $i; ?>][yearly]" value="1" <?php checked( ! empty( $d['yearly'] ) ); ?>></td>
							<td><button type="button" class="button-link stm-remove" aria-label="Șterge rândul">✕</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button stm-add" data-stm-target="days_off">+ Adaugă zi liberă</button></p>

				<h2>Google Calendar</h2>
				<p class="description">Fiecare rezervare apare automat într-un calendar Google (cu numele, telefonul, emailul și pachetul clientului), se actualizează la mutare sau confirmare și dispare la anulare. Are nevoie de un cont de serviciu Google; pașii de configurare sunt în readme.</p>
				<?php $acc = STM_GCal::account(); $st = STM_GCal::status(); ?>
				<table class="form-table">
					<tr>
						<th>Sincronizare</th>
						<td><label><input type="hidden" name="<?php echo esc_attr( self::name( 'gcal_enabled' ) ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( self::name( 'gcal_enabled' ) ); ?>" value="1" <?php checked( $s['gcal_enabled'] ); ?>> Pune rezervările în Google Calendar</label></td>
					</tr>
					<tr>
						<th><label for="stm-gcal-id">ID-ul calendarului</label></th>
						<td><input type="text" id="stm-gcal-id" class="large-text" name="<?php echo esc_attr( self::name( 'gcal_calendar_id' ) ); ?>" value="<?php echo esc_attr( $s['gcal_calendar_id'] ); ?>" placeholder="xxxxxxxx@group.calendar.google.com">
						<p class="description">Google Calendar → calendarul „Ședințe foto” → Setări și partajare → „ID calendar” (jos, la „Integrează calendarul”).</p></td>
					</tr>
					<tr>
						<th><label for="stm-gcal-json">Cheia contului de serviciu (JSON)</label></th>
						<td>
							<?php if ( $acc ) : ?>
								<p>Cheie salvată pentru <code><?php echo esc_html( $acc['client_email'] ); ?></code>. Partajează calendarul cu această adresă („Efectuează modificări la evenimente”). Lipește altă cheie ca să o înlocuiești, sau scrie <code>-</code> ca să o ștergi.</p>
							<?php endif; ?>
							<textarea id="stm-gcal-json" rows="4" class="large-text code" name="<?php echo esc_attr( self::name( 'gcal_json' ) ); ?>" placeholder='{"type": "service_account", "client_email": "...", "private_key": "..."}'></textarea>
							<p class="description">Conținutul fișierului .json descărcat din Google Cloud (Service account → Keys). Se păstrează doar pe acest server.</p>
						</td>
					</tr>
					<tr>
						<th>Stare</th>
						<td>
							<?php if ( $st ) : ?>
								<p><span class="stm-badge stm-badge--<?php echo $st['ok'] ? 'confirmed' : 'cancelled'; ?>"><?php echo $st['ok'] ? 'OK' : 'Eroare'; ?></span> <?php echo esc_html( $st['message'] ); ?> <span class="stm-muted">(<?php echo esc_html( $st['time'] ); ?>)</span></p>
							<?php else : ?>
								<p class="stm-muted">Nicio acțiune încă.</p>
							<?php endif; ?>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=stm_gcal_test' ), 'stm_gcal_test' ) ); ?>">Testează conexiunea</a>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=stm_gcal_sync_all' ), 'stm_gcal_sync_all' ) ); ?>" onclick="return confirm('Pun în calendar toate rezervările viitoare neanulate?')">Pune în calendar rezervările viitoare</a>
							<p class="description">Salvează setările înainte de test. Culori: verde = confirmată, galben = în așteptare.</p>
						</td>
					</tr>
				</table>

				<h2>Cum îl folosești</h2>
				<ol>
					<li>Pe site-ul principal: <strong>Mini-ședințe → Adaugă eveniment</strong> (titlu, descriere, imagine, zile, pachete).</li>
					<li>Pune <code>[sterea_mini]</code> într-o pagină (toate evenimentele) sau <code>[sterea_mini id="…"]</code> (un singur eveniment).</li>
					<li>Pe al doilea site: instalezi pluginul, alegi „Afișare de pe alt site”, completezi adresa site-ului principal și pui același shortcode într-o pagină. Dacă folosești <code>id</code>, este ID-ul evenimentului de pe site-ul principal.</li>
				</ol>

				<?php submit_button( 'Salvează setările' ); ?>
			</form>
		</div>
		<?php
	}
}
