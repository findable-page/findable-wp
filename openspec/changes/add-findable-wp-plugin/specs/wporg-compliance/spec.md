# wporg-compliance

## ADDED Requirements

### Requirement: GPL-compatible licensing (guideline 1)
The plugin SHALL be licensed "GPLv2 or later", declared in the main file header (`License`, `License URI`), in `readme.txt`, and in a `LICENSE` file. Every bundled file SHALL be GPL-compatible. Fix advice derived from findable-page (MIT) SHALL be credited in `readme.txt`.

#### Scenario: License audit
- **WHEN** CI scans `composer.lock` (no-dev) and `package.json` production dependencies shipped in the zip
- **THEN** all licenses are GPL-compatible, or the job fails

### Requirement: Plugin header
`findable.php` SHALL include: `Plugin Name: Findable`, `Plugin URI`, `Description`, `Version`, `Requires at least: 6.5`, `Requires PHP: 7.4`, `Author`, `License: GPLv2 or later`, `License URI`, `Text Domain: findable`. `Version` SHALL equal `FINDABLE_VERSION` and `readme.txt` `Stable tag`.

#### Scenario: Version mismatch
- **WHEN** `Version` is `1.0.1` and `Stable tag` is `1.0.0`
- **THEN** the CI version-consistency check fails

### Requirement: readme.txt
`readme.txt` SHALL follow the WordPress.org readme standard with header fields `Contributors`, `Tags` (at most 5, no competitor names), `Tested up to` (latest WordPress major tested), `Requires PHP`, `Stable tag` (a real version, not `trunk`), `License`, `License URI`, a short description of at most 150 characters, and sections Description, Installation, Frequently Asked Questions, Screenshots, Changelog, Upgrade Notice. It SHALL pass the WordPress.org readme validator.

#### Scenario: Tag count
- **WHEN** `readme.txt` lists six tags
- **THEN** the readme lint step fails

### Requirement: External service disclosure (guidelines 6, 7)
`readme.txt` SHALL contain an "External services" section naming the service (`https://findable.page`), what is sent (post permalink, optional keyphrase, site URL in the User-Agent, API key if set), and when it is sent. It SHALL state that findable.page fetches the public URL, link Terms of use (`https://findable.page/terms`) and Privacy policy (`https://findable.page/privacy`), and note that a self-hosted endpoint can replace it.

#### Scenario: Reviewer reads readme
- **WHEN** a plugin reviewer looks for external calls
- **THEN** every host the plugin contacts by default is listed with its purpose and policy links

### Requirement: No unsolicited outbound requests (guideline 7)
The plugin SHALL make no outbound request on activation, on page load of unrelated admin screens, or on the frontend. It SHALL contain no telemetry, usage tracking or analytics.

#### Scenario: Fresh activation
- **WHEN** the plugin is activated and the admin browses Dashboard, Posts and Settings without clicking a scan or test button
- **THEN** no HTTP request is made to any Findable endpoint, except the cached i18n fetch for de/es/ru/tr locales when fix advice is first displayed for an existing result

### Requirement: Local assets, readable code (guidelines 4, 8, 13)
All JS and CSS SHALL ship inside the plugin and use core-bundled libraries (React via `wp-element`, `wp-components`, `wp-api-fetch`). No CDN assets, no admin iframes, no remote code execution, no obfuscation. Built files in `build/` SHALL ship with their `src/` and `package.json`, and `readme.txt` SHALL link the public source repository.

#### Scenario: Zip contents
- **WHEN** the release zip is built
- **THEN** it contains `src/`, `build/`, `package.json` and no `node_modules/`, `tests/`, `.github/`, `vendor/` dev packages or `.map` files

### Requirement: Restrained admin UI (guidelines 10, 11)
The plugin SHALL NOT add frontend credits or links. Admin notices SHALL appear only on Findable screens, except the post-save scan result notice on the edit screen; each SHALL be dismissible or self-resolving. No dashboard widgets, upsells or review nags.

#### Scenario: Notice scope
- **WHEN** bulk scan is paused and the admin views Plugins
- **THEN** no Findable notice appears on the Plugins screen

### Requirement: No trialware (guideline 5)
All features SHALL work without payment or account. The API key only passes through to the endpoint.

#### Scenario: No key
- **WHEN** no API key is set and the hosted endpoint is used
- **THEN** every feature works

### Requirement: Naming (guideline 17)
The slug and text domain SHALL be `findable`; the display name SHALL not start with a third-party trademark.

#### Scenario: Slug check
- **WHEN** Plugin Check runs
- **THEN** text domain matches slug with no warnings

### Requirement: Privacy policy content
The plugin SHALL register suggested privacy text with `wp_add_privacy_policy_content()` describing the data sent to the endpoint.

#### Scenario: Privacy guide
- **WHEN** an admin opens Settings > Privacy > Policy Guide
- **THEN** a "Findable" section explains that post URLs and keyphrases are sent to the configured endpoint

### Requirement: Uninstall cleanup
`uninstall.php` SHALL check `WP_UNINSTALL_PLUGIN`, delete options `findable_settings`, `findable_api_key`, `findable_bulk`, `findable_db_version` and all `findable_*` transients, unschedule all Findable cron events, and always delete legacy meta `_findable_scan`, `_findable_fixes`, `_findable_fixes_backup`. On multisite it SHALL repeat per site.

#### Scenario: Multisite uninstall
- **WHEN** the plugin is deleted from a network with three sites
- **THEN** no `findable_*` option or Findable cron event remains on any site

### Requirement: Optional result deletion on uninstall
`uninstall.php` SHALL delete `_findable_result`, `_findable_overall`, `_findable_scanned_at`, `_findable_error` and `_findable_keyphrase` meta only when `delete_data_on_uninstall` is true.

#### Scenario: Uninstall keeping data
- **WHEN** the plugin is deleted with `delete_data_on_uninstall` false
- **THEN** options and cron events are gone, legacy meta is gone, and `_findable_result` meta remains

#### Scenario: Uninstall deleting data
- **WHEN** the plugin is deleted with `delete_data_on_uninstall` true
- **THEN** no `_findable_*` meta or `findable_*` option remains

### Requirement: Plugin Check passes
The release build SHALL pass WordPress Plugin Check (`wp plugin check findable`) with no errors and no warnings left unexplained in the PR.

#### Scenario: CI Plugin Check
- **WHEN** the CI Plugin Check job runs against the built zip
- **THEN** it exits 0
