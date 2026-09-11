# MusicCurator

MusicCurator is a Nextcloud music-library management app designed to complement Nextcloud Music.
It focuses on **curation rather than playback**: scan a real Nextcloud music library, inspect existing audio metadata, compare it with metadata providers, review changes, manage playlists and organize files without bypassing Nextcloud's file layer.

> **Status: first public beta (0.2.15).** The `development` branch remains the active test branch; `master` contains release-ready beta snapshots. Back up important music before testing write features.

## Current beta features

- Native Nextcloud 34+ UI using `@nextcloud/vue`, including responsive mobile navigation and Nextcloud theming
- Per-user music-folder selection
- Recursive library scan with a persistent database index, so the last library state can be restored without rescanning every file on each app open
- MP3 ID3 and FLAC Vorbis-comment metadata reading
- MusicBrainz metadata search without a personal API key
- Last.fm genre/tag enrichment when a personal API key is configured
- Optional Discogs enrichment with a personal token
- Optional AcoustID/Chromaprint audio fingerprint identification
- Genre normalization and cross-provider fallback logic
- MusicBrainz release-group genre lookup and cover artwork through the Cover Art Archive
- Album and playlist artwork where matching provider data is available
- Batch metadata review with high-confidence auto-selection and manual review for uncertain matches
- Experimental MP3 tag writing with ffmpeg stream copy, without audio re-encoding
- Rollback records for changed MP3 metadata fields
- Track number, year and normalized genre support
- Nextcloud Files API move/rename preview with collision and library-boundary protection
- Change history for file operations
- Managed `.m3u8` playlist creation from folders
- Album-like playlist ordering by embedded track number and natural filename ordering for mixed playlist folders
- Extended M3U labels with visible playlist sequence numbers
- Optional AI Advisor using OpenAI, Mistral or local Ollama; AI remains advisory and never automatically writes tags or moves files

## What MusicCurator is not

MusicCurator is not intended to replace Nextcloud Music or another player. It manages and cleans the files and metadata; playback remains the job of Nextcloud Music, Amplify or another compatible client.

## Safety principles

MusicCurator should never silently rewrite a library. The beta therefore keeps destructive operations behind explicit user actions:

- file moves stay inside the configured music library
- existing targets are never silently overwritten
- non-MusicCurator playlists are never silently replaced
- MP3 tag writes store rollback values for changed fields
- uncertain metadata matches remain review items instead of being bulk-written automatically
- cloud AI providers receive filenames/tags/folder statistics for advisory classification, not the audio files themselves

## Branch model

- `development` — active development and real-world testing
- `master` — release-ready beta/stable snapshots
- feature/fix branches — optional isolated work merged into `development`

## Development installation

MusicCurator currently requires a frontend build when installed directly from Git:

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/Happyfeet01/musiccurator.git
cd musiccurator
git checkout development

nvm use 24
composer install --no-dev --optimize-autoloader
npm install --no-audit --no-fund
npm run build

cd /var/www/nextcloud
sudo -u www-data php occ app:enable musiccurator
```

GitHub beta releases include a built archive so testers do not need the Node toolchain for the packaged release.

## Requirements

- Nextcloud 34 or 35
- PHP version supported by the corresponding Nextcloud release
- `ffmpeg` for experimental MP3 tag writing
- `fpcalc` / Chromaprint only when AcoustID fingerprinting is used

## Providers

- **MusicBrainz** — enabled by default; no personal API key required
- **Last.fm** — optional personal API key; useful for track/artist genre tags
- **Discogs** — optional personal token
- **AcoustID** — optional client/API key plus `fpcalc`
- **AI Advisor** — optional OpenAI, Mistral or local Ollama configuration

## License

AGPL-3.0-or-later.
