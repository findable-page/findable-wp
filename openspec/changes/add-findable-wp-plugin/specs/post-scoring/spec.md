# post-scoring

## ADDED Requirements

### Requirement: Scannable posts only
A post SHALL be scanned only if its type is in `post_types`, its status is `publish`, and `is_post_publicly_viewable()` is true. The scanned URL SHALL be `get_permalink()`.

#### Scenario: Draft
- **WHEN** a user clicks "Scan now" on a draft
- **THEN** no API call is made and the UI shows "Publish this post first. Findable scans the live URL."

#### Scenario: Password-protected post
- **WHEN** the post has a password
- **THEN** the scan is refused with code `findable_not_scannable`

#### Scenario: Disabled post type
- **WHEN** the post type `product` is not in `post_types`
- **THEN** no sidebar, meta box or list column is added for `product`

### Requirement: Scan REST route
`POST /wp-json/findable/v1/posts/{id}/scan` SHALL require `current_user_can( 'edit_post', $id )`, accept optional `keyphrase` (string, max 100 chars, `sanitize_text_field`), save the keyphrase to `_findable_keyphrase`, call the API, store the result on success or `_findable_error` on failure, and return the same payload as the result route. A second scan of the same post within 10 seconds SHALL return 429 `findable_too_soon`.

#### Scenario: Author scans own post
- **WHEN** an author scans their own published post
- **THEN** the API is called once, `_findable_result`, `_findable_overall`, `_findable_scanned_at` are saved, and the response contains the result

#### Scenario: Contributor scans another user's post
- **WHEN** a contributor calls the route for a post they cannot edit
- **THEN** the response is 403 `rest_forbidden` and no API call is made

#### Scenario: API failure keeps old result
- **WHEN** the post has a result from yesterday and the new scan returns `findable_server`
- **THEN** the old result is kept, `_findable_error` is saved, and the response is the old result plus the error

#### Scenario: Double click
- **WHEN** two scan requests for the same post arrive 3 seconds apart
- **THEN** the second returns 429 `findable_too_soon` and does not call the API

### Requirement: Result REST route
`GET /wp-json/findable/v1/posts/{id}/result` SHALL require `edit_post` and return `{ result|null, fresh: bool, age_seconds, error|null, scannable: bool, reason|null, report_url, advice: { [checkId]: string } }` where `advice` covers every failing check in the result.

#### Scenario: Never scanned
- **WHEN** the post has no result
- **THEN** the route returns `result: null` and `scannable` describing whether a scan is possible

### Requirement: Block editor sidebar
For enabled post types using the block editor, the plugin SHALL register a `PluginSidebar` named `findable-sidebar` with a `PluginSidebarMoreMenuItem`, showing: overall score with band label, SEO/AEO/GEO scores with passed/total, a keyphrase field, a "Scan now" button with busy state, freshness ("Scanned 3 hours ago", "Stale: post changed since last scan"), the last error if any, and a "Full report on findable.page" link.

#### Scenario: Open sidebar on scanned post
- **WHEN** the user opens the Findable sidebar on a scanned post
- **THEN** it fetches the result route once and renders scores and checks without calling the scan API

#### Scenario: Unsaved changes
- **WHEN** the editor has unsaved edits (`isEditedPostDirty()`)
- **THEN** the sidebar shows "Save or update the post to score your latest edits." next to "Scan now"

#### Scenario: Scan from sidebar
- **WHEN** the user clicks "Scan now"
- **THEN** the button is disabled with a spinner, and on completion the scores and checks update and a snackbar notice "Findable scan complete" is shown

### Requirement: Check breakdown with fix advice
Both the sidebar and the meta box SHALL list checks grouped by family with filters Failing (default), Passing and Not applicable, each with a count. Every failing check SHALL show its label and fix advice from `Fix_Advice`. All API-originated text SHALL render as plain text.

#### Scenario: Failing check with known advice
- **WHEN** check `title_length` fails
- **THEN** it shows its label and "Keep the title between 30 and 60 characters so search engines do not truncate it." (or the localized text)

#### Scenario: Unknown check id
- **WHEN** the API returns a new check id with no advice
- **THEN** the generic advice "See the full report on findable.page for how to fix this check." is shown

#### Scenario: Legacy result from 0.2.0
- **WHEN** the result has `legacy: true`
- **THEN** only failing checks are shown with the note "Scan again to see the full breakdown."

### Requirement: Classic meta box
For enabled post types not using the block editor, the plugin SHALL add a side meta box "Findable" rendering the same data server-side, a keyphrase field and a "Scan now" button that submits to `admin-post.php?action=findable_scan` with nonce `findable_scan_{post_id}`, then redirects back to the edit screen with a result notice.

#### Scenario: Missing nonce
- **WHEN** `admin-post.php?action=findable_scan` is requested without a valid nonce
- **THEN** the request dies with 403 and no scan happens

### Requirement: List table column
For each enabled post type the list table SHALL have a sortable "Findable" column showing overall score and band text, "Not scanned" when absent, and "(stale)" when stale. Sorting SHALL use `_findable_overall` with unscanned posts last.

#### Scenario: Sort by score ascending
- **WHEN** the user sorts the Posts list by Findable ascending
- **THEN** the lowest scores appear first and unscanned posts appear at the end

### Requirement: Score bands
Scores SHALL map to bands 0-49 "Poor", 50-79 "Needs work", 80-100 "Good" in every view. Colour SHALL never be the only indicator and contrast SHALL meet WCAG 2.1 AA.

#### Scenario: Boundary values
- **WHEN** scores 49, 50, 79, 80 are rendered
- **THEN** they show Poor, Needs work, Needs work, Good

### Requirement: Scan on publish (opt-in)
When `scan_on_publish` is true, publishing or updating a scannable post SHALL schedule one `findable_scan_post` single event 60 seconds later for that post, with at most one pending event per post. Autosaves and revisions SHALL NOT schedule scans.

#### Scenario: Two quick updates
- **WHEN** a post is updated twice within 60 seconds
- **THEN** exactly one `findable_scan_post` event is pending for it

#### Scenario: Setting off
- **WHEN** `scan_on_publish` is false
- **THEN** publishing schedules nothing and makes no API call
