<?php
/**
 * Plugin Name:       Orbitali
 * Plugin URI:        https://github.com/orbitali/wp-orbitali
 * Description:       Sync selected WordPress pages and posts into an Orbitali voice agent's knowledge base.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Orbitali
 * Author URI:        https://orbitali.ai
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       orbitali
 * Domain Path:       /languages
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ORBITALI_VERSION', '0.1.0' );
define( 'ORBITALI_PLUGIN_FILE', __FILE__ );
define( 'ORBITALI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORBITALI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ORBITALI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Production default; overridable via the ORBITALI_API_BASE_URL constant for staging/dev use.
define( 'ORBITALI_DEFAULT_API_BASE_URL', 'https://api.orbitali.ai/public/v1' );

require_once ORBITALI_PLUGIN_DIR . 'includes/class-orbitali-settings.php';
require_once ORBITALI_PLUGIN_DIR . 'includes/class-orbitali-api-client.php';
require_once ORBITALI_PLUGIN_DIR . 'includes/class-orbitali-post-meta.php';
require_once ORBITALI_PLUGIN_DIR . 'includes/class-orbitali-document-naming.php';
require_once ORBITALI_PLUGIN_DIR . 'includes/class-orbitali-content-extractor.php';
require_once ORBITALI_PLUGIN_DIR . 'includes/class-orbitali-sync-manager.php';
require_once ORBITALI_PLUGIN_DIR . 'includes/class-orbitali-rest-controller.php';
require_once ORBITALI_PLUGIN_DIR . 'includes/class-orbitali-editor-panel.php';
require_once ORBITALI_PLUGIN_DIR . 'includes/class-orbitali-withdrawal-hooks.php';
require_once ORBITALI_PLUGIN_DIR . 'includes/class-orbitali-settings-page.php';
require_once ORBITALI_PLUGIN_DIR . 'includes/class-orbitali-plugin.php';

Orbitali_Plugin::instance();
