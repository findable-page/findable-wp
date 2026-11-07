# i18n

## ADDED Requirements

### Requirement: Translatable PHP strings
Every user-facing PHP string SHALL use WordPress i18n functions with the literal text domain `findable`. Strings with placeholders SHALL use `sprintf` with a `/* translators: */` comment. Plurals SHALL use `_n()`. The plugin SHALL NOT call `load_plugin_textdomain()`.

#### Scenario: PHPCS i18n sniff
- **WHEN** PHPCS runs with `WordPress.WP.I18n` and `text_domain` set to `findable`
- **THEN** it reports no errors

#### Scenario: Plural count
- **WHEN** the dashboard shows 1 failing post and 3 failing posts
- **THEN** it uses `_n( '%s post', '%s posts', $n, 'findable' )` for both

### Requirement: Translatable JS strings
Editor and dashboard scripts SHALL use `@wordpress/i18n` with domain `findable`, declare `wp-i18n` as a dependency, and be registered with `wp_set_script_translations( $handle, 'findable' )`.

#### Scenario: Script translations registered
- **WHEN** the editor sidebar script is enqueued
- **THEN** `wp_set_script_translations( 'findable-editor', 'findable' )` has been called

### Requirement: POT file
The build SHALL generate `languages/findable.pot` with `wp i18n make-pot` covering PHP and JS sources, excluding `vendor/`, `node_modules/`, `tests/`.

#### Scenario: CI checks POT freshness
- **WHEN** CI regenerates the POT
- **THEN** the set of msgids matches the committed file, or the job fails

### Requirement: Localized fix advice
For the user locale (`get_user_locale()`), the plugin SHALL map the language part to `de`, `es`, `ru` or `tr` and use the API's `/i18n/{lang}.json` `fix` and `name` text when available, falling back to bundled English advice and the API label.

#### Scenario: Spanish admin
- **WHEN** the user locale is `es_ES` and the API serves `es.json`
- **THEN** failing checks show Spanish names and fix advice

#### Scenario: French admin
- **WHEN** the user locale is `fr_FR`
- **THEN** English advice is shown and no i18n request is made

### Requirement: Locale-aware formatting
Dates SHALL use `wp_date()` with site date and time formats or `human_time_diff()`, and numbers SHALL use `number_format_i18n()`.

#### Scenario: German number format
- **WHEN** the site locale is `de_DE` and 12345 posts are scannable
- **THEN** the dashboard shows `12.345`

### Requirement: RTL support
Admin CSS SHALL use logical properties or ship an `-rtl.css` file registered with `wp_style_add_data( $handle, 'rtl', 'replace' )`.

#### Scenario: Arabic admin
- **WHEN** the admin language is RTL
- **THEN** sidebar and dashboard layout mirror correctly
