<?php
/**
 * Plugin Name: GF CardDAV Server
 * Plugin URI: https://github.com/guilamu/gf-carddav-server
 * Description: Exposes Gravity Forms entries as a native CardDAV address book.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Guilamu
 * Text Domain: gf-carddav-server
 * Domain Path: /languages
 * Update URI: https://github.com/guilamu/gf-carddav-server/
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GF_CARDDAV_SERVER_VERSION', '1.0.1' );
define( 'GF_CARDDAV_SERVER_FILE', __FILE__ );
define( 'GF_CARDDAV_SERVER_DIR', plugin_dir_path( __FILE__ ) );
define( 'GF_CARDDAV_SERVER_URL', plugin_dir_url( __FILE__ ) );
define( 'GF_CARDDAV_SERVER_OPTION', 'gf_carddav_server_settings' );
define( 'GF_CARDDAV_SERVER_ASSET_DIR', GF_CARDDAV_SERVER_DIR . 'assets/' );
define( 'GF_CARDDAV_SERVER_REWRITE_VERSION_OPTION', 'gf_carddav_server_rewrite_version' );

require_once GF_CARDDAV_SERVER_ASSET_DIR . 'class-gf-carddav-github-updater.php';

add_action( 'init', 'gf_carddav_server_load_textdomain' );
add_action( 'gform_loaded', array( 'GF_CardDAV_AddOn_Bootstrap', 'load' ), 5 );
add_filter( 'plugin_row_meta', 'gf_carddav_server_plugin_row_meta', 10, 2 );
add_action( 'plugins_loaded', 'gf_carddav_server_register_bug_reporter', 20 );

GF_CardDAV_GitHub_Updater::init();

function gf_carddav_server_load_textdomain() {
	load_plugin_textdomain( 'gf-carddav-server', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

function gf_carddav_server_plugin_row_meta( $plugin_meta, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $plugin_meta;
	}

	$plugin_slug = 'gf-carddav-server';
	$details_url = self_admin_url(
		'plugin-install.php?tab=plugin-information&plugin=' . $plugin_slug . '&TB_iframe=true&width=772&height=926'
	);

	$plugin_meta[] = sprintf(
		'<a href="%1$s" class="thickbox open-plugin-details-modal" aria-label="%2$s">%3$s</a>',
		esc_url( $details_url ),
		esc_attr__( 'View GF CardDAV Server details', 'gf-carddav-server' ),
		esc_html__( 'View details', 'gf-carddav-server' )
	);

	if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
		$plugin_meta[] = sprintf(
			'<a href="#" class="guilamu-bug-report-btn" data-plugin-slug="%1$s" data-plugin-name="%2$s">%3$s</a>',
			esc_attr( 'gf-carddav-server' ),
			esc_attr__( 'GF CardDAV Server', 'gf-carddav-server' ),
			esc_html__( 'Report a Bug', 'gf-carddav-server' )
		);
	} else {
		$plugin_meta[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( 'https://github.com/guilamu/guilamu-bug-reporter/releases' ),
			esc_html__( 'Report a Bug (install Bug Reporter)', 'gf-carddav-server' )
		);
	}

	return $plugin_meta;
}

function gf_carddav_server_register_bug_reporter() {
	if ( ! class_exists( 'Guilamu_Bug_Reporter' ) ) {
		return;
	}

	Guilamu_Bug_Reporter::register(
		array(
			'slug'        => 'gf-carddav-server',
			'name'        => 'GF CardDAV Server',
			'version'     => GF_CARDDAV_SERVER_VERSION,
			'github_repo' => 'guilamu/gf-carddav-server',
		)
	);
}

class GF_CardDAV_AddOn_Bootstrap {
	public static function load() {
		if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
			return;
		}

		require_once GF_CARDDAV_SERVER_ASSET_DIR . 'class-gf-carddav-addon.php';
		GFAddOn::register( 'GF_CardDAV_AddOn' );
		GF_CardDAV_AddOn::get_instance();
	}
}

require_once GF_CARDDAV_SERVER_ASSET_DIR . 'class-gf-carddav-plugin.php';
require_once GF_CARDDAV_SERVER_ASSET_DIR . 'class-gf-carddav-settings.php';
require_once GF_CARDDAV_SERVER_ASSET_DIR . 'class-gf-carddav-auth.php';
require_once GF_CARDDAV_SERVER_ASSET_DIR . 'class-gf-carddav-server.php';
require_once GF_CARDDAV_SERVER_ASSET_DIR . 'class-gf-carddav-principal.php';
require_once GF_CARDDAV_SERVER_ASSET_DIR . 'class-gf-carddav-directory.php';
require_once GF_CARDDAV_SERVER_ASSET_DIR . 'class-gf-carddav-vcf.php';
require_once GF_CARDDAV_SERVER_ASSET_DIR . 'class-gf-carddav-logger.php';

function gf_carddav_server_activate() {
	GF_CardDAV_Plugin::register_rewrite_rules();
	flush_rewrite_rules();
}

function gf_carddav_server_deactivate() {
	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'gf_carddav_server_activate' );
register_deactivation_hook( __FILE__, 'gf_carddav_server_deactivate' );

function gf_carddav_server() {
	return GF_CardDAV_Plugin::get_instance();
}

function gf_carddav_addon() {
	if ( ! class_exists( 'GF_CardDAV_AddOn' ) ) {
		return null;
	}

	return GF_CardDAV_AddOn::get_instance();
}

gf_carddav_server();