# Testing with Docker

This spins up a throwaway WordPress site with the plugin already mounted and
activated, so you can click through Settings → Orbitali and the editor panel
yourself. It does not build a custom image - it composes the official
`wordpress` and `wordpress:cli` images and bind-mounts `orbitali/` straight
into `wp-content/plugins/orbitali`, so edits to the plugin source show up on
page reload with no rebuild step.

## Start it

```bash
docker compose up -d
docker compose logs -f cli   # watch setup finish, then Ctrl-C
```

The `cli` service waits for the database and for WordPress's own entrypoint
to generate `wp-config.php`, then runs `wp core install`, activates the
plugin, turns on pretty permalinks, and creates a sample page with headings,
a list, a link, and a table so there's something to Preview/Sync
immediately.

Once the `cli` container exits (check with `docker compose ps` - it should
show `Exited (0)`), open:

- Site: http://localhost:8080
- Admin: http://localhost:8080/wp-admin (`admin` / `admin` by default)
- Plugin settings: http://localhost:8080/wp-admin/options-general.php?page=orbitali-settings
- Sample page: http://localhost:8080/orbitali-sample-page/

Override the port or admin credentials by copying `.env.example` to `.env`
first - none of those values are secrets, they only configure the local
throwaway database and admin account.

## Background sync jobs and WP-Cron

Clicking **Sync** on a page queues the actual upload as a WP-Cron job (see
`docs/ARCHITECTURE.md` §3 for why). WordPress's normal way of firing that -
a loopback HTTP request the PHP process makes to its own site URL - is
unreliable in Docker: this container's own site URL is the *published* host
and port (e.g. `http://localhost:8080`), but nothing inside the container
listens on that port; only Apache's internal port 80 does. That mismatch
varies by Docker Desktop/Engine version and isn't something a plugin can
reliably paper over.

Rather than fight that, this stack runs a dedicated `cron-runner` service
that executes due WP-Cron jobs directly every 5 seconds
(`wp cron event run --due-now`), independent of the loopback mechanism. This
is the containerized equivalent of the real-server-cron setup the README's
troubleshooting section recommends for a production site whose host blocks
loopback requests. If you ever see a page stuck on "Processing" for more
than a few seconds, check `docker compose logs cron-runner` and confirm the
service is running (`docker compose ps`).

## What you can actually test this way

- The full connection flow in Settings → Orbitali, **if you have a real
  Orbitali API key** - this container only provides WordPress; it still
  makes real outbound HTTPS calls to `api.orbitali.ai`. Nothing here mocks
  Orbitali itself.
- The editor panel (block editor and classic editor - install the Classic
  Editor plugin via `wp plugin install classic-editor --activate` inside the
  `wordpress` container if you want to check that path too) on the sample
  page or any page/post you create.
- Content extraction on your own content - create a page with the builder/
  markup you care about and use **Preview text** before syncing.
- Withdrawal behavior - trash, unpublish, or password-protect a previously
  synced page and watch the Orbitali panel/admin notice.

Without a real API key you can still verify the UI renders correctly, the
settings page's "Connect" button reports an error for a bad key, and that no
request related to Orbitali is ever made for content that isn't
published/public.

## Divi

This image does not include Divi (it's a paid theme/plugin). If you have a
Divi license, drop the extracted plugin/theme into the shared `wp_data`
volume - the simplest way is to copy it into the running container:

```bash
docker compose cp /path/to/Divi.zip wordpress:/tmp/divi.zip
docker compose exec wordpress wp plugin install /tmp/divi.zip --activate --allow-root
# or, for the Divi theme:
docker compose exec wordpress wp theme install /tmp/divi.zip --activate --allow-root
```

Then build a page with the Divi builder and use **Preview text** to confirm
extraction looks right before syncing.

## Resetting

```bash
docker compose down -v   # also deletes the database/WordPress volumes
docker compose up -d
```

## Running the PHPUnit suite instead

This Docker setup is for manual/browser testing. The automated test suite
(`orbitali/tests/`) mocks every Orbitali HTTP call and doesn't need a running
site - see the "Running tests" section in the root `README.md`. It needs a
local PHP + Composer environment (or its own container, not included here)
rather than this docker-compose stack.
