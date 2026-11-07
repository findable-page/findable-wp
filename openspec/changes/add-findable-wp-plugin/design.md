# Design: add-findable-wp-plugin

## Context
- API: `POST {endpoint}/api/scan` with `{ url, keyphrase? }`, 10 scans/min/IP, public targets only, no auth today (see `openspec/project.md`).
- A full scan response carries about 160 checks across `seo` (98), `aeo` (35), `geo` (27). Check objects have `id`, `label`, `pass`, optional `applicable`, `severity`, `category`. In the current Worker `severity` and `category` are not populated; the plugin must not depend on them.
- The scan fetches the **public permalink**, so it scores what is live, never unsaved editor state.
- Prototype 0.2.0 stored `_findable_scan` post meta with failing checks only and offered "Fix All" head-tag injection.

## Goals / non-goals
Goals: correct scores with clear fix advice per post, a site overview, background scanning that respects the rate limit, and a plugin that passes WordPress.org review and Plugin Check on the first submission.
Non-goals: see proposal.

## Architecture
Small set of classes under `includes/`, namespace `Findable\`, Composer classmap autoload in dev, a hand-written `require` list in production (no Composer runtime dependency).

```
findable.php                 bootstrap: headers, constants, requires, hooks
uninstall.php                cleanup
includes/
  class-api-client.php       Api_Client: scan(), health(), check_text($lang)
  class-result.php           Result: normalize(array $response): array|WP_Error, pure
  class-store.php            Store: get/save/stale per post, aggregate for dashboard
  class-fix-advice.php       Fix_Advice: for($check_id, $locale): string
  class-settings.php         Settings API registration, sanitize callbacks, getters
  class-rest.php             REST routes findable/v1
  class-bulk-scan.php        Cron queue
  class-admin.php            menus, dashboard page, list-table column, meta box, notices
  class-migrate.php          0.2.0 -> 1.0 data migration
