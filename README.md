# Orbitali for WordPress

Sync selected WordPress pages and posts into an [Orbitali](https://orbitali.ai) voice
agent's knowledge base, directly from the WordPress admin - no middleman server, no
MCP, no Codex. The plugin talks to Orbitali's public API over plain server-side HTTP
requests using the WordPress HTTP API (`wp_remote_request`).

This repository contains the plugin source (`orbitali/`), a release build script
(`bin/build-zip.sh`), and architecture notes (`docs/ARCHITECTURE.md`).

## Status

MVP. Read `docs/ARCHITECTURE.md` for the full list of confirmed API behavior, known
backend gaps, and scope decisions made because of them.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- An Orbitali account and an API key (Orbitali dashboard → Settings → API keys)
- Outbound HTTPS access from your WordPress server to `api.orbitali.ai`

## Installation

1. Download the latest release ZIP (or build one yourself, see below).
2. In WordPress admin: **Plugins → Add New → Upload Plugin**, upload the ZIP, Activate.
3. Go to **Settings → Orbitali**.

## Setup

1. Paste your Orbitali API key and click **Connect**. The plugin validates it
   immediately by listing the agents it can access.
2. Pick an existing agent as the sync destination, or click **Create new agent**.
   New agents are created as a **static, knowledge-only, draft** agent - phone number
   assignment and activation happen later, in the Orbitali dashboard, outside this
   plugin's scope.
3. The API key is stored only in the WordPress database on your server. After saving,
   the UI only ever shows a masked version (`••••••••1a2b`); the full key is never sent
   back to the browser, logged, or included in any REST/AJAX response.
4. Changing the API key re-validates it immediately. If the new key can't see the
   previously selected agent, the destination selection is cleared and you'll need to
   pick again.

## Using it on a page or post

Open any **published, public** page or post. You'll see an **Orbitali** panel - in the
block editor's Document sidebar, or as a meta box in the classic editor:

- **Preview text** - shows exactly the Markdown that would be uploaded, extracted from
  the content currently saved in the database. It never includes unsaved edits sitting
  in the editor; save/update the post first if you want to preview your latest changes.
- **Sync to Orbitali** - uploads (first time) or replaces (subsequent times) the
  document mapped to this page/post for the currently selected agent.
- **Remove from Orbitali** - deletes the mapped document from the agent's knowledge
  base. This is a deliberate action independent of publishing changes.

Syncing is **manual only**. Editing and re-saving a page does not automatically
re-sync it - click **Sync to Orbitali** again after making changes you want reflected.

If a synced page is later trashed, permanently deleted, unpublished, or password
protected, the plugin automatically attempts to remove the corresponding document from
Orbitali. If that removal fails (network issue, Orbitali unavailable), you'll see an
admin notice and the item's Orbitali panel will show a retryable "Removal failed"
status - it is never silently reported as removed.

## What gets sent, when, and where credentials live

- **What's sent to Orbitali:** page/post title, canonical permalink, last-modified date,
  and a cleaned Markdown rendering of the visible page content (headings, paragraphs,
  lists, links, tables). Scripts, styles, navigation, footers, forms, and other
  layout-only markup are stripped before anything leaves the server. See
  `docs/ARCHITECTURE.md` for exactly how extraction works.
- **When:** only when an administrator/editor clicks **Sync to Orbitali** (or triggers
  an automatic withdrawal by un-publishing/trashing/deleting a previously-synced item).
  Nothing is sent on a schedule and nothing is sent just because a page was viewed or
  saved.
- **Where credentials live:** the Orbitali API key is stored as a WordPress option on
  your own server's database. It is never embedded in any JavaScript, theme output, or
  public REST response, and is never written to PHP error logs by this plugin.

## Content extraction support

- **Block editor (Gutenberg):** core blocks render through WordPress's normal block
  rendering, then get converted to Markdown.
- **Classic editor:** plain HTML content is supported directly.
- **Divi:** Divi's own builder shortcodes (`et_pb_*`) are allowed to render, since
  that's the page builder this MVP was built and tested against.
- **Other page builders are not specifically supported.** Any other shortcode found in
  the content is *not* executed - its markup is stripped but its plain enclosed text (if
  any) is kept, so you don't silently lose authored text, but builder-specific rendering
  (buttons, columns, sliders, etc. from Elementor, WPBakery, etc.) is not reproduced.
  **Always use Preview before syncing** to confirm the extraction looks right for your
  theme/builder combination.
- PDFs, OCR, and text embedded only in images are out of scope for this MVP.

## Building a release ZIP

```bash
bin/build-zip.sh            # writes build/orbitali-<version>.zip
```

The script copies `orbitali/`, strips dev-only paths (`tests/`, VCS metadata, env
files), and zips the result. No credentials or development-only files are ever
included; the plugin ships with zero bundled dependencies beyond WordPress core.

## Running tests

```bash
cd orbitali
composer install   # pulls in the WordPress PHPUnit test scaffolding, dev-only
phpunit
```

Tests mock every Orbitali HTTP call via WordPress's `pre_http_request` filter - no real
Orbitali account, network access, or production data is touched. See
`docs/ARCHITECTURE.md` for the list of scenarios covered and what still needs a real
WordPress + Orbitali account to verify by hand.

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| "The Orbitali API key is invalid or has been revoked." | Key was typed wrong, revoked, or belongs to a deleted account. Re-enter it. |
| Sync stays on "Processing" for a long time | Large documents are embedded chunk-by-chunk by Orbitali and this plugin uploads via WP-Cron in the background. If your host blocks WP-Cron's loopback request or has very infrequent site traffic, set up a real server cron hitting `wp-cron.php` on a short interval. |
| "Synced, but removing the previous version failed…" | The new content is already live on Orbitali; only cleanup of the old copy failed. Click **Sync** again to retry cleanup, or remove the old document manually from the Orbitali dashboard. |
| Orbitali panel doesn't appear | Confirm the post type is Page or Post (the only types enabled by default - use the `orbitali_allowed_post_types` filter to add more) and that your user has edit rights on that specific item. |
| Nothing/garbled text in Preview | Your theme or page builder likely renders content in a way this MVP's extractor doesn't understand. Divi is explicitly supported; other builders are not. |

## License

MIT - see `LICENSE`.
