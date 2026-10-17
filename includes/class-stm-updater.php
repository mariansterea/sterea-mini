<?php
/**
 * Update-uri din GitHub Releases (repo public), fără server propriu.
 *
 * WordPress citește antetul "Update URI: https://github.com/<owner>/<repo>" și
 * cheamă filtrul update_plugins_github.com; noi răspundem cu ultimul release.
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Updater {

	const REPO      = 'mariansterea/sterea-mini';
	const TRANSIENT = 'stm_github_release';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'plugin_action_links_' . plugin_basename( STM_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'force_check' ) );
	}

	/**
	 * Ultimul release din GitHub (cache 6 ore).
	 */
	public static function release( $force = false ) {
		$cached = get_site_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'sterea-mini/' . STM_VERSION . '; ' . home_url( '/' ),
				),
			)
		);
		$data = array();
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) && ! empty( $json['tag_name'] ) ) {
				$package = $json['zipball_url'];
				foreach ( (array) ( isset( $json['assets'] ) ? $json['assets'] : array() ) as $a ) {
					if ( ! empty( $a['browser_download_url'] ) && preg_match( '/\.zip$/i', $a['name'] ) ) {
						$package = $a['browser_download_url'];
						break;
					}
				}
				$data = array(
					'version' => ltrim( (string) $json['tag_name'], 'vV' ),
					'package' => $package,
					'url'     => $json['html_url'],
					'notes'   => isset( $json['body'] ) ? (string) $json['body'] : '',
					'date'    => isset( $json['published_at'] ) ? substr( $json['published_at'], 0, 10 ) : '',
				);
			}
		}
		set_site_transient( self::TRANSIENT, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Răspuns pentru verificarea de update-uri a WordPress.
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( STM_FILE ) !== $plugin_file ) {
			return $update;
		}
		$r = self::release();
		if ( empty( $r['version'] ) || ! version_compare( $r['version'], STM_VERSION, '>' ) ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => 'sterea-mini',
			'plugin'       => $plugin_file,
			'version'      => $r['version'],
			'url'          => $r['url'],
			'package'      => $r['package'],
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
			'tested'       => '',
			'requires_php' => '7.4',
			'compatibility' => new stdClass(),
		);
	}

	/**
	 * Fereastra „View version details” din lista de plugin-uri.
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'sterea-mini' !== $args->slug ) {
			return $result;
		}
		$r = self::release();
		if ( empty( $r['version'] ) ) {
			return $result;
		}
		$info                = new stdClass();
		$info->name          = 'Sterea Mini-Ședințe';
		$info->slug          = 'sterea-mini';
		$info->version       = $r['version'];
		$info->author        = 'Daniela & Marian Sterea';
		$info->homepage      = 'https://github.com/' . self::REPO;
		$info->download_link = $r['package'];
		$info->last_updated  = $r['date'];
		$info->requires      = '6.0';
		$info->requires_php  = '7.4';
		$info->sections      = array(
			'description' => 'Rezervări pentru mini-ședințe foto, pe evenimente cu date fixe.',
			'changelog'   => '<pre style="white-space:pre-wrap">' . esc_html( $r['notes'] ) . '</pre>',
		);
		return $info;
	}

	/**
	 * Arhiva de pe GitHub poate avea folderul "mariansterea-sterea-mini-abc123"; îl redenumim în "sterea-mini".
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( STM_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . 'sterea-mini/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) {
			return $want;
		}
		return $source;
	}

	public static function action_links( $links ) {
		$url     = wp_nonce_url( add_query_arg( 'stm_check_update', '1', admin_url( 'plugins.php' ) ), 'stm_check_update' );
		$links[] = '<a href="' . esc_url( $url ) . '">Verifică update</a>';
		return $links;
	}

	public static function force_check() {
		if ( empty( $_GET['stm_check_update'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'stm_check_update' );
		delete_site_transient( self::TRANSIENT );
		self::release( true );
		delete_site_transient( 'update_plugins' );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