data/fix-advice-en.php       bundled English advice map (check id => text)
src/editor/index.js          block editor sidebar (built to build/)
src/admin/bulk.js            dashboard bulk-scan progress
languages/                   empty; translate.wordpress.org supplies packs
```

`Result::normalize()` and `Fix_Advice` are pure and unit-testable without HTTP. Everything that does I/O is at the edges (`Api_Client`, `Store`, `Bulk_Scan`).

## Data model
Post meta (all prefixed `_findable_`, protected, `show_in_rest` false):

| Key | Type | Purpose |
|---|---|---|
| `_findable_result` | array | Normalized scan result (below). |
| `_findable_overall` | int | Copy of overall score for `meta_query`/`orderby` sorting. |
| `_findable_scanned_at` | int | Unix time of last successful scan, for freshness and sorting. |
| `_findable_keyphrase` | string | Optional keyphrase, max 100 chars (same key as 0.2.0). |
| `_findable_error` | array | Last failed scan `{ code, message, at }`; cleared on success. |

Normalized result:
```php
array(
  'v'          => 1,                 // schema version
  'url'        => 'https://...',     // URL actually scanned
  'overall'    => 72,
  'families'   => array( 'seo' => array( 'score' => 80, 'passed' => 70, 'total' => 98 ), 'aeo' => ..., 'geo' => ... ),
  'checks'     => array( array( 'id' => 'title_length', 'f' => 'seo', 'label' => '...', 's' => 'fail' ), ... ), // s: pass|fail|na
  'keyphrase'  => 'example',
  'scanned_at' => 1767225600,
  'endpoint'   => 'https://findable.page',
  'api_scanned_at' => '2026-...Z',
)
```
Storing all checks (not just failing) is required for the breakdown. Size is about 12-16 KB per post, acceptable for postmeta. Hard caps: 400 checks, labels 200 chars.

Options (all `autoload` = false except `findable_settings`):

| Option | Content |
|---|---|
| `findable_settings` | endpoint, post_types, cache_ttl_days, bulk_per_minute, scan_on_publish, delete_data_on_uninstall |
| `findable_api_key` | secret, separate option, never autoloaded, never rendered back |
| `findable_bulk` | bulk state `{ status, cursor, done, failed, total, started_at, force, next_at, last_error }` |
| `findable_db_version` | migration marker |

Transients: `findable_check_text_{lang}` (7 days), `findable_dashboard` (aggregate, 12 h, deleted on any result write), `findable_bulk_lock` (lock).

**Why postmeta, not a custom table:** native, survives export/import, no schema migrations. The dashboard aggregate needs a full pass over results; it runs in batches of 200 and is cached. Upgrade path: custom table when sites with more than about 20k scanned posts report slow dashboards.

## API client
- Single method per endpoint. All calls use `wp_safe_remote_post` / `wp_safe_remote_get` (blocks private IPs, enforces ports) with `timeout` 30 s, `limit_response_size` 1 MB, `redirection` 0, `User-Agent: Findable-WP/{version}; {home_url}`.
- The safe HTTP API only allows ports 80, 443 and 8080 and public IPs. Self-hosted endpoints on other ports, localhost or a LAN (`findable -serve 127.0.0.1:8080`) need `define( 'FINDABLE_ALLOW_PRIVATE_ENDPOINT', true )` in `wp-config.php`, which switches to `wp_remote_*`. A constant, not a UI toggle, because it weakens SSRF protection.
- API key: sent as `Authorization: Bearer {key}` only when non-empty and the endpoint is `https` (or private endpoint allowed). Constant `FINDABLE_API_KEY` overrides the option.
- Error mapping to `WP_Error` codes: `findable_http` (transport), `findable_rate_limited` (429, carries `retry_after` seconds, default 60), `findable_blocked` (400 with error text, e.g. non-public host), `findable_server` (5xx), `findable_bad_response` (non-JSON or failed validation). The API's `error` string is shown only after `sanitize_text_field` and length cap 300.

## Fix advice
- `Fix_Advice::for( $id, $locale )`: WP locale `de_DE` maps to `de`; if the API serves that language, use `fix` from cached `/i18n/{lang}.json`, else bundled English, else generic text "See the full report on findable.page for how to fix this check."
- Bundled English map is generated from `findable-page/web/index.html` `fixAdvice` by a dev script (`bin/sync-fix-advice.php`), committed, MIT attribution in `readme.txt`. A CI job does not fetch it live.
- Translated check names from `/i18n` replace API `label` only for display; stored labels stay English.

## REST API (`findable/v1`)
| Route | Method | Permission | Purpose |
|---|---|---|---|
| `/posts/(?P<id>\d+)/scan` | POST | `edit_post` on id | Scan now, args `keyphrase` (≤100), returns result |
| `/posts/(?P<id>\d+)/result` | GET | `edit_post` on id | Stored result + fix advice + freshness |
| `/bulk` | GET | `manage_options` | Bulk state |
| `/bulk` | POST | `manage_options` | Start (`force` bool) or `action` `pause`/`resume` |
| `/bulk` | DELETE | `manage_options` | Cancel |
| `/connection` | POST | `manage_options` | Test endpoint via `GET /health` |

REST uses the `wp_rest` nonce through `@wordpress/api-fetch`. The classic meta box posts to `admin-post.php` with its own nonce. No `wp_ajax_*` handlers.

## Editor UI
- Block editor: `PluginSidebar` (icon + "Findable" in the More menu) with overall score, three family scores, filter tabs (Failing / Passing / Not applicable), checks grouped by family, fix advice under each failing check, keyphrase field, "Scan now", freshness line and link to the full report on findable.page (`https://findable.page/{host}{path}`, the existing deep link).
- Warns when the post is not published ("Publish first, Findable scans the live URL") and when the editor has unsaved changes ("Update the post to score your latest edits").
- Classic editor and post types without block editor: meta box with the same data rendered in PHP, failing checks first, passing collapsed in `<details>`.
- List tables: sortable "Findable" column (overall score, colour band plus text, `-` when never scanned, "stale" marker).
- Score bands, shared by all views: 0-49 poor, 50-79 needs work, 80-100 good. Colour is never the only signal (text label always present).

