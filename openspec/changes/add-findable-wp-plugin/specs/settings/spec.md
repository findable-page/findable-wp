# settings

## ADDED Requirements

### Requirement: Settings page
The plugin SHALL register a "Settings" submenu under the "Findable" admin menu, built with the Settings API (`register_setting`, `add_settings_section`, `add_settings_field`) and visible only to users with `manage_options`.

#### Scenario: Administrator opens settings
- **WHEN** an administrator visits Findable > Settings
- **THEN** the page shows fields for endpoint, API key, post types, cache lifetime, bulk scan pace, scan on publish and delete data on uninstall

#### Scenario: Editor is denied
- **WHEN** a user without `manage_options` requests `admin.php?page=findable-settings`
- **THEN** WordPress shows "Sorry, you are not allowed to access this page." and no settings are rendered

### Requirement: Endpoint setting
The endpoint SHALL default to `https://findable.page`. On save it SHALL be trimmed, stripped of trailing slashes and of a trailing `/api/scan`, validated with `esc_url_raw` restricted to `http` and `https`, and rejected if it contains credentials, a query or a fragment. Invalid input SHALL keep the previous value and add a settings error.

#### Scenario: Pasted full scan URL
- **WHEN** the admin saves `https://findable.page/api/scan/`
- **THEN** the stored endpoint is `https://findable.page`

#### Scenario: Self-hosted server
- **WHEN** the admin saves `https://findable.example.com:8080`
- **THEN** it is stored unchanged

#### Scenario: Port blocked by safe HTTP API
- **WHEN** the admin saves `https://findable.example.com:8443` and `FINDABLE_ALLOW_PRIVATE_ENDPOINT` is not true
- **THEN** it is stored, and a settings warning says only ports 80, 443 and 8080 are reachable unless `FINDABLE_ALLOW_PRIVATE_ENDPOINT` is set

#### Scenario: Bad scheme
- **WHEN** the admin saves `javascript:alert(1)` or `ftp://host`
- **THEN** the previous endpoint is kept and the error "Enter an http or https URL." is shown

#### Scenario: Credentials in URL
- **WHEN** the admin saves `https://user:pass@scan.example.com`
- **THEN** the value is rejected with "Do not put credentials in the endpoint URL. Use the API key field."

### Requirement: API key setting
The API key SHALL be stored in its own non-autoloaded option `findable_api_key`, rendered as a password field that never echoes the saved value, kept unchanged when submitted empty, and cleared only when the "Remove saved key" checkbox is ticked. When `FINDABLE_API_KEY` is defined the field SHALL be disabled with the note "Set in wp-config.php". The field description SHALL state that the hosted findable.page API needs no key today.

#### Scenario: Saved key is not disclosed
- **WHEN** a key is saved and the settings page is reloaded
- **THEN** the HTML contains no part of the key, and shows "A key is saved."

#### Scenario: Empty submit keeps key
- **WHEN** the admin saves the form without typing in the key field
- **THEN** the stored key is unchanged

#### Scenario: Remove key
- **WHEN** the admin ticks "Remove saved key" and saves
- **THEN** `findable_api_key` is deleted

### Requirement: Other settings
The plugin SHALL store in `findable_settings`:
- `post_types`: array of public post types, default `post` and `page`; values not in `get_post_types( array( 'public' => true ) )` or equal to `attachment` are dropped.
- `cache_ttl_days`: int 1-90, default 7.
- `bulk_per_minute`: int 1-8, default 5.
- `scan_on_publish`: bool, default false.
- `delete_data_on_uninstall`: bool, default false.

#### Scenario: Out-of-range values are clamped
- **WHEN** the admin submits `bulk_per_minute=50` and `cache_ttl_days=0`
- **THEN** the stored values are 8 and 1

#### Scenario: Unknown post type
- **WHEN** a crafted request submits `post_types[]=nav_menu_item`
- **THEN** it is not stored

### Requirement: Connection test
The settings page SHALL provide a "Test connection" button that calls REST `POST findable/v1/connection`, which calls `Api_Client::health()` against the currently saved endpoint and reports the service name and version or the error message.

#### Scenario: Successful test
- **WHEN** the endpoint is healthy
- **THEN** the page shows "Connected to findable 0.3.0."

#### Scenario: Unreachable endpoint
- **WHEN** the health request times out
- **THEN** the page shows "Could not reach the endpoint:" followed by the escaped error message

### Requirement: Endpoint change invalidates freshness
Changing the endpoint SHALL mark all stored results stale (freshness compares `endpoint`) without deleting them.

#### Scenario: Switch to self-hosted
- **WHEN** the endpoint changes from `https://findable.page` to `https://scan.example.com`
- **THEN** every existing result is shown as stale and a bulk scan without `force` rescans them

### Requirement: Cron health hint
The settings page and dashboard SHALL show a non-dismissible inline hint (not an admin notice on other screens) when `DISABLE_WP_CRON` is true or a `findable_bulk_tick` event is overdue by more than 10 minutes.

#### Scenario: Cron disabled
- **WHEN** `DISABLE_WP_CRON` is true
- **THEN** the hint "WP-Cron is disabled. Bulk scans run only when a server cron calls wp-cron.php." is shown with a link to the WordPress cron documentation
