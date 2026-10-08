=== Orbitali ===
Contributors: orbitali
Tags: orbitali, voice agent, knowledge base, ai, sync
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Sync selected pages and posts into an Orbitali voice agent's knowledge base.

== Description ==

Orbitali connects your WordPress site to an [Orbitali](https://orbitali.ai) voice
agent's knowledge base. Connect an API key, choose (or create) a destination agent,
then sync individual pages and posts on demand from a panel right in the editor.

**What this plugin does:**

* Validates your Orbitali API key and lists the agents it can access.
* Lets you create a new static, knowledge-only draft agent if you don't have one yet.
* Adds an Orbitali panel to the block editor and the classic editor for Pages and
  Posts, with Preview, Sync, and Remove actions.
* Extracts clean, readable Markdown from published content - headings, paragraphs,
  lists, links, and tables - while stripping scripts, navigation, footers, and forms.
* Understands Divi builder content (`et_pb_*` shortcodes). Other page builders are not
  specifically supported; always use Preview first.
* Tracks every sync by stable WordPress post ID and Orbitali document ID, so renamed
  slugs, switched agents, and switched API keys never silently overwrite or orphan
  content.
* Automatically withdraws a page's document from Orbitali if it's later trashed,
  deleted, unpublished, or password-protected - and tells you clearly if that removal
  fails, instead of pretending it succeeded.

**What this plugin deliberately does not do (yet):**

* No phone number assignment or agent activation - new agents stay in "draft."
* No automatic/background sync on every save - syncing is always a manual click.
* No bulk site crawling.
* No OCR, PDF ingestion, or image-only text.
* No guaranteed support for page builders other than Divi.

Your Orbitali API key is stored only in your WordPress database and is never sent to
the browser, logged, or exposed through any public endpoint.

== Installation ==

1. Upload the plugin through **Plugins → Add New → Upload Plugin**, or extract it into
   `wp-content/plugins/orbitali`.
2. Activate the plugin.
3. Go to **Settings → Orbitali**, enter your API key, and click Connect.
4. Select or create a destination agent.
5. Open a published page or post and use the Orbitali panel to Preview and Sync.

== Frequently Asked Questions ==

= Does this sync automatically whenever I update a page? =

No. Syncing is manual by design. Edit and save your page, then click **Sync to
Orbitali** again to push the update.

= What happens if I unpublish or delete a synced page? =

The plugin automatically tries to remove the corresponding document from Orbitali. If
that removal fails, you'll see an admin notice and a retryable status in the item's
Orbitali panel - it is never reported as removed unless it's confirmed.

= Which page builders are supported? =

Core WordPress blocks, classic editor HTML, and Divi's own builder shortcodes. Other
builders' shortcodes are not executed during extraction (to avoid running arbitrary
third-party code), so their output will not be reproduced faithfully. Always check
**Preview text** before syncing.

= Where is my API key stored? =

In the WordPress options table on your own server. It is never sent to the browser
after the first save (only a masked version is shown), never logged, and never
returned by any REST or AJAX response.

== Changelog ==

= 0.1.0 =
* Initial MVP: connection settings, agent selection/creation, editor panel sync,
  Divi-aware extraction, withdrawal on unpublish/trash/delete.
