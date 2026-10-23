# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.8] - 2026-10-23

### Fixed
- Watch-dir dialog showed zero videos: discover API and Files JS now share `{ success, count, files }` with user-root-relative paths.
- Registering a watch directory queues an initial scan and sets `last_scan_at` after processing.
- Watch scans enqueue fingerprint/encode jobs (skip ready, queued, and auto-disabled media) via shared `VideoCollectionService`.
- Directory “start now” sends each file’s own path, not the watched folder path for every entry.
- Watched folders show a static orange HLS marker in list and grid views (fileid-based, NC 35).
- HLS notifications default to one summary per batch; Personal settings add per-file / summary / off.

## [2.1.7] - 2026-10-23

### Fixed
- HLS encodes failed on ffmpeg 8: `-autorotate 1` → `-autorotate`.

## [2.1.6] - 2026-10-23

### Fixed
- Encodes failed in FreeBSD jails: `idprio` is not permitted for www; now probed and falls back to `nice -n 19`. ffmpeg stderr is included in job errors.
- Clip export: `fclose()` on a stream already consumed by `newFile()` threw and hid the result.

## [2.1.5] - 2026-10-23

### Fixed
- Queue worker crash loop: duplicate `Notifier::getId()` (PHP method names are case-insensitive) caused a fatal "Cannot redeclare". Added `npm run test:php-lint`.

## [2.1.4] - 2026-10-23

### Fixed
- HLS chips never appeared on NC 35: video rows were detected via a mime attribute NC 35 does not set on rows.
- Grid view chip selector now uses `.files-list--grid`; chip colours readable in light and dark themes.

### Added

- First release
