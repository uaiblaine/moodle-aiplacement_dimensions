# aiplacement_dimensions

AI placement plugin for Moodle that suggests competencies for an activity through the core AI
subsystem (`core_ai`). It plugs into the AI placement API (`\core_ai\placement`) and declares the
`generate_text` action, so a configured AI provider can propose relevant competencies while a
teacher edits an activity.

## What it does

A "Suggest competencies with AI" button is added to the activity editing form, next to the
competencies picker. Pressing it opens a drawer where the teacher chooses a competency framework
and, optionally, one or more branches to scope the request. Submitting the drawer sends the
activity's introduction text, together with the numbered list of candidate competencies, to the
site's configured AI provider (`aiplacement_dimensions_suggest_competencies`, in
`classes/external/suggest_competencies.php`), which replies with a short list of picks. Each
resolved suggestion can be added to the course and selected in the activity's own competencies
field with one click; nothing is written to the course until the activity form itself is saved.

The suggestion logic lives in `classes/local/candidates.php` (fetching the scoped competency
subtree), `classes/local/prompt.php` (building the numbered candidate list and instruction text,
capped at `prompt::DEFAULT_BUDGET` candidates), and `classes/local/resolver.php` (mapping the
model's numbered picks back to real competency records). The client-side flow is
`amd/src/suggest.js`.

## Before the button appears

The button silently does not render — no message, no log line — unless every one of the
following is true. Each is checked again by the web service itself, so nothing here is only a
client-side gate:

- **The placement must be enabled by an admin.** On install, the `enabled` config key for
  `aiplacement_dimensions` is left unset, and Moodle core has no default-enable path for
  `aiplacement` plugins: unlike some other plugin types, installing this one does not turn it on.
  An administrator must visit *Site administration -> AI -> AI placements* and enable it
  explicitly.
- **At least one `generate_text` provider must be configured and enabled**, at *Site
  administration -> AI -> AI providers*, with the `generate_text` action turned on for it.
- The `generate_text` action must be enabled for this placement specifically (a separate toggle
  from the two above).
- AI tools must not be turned off for the course, or for the specific activity.
- The current user needs `moodle/competency:coursecompetencymanage` and
  `aiplacement/dimensions:suggest` in the activity (or course, for a not-yet-created activity)
  context, and must have accepted the AI acceptable use policy.
- Competency-based education (`core_competency`) must itself be enabled site-wide.

If a fresh install shows no button anywhere, the most likely causes, in order, are the placement
toggle and the missing provider.

## Requirements

- Moodle 5.1, 5.2 or 5.3 (`$plugin->supported = [501, 503]`).
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
install. Afterwards, enable the placement and configure an AI provider as described above — the
button will not appear until both are done.

## License

GNU GPL v3 or later. See [LICENSE](http://www.gnu.org/copyleft/gpl.html).
