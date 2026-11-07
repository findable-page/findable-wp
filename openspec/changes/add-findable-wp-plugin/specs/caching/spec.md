# caching

## ADDED Requirements

### Requirement: Stored results
A successful scan SHALL save the normalized result to `_findable_result` and copy `overall` to `_findable_overall` and the scan time to `_findable_scanned_at`. All plugin post meta SHALL be registered as protected (leading underscore) and not exposed in the core REST API.

#### Scenario: Core REST does not leak results
- **WHEN** `GET /wp-json/wp/v2/posts/{id}` is requested
- **THEN** the response contains no `_findable_*` keys

### Requirement: Freshness
A result SHALL be fresh only when all hold: `scanned_at >= strtotime( post_modified_gmt )`, age is less than `cache_ttl_days` days, and `endpoint` equals the current endpoint. Otherwise it is stale. Stale results SHALL still be displayed with an age label.

#### Scenario: Post edited after scan
- **WHEN** a post is updated after its last scan
- **THEN** its result is stale

#### Scenario: TTL expired
- **WHEN** `cache_ttl_days` is 7 and the result is 8 days old
- **THEN** it is stale

### Requirement: No read-through cache for manual scans
A manual "Scan now" SHALL always call the API (subject to the 10-second double-click guard). Only bulk scans and dashboard counts use freshness to skip work.

#### Scenario: Scan a fresh post manually
- **WHEN** a user clicks "Scan now" on a fresh post
- **THEN** the API is called

### Requirement: Check text cache
Responses of `/i18n/{lang}.json` SHALL be cached in transient `findable_check_text_{lang}` for 7 days. A failed fetch SHALL cache an empty array for 1 hour so a down endpoint is not retried on every page load.

#### Scenario: Endpoint down when loading advice
- **WHEN** the site locale is `de_DE` and the i18n fetch fails
- **THEN** English advice is shown and no new fetch is attempted for 1 hour

### Requirement: Result cleanup
When a post is permanently deleted its `_findable_*` meta SHALL be removed by core with the post. When a post type is removed from `post_types`, its results SHALL be kept but excluded from dashboard aggregates.

#### Scenario: Post type disabled
- **WHEN** `page` is removed from `post_types`
- **THEN** pages no longer count on the dashboard, and their meta still exists

### Requirement: Migration from prototype 0.2.0
When `findable_db_version` is below 1, the plugin SHALL convert each `_findable_scan` meta into `_findable_result` with `legacy: true` (failing checks only, status `fail`), set `_findable_overall` and `_findable_scanned_at`, and keep `_findable_keyphrase`. It SHALL process at most 200 posts per admin request, then set `findable_db_version` to 1.

#### Scenario: Upgrade with existing scans
- **WHEN** 0.2.0 stored `_findable_scan` with `overall` 64 on a post
- **THEN** after upgrade the list column shows 64 and the sidebar shows the legacy failing list

#### Scenario: Migration is idempotent
- **WHEN** the migration runs twice
- **THEN** results are not duplicated or overwritten by older data

### Requirement: Fix All removal notice
If any post has legacy `_findable_fixes` meta, the plugin SHALL show one dismissible notice on the Findable dashboard saying Fix All was removed in 1.0 and those head tags are no longer printed.

#### Scenario: Prototype user had fixes applied
- **WHEN** a 0.2.0 site with applied fixes upgrades
- **THEN** the dashboard shows the notice once, and it stays dismissed after "Dismiss"
