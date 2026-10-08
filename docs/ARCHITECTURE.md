# Orbitali for WordPress - Architecture Notes

This document records what was confirmed by reading the Orbitali backend source
(`apps/api` in the `platform` monorepo, as of this plugin's initial development), the
gaps that design decisions below are compensating for, and how the plugin's state
machine works. It is the source of truth for *why* the code is shaped the way it is.

## 1. Confirmed API contract

Base URL (production): `https://api.orbitali.ai/public/v1` (from
`infra/k8s/ingress.yaml` / `ORBITALI_PLATFORM.md`; mounted in the backend as
`routes/public/index.ts`, prefix `/public/v1`).

Authentication: `Authorization: Bearer <key>` where `<key>` is an API key created from
the Orbitali dashboard (Settings → API keys, session-authenticated only - **API keys
cannot be created or managed via the public API itself**, so this plugin only ever
*consumes* a key the admin already has). Keys are validated against a hash in
`db/api-keys.ts::requireApiKey`; there is no scoping below "the whole organization/
account" - a key can see and modify every agent and knowledge document the account
owns.

Endpoints this plugin uses (all under the base URL above):

| Method | Path | Used for |
|---|---|---|
| GET | `/agents` | List agents accessible to the key (Connect validation, settings page) |
| POST | `/agents` | Create a new agent (`agentType: "static"`, `promptType: "static"`) |
| GET | `/agents/:id` | Confirm an agent is still accessible before selecting it |
| GET | `/agents/:id/knowledge` | List documents (verify-ready check, duplicate-upload guard) |
| POST | `/agents/:id/knowledge` | Create a document (`{ name?, description?, content }` JSON body) |
| DELETE | `/agents/:id/knowledge/:documentId` | Delete a document (withdrawal, replacement cleanup) |

Confirmed backend behavior that directly shaped the plugin:

- **Agent creation always starts as `status: "draft"`**, regardless of what the
  request sends (`createAgentWithPrompt` in `db/agents.ts` hard-codes
  `status: "draft"`). The active-agent plan limit (`AgentLimitExceededError`) is only
  enforced on *activation*, not creation, so creating a draft agent from this plugin
  can never fail due to plan limits.
- **Knowledge document creation is synchronous end-to-end.** `createKnowledgeDocument`
  in `db/knowledge-documents.ts` always inserts the row with `status: "ready"` -
  chunking + Vertex AI embedding (`services/knowledge-ingestion.ts`) happens inline,
  sequentially per chunk, before the HTTP response is returned. There is currently no
  "upload now, index later" async state on the backend. The `knowledgeDocumentSchema`
  still defines `processing` and `failed` statuses, which this plugin's verification
  step still checks for, defensively, in case that becomes asynchronous later.
- **No update/upsert for knowledge documents via the public API.** Only `POST` (create)
  and `DELETE` exist at `/public/v1/agents/:id/knowledge*`. (A `PUT
  /v1/agents/:id/knowledge/:documentId` for renaming metadata exists, but it's on the
  session-cookie-authenticated `/v1/agents` router, not reachable with an API key.)
- **Document name/description limits:** `name` ≤ 120 chars, `description` ≤ 500 chars,
  `content` 1 to 1,000,000 chars (`createKnowledgeDocumentRequestSchema`). There is no
  separate "file size" limit for the JSON-content path this plugin uses - the 1MB
  character cap on `content` is the effective ceiling.
- **No rate limiting observed** on the public API routes in the backend source (some
  unrelated chat/builder features do rate-limit, knowledge/agent routes do not). The
  client still handles HTTP 429 defensively in case that changes.
- **Agent fields relevant to a "static, knowledge-only" agent:** `agentType: "static"`
  agents cannot have custom tools and are forced to `promptType: "static"` server-side
  even if a different value is sent. `language` and `voiceName` are free-form strings
  validated only by length (2-16 and 1-80 chars respectively) - there is no
  server-side enum. The curated dropdown options in Settings mirror the values
  Orbitali's own dashboard currently offers (`eve`/`ara`/`rex`/`sal`/`leo` voices;
  `en-US`/`es-ES`/`es-US`/`it-IT` languages) purely so the UI doesn't make users guess;
  any other value the API accepts would still work.

