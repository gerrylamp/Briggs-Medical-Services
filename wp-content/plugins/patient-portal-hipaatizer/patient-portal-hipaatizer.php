<?php
/**
 * Plugin Name: Patient Portal for HIPAAtizer
 * Description: A lightweight patient portal for WordPress/Elementor that connects logged-in users to HIPAAtizer forms and stores only minimal submission status metadata.
 * Version: 1.1.6
 * Author: Eziekiel
 * Text Domain: patient-portal-hipaatizer
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PPH_VERSION', '1.1.6' );
define( 'PPH_FILE', __FILE__ );
define( 'PPH_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPH_URL', plugin_dir_url( __FILE__ ) );

require_once PPH_DIR . 'includes/class-pph-db.php';
require_once PPH_DIR . 'includes/class-pph-webhook.php';
require_once PPH_DIR . 'includes/class-pph-frontend.php';
require_once PPH_DIR . 'includes/class-pph-admin.php';
require_once PPH_DIR . 'includes/class-pph-plugin.php';

register_activation_hook( __FILE__, array( 'PPH_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PPH_Plugin', 'deactivate' ) );

PPH_Plugin::instance();
