# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [v0.1.1] - 2026-07-31

### Fixed

- Corrected the declared `local_dimensions` dependency from `2026072801` (a version
  that was never released) to `2026072700` (current main). The scaffold anticipated a
  future release; both web services this placement consumes
  (`local_dimensions_browse_structure`, `local_dimensions_link_competency_course`)
  already ship in `2026072700`. The stale requirement blocked Moodle site
  installation whenever both plugins were present.

## [v0.1] - 2026-07-29

### Added

- Initial release: AI-assisted competency suggestions on the activity edit form,
  delegating linking and browsing to `local_dimensions` web services.