## 2. Material API gaps and how the plugin compensates

These are real limitations found by reading the backend, not assumptions:

1. **No organization identity endpoint reachable with an API key.** `/public/v1` has
   no `/organization` or `/account` route; `account.display_name` exists in the
   database but is never exposed to API-key clients. **Compensation:** the Settings
   page cannot show "Connected organization: Acme Corp." It shows connection validity
   (via a successful `GET /agents` call) and the selected agent instead, with an
   explicit note explaining why no organization name is shown. If Orbitali adds such
   an endpoint later, this is the one place to wire it in
   (`Orbitali_Settings_Page::build_view_data()`).
2. **No update/upsert for knowledge documents.** **Compensation:** every content or
   slug change is a *replace*: upload new → verify ready → delete old (see §4). This
   plugin never claims the replacement is atomic, because it isn't - there is a window
   where both the old and new document exist and could both be retrieved by the agent.
3. **No idempotency key on document creation.** If the WordPress-to-Orbitali HTTP
   request to create a document times out or the connection drops *after* Orbitali
   processed it but *before* the response reaches WordPress, there's no server-provided
   way to know whether a document was actually created. **Compensation (best-effort,
   not a guarantee):** before uploading, the sync job lists existing documents for the
   agent and adopts one with a matching name created in the last 10 minutes instead of
   creating a new one. This reduces, but cannot eliminate, the chance of a duplicate
   document after a network timeout. This is documented as a known limitation, not
   silently "handled."
4. **Document creation can be slow and offers no status callback.** Chunking +
   sequential embedding calls for a large document can take a long time. Because this
   happens inside the HTTP request/response cycle on Orbitali's side, and because
   nothing pushes a webhook back to WordPress when it's done, this plugin runs the
   upload out-of-band via WP-Cron (see §3) instead of blocking the editor, and polls
   `GET .../knowledge` afterward to confirm the resulting document's `status`.

## 3. Why uploads run through WP-Cron, not inline

The task requires "avoid long blocking requests in the editor." Given gap #4 above,
a direct synchronous `wp_remote_post` from the editor's save/sync click could block a
PHP worker for a long time on a large document. So:

1. Clicking **Sync** in the editor calls `POST /orbitali/v1/sync` (a WordPress REST
   route this plugin registers, not an Orbitali route). The handler validates
   eligibility, computes a content hash, and - if there's actually something to do -
   persists a `queued` status and schedules a **single WP-Cron event**
   (`wp_schedule_single_event` + `spawn_cron()`) instead of calling Orbitali directly.
   It returns immediately.
2. The scheduled event runs `Orbitali_Sync_Manager::run_sync_job()` in its own request,
   with its own timeout budget, performing the actual create → verify → delete-old
   flow against Orbitali.
3. The editor panel polls `GET /orbitali/v1/status/:post_id` every ~3 seconds until the
   status reaches a terminal state (`synced`/`failed`), capped at ~5 minutes of
   polling.

