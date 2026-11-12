# Review progress and scan diagnostics

Each indexed track starts **Pending**, including tracks that already have complete tags.
Use **Mark as done** after reviewing it, or **Reopen for review** to put it back on the list.
The library shows pending/done totals and filters independently of the missing-tags filter.
These counts refer to the indexed library, not files that the scan has not reached yet.

Review state is stored per Nextcloud user and file ID, independently of cached metadata.
Refreshing the page, rescanning or discovering a new path for the same file ID preserves it.
A successful tag write, undo or move through MusicCurator records an action and reopens the
track. Scans, provider lookups and previews never mark anything done. External file changes
are not a complete audit trail; a rescan preserves the manually chosen review status.

The selected track shows its latest 50 review actions, newest first. Older actions remain
stored. No old actions are inferred or backfilled from existing tags. The history records
action types and timestamps, not tag values, credentials or the contents of audio files.
The existing rollback mechanism remains separate. The endpoints require a signed-in user,
an indexed file accessible in that user's configured library, and CSRF protection for writes.

## Nextcloud logging

Filter the Nextcloud log for app `musiccurator`. New messages distinguish:

- missing saved library path;
- cache load failure (with the underlying exception);
- loaded row count, restored tracks, paths outside the current library and invalid metadata;
- an empty/incomplete cache after a previous scan;
- the 5,000-track scan boundary, cache hits and fresh reads;
- the file ID and processed count when an index write fails;
- review-history/status failures and failed undo/move/index updates.

Errors and warnings use those levels; successful restoration and review actions use info.
Nextcloud's configured log level still applies. If a real file write succeeds but history
or index persistence fails, the API returns `historyRecorded: false`; the batch UI warns
against repeating the write blindly. This is diagnostic support, not proof of the cause of #57.

## Upgrade and validation

### Scan results shrinking after a page reload (1.6.2)

The 1.6.1 source registers legacy `LibraryController` route attributes alongside
the current definitions in `appinfo/routes.php`. For example, both controllers
claim `POST /api/library/scan`. Nextcloud 34 and 35 register attribute routes
before loading the PHP route file, allowing the legacy scan handler to match first.
That handler returns filesystem results without writing the persistent index or
the last-scan timestamp. After F5, the cache endpoint can therefore return an empty
or older partial index. The screenshot's successful scan count does not prove
that the persistent scan controller ran.

Version 1.6.2 removes the legacy attributes; the existing URLs remain defined in
`routes.php`. It does not delete existing index rows or review history. Install the
updated app through a normal versioned upgrade and run one new scan to populate
the index: F5 alone cannot recover tracks that were never persisted. A server may
retain its previous route map until its routing cache expires or is invalidated;
reloading the browser does not clear that server cache.

Regression coverage checks attributes and PHP route definitions together so
different controllers cannot claim the same HTTP method and URL. SQLite tests
start with zero or 43 indexed tracks, scan 60 files, and verify that a new
controller/index instance restores all 60 with the saved scan timestamp.
These tests cover route ownership and persistence; the real Nextcloud deployment
still needs an upgrade, rescan and F5 check to confirm the reported instance is fixed.

The new `Version0202Date20261109120000` migration adds review columns and a history table
without clearing the existing index. Run it through Nextcloud's normal app upgrade as part
of the 1.6.1 release; merely replacing PHP files without running migrations is insufficient.
This change does not remove the 5,000-file scan cap or add scan resumption.

Run `npm run test:frontend`, `npm run lint`, `npm run stylelint`, `npm run l10n:check`,
`npm run build`, and PHP syntax checks. For isolated PHP tests:

```sh
composer install --no-scripts
composer install --working-dir=vendor-bin/phpunit
vendor-bin/phpunit/vendor/bin/phpunit -c tests/phpunit-unit.xml
```

The isolated suite excludes the pre-existing app-template smoke test that needs a full
Nextcloud checkout. SQLite tests exercise actual persistence/rollback and a 10,001-track
cache response behind a test adapter for the Nextcloud query builder. Frontend tests use
the real progress composable with mocked HTTP responses.

Before release, verify on Nextcloud: migrate an existing index, mark/reopen a track, reload
and rescan, write/undo tags, move a file, and check that another user cannot see its history.
Capture the cache request and the new log messages on the installation affected by #57.
