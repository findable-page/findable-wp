# quality-ci

## ADDED Requirements

### Requirement: Composer dev tooling
`composer.json` SHALL require-dev `wp-coding-standards/wpcs` ^3, `phpcompatibility/phpcompatibility-wp`, `dealerdirect/phpcodesniffer-composer-installer`, `yoast/phpunit-polyfills`, `wp-phpunit/wp-phpunit`, `phpunit/phpunit` (version compatible with the tested PHP range), and define scripts `lint` (phpcs), `fix` (phpcbf) and `test` (phpunit). The plugin SHALL have no Composer runtime dependencies.

#### Scenario: Fresh clone
- **WHEN** a contributor runs `composer install && composer lint && composer test`
- **THEN** all three commands succeed given a test database

### Requirement: PHPCS ruleset
`phpcs.xml.dist` SHALL use rulesets `WordPress` (Core, Docs, Extra) and `PHPCompatibilityWP` with `testVersion` `7.4-`, `minimum_wp_version` `6.5`, text domain `findable`, prefixes `findable`, `Findable`, `FINDABLE`, and exclude `vendor/`, `node_modules/`, `build/`, `tests/bootstrap.php`.

#### Scenario: Unprefixed global
- **WHEN** a function `scan_post()` is added in the global namespace
- **THEN** `WordPress.NamingConventions.PrefixAllGlobals` fails the lint

### Requirement: PHPUnit suite
Tests SHALL run on the WordPress test framework (`WP_UnitTestCase`) at public seams, and SHALL cover every requirement scenario in api-client, settings, post-scoring REST routes, caching, bulk-scan, security and uninstall that is testable in PHP. HTTP SHALL be stubbed with `pre_http_request`; tests SHALL NOT make real network calls.

#### Scenario: Accidental live call
- **WHEN** a test triggers `wp_safe_remote_post` without a stub
- **THEN** the test fails with "Unstubbed HTTP request"

#### Scenario: Coverage of security seams
- **WHEN** the REST permission tests run
- **THEN** each `findable/v1` route is asserted for anonymous, subscriber, author, editor and administrator

### Requirement: JS tooling
`package.json` SHALL use `@wordpress/scripts` with scripts `build`, `start`, `lint:js`, `lint:css`, `test:unit` (Jest for pure helpers such as score band and filter logic), and `make-pot` wrapper. Lockfile SHALL be committed.

#### Scenario: JS lint
- **WHEN** `npm run lint:js` runs
- **THEN** it exits 0 and forbids `dangerouslySetInnerHTML` via an ESLint `no-restricted-syntax` or React rule

### Requirement: GitHub Actions CI
`.github/workflows/ci.yml` SHALL run on push to `main` and on pull requests with `permissions: contents: read` and pinned action versions. It SHALL run jobs `phpcs` (`composer lint`), `phpunit`, `js` (`npm ci`, lint, Jest, build), `i18n` (POT msgids match committed file), `consistency` and `plugin-check` (`WordPress/plugin-check-action` on the built zip).

#### Scenario: PR with an escaping bug
- **WHEN** a PR adds `echo $label;`
- **THEN** the `phpcs` job fails and the PR shows a red check

### Requirement: PHPUnit matrix
The `phpunit` job SHALL run a matrix of PHP 7.4, 8.1 and 8.3 (plus latest) against WordPress 6.5 and latest, with a MySQL service and `bin/install-wp-tests.sh`.

#### Scenario: Oldest supported versions
- **WHEN** the matrix row PHP 7.4 + WordPress 6.5 runs
- **THEN** the full PHPUnit suite passes

### Requirement: Consistency checks
The `consistency` job SHALL fail when plugin `Version`, `FINDABLE_VERSION` and readme `Stable tag` differ, when `wp_remote_` or `wp_safe_remote_` appears outside `includes/class-api-client.php`, or when `readme.txt` has more than 5 tags.

#### Scenario: Stray HTTP call
- **WHEN** a PR adds `wp_remote_get` in `class-admin.php`
- **THEN** the `consistency` job fails

### Requirement: Committed build policy
The `js` job SHALL build `build/` from `src/`. `build/` SHALL NOT be committed; the release workflow builds it and ships it with `src/`.

#### Scenario: Release zip has build
- **WHEN** the release zip is produced
- **THEN** it contains `build/index.js` and `build/index.asset.php`

### Requirement: Release workflow
`.github/workflows/release.yml` SHALL, on a `v*` tag, verify the tag equals the plugin version, build the zip using `.distignore`, attach it to a GitHub release, and deploy to WordPress.org SVN with `10up/action-wordpress-plugin-deploy` only when the `SVN_USERNAME` and `SVN_PASSWORD` secrets exist. Assets for the directory (banner, icon, screenshots) SHALL live in `.wordpress-org/`.

#### Scenario: Tag mismatch
- **WHEN** tag `v1.0.1` is pushed while the plugin header says `1.0.0`
- **THEN** the release job fails before building

### Requirement: Contributor docs
`README.md` SHALL document local setup (`wp-env` config `.wp-env.json`), running lint and tests, building JS, regenerating the POT and syncing fix advice.

#### Scenario: Local environment
- **WHEN** a contributor runs `npx wp-env start`
- **THEN** a WordPress site with the plugin active is available for manual testing
