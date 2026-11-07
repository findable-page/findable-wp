# Proposal: add-findable-wp-plugin

## Why
Site owners who already use WordPress want to know whether each post or page can be found by Google and quoted by AI answer engines (ChatGPT, Perplexity) without leaving wp-admin. The Findable API already scores a URL across 160 SEO, AEO and GEO checks and names what to fix. The prototype plugin (`findable/wp-plugin/findable` v0.2.0) proved the idea but is a single 489-line file with a classic meta box only, a hard-coded endpoint, no background scanning, no tests, no CI and no block editor UI. It is not ready for the WordPress.org directory.

## What changes
New standalone plugin repository `findable-wp`, slug `findable`, version 1.0.0:

- **api-client**: one HTTP client for `POST /api/scan`, `GET /health` and `GET /i18n/{lang}.json`, with response validation and error mapping.
- **settings**: settings page for endpoint, optional API key, enabled post types, cache lifetime, bulk scan pace and opt-in behaviours, with a connection test.
- **post-scoring**: block editor sidebar and classic meta box showing overall and family scores, per-check breakdown and fix advice, with optional keyphrase. Score column in list tables.
- **dashboard**: site-wide page with averages, score distribution, weakest posts and most common failing checks.
- **bulk-scan**: WP-Cron queue that scans many posts within the API rate limit, resumable, cancellable, with progress.
- **caching**: stored scan results with freshness rules and invalidation on content change; cached translations.
- **i18n**: every UI string translatable in PHP and JS; localized fix advice from the API for supported languages.
- **security**: nonces, capability checks, REST permission callbacks, sanitization, late escaping, SSRF-safe endpoint handling, secret handling for the API key.
- **wporg-compliance**: GPLv2+, `readme.txt`, external service disclosure, no tracking, no remote assets, uninstall cleanup, privacy policy text.
- **quality-ci**: PHPUnit, PHPCS (WPCS), JS lint/build, Plugin Check and release zip in GitHub Actions.

## Assumptions (stated, not verified with the owner)
1. **API key is forward-compatible only.** The real API has no authentication. The setting exists because it was requested; when set it is sent as `Authorization: Bearer <key>`, and the hosted API ignores it today. The UI says so.
2. **"Fix suggestions" means fix advice text**, not editing content. The prototype's "Fix All" head-tag injection is out of scope for 1.0 (see Non-goals).
3. **English fix advice is bundled in the plugin** (copied from `findable-page/web/index.html` `fixAdvice`, MIT, GPL-compatible) because the API serves no English advice file. Localized advice comes from `/i18n/{lang}.json`.
4. **New repo supersedes the prototype.** Meta key `_findable_scan` from 0.2.0 is read once and migrated so existing users keep their scores.

## Non-goals (1.0)
- Writing to post content or `<head>` (prototype "Fix All"). Revisit after 1.0 if users ask; it overlaps SEO plugins.
- `/api/suggest` AI rewrites, `/api/watch`, `/api/report` permanent links, Core Web Vitals (`/api/cwv`).
- Site checks (`site: true`) beyond an optional one-off display on the dashboard.
- Scanning unpublished drafts or private sites (the API cannot fetch them).
- Multisite network-level dashboard. Plugin works per site when network-activated.
- Paid tiers, upsells, telemetry.

## Impact
- New repo, no changes to `findable` or `findable-page`.
- External dependency on a public API with a 10 scans/minute/IP limit; shared hosting may share that budget with other sites on the same IP.
- Prototype users migrate by installing 1.0 over 0.2.0 (same slug and meta key prefix).
