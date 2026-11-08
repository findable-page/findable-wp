# Tasks: add-findable-wp-plugin

Vertical slices. Each slice ends with its tests green, `composer lint` clean, and a working plugin. Write the failing test first in every slice.

## 0. Skeleton and CI tracer
- [x] 0.1 `findable.php` header (Version 1.0.0, Requires at least 6.5, Requires PHP 7.4, GPLv2+, Text Domain `findable`), constants, `ABSPATH` guard.
- [x] 0.2 `composer.json` (dev only), `phpcs.xml.dist`, `phpunit.xml.dist`, `tests/bootstrap.php`, `bin/install-wp-tests.sh`, unstubbed-HTTP guard.
- [x] 0.3 `package.json` with `@wordpress/scripts`, `.wp-env.json`, `.distignore`, `LICENSE`, `.editorconfig`.
- [x] 0.4 `.github/workflows/ci.yml` with phpcs, phpunit matrix, js and consistency jobs; one trivial passing test.
- [x] 0.5 Minimal `readme.txt` and `README.md`.

## 1. Scan one post end to end (classic meta box)
- [x] 1.1 `Result::normalize()` + tests (api-client: Response validation).
- [x] 1.2 `Api_Client::scan()` with error mapping + tests via `pre_http_request` (api-client).
- [x] 1.3 `Store`: save/get result, freshness rule + tests (caching: Stored results, Freshness).
- [x] 1.4 Scannable-post rule + tests (post-scoring: Scannable posts only).
- [x] 1.5 Classic meta box with `admin-post.php` scan, nonce, capability, failing checks rendered escaped (post-scoring: Classic meta box; security).
- [x] 1.6 Bundled English fix advice `data/fix-advice-en.php` + `bin/sync-fix-advice.php` + `Fix_Advice` tests.

## 2. Settings
- [x] 2.1 Settings API page and sanitize callbacks + tests (settings).
- [x] 2.2 API key option, constant override, Bearer header rules + tests.
- [x] 2.3 `Api_Client::health()` + REST `connection` route + Test connection button.
- [x] 2.4 `FINDABLE_ALLOW_PRIVATE_ENDPOINT` switch + tests.

## 3. REST and block editor sidebar
- [x] 3.1 REST `posts/{id}/scan` and `posts/{id}/result` with permission callbacks, args schema, 10 s guard + per-role tests (post-scoring, security).
- [x] 3.2 Editor sidebar (`src/editor`): scores, filters, fix advice, keyphrase, Scan now, dirty/unpublished warnings, snackbar.
- [x] 3.3 Jest tests for score band and check filter helpers; ESLint ban on `dangerouslySetInnerHTML`.
- [x] 3.4 `wp_set_script_translations`, asset dependencies from `index.asset.php`.

## 4. List table column
- [x] 4.1 Sortable "Findable" column with band text and stale marker + test for orderby with unscanned last.

## 5. Bulk scan
- [x] 5.1 Bulk state, REST `bulk` GET/POST/DELETE + permission tests.
- [x] 5.2 `findable_bulk_tick`: lock, cursor walk, pace, 40 s budget, skip fresh + tests.
- [x] 5.3 429 backoff, per-post failures, pause after 5 consecutive failures, completion + tests.
- [x] 5.4 Deactivation hook clears events; cron health hint.
- [x] 5.5 Opt-in scan on publish with dedup + tests.

## 6. Dashboard
- [x] 6.1 Aggregate (batched, transient, invalidation) + tests with fixture results (dashboard: Site summary scenario numbers).
- [x] 6.2 Dashboard page: summary, bands, weakest posts, common failing checks with advice.
- [x] 6.3 `findable_failing` posts-list filter with id validation + test.
- [x] 6.4 Bulk panel JS with polling, visibility pause, `aria-live`.

## 7. i18n
- [x] 7.1 Audit all strings, translators comments, `_n()`; PHPCS I18n clean.
- [x] 7.2 `Api_Client::check_text()` + transient caching incl. 1 h negative cache + tests.
- [x] 7.3 Localized names/advice in sidebar, meta box, dashboard.
- [x] 7.4 `languages/findable.pot` + CI i18n job; RTL CSS.

## 8. Migration and uninstall
- [x] 8.1 0.2.0 migration, batched, idempotent + tests; Fix All removal notice.
- [x] 8.2 `uninstall.php` incl. multisite + tests for both data settings.
- [x] 8.3 Deactivation leaves data intact.

## 9. WordPress.org readiness
- [x] 9.1 Full `readme.txt`: External services section, FAQ (local sites cannot be scanned, rate limit, shared hosting, self-hosting with `findable -serve`), screenshots, changelog, upgrade notice from 0.2.0, MIT credit for fix advice.
- [x] 9.2 `wp_add_privacy_policy_content()`.
- [ ] 9.3 `.wordpress-org/` banner, icon, screenshots. (Banners and icon present; screenshots missing.)
- [ ] 9.4 Plugin Check job in CI on the built zip; fix all findings. (Job configured; not run.)
- [x] 9.5 License audit step for shipped dependencies.
- [x] 9.6 `release.yml` with tag/version check and optional SVN deploy.

## 10. Verification before release
- [ ] 10.1 Manual pass in `wp-env`: classic and block editor, editor and author roles, de_DE and ar locales, bulk scan of 30 posts against the hosted API without a 429 storm. (Not run.)
- [x] 10.2 Separate reviewer pass (security focus: capabilities, nonces, escaping, SSRF, key handling) before tagging 1.0.0.
- [ ] 10.3 Validate change with `openspec validate add-findable-wp-plugin --strict`. (`openspec` CLI unavailable.)
