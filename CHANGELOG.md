# Changelog

All notable changes to MusicCurator will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.2] - Unreleased

### Changed

- Upgrade Vite from 7.3.7 to 8.3.5 and update the build dependency lockfile.
- Run the complete frontend test suite in the Node CI workflow.
- Upgrade @nextcloud/vue from 9.13.0 to 9.14.0, including its updated Vue runtime and UI fixes (#49).
- Upgrade the isolated Rector development tool from 2.6.7 to 2.8.1 and its PHPStan dependency from 2.2.14 to 2.3.4 (#48).

### Fixed

- Pin minimatch to patched version 11.0.1 across the dependency tree and verify WebDAV glob-filter compatibility.

## [1.5.1] - Unreleased

### Added

- Add an App Store screenshot with labelled sample data and a permanent image URL.
- Add voluntary support links and the GitHub funding button.
- Audit the application and all isolated PHP development-tool lockfiles in CI.

### Fixed

- Resolve npm dependency security advisories and remove the unused Node crypto polyfill dependency tree and previous advisory exceptions.
- Fix PHP security checks by aligning Composer, app requirements and CI with PHP 8.4; keep syntax checks for PHP 8.4 and 8.5.

### Changed

- Upgrade static analysis to Psalm 6.20.3 and migrate the existing Nextcloud 34/35 baselines; existing baseline findings remain documented technical debt.
- Prepare version 1.5.1 for the Nextcloud App Store.

## [1.5.0] - Unreleased

### Added

- Publish MusicCurator-managed M3U8 playlists to Nextcloud Music for use through its Subsonic interface.
- Update the linked server playlist on subsequent publication and report missing or unindexed tracks without replacing an existing playlist with incomplete contents.

### Fixed

- Show failed and unconfigured metadata providers in batch results instead of reporting them as successful searches without matches.
- Reject invalid metadata responses and preserve successful provider results when optional scan-index persistence fails.
- Refresh playlist lists after creation and refresh persisted album metadata after tag writes and undo.
- Restore the complete Vue source and keep Music playlist mapping keys within Nextcloud's 64-character setting limit.

### Changed

- Require PHP 8.2 or newer, consistent with the minimum supported Nextcloud version.
- Keep PHP 8.4 app syntax checks; run the existing Psalm 5 analyzer on PHP 8.3 to avoid its PHP 8.4 crash.
- Update DOMPurify, pbkdf2 and source-map-js to their patched versions; evaluate full and runtime audits with narrowly scoped, expiring exceptions for three reviewed development-tool advisories.
- Explain empty album views and the MP3/FLAC tag-reading scope.

### Known limitations

- Metadata-provider availability and credentials must still be checked on the hosting server.
- Existing source-analysis and formatting findings remain open before release validation is complete.
- Playlist publication requires the optional Nextcloud Music app and its index to include every referenced track.

## [1.0.0] - 2026-09-20

### Changed

- Prepare the 1.0.0 release from the validated main branch.
- Update Vue, ESLint, Stylelint and the OpenAPI extractor together with their configuration.
- Restore automated checks and add an npm security audit gate.
- Document existing PHP analysis findings with version-specific regression baselines.
- Provide a local signing and packaging command; signing keys remain on the release server.

### Known limitations

- MP3 tag writing remains experimental; use backed-up music when testing write operations.
- Existing PHP analysis findings remain documented in docs/ci.md and are not resolved by this release.

## [0.2.15] - 2026-08-29

### First public beta

MusicCurator 0.2.15 is the first public beta release. It is intended for testing on real Nextcloud music libraries and is not yet a final/stable release.

### Added

- Native Nextcloud 34+ interface with responsive mobile navigation and Nextcloud theming.
- Per-user music-library folder selection and recursive library scanning.
- Persistent per-user scan index so an existing library can be restored from the database without rescanning every file on each app open.
- MP3 ID3 and FLAC Vorbis-comment metadata reading.
- Metadata lookup through MusicBrainz, Last.fm, optional Discogs and optional AcoustID/Chromaprint.
- Cross-provider genre enrichment with MusicBrainz first, Last.fm track/artist tags as fallback and Discogs as optional enrichment.
- MusicBrainz release-group genre lookup and release/cover-art metadata.
- Cover artwork in metadata results, albums and playlist views where provider data is available.
- Batch metadata preview with high-confidence auto-selection and manual review for uncertain matches.
- Experimental MP3 metadata writing through ffmpeg stream copy without audio re-encoding.
- Rollback records for changed MP3 metadata fields.
- Track number, year and normalized genre writing for supported MP3 matches.
- Nextcloud Files API move/rename preview and execution with collision and library-boundary checks.
- Change history for file operations.
- Managed `.m3u8` playlist creation from folders.
- MusicCurator-managed playlist marker so user-created playlists are never silently overwritten.
- Extended M3U playlist labels with visible sequence numbers.
- Album-like folders sorted by embedded track number; mixed playlist folders preserve natural filename order.
- MusicBrainz track-position extraction so album track numbers can be written to metadata.
- Optional AI Advisor with OpenAI, Mistral and local Ollama support. AI remains advisory and does not automatically write tags or move files.

### Safety notes

- MP3 tag writing is still marked experimental and should be tested on backed-up music first.
- MusicCurator does not overwrite non-managed `.m3u8` playlists.
- File moves stay inside the configured Nextcloud music library and do not overwrite existing targets.
- Cloud AI providers receive filenames/tags/folder statistics for advisory classification, not the audio files themselves.

### Known limitations

- Embedded tag writing currently supports MP3 only.
- Metadata completeness depends on the configured provider data; ambiguous or missing matches still require manual review.
- Playlist order can only be reconstructed when the source folder already contains usable order information such as track tags or numbered filenames.
- This GitHub beta is not yet a Nextcloud App Store release.

## [Unreleased]

### Planned

- Broader tag-writing support beyond MP3.
- Further playlist maintenance and automatic refresh after file operations.
- Additional metadata confidence and provider-consensus improvements.
- More release packaging and App Store hardening.
