# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.5] - 2026-10-23

### Fixed
- Queue worker crash loop: duplicate `Notifier::getId()` (PHP method names are case-insensitive) caused a fatal "Cannot redeclare". Added `npm run test:php-lint`.

## [2.1.4] - 2026-10-23

### Fixed
- HLS chips never appeared on NC 35: video rows were detected via a mime attribute NC 35 does not set on rows.
- Grid view chip selector now uses `.files-list--grid`; chip colours readable in light and dark themes.

### Added

- First release
