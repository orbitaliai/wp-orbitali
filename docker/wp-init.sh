#!/bin/sh
# One-shot setup run by the `cli` service: waits for the `wordpress` service
# to generate wp-config.php and for the database to accept connections, then
# installs WordPress (if not already installed), activates the Orbitali
# plugin, enables pretty permalinks, and seeds a sample page with headings,
# a list, a link, and a table so there's something to Preview/Sync
# immediately without hand-authoring content.
set -eu

WP="wp --path=/var/www/html --allow-root"

echo "Waiting for wp-config.php..."
i=0
until [ -f /var/www/html/wp-config.php ]; do
	i=$((i + 1))
	if [ "$i" -ge 60 ]; then
		echo "Timed out waiting for wp-config.php" >&2
		exit 1
	fi
	sleep 2
done

echo "Waiting for the database..."
i=0
until $WP db check >/dev/null 2>&1; do
	i=$((i + 1))
	if [ "$i" -ge 60 ]; then
		echo "Timed out waiting for the database" >&2
		exit 1
	fi
	sleep 2
done

if ! $WP core is-installed >/dev/null 2>&1; then
	echo "Installing WordPress..."
	$WP core install \
		--url="${WP_SITE_URL:-http://localhost:8080}" \
		--title="Orbitali Test Site" \
		--admin_user="${WP_ADMIN_USER:-admin}" \
		--admin_password="${WP_ADMIN_PASSWORD:-admin}" \
		--admin_email="${WP_ADMIN_EMAIL:-admin@example.com}" \
		--skip-email
else
	echo "WordPress already installed."
fi

$WP plugin activate orbitali
$WP rewrite structure '/%postname%/'
$WP rewrite flush

if ! $WP post list --post_type=page --field=post_name | grep -qx orbitali-sample-page; then
	echo "Creating sample page..."
	$WP post create \
		--post_type=page \
		--post_status=publish \
		--post_title="Orbitali Sample Page" \
		--post_name=orbitali-sample-page \
		--post_content='<h2>About this page</h2><p>This is a sample page for testing the <a href="https://orbitali.ai">Orbitali</a> plugin. It has headings, a list, a link, and a table.</p><ul><li>Headings</li><li>Lists</li><li>Links</li><li>Tables</li></ul><table><tr><th>Feature</th><th>Status</th></tr><tr><td>Preview text</td><td>Supported</td></tr><tr><td>Sync to Orbitali</td><td>Supported</td></tr></table>'
fi

echo ""
echo "================================================================"
echo " Orbitali test site ready: ${WP_SITE_URL:-http://localhost:8080}"
echo " Admin login:   ${WP_ADMIN_USER:-admin} / ${WP_ADMIN_PASSWORD:-admin}"
echo " Sample page:   ${WP_SITE_URL:-http://localhost:8080}/orbitali-sample-page/"
echo " Plugin settings: ${WP_SITE_URL:-http://localhost:8080}/wp-admin/options-general.php?page=orbitali-settings"
echo "================================================================"
