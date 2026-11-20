<?php
/**
 * Plugin Name: IUC Syd Portal Features
 * Description: Company, communication and private-request features extending BuddyPress.
 * Version: 1.0.0
 * Author: IUC Syd
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define(
	'IUC_PORTAL_FEATURES_PATH',
	plugin_dir_path( __FILE__ )
);

define(
	'IUC_PORTAL_FEATURES_URL',
	plugin_dir_url( __FILE__ )
);

require_once IUC_PORTAL_FEATURES_PATH . 'includes/private-requests.php';
require_once IUC_PORTAL_FEATURES_PATH . 'includes/admin-dashboard.php';
require_once IUC_PORTAL_FEATURES_PATH . 'includes/company-admin.php';
require_once IUC_PORTAL_FEATURES_PATH . 'includes/company-view.php';
require_once IUC_PORTAL_FEATURES_PATH . 'includes/company-link.php';
require_once IUC_PORTAL_FEATURES_PATH . 'includes/user-admin.php';
require_once IUC_PORTAL_FEATURES_PATH . 'includes/request-admin.php';
require_once IUC_PORTAL_FEATURES_PATH . 'includes/content-shells.php';
require_once IUC_PORTAL_FEATURES_PATH . 'includes/mock-data.php';

/**
 * Load the plugin's frontend styles.
 */
function iuc_syd_features_assets() {
	$path = IUC_PORTAL_FEATURES_PATH . 'assets/features.css';

	wp_enqueue_style(
		'iuc-syd-features',
		IUC_PORTAL_FEATURES_URL . 'assets/features.css',
		array(),
		file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0'
	);
}
add_action( 'wp_enqueue_scripts', 'iuc_syd_features_assets' );