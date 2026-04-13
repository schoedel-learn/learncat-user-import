<?php
/**
 * Plugin Name:       LearnCAT User Import
 * Plugin URI:        https://github.com/schoedel-learn/learncat-user-import
 * Description:       Bulk import users via CSV into WordPress, BuddyBoss XProfile, WooCommerce billing/shipping, and LearnDash groups & courses. Unknown columns are silently skipped.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Barry Schoedel
 * Author URI:        https://github.com/schoedel-learn
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       learncat-user-import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCUI_VERSION',   '1.0.0' );
define( 'LCUI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LCUI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ── Autoload includes ────────────────────────────────────────────────────────
require_once LCUI_PLUGIN_DIR . 'includes/class-field-registry.php';
require_once LCUI_PLUGIN_DIR . 'includes/class-csv-parser.php';
require_once LCUI_PLUGIN_DIR . 'includes/class-row-importer.php';
require_once LCUI_PLUGIN_DIR . 'includes/class-admin-page.php';

// ── Boot ─────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', array( 'LCUI_Admin_Page', 'init' ) );
