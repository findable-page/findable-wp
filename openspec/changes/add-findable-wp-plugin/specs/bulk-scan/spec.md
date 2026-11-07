# bulk-scan

## ADDED Requirements

### Requirement: Start, pause, resume, cancel
Users with `manage_options` SHALL control bulk scans through REST `findable/v1/bulk` (`GET` state, `POST` start with `force` bool or `action` `pause`/`resume`, `DELETE` cancel). Starting while a run is `running` or `paused` SHALL return 409 `findable_bulk_running`.

#### Scenario: Start
- **WHEN** an admin starts a bulk scan with `force=false`
- **THEN** state becomes `{ status: running, cursor: 0, done: 0, failed: 0, total: <count of scannable posts needing scan> }` and one `findable_bulk_tick` event is scheduled within 5 seconds

#### Scenario: Non-admin
- **WHEN** an editor calls `POST findable/v1/bulk`
- **THEN** the response is 403 and state is unchanged

#### Scenario: Cancel
- **WHEN** an admin cancels a running scan
- **THEN** status becomes `cancelled`, the pending tick is unscheduled, and results already saved are kept

### Requirement: Cursor walk
Each tick SHALL select the next scannable posts with `ID > cursor` ordered by `ID` ascending, skipping posts whose result is fresh unless `force` is true, and advance `cursor` past each post it processed.

#### Scenario: Fresh post skipped
- **WHEN** a post was scanned an hour ago and has not changed, and `force` is false
- **THEN** the tick skips it without an API call and does not count it in `done`

#### Scenario: Post added during run
- **WHEN** a new post is published with an ID above the cursor during a run
- **THEN** the run scans it

### Requirement: Pace within the API rate limit
A tick SHALL make at most `bulk_per_minute` scan calls (default 5, max 8, below the API's 10/min/IP), stop starting new scans after 40 seconds of wall time, and schedule the next tick 60 seconds after it started.

#### Scenario: Default pace
- **WHEN** 100 posts need scanning at default settings
- **THEN** no tick makes more than 5 API calls and the run needs at least 20 ticks

### Requirement: Rate-limit backoff
On `findable_rate_limited` the tick SHALL stop, leave the cursor on the post that was limited, store `last_error`, and schedule the next tick after `retry_after` seconds (at least 60).

#### Scenario: 429 mid-tick
- **WHEN** the third scan of a tick returns 429 with `Retry-After: 120`
- **THEN** that post is retried first in the next tick, scheduled 120 seconds later

### Requirement: Failure handling
Per-post failures (`findable_blocked`, `findable_bad_response`, `findable_not_scannable`) SHALL be stored in `_findable_error`, increment `failed`, and advance the cursor. Five consecutive `findable_http`, `findable_server` or `findable_unauthorized` errors SHALL set status `paused` and show a dashboard-only notice with the last error and a Resume button.

#### Scenario: Endpoint down
- **WHEN** five scans in a row fail with `findable_server`
- **THEN** status is `paused`, no further ticks are scheduled, and the dashboard shows "Bulk scan paused: the Findable API is not responding."

### Requirement: Concurrency lock
A tick SHALL acquire lock `findable_bulk_lock` (expires after 120 seconds) before work and exit without changes if the lock is held.

#### Scenario: Overlapping cron runs
- **WHEN** two requests trigger `findable_bulk_tick` at the same time
- **THEN** only one performs scans

### Requirement: Completion
When no posts remain after the cursor, status SHALL become `done` with `finished_at`, the dashboard transient SHALL be deleted, and no further tick is scheduled.

#### Scenario: Run finishes
- **WHEN** the last post is processed
- **THEN** the dashboard shows "Bulk scan finished: {done} scanned, {failed} failed."

### Requirement: Cleanup of scheduled events
Deactivation SHALL unschedule `findable_bulk_tick` and every `findable_scan_post` event and set a running bulk status to `paused`.

#### Scenario: Deactivate during run
- **WHEN** the plugin is deactivated while a bulk scan runs
- **THEN** `wp_next_scheduled( 'findable_bulk_tick' )` is false
