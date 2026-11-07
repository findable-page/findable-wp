# api-client

## ADDED Requirements

### Requirement: Single API client
All outbound HTTP to a Findable endpoint SHALL go through `Findable\Api_Client`. No other file SHALL call `wp_remote_*` or `wp_safe_remote_*`.

#### Scenario: Static check for stray HTTP calls
- **WHEN** CI greps `includes/`, `findable.php` and `uninstall.php` for `wp_remote_` and `wp_safe_remote_`
- **THEN** matches appear only in `includes/class-api-client.php`

### Requirement: Scan request
`Api_Client::scan( $url, $keyphrase = '' )` SHALL send `POST {endpoint}/api/scan` with header `Content-Type: application/json` and JSON body `{"url": $url}`, adding `"keyphrase"` only when non-empty after trimming. It SHALL NOT send `"site": true`.

#### Scenario: Scan without keyphrase
- **WHEN** `scan( 'https://example.com/hello/' )` is called
- **THEN** the request body is exactly `{"url":"https://example.com/hello/"}`

#### Scenario: Scan with keyphrase
- **WHEN** `scan( 'https://example.com/hello/', '  coffee grinder ' )` is called
- **THEN** the body contains `"keyphrase":"coffee grinder"`

#### Scenario: Keyphrase over the API limit
- **WHEN** a keyphrase longer than 100 characters is passed
- **THEN** the client returns `WP_Error` code `findable_invalid_keyphrase` without calling the API

### Requirement: Transport limits
Requests SHALL use a 30 second timeout, `limit_response_size` of 1 MB, no redirects, and `User-Agent: Findable-WP/{FINDABLE_VERSION}; {home_url()}`. Requests SHALL use `wp_safe_remote_post` / `wp_safe_remote_get` unless the constant `FINDABLE_ALLOW_PRIVATE_ENDPOINT` is `true`.

#### Scenario: Private endpoint without opt-in
- **WHEN** the endpoint is `http://127.0.0.1:8080` and `FINDABLE_ALLOW_PRIVATE_ENDPOINT` is not defined
- **THEN** the request is refused by the safe HTTP API and the client returns `WP_Error` code `findable_http`

#### Scenario: Private endpoint with opt-in
- **WHEN** `FINDABLE_ALLOW_PRIVATE_ENDPOINT` is `true` and the endpoint is `http://127.0.0.1:8080`
- **THEN** the client sends the request with `wp_remote_post`

### Requirement: Optional API key header
When an API key is configured (option `findable_api_key` or constant `FINDABLE_API_KEY`, constant wins), the client SHALL send `Authorization: Bearer {key}`. The key SHALL only be sent to `https` endpoints, or to `http` endpoints when `FINDABLE_ALLOW_PRIVATE_ENDPOINT` is `true`.

#### Scenario: Key over https
- **WHEN** a key is set and the endpoint is `https://findable.page`
- **THEN** the request carries `Authorization: Bearer {key}`

#### Scenario: Key over plain http to a public host
- **WHEN** a key is set and the endpoint is `http://scan.example.com`
- **THEN** the header is omitted

#### Scenario: Unauthorized
- **WHEN** the API responds 401 or 403 with a JSON `error`
- **THEN** the client returns `WP_Error` code `findable_unauthorized` with the message "The Findable API rejected the API key."

### Requirement: Error mapping for throttling and auth
The client SHALL return `WP_Error` `findable_rate_limited` on 429, with data `retry_after` taken from the `Retry-After` header (default 60, clamped 30-600), and `findable_unauthorized` on 401 or 403.

#### Scenario: Rate limited
- **WHEN** the API returns 429 with no `Retry-After`
- **THEN** the client returns `findable_rate_limited` with `retry_after` 60

### Requirement: Error mapping for blocked targets
On 400 with a JSON `error`, the client SHALL return `findable_blocked` whose message is the API text passed through `sanitize_text_field` and capped at 300 characters.

#### Scenario: Non-public target
- **WHEN** the API returns 400 `{"error":"target resolves to a private address"}`
- **THEN** the client returns `findable_blocked` whose message includes that text, HTML-stripped

### Requirement: Error mapping for transport and server failures
The client SHALL return `findable_http` on transport failure, `findable_server` on 5xx, and `findable_bad_response` on any other non-200 status, non-JSON body or invalid body. It SHALL never return a raw HTTP response.

#### Scenario: HTML error page
- **WHEN** the endpoint returns 200 with `text/html` body
- **THEN** the client returns `findable_bad_response`

#### Scenario: Server error
- **WHEN** the API returns 500 `{"error":"audit failed"}`
- **THEN** the client returns `findable_server`

### Requirement: Response validation
A 200 scan response SHALL be accepted by `Result::normalize()` only if `overall` is numeric and each of `seo`, `aeo`, `geo` is an object with numeric `score` and an array `checks`. Scores SHALL be cast to int and clamped to 0-100.

#### Scenario: Valid response
- **WHEN** a response with `overall: 72` and three families of checks is normalized
- **THEN** the result has `overall` 72, three `families` entries with `score`, `passed`, `total`, and one entry per valid check with `id`, `f`, `label`, `s`

#### Scenario: Out-of-range score
- **WHEN** `overall` is `9999`
- **THEN** the stored `overall` is 100

#### Scenario: Missing family
- **WHEN** the response lacks `geo`
- **THEN** normalization returns `WP_Error` `findable_bad_response`

### Requirement: Check normalization
Each check SHALL require a string `id` matching `/^[a-z0-9_]{1,64}$/` and a boolean `pass`, else it is dropped. Labels SHALL be `sanitize_text_field` and capped at 200 characters; at most 400 checks are kept. Status SHALL be `na` when `applicable === false`, else `pass` or `fail`.

#### Scenario: Invalid check dropped
- **WHEN** a check has `id` `"Bad Id!"`
- **THEN** it is not stored and other checks are kept

#### Scenario: Skipped check is not failing
- **WHEN** a check is `{"id":"geo_authoritative_links","pass":true,"applicable":false}`
- **THEN** its status is `na` and it is not counted as failing

#### Scenario: Hostile label
- **WHEN** a label is `<script>alert(1)</script>Title`
- **THEN** the stored label contains no tags

### Requirement: Health check
`Api_Client::health()` SHALL call `GET {endpoint}/health` and succeed only when the JSON body has `status` equal to `ok`, returning the reported `name` and `version`.

#### Scenario: Healthy hosted endpoint
- **WHEN** the endpoint returns `{"status":"ok","name":"findable","version":"0.3.0"}`
- **THEN** `health()` returns `array( 'name' => 'findable', 'version' => '0.3.0' )`

#### Scenario: Wrong service
- **WHEN** the endpoint returns 200 with `{"hello":"world"}`
- **THEN** `health()` returns `findable_bad_response`

### Requirement: Check text fetch
`Api_Client::check_text( $lang )` SHALL call `GET {endpoint}/i18n/{lang}.json` only for `lang` in `de`, `es`, `ru`, `tr`, and return a map of check id to `{ name, fix }` with both fields sanitized as plain text.

#### Scenario: Unsupported language
- **WHEN** `check_text( 'fr' )` is called
- **THEN** no request is made and an empty array is returned
