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
		);
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
