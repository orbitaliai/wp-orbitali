<?php
/**
 * Uninstall cleanup. Runs only when the plugin is deleted from the Plugins
 * screen (not on simple deactivation), and only removes this plugin's own
 * WordPress-side data.
 *
 * Deliberately does NOT contact Orbitali to delete any remote knowledge
 * documents: withdrawal is an explicit, confirmed action elsewhere in this
 * plugin, and an uninstall should never perform a remote bulk-delete the
 * admin cannot see happening. Anyone who wants the agent's knowledge base
 * cleared out should do so from the Orbitali dashboard, or use "Remove from
 * Orbitali" on each item before deleting the plugin.
 *
 * @package Orbitali
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$options = array(
	'orbitali_api_key',
	'orbitali_api_base_url',
	'orbitali_agent_id',
	'orbitali_agent_name',
	'orbitali_agent_status',
	'orbitali_connection_ok',
	'orbitali_connected_at',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_orbitali_mappings'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_orbitali\\_verify\\_attempts\\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_orbitali\\_%' OR option_name LIKE '\\_transient\\_timeout\\_orbitali\\_%'" );