## Dashboard
Top-level menu "Findable" (capability `edit_others_posts`) with submenus Dashboard and Settings (`manage_options`).
Content: scanned / scannable / stale counts, average overall and per family, distribution across the three bands, 10 lowest-scoring posts, 10 most common failing checks with affected post count and fix advice, bulk scan panel (start, rescan all, cancel, progress bar, ETA, last error). Server-rendered; only the bulk panel polls REST every 10 s while running.

## Bulk scan
- Not a real queue table. State holds a **cursor** (last processed post ID) and walks scannable posts with `ID > cursor ORDER BY ID ASC`, so 50k posts cost no memory and new posts join naturally.
- One single event `findable_bulk_tick` at a time (`wp_schedule_single_event`). Each tick: take lock (`add_option` style atomic transient, 120 s), scan up to `bulk_per_minute` posts (default 5, max 8), stop early after 40 s wall time, save state, schedule next tick for `+60 s`.
- Skips posts with a fresh result unless `force`.
- 429: do not advance the cursor, schedule next tick at `+retry_after` (min 60 s), record `last_error`. Five consecutive transport, 5xx or unauthorized failures pause the run (`status = paused`) with a dashboard notice.
- Per-post failures (blocked, bad response) are recorded on the post (`_findable_error`), counted in `failed`, and the cursor moves on.
- Completion sets `status = done`, deletes the dashboard transient.
- WP-Cron needs traffic; Settings and the dashboard show a hint when `DISABLE_WP_CRON` is true or the next tick is overdue by more than 10 minutes, linking to the WordPress docs on real cron.
- "Scan on publish/update" (opt-in, default off) schedules a single `findable_scan_post` event 60 s after a scannable post is published or updated, deduplicated per post.

## Freshness rule
A result is **fresh** when `scanned_at >= post_modified_gmt` and age < `cache_ttl_days` (default 7, range 1-90) and the endpoint matches the current setting. Otherwise **stale**. Stale results are still shown, labelled with age. A manual scan always calls the API (no read-through cache) but the REST route rejects a second scan of the same post within 10 s (`findable_too_soon`) to stop double clicks burning the rate limit.

## Migration from 0.2.0
On `plugins_loaded` when `findable_db_version` < 1: for each post with `_findable_scan`, convert to `_findable_result` with `legacy: true` (failing checks only, no passing list), set `_findable_overall` and `_findable_scanned_at`, keep `_findable_keyphrase`. Runs in batches of 200 per request until done. If any post had `_findable_fixes` set, show one dismissible notice: Fix All was removed in 1.0 and those head tags are no longer printed. Old fix meta is deleted on uninstall only.

## Security
- Capability matrix: scan/view one post = `edit_post`; dashboard = `edit_others_posts`; settings, bulk, connection test = `manage_options`.
- Every form: `wp_nonce_field` + `check_admin_referer`; REST: `permission_callback` never `__return_true`.
- Inputs: `absint`, `sanitize_text_field`, `esc_url_raw` + scheme allowlist, `rest_sanitize_boolean`, enum checks; post type list intersected with registered public types.
- Output: escape late (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` never needed for API data, it is treated as plain text). JS renders API strings as text nodes only (no `dangerouslySetInnerHTML`, no `innerHTML`).
- API response is untrusted: type-checked, clamped, size-capped in `Result::normalize()`.
- Secret: API key field is `type=password`, shows "A key is saved" instead of the value, empty submit keeps the existing key, explicit "Remove key" checkbox clears it.
- Direct file access guard `defined( 'ABSPATH' ) || exit;` in every PHP file.

## Risks / trade-offs
- **Shared IP rate limit:** on shared hosting the 10/min budget is per server IP. Mitigated by conservative default pace (5/min) and 429 backoff.
- **Sites behind Cloudflare challenges or basic auth** fail to scan. The blocked/403 path shows "Findable could not fetch this URL. Make sure it is public and not behind a login or bot challenge."
- **Label drift:** check IDs come from upstream; unknown IDs fall back to the generic advice. `bin/sync-fix-advice.php` is re-run each release.
- **Postmeta aggregate cost** on very large sites: batched and cached; custom table is the upgrade path.

## Open questions
- Will the hosted API ever require a key? If yes, header name and error code for invalid key (assumed 401 `findable_unauthorized`).
- Should the plugin offer the prototype's Fix All again in 1.x as an opt-in module?