**Known limitation:** WP-Cron is pseudo-cron - it only fires on a site visit (or via
`spawn_cron()`'s loopback request, which some locked-down hosts block). On a very
low-traffic site with loopback requests disabled, a queued sync may sit until a real
visitor hits the site. The README's Troubleshooting section recommends a real server
cron hitting `wp-cron.php` for anyone who needs tighter timing. Deletes (withdrawal,
cleanup-of-old-document, manual Remove) are fast single calls and run synchronously,
since the spec requires withdrawal to be confirmed before being reported as successful
- an async "removed" status users can't trust would be worse than a short wait.

## 4. Identity, naming, and the replace-not-update flow

**Identity is never the slug or document name.** Every mapping is keyed by
`(WordPress post ID, Orbitali agent ID) → { document_id, content_hash, status, ... }`,
stored in one post-meta array (`_orbitali_mappings`) so a single post can carry
mappings for more than one agent across time (see §5). The document **name** sent to
Orbitali is purely cosmetic, built as:

```
<hierarchical-slug-path> (wp-<post ID>)
```

e.g. `about/team (wp-42)`. The `(wp-<ID>)` suffix is always present, so two posts can
never collide on name even if their slugs happen to match (a Page and a Post can both
be `/about/` in WordPress). An empty slug falls back to `untitled (wp-<ID>)`. Names
longer than 120 characters (the API's limit) are truncated from the left, keeping the
most specific (rightmost) path segment and the ID suffix intact.

**Content hash** covers `{ name, title, canonical URL, extracted body }` -
deliberately *excluding* the "Last updated" line embedded in the uploaded document
body, because WordPress bumps `post_modified` on every save regardless of whether
anything meaningful changed; hashing it would mark every resave as "changes pending"
even with identical content. A slug rename does change the hash (via `name`), which
correctly triggers a replace even if the body text is untouched - matching "a changed
slug must rename or replace the document without leaving an orphan."

**Replace flow** (first sync is the same flow with no old document to clean up):

1. Extract content from the currently saved post; compute the hash. If it matches the
   last-synced hash and a current document still exists, do nothing (skip
   unchanged-content uploads).
2. `POST .../knowledge` with the new content. Persist the returned document ID as
   `pending_document_id` **immediately** - before anything else - so a crash or
   timeout after this point has something to resume from on retry.
3. `GET .../knowledge`, find that ID, confirm `status === "ready"` (today this is
   already true by the time step 2 returns; the check exists for forward
   compatibility if Orbitali's ingestion becomes async). If it comes back `failed`,
   delete the bad document and report failure - **the previous working document is
   left untouched**, satisfying "preserve the last working document if the new upload
   fails."
4. `DELETE` the previous document ID (if any). Success or 404 (already gone) both
   count as clean. On any other failure, the **new** document is still committed as
   current (its content is correct and live), but the old ID is kept as
   `stale_document_id` with a visible "pending cleanup" note. The next click of
   **Sync** (or a dedicated retry) attempts that delete again before doing anything
   else. This is the honestly-documented "temporary overlap": between steps 2 and 4,
   and for however long cleanup stays pending after a failed step 4, the agent could
   retrieve from both the old and new document.

**Concurrency / duplicate clicks:** a WordPress transient lock
(`orbitali_lock_<post_id>_<agent_id>`, 10-minute TTL) is acquired before step 1 and
held until the whole flow (including the async cron hop) finishes. A second click,
retry, or overlapping request while the lock is held is told "a sync is already in
progress" instead of starting a second flow.

## 5. Why mappings are per-agent, not global

The destination agent is a single global WordPress setting, but it can change over
time, and the API key (and therefore the Orbitali account/organization) can be swapped
independently. Storing mappings keyed by agent ID means:

- Switching back to a previously-used agent reconnects to its existing document
  instead of re-creating a duplicate.
- Switching to a *different* agent (or a different account's key, where by coincidence
  an old agent ID doesn't exist or isn't visible) leaves the old mapping's data alone -
  it simply becomes inert (any attempt to act on it will 404 against the new key and
  surface that clearly) rather than being deleted or silently adopted.
- This plugin only ever touches documents it created and is tracking in
  `_orbitali_mappings`. It never lists an agent's full knowledge base and matches by
  name to "adopt" or delete something it didn't create.

## 6. Content extraction

Deterministic, DOM-based, no LLM involved:

1. Start from the post's saved `post_content` (never unsaved editor state).
2. Every *registered* WordPress shortcode tag is checked against an allowlist
   (Divi's `et_pb_*` tags, plus the core `caption` shortcode). Any tag **not** on the
   allowlist is removed via regex *before* `do_shortcode()` runs - its PHP callback is
   never invoked. For a shortcode that wraps content (`[tag]text[/tag]`), the inner
   text is kept; for a self-closing one, it's dropped entirely and reported as an
   "excluded unsupported shortcode" warning in Preview.
3. `do_blocks()` expands block-editor content (core static blocks already store their
   rendered HTML inline in `post_content`; this also expands dynamic core blocks).
   `do_shortcode()` then expands only the allowlisted tags (in practice: Divi's
   builder, if the site uses it). `wptexturize()` is deliberately skipped - its
   cosmetic quote/dash substitutions aren't needed and would make content hashes less
   predictable. The result is passed through `wpautop()`.
4. The resulting HTML is parsed with PHP's built-in `DOMDocument`. `<script>`,
   `<style>`, `<nav>`, `<footer>`, `<form>`, `<header>`, `<iframe>`, form controls,
   `<svg>`, `<audio>`/`<video>`, and `<aside>` subtrees are removed outright.
5. The remaining tree is walked recursively into Markdown: `h1`-`h6` → `#`...`######`,
   `p`/`div` → paragraph breaks, `ul`/`ol`/`li` → `-`/`1.` lists, `a[href]` →
   `[text](url)` (root-relative links are resolved against `home_url()`), `table` →
   GitHub-flavored Markdown tables, `blockquote` → `>` lines, `strong`/`b` → `**bold**`,
   `em`/`i` → `_italic_`. Anything else is flattened (its text kept, no markdown
   marker) rather than dropped, so unknown wrapper markup doesn't lose real content.
6. Empty extraction (nothing readable found) is reported explicitly, both in Preview
   and as a hard stop before Sync - the plugin refuses to upload an empty document
   (which the backend would reject anyway).

The uploaded document is `# <title>\n\nSource: <permalink>\nLast updated: <ISO
date>\n\n<extracted body>`.

## 7. What "withdrawal" does and doesn't do

Hooked on `wp_trash_post`, `before_delete_post`, and `save_post` (when a tracked post's
status/password makes it no longer publicly viewable). For every agent the post has a
live document with, it calls `DELETE .../knowledge/:documentId`. Success or 404 both
clear the mapping to `not_synced`. Any other failure sets status `withdrawal_failed`
with the error preserved, shows an admin notice, and leaves the document ID in place
so the next automatic or manual attempt can retry - it is never reported as removed
until a delete call actually confirms it (success or already-gone).

Deactivating or deleting the plugin itself **never** triggers remote deletion -
`uninstall.php` only removes this plugin's own WordPress-side options/post-meta.
Clearing out an agent's knowledge base is something the admin does deliberately, not a
side effect of removing the plugin.

## 8. Security notes

- The API key never leaves the server after it's first saved: the settings page always
  renders a masked value server-side; no REST/AJAX response ever includes the raw key;
  it is never written to PHP error logs by this plugin's own code (the HTTP client
  only surfaces the transport-level `WP_Error` message on network failure, which never
  contains request headers/body).
- Every admin-ajax action (`orbitali_connect`, `orbitali_select_agent`,
  `orbitali_create_agent`, `orbitali_disconnect`) checks `current_user_can(
  'manage_options' )` **and** a nonce (`check_ajax_referer`) - the nonce alone is never
  treated as authorization.
- Every editor REST route checks `current_user_can( 'edit_post', $post_id )` for the
  *specific* post ID in the request, not just a blanket editor capability, and is
  restricted to the allowed post types.
- Outbound requests set `sslverify => true` and target a base URL that is either the
  known production default or a value from a PHP constant
  (`ORBITALI_API_BASE_URL`) an admin sets in `wp-config.php` - not a free-text UI field
  - so the destination host stays operator-controlled.

## 9. Known limitations (plain summary)

- No automatic/scheduled sync - manual only, by design.
- Best-effort, not guaranteed, protection against duplicate documents from a network
  timeout during upload (no backend idempotency key exists to rely on).
- Replacement is not atomic; there is a real (usually brief) window where both the old
  and new document can be retrieved by the agent, and a longer one if old-document
  cleanup fails and hasn't been retried yet.
- Organization name cannot be displayed (not exposed by the API to key-authenticated
  clients).
- Only Divi is specifically supported among page builders; other builders' shortcodes
  are stripped (not executed), degrading extraction quality for sites using them.
- OCR, PDFs, and image-only text are out of scope.
- WP-Cron timing depends on site traffic/loopback requests unless the host runs a real
  cron against `wp-cron.php`.
