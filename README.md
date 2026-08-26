# MusicCurator

MusicCurator is a Nextcloud music-library management app designed to complement Nextcloud Music.
It focuses on **curation rather than playback**: inspect existing audio metadata, compare it with trusted metadata providers, approve changes field by field, and organize files without bypassing Nextcloud's file layer.

> Status: early development prototype. The `development` branch is for active testing; `master` is reserved for release-ready code.

## Project goals

- Native Nextcloud 34+ UI using `@nextcloud/vue`
- Responsive layout that follows Nextcloud theming, dark mode and accessibility conventions
- Optional glass-like surfaces that preserve the current Nextcloud background instead of replacing it
- Existing file metadata shown next to provider suggestions
- Field-by-field approval instead of destructive automatic retagging
- MusicBrainz as the first metadata provider, with a provider architecture for AcoustID/Chromaprint, Discogs and others
- Per-user provider credentials and preferences
- Album completeness checks and missing-track hints
- Playlist-aware organization so retagging does not silently break playlists
- File rename/move/copy operations performed through the Nextcloud Files API
- Dry-run preview and audit trail before write operations

## Planned workflow

```text
MediaFetch / uploads / existing files
                ↓
        MusicCurator scan
                ↓
Current tags ↔ provider suggestions
                ↓
      User reviews differences
                ↓
   Dry run: tags / rename / move
                ↓
        Approved changes
                ↓
       Nextcloud Music playback
```

## Branch model

- `development` — active development and local Nextcloud testing
- `master` — release-ready versions
- feature/fix branches — optional isolated work merged into `development`

The repository also contains the original `main` branch created from the Nextcloud app template. New MusicCurator work is intentionally being developed on `development` and promoted to `master` only after testing.

## Development build

```bash
npm ci
npm run build
composer install
```

For a Nextcloud development installation, clone the repository into `apps/musiccurator`, check out `development`, build the frontend, and enable the app with `occ app:enable musiccurator`.

## Safety principles

MusicCurator should never silently rewrite a library. Automatic actions must be opt-in, provider secrets must not be exposed to frontend code, and file operations must stay inside the authenticated user's Nextcloud storage through supported APIs.

## License

AGPL-3.0-or-later.
