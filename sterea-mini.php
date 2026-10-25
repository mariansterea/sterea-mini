<?php
/**
 * Plugin Name:       Sterea Mini-Ședințe
 * Description:       Rezervări pentru mini-ședințe foto, pe evenimente cu date fixe: sloturi, pachete, confirmări pe email, anulare de către client, reamintiri și programul zilei. Poate afișa și rezerva sloturile de pe alt site (ex. lipovenesc.ro → site-ul fotografului).
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Daniela & Marian Sterea
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/mariansterea/sterea-mini
 * Text Domain:       sterea-mini
 */

defined( 'ABSPATH' ) || exit;

define( 'STM_VERSION', '1.5.0' );
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
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . STM_Helpers::CPT_THEME . '&page=stm-settings' ) ) . '">Setări</a>' );
		return $links;
	}
);
