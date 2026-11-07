# Project: findable-wp

## Purpose
WordPress plugin "Findable" (slug `findable`) that scores posts and pages for search (SEO) and AI findability (AEO, GEO) by calling the Findable scan API, and shows scores, per-check results and fix advice inside wp-admin.

Upstream projects (read-only for this repo):
- `../findable` (github.com/findable-page/findable, Go, MIT): scoring engine, CLI, self-hostable API via `findable -serve`. Contains the earlier prototype plugin `wp-plugin/findable` v0.2.0.
- `../findable-page` (Cloudflare Worker `seoscore-api`): hosted API at `https://findable.page`.

## Domain language
Use these exact terms in code, UI copy and specs.

| Term | Meaning |
|---|---|
| Scan | One `POST {endpoint}/api/scan` call for one URL. |
| Scan result | The stored, normalized subset of one scan response for one post. |
| Overall score | `overall`, integer 0-100. Weighted SEO 50%, AEO 25%, GEO 25% (computed by the API, never by the plugin). |
| Family | One of `seo`, `aeo`, `geo`. Each has `score`, `passed`, `total`, `checks[]`. |
| Check | `{ id, label, pass, applicable?, severity?, category? }`. `applicable: false` means skipped, not failed. |
| Failing check | `pass === false` and `applicable !== false`. |
| Fix advice | Human text telling the owner how to fix a failing check, keyed by check `id`. |
| Keyphrase | Optional focus phrase sent with a scan, max 100 chars (API limit). |
| Endpoint | Base URL of a Findable API: hosted `https://findable.page` or self-hosted `findable -serve`. |
| Bulk scan | Background scan of many posts driven by WP-Cron. |
| Scannable post | Published, publicly viewable post of an enabled post type. |

## Upstream API facts (verified against source, 2026)
- `POST /api/scan`, JSON body `{ "url": string, "keyphrase"?: string, "site"?: boolean }`.
- 200 body: `{ url, overall, seo, aeo, geo, cwv, internalLinks, scannedAt, site? }`. `cwv.score` is always `null` in scan responses.
- Errors are JSON `{ "error": string }`: 400 bad body or blocked target (non-public host, bad port), 429 rate limited (10 scans per minute per client IP, IPv6 keyed per /64), 500 audit failure.
- Target fetch limits: public hosts only, ports 80/443, 5 redirects, 10 s, 5 MB. Local, staging-behind-auth and private-network sites cannot be scanned by the hosted API or by `findable -serve`.
- `GET /health` returns `{ "status": "ok", "name": "findable", "version": string }` on both the Worker and the Go server.
- `GET /i18n/{lang}.json` for `de`, `es`, `ru`, `tr` returns `{ [checkId]: { name, fix } }`. No English file exists; English fix advice lives only in the web UI bundle.
- No API key or account exists today. `/api/suggest`, `/api/watch`, `/api/report` are browser/origin-oriented and out of scope.

## Tech stack
- PHP 7.4+ (tested through current 8.x), WordPress 6.5+.
- Block editor UI: `@wordpress/scripts` build, `@wordpress/components`, `@wordpress/api-fetch`, `@wordpress/i18n`. Built JS ships with its source in the plugin zip.
- Tooling: Composer (dev only), PHPUnit via `wp-phpunit` + `yoast/phpunit-polyfills`, PHPCS with WordPress-Coding-Standards 3.x and PHPCompatibilityWP, WordPress Plugin Check, GitHub Actions.

## Conventions
- Prefix every global symbol, option, meta key, hook, transient, cron hook, REST namespace, CSS class and script handle with `findable` / `Findable` / `FINDABLE_`.
- Text domain `findable` (equals slug). No `load_plugin_textdomain()`.
- Escape late, sanitize early, verify nonce and capability on every state change.
- All outbound HTTP goes through one API client class; nothing else calls `wp_remote_*`.
- Specs: `openspec/changes/<id>/` with proposal, design, tasks and per-capability `specs/<capability>/spec.md` using `## ADDED Requirements`, `### Requirement:` (SHALL/MUST) and `#### Scenario:` (WHEN/THEN).
