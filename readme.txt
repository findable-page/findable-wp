=== Findable ===
Contributors: findable
Tags: seo, ai-search, content-analysis
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Score every post for SEO and AI findability, see exactly what fails and how to fix it.

== Description ==

Findable scores each post and page from 0 to 100 across three families: SEO (classic search signals, 50% of the score), AEO (answer engines like ChatGPT and Perplexity, 25%) and GEO (generative engines like Google AI, 25%). Scores come from the free Findable API, which fetches the live public URL and runs about 160 checks.

= Features =

* Scan any published post from the editor sidebar (block editor) or the Findable meta box (classic editor)
* Overall, SEO, AEO and GEO scores with passed/total counts
* Every failing check with plain-language fix advice, localized when your language is served
* Optional keyphrase support
* Site dashboard with averages, score distribution, weakest posts and most common failing checks
* Background bulk scanning paced to respect the API rate limit
* Sortable score column in the posts list

= External services =

This plugin sends data to a Findable API endpoint. By default that is `https://findable.page`.

* **What is sent:** the public permalink of the post you scan, your optional keyphrase, and your site URL in the request User-Agent. If you set an API key, it is sent as an `Authorization` header.
* **When it is sent:** only when you click Scan now, when an opted-in scan-on-publish runs, during a bulk scan you started, when you test the connection, and (for German, Spanish, Russian or Turkish admins) once every 7 days to fetch translated check names and fix advice.
* **What the service does:** findable.page fetches the public URL and analyzes its HTML. Results are not used for tracking.
* **Terms of use:** https://findable.page/terms
* **Privacy policy:** https://findable.page/privacy

No data is sent on activation, page loads or unrelated admin screens. There is no telemetry, account or payment. A self-hosted endpoint (run `findable -serve`) can replace findable.page entirely in Findable Settings.

The bundled English fix advice is derived from the findable.page web UI, MIT licensed, © findable.

== Installation ==

1. Upload the `findable` folder to `/wp-content/plugins/` or install from the WordPress.org directory.
2. Activate the plugin.
3. Open any published post and open the Findable sidebar in the editor, or visit the Findable dashboard for a site overview.
4. Optionally set a self-hosted endpoint or enable post types in Findable Settings.

== Frequently Asked Questions ==

= Why can my local or staging site not be scanned? =

The API fetches the live public URL, the same way a search engine or AI crawler would. Local sites, staging sites behind authentication and private networks cannot be fetched, so they cannot be scanned. Publish on a public URL first, or self-host the API on a reachable server.

= Why is scanning slow or rate limited? =

The hosted API allows 10 scans per minute per IP, and on shared hosting that budget is shared by every site on the server. The plugin paces bulk scans below that limit and backs off automatically when it receives a rate-limit response. Run your own endpoint with `findable -serve` to escape the shared budget.

= Can I self-host the API? =

Yes. Install the Go CLI (`go install github.com/findable-page/findable/cmd/findable@latest`), run `findable -serve` and set its URL in Findable Settings. Servers on localhost, LAN or unusual ports need `define( 'FINDABLE_ALLOW_PRIVATE_ENDPOINT', true );` in wp-config.php.

= Do I need an API key or account? =

No. Every feature works without a key. The key field exists for future compatibility; when set, it is sent as a Bearer header and the hosted API ignores it today.

= Does the plugin edit my content or head tags? =

No. The plugin only reports and advises. The old prototype's Fix All feature was removed in 1.0.

= What changed vs version 0.2? =

Version 1.0 is a rewrite: block editor sidebar, site dashboard, bulk scanning, translations, and no Fix All. Your old scan results are migrated automatically. Fix All head tags are no longer printed.

== Screenshots ==

1. Settings page: Findable endpoint, optional API key, post types, cache lifetime, bulk pace and a connection test.
2. Findable sidebar in the block editor: overall score, SEO/AEO/GEO breakdown and failing checks with fix advice.
3. Site dashboard: averages, score bands, weakest posts and the most common failing checks.
4. Bulk scan panel with progress, pause, resume and cancel.
5. Sortable Findable score column in the posts list.

== Changelog ==

= 1.0.0 =

* First stable release.
* Block editor sidebar and classic meta box with scores, checks and fix advice.
* Site dashboard with aggregates, weakest posts and common failing checks.
* WP-Cron bulk scan with rate-limit backoff, pause, resume and cancel.
* Translatable UI (de, es, ru, tr check text from the API).
* Settings page with endpoint, API key, post types, cache lifetime and pace.
* Migration from prototype 0.2.0; Fix All removed.
* Privacy policy content and full uninstall options.

= 0.2.0 =

* Prototype: classic meta box, failing-check list, Fix All head tags.

== Upgrade Notice ==

= 1.0.0 =

Rewrite with a block editor sidebar, dashboard and bulk scanning. Fix All was removed; its head tags are no longer printed. Old scan results are kept.
