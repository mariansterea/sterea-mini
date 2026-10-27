<?php
/**
 * Plugin Name:       Sterea Mini-Ședințe
 * Description:       Rezervări pentru mini-ședințe foto, pe evenimente cu date fixe: calendar de disponibilitate, sloturi, pachete, cupoane, dashboard, confirmări pe email, sincronizare cu Google Calendar, anulare de către client, reamintiri și programul zilei. Poate afișa și rezerva sloturile de pe alt site (ex. lipovenesc.ro → site-ul fotografului).
 * Version:           1.7.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Daniela & Marian Sterea
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/mariansterea/sterea-mini
 * Text Domain:       sterea-mini
 */

defined( 'ABSPATH' ) || exit;

define( 'STM_VERSION', '1.7.1' );
define( 'STM_FILE', __FILE__ );
define( 'STM_DIR', plugin_dir_path( __FILE__ ) );
define( 'STM_URL', plugin_dir_url( __FILE__ ) );

require_once STM_DIR . 'includes/class-stm-helpers.php';
require_once STM_DIR . 'includes/class-stm-themes.php';
require_once STM_DIR . 'includes/class-stm-bookings.php';
require_once STM_DIR . 'includes/class-stm-emails.php';
require_once STM_DIR . 'includes/class-stm-rest.php';
require_once STM_DIR . 'includes/class-stm-frontend.php';
require_once STM_DIR . 'includes/class-stm-settings.php';
require_once STM_DIR . 'includes/class-stm-schedule.php';
require_once STM_DIR . 'includes/class-stm-updater.php';
require_once STM_DIR . 'includes/class-stm-coupons.php';
require_once STM_DIR . 'includes/class-stm-dashboard.php';
require_once STM_DIR . 'includes/class-stm-gcal.php';

register_activation_hook( __FILE__, array( 'STM_Bookings', 'install' ) );
register_deactivation_hook( __FILE__, array( 'STM_Emails', 'unschedule' ) );

add_action(
	'plugins_loaded',
	function () {
		STM_Themes::init();
		STM_Bookings::init();
		STM_Emails::init();
		STM_Rest::init();
		STM_Frontend::init();
		STM_Settings::init();
		STM_Schedule::init();
		STM_Updater::init();
		STM_Coupons::init();
		STM_Dashboard::init();
		STM_GCal::init();

		// La prima rulare a unei versiuni noi: culoarea implicită veche (verde) devine auriul site-ului.
		if ( get_option( 'stm_version' ) !== STM_VERSION ) {
			$settings = get_option( STM_Helpers::OPTION, array() );
			if ( is_array( $settings ) && isset( $settings['accent'] ) && '#2f5d50' === strtolower( $settings['accent'] ) ) {
				$settings['accent'] = '#be9656';
				update_option( STM_Helpers::OPTION, $settings );
			}
			update_option( 'stm_version', STM_VERSION, false );
		}
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . STM_Helpers::CPT_THEME . '&page=stm-settings' ) ) . '">Setări</a>' );
		return $links;
	}
);
