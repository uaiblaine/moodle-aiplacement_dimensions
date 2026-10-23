# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [v0.1.2] - Unreleased

### Fixed

- README: it now says what reaches the AI provider (the instruction text, the activity
  description as typed, HTML included, cut to 20,000 characters, and at most 200 candidate
  competency short names; no ids, idnumbers or profile data) and what is stored (no tables of
  the plugin's own; core_ai's request log, and how a data request and a deletion treat it). It
  also lists every capability the request checks, the placement's two core settings, and
  explains that a missing provider fails inside core_ai rather than in the web service.
- The privacy metadata string said the AI provider records the request; core_ai does. It now
  also names the competency short names that are sent.

### Added

- A `LICENSE` file beside `COPYING.txt`, as the plugins directory requires a licence file in
  the package.
- `tests/marketplace_readiness_test.php`, the fleet's file-reading check of the plugins
  directory guidelines, and tests that pin the README's statements on what is sent to the
  provider to the code; `mutations/gates.conf` holds each guard.
- README "Settings", "Capabilities", "What is sent to the AI provider", "Data stored" and
  "Translations" sections.

### Changed

- `lang/pt_br` stays in the repository but leaves the release package: the plugins directory
  accepts only English strings in a package, and translations reach sites through AMOS.
- `local_dimensions` now has one branch per Moodle version, numbered in that version's
  namespace (5.1 `20251006XX`, 5.2 `20260420XX`, 5.3 `20261005XX`), below its old date-numbered
  releases. The declared dependency moves from `2026072700` to `2025100600`, the first 5.1
  release, which every branch meets and which has both web services this placement calls.
- CI: one job per supported Moodle version, each installing `local_dimensions` from the branch
  for that version; a 5.3 job (the full PHP x DB matrix) is added, so the top of the declared
  range is tested.

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
