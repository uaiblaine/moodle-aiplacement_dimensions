# aiplacement_dimensions

AI placement plugin for Moodle that suggests competencies for an activity through the core AI
subsystem (`core_ai`). It plugs into the AI placement API (`\core_ai\placement`) and declares the
`generate_text` action, so a configured AI provider can propose relevant competencies while a
teacher edits an activity.

## Status

This is an early scaffold (`v0.1`, `MATURITY_ALPHA`). The placement class, capability, privacy
provider and CI are in place; the actual suggestion logic (calling the AI action, mapping the
response to competencies, and the UI hook on the activity form) lands in follow-up work.

## Requirements

- Moodle 5.1 or 5.2 (`$plugin->supported = [501, 503]`; the 5.3 leg is added once
  `MOODLE_503_STABLE` exists upstream).
- [`local_dimensions`](https://github.com/uaiblaine/moodle-local_dimensions), pinned to version
  `2026072801` or later. This plugin declares it as a hard dependency (`$plugin->dependencies`),
  so Moodle will refuse to install `aiplacement_dimensions` without it present.

## Capability

`aiplacement/dimensions:suggest` (`read`, `CONTEXT_MODULE`) gates asking the AI provider for
competency suggestions. It does not gate writing competencies to a course — that is enforced by
core via `moodle/competency:coursecompetencymanage`.

## Privacy

This plugin stores no personal data of its own (`core_privacy\local\metadata\null_provider`).
Every request it makes goes through `core_ai`, which records the request under its own privacy
metadata.

## Installation

Place this directory at `ai/placement/dimensions` inside a Moodle 5.1+ codebase, install
`local_dimensions` first, then visit *Site administration > Notifications* to complete the
install.

## License

GNU GPL v3 or later. See [LICENSE](http://www.gnu.org/copyleft/gpl.html).
