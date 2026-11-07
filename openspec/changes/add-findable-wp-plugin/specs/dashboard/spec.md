# dashboard

## ADDED Requirements

### Requirement: Dashboard page
The plugin SHALL add a top-level "Findable" admin menu whose first page "Dashboard" requires `edit_others_posts` and is server-rendered.

#### Scenario: Editor sees dashboard
- **WHEN** an editor opens Findable > Dashboard
- **THEN** the summary renders, and bulk scan controls are hidden because the editor lacks `manage_options`

#### Scenario: Author is denied
- **WHEN** an author requests the dashboard URL
- **THEN** access is denied

### Requirement: Site summary
The dashboard SHALL show: number of scannable posts, scanned posts, stale posts, never-scanned posts; average overall, SEO, AEO and GEO scores over scanned posts; and the count of posts in each score band.

#### Scenario: Mixed site
- **WHEN** 10 posts are scannable, 6 scanned with overall 90, 85, 60, 55, 40, 30, and 1 of them stale
- **THEN** it shows scanned 6, stale 1, never scanned 4, average overall 60, bands Good 2, Needs work 2, Poor 2

#### Scenario: Nothing scanned
- **WHEN** no post has a result
- **THEN** it shows an empty state "No posts scanned yet." with a "Scan all posts" button for users with `manage_options`

### Requirement: Weakest posts
The dashboard SHALL list up to 10 scanned posts with the lowest overall score, each with title linked to its edit screen, post type, overall score with band, failing check count and scan age.

#### Scenario: Ties
- **WHEN** two posts have the same score
- **THEN** the one scanned longer ago appears first

### Requirement: Most common failing checks
The dashboard SHALL list up to 10 check ids failing on the most scanned posts, with family, label, affected post count, percent of scanned posts and fix advice, and a link that filters the posts list to affected posts (`findable_failing={id}` query var on `edit.php`, validated against `/^[a-z0-9_]{1,64}$/`).

#### Scenario: Filter posts by failing check
- **WHEN** the user clicks the affected count for `desc_exists`
- **THEN** `edit.php?findable_failing=desc_exists` lists only posts whose stored result has `desc_exists` failing

#### Scenario: Invalid filter value
- **WHEN** `findable_failing=<script>` is requested
- **THEN** the filter is ignored

### Requirement: Aggregate caching
Aggregates SHALL be computed in batches of 200 posts and cached in transient `findable_dashboard` for 12 hours. The transient SHALL be deleted whenever a result is saved or deleted, when settings change, and when bulk scan finishes.

#### Scenario: Cache invalidated by scan
- **WHEN** a post is rescanned
- **THEN** the next dashboard load recomputes the aggregate

### Requirement: Bulk scan panel
For users with `manage_options` the dashboard SHALL show the bulk scan panel defined in the bulk-scan capability: status, progress "{done} of {total}", failed count, estimated time remaining, last error, and buttons Start (scan stale and unscanned), Rescan all, Pause/Resume and Cancel. While a run is active the panel SHALL poll `GET findable/v1/bulk` every 10 seconds and stop polling when the page is hidden (`visibilitychange`) or the run ends.

#### Scenario: Progress update
- **WHEN** a bulk run is active and the page is open
- **THEN** progress updates without reload, and the progress region is an `aria-live="polite"` region

### Requirement: Accessible markup
Dashboard tables SHALL use `<table class="widefat striped">` with `<th scope="col">` headers, and charts (if any) SHALL have a text equivalent.

#### Scenario: Screen reader
- **WHEN** the band distribution is shown as a bar
- **THEN** the same numbers are present as text
