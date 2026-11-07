# security

## ADDED Requirements

### Requirement: Capability matrix
The plugin SHALL enforce:

| Action | Capability |
|---|---|
| View or scan one post | `edit_post` for that post |
| View dashboard | `edit_others_posts` |
| Settings, bulk scan control, connection test, migration notice dismiss | `manage_options` |

Checks SHALL run on the server in the handler, never only by hiding UI.

#### Scenario: Direct REST call bypassing UI
- **WHEN** a subscriber with a valid REST nonce calls `POST findable/v1/posts/1/scan`
- **THEN** the response is 403 and no API call is made

### Requirement: REST permission callbacks
Every route in `findable/v1` SHALL define a `permission_callback` that checks the capability above. No route SHALL use `__return_true`. Route args SHALL declare `type`, `required`, `sanitize_callback` and `validate_callback`.

#### Scenario: Missing permission callback
- **WHEN** a unit test iterates `rest_get_server()->get_routes( 'findable/v1' )`
- **THEN** every endpoint has a callable `permission_callback` that returns false for an anonymous user

#### Scenario: Non-numeric id
- **WHEN** `POST findable/v1/posts/abc/scan` is requested
- **THEN** the route does not match and returns 404

### Requirement: Nonces on non-REST forms
Settings forms SHALL use `settings_fields()`; the meta box scan form and notice dismissals SHALL use `wp_nonce_field()` with an action unique to the post or notice and verify it with `check_admin_referer()` before any change.

#### Scenario: CSRF on meta box scan
- **WHEN** a logged-in editor is lured to submit a forged `admin-post.php?action=findable_scan&post=5`
- **THEN** the request fails nonce verification and no scan happens

### Requirement: Sanitize input, escape output
All request input SHALL be unslashed and sanitized at entry (`absint`, `sanitize_text_field`, `sanitize_key`, `esc_url_raw`, `rest_sanitize_boolean`, allowlists). All output SHALL be escaped at the point of output (`esc_html`, `esc_attr`, `esc_url`, `esc_html__`). API data SHALL be treated as plain text; JS SHALL render it via React text nodes only and SHALL NOT use `dangerouslySetInnerHTML`, `innerHTML` or `eval`.

#### Scenario: Malicious label from a compromised endpoint
- **WHEN** an endpoint returns a label `<img src=x onerror=alert(1)>`
- **THEN** sidebar, meta box, list table and dashboard display it as inert text

#### Scenario: PHPCS escaping sniff
- **WHEN** PHPCS runs `WordPress.Security.EscapeOutput`, `WordPress.Security.NonceVerification`, `WordPress.Security.ValidatedSanitizedInput`
- **THEN** it reports no errors and no unexplained `phpcs:ignore`

### Requirement: SSRF protection
Outbound requests SHALL use the safe HTTP API unless `FINDABLE_ALLOW_PRIVATE_ENDPOINT` is true, SHALL NOT follow redirects, and the only user-influenced URL component SHALL be the admin-set endpoint. Post URLs are sent in the JSON body, never used as a request target by the plugin.

#### Scenario: Endpoint redirects to metadata service
- **WHEN** the endpoint answers 302 to `http://169.254.169.254/`
- **THEN** the plugin does not follow it and returns `findable_bad_response`

### Requirement: Secret handling
The API key SHALL never be printed in HTML, REST responses, admin notices, error messages or logs, and SHALL be excluded from any debug output.

#### Scenario: Error contains key
- **WHEN** a transport error message happens to include the Authorization header value
- **THEN** the displayed message replaces the key with `***`

### Requirement: No direct file access
Every PHP file except `findable.php` SHALL begin with `defined( 'ABSPATH' ) || exit;` (and `uninstall.php` SHALL check `WP_UNINSTALL_PLUGIN`).

#### Scenario: Direct request
- **WHEN** `/wp-content/plugins/findable/includes/class-rest.php` is requested over HTTP
- **THEN** it outputs nothing

### Requirement: Safe database access
The plugin SHALL use WP APIs (`get_posts`, `WP_Query`, meta functions). Any direct query SHALL use `$wpdb->prepare()`.

#### Scenario: Aggregate query
- **WHEN** the dashboard aggregate reads results in batches
- **THEN** any custom SQL passes `$wpdb->prepare()` and PHPCS `WordPress.DB.PreparedSQL` reports nothing
