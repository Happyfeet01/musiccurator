# Changelog

All notable changes to MusicCurator will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
