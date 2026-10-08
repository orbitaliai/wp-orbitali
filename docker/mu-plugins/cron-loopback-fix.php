<?php
/**
 * Docker-only fix: WP-Cron (used by Orbitali_Sync_Manager to run uploads in
 * the background) fires by having PHP make an HTTP "loopback" request to
 * this same site's own wp-cron.php. The site's public URL here is
 * http://localhost:<published-port> (e.g. :8080), which is correct for a
 * browser on the host - but inside this container nothing listens on that
 * port; Apache only listens on port 80 internally. Without this fix, every
 * background sync job is scheduled but silently never runs, and the editor
 * panel gets stuck on "Processing" forever.
 *
 * This only matters for this local/dev Docker setup. A real production site
 * doesn't have this host/port mismatch between its public URL and what its
 * own server can reach, so the plugin itself doesn't need this - it's not
 * shipped as part of the Orbitali plugin.
 *
 * @package Orbitali
 */

add_filter(
	'cron_request',
	function ( $request ) {
		$request['url'] = preg_replace( '#^(https?://)[^/]+#', '$1127.0.0.1', $request['url'] );
		return $request;
	}
);
