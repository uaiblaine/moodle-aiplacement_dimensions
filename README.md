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
following is true. Each is checked again on the server when the request is made (a missing
provider makes the call fail inside `core_ai`), so nothing here is only a client-side gate:

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
- The current user needs the capabilities listed under *Capabilities* below, and must have
  accepted the AI acceptable use policy.
- Competency-based education (`core_competency`) must itself be enabled site-wide.

If a fresh install shows no button anywhere, the most likely causes, in order, are the placement
toggle and the missing provider.

## Requirements

- Moodle 5.1, 5.2 or 5.3 (`$plugin->supported = [501, 503]`).
- [`local_dimensions`](https://github.com/uaiblaine/moodle-local_dimensions), from its branch
  for the site's Moodle version (`MOODLE_501_STABLE`, `MOODLE_502_STABLE`, or `main` for 5.3);
  the declared minimum is `2025100600`, the first 5.1 release. This plugin declares it as a hard dependency (`$plugin->dependencies`),
  so Moodle will refuse to install `aiplacement_dimensions` without it present.

## Settings

The plugin has no `settings.php` of its own. Its two switches are core's AI placement settings,
stored in `config_plugins` under `aiplacement_dimensions`:

- `enabled`: the placement on or off, at *Site administration > AI > AI placements* (off until
  an administrator turns it on).
- `generate_text`: the placement's only action, in the actions table of its settings page.

The per-course and per-activity AI opt-outs are core's too (`enabledaiactions` on the course
and activity forms); the placement honours them.

## Capabilities

- `aiplacement/dimensions:suggest` (`read`, `CONTEXT_MODULE`; editing teachers and managers by
  default): ask the AI provider for competency suggestions, in the activity's context (the
  course's, for an activity not yet created). It does not gate writing competencies to a
  course; core does that.

The request also checks these core capabilities, in the same context unless stated:

- `moodle/competency:coursecompetencymanage`: the suggestions are added to the course's
  competencies, and core requires it for that write.
- `moodle/course:manageactivities` in the course, for an activity not yet created.
- `moodle/competency:competencyview` in the chosen framework's own context: a framework the
  user may not read is refused with the same error as one that does not exist.

## What is sent to the AI provider

The request goes through the core AI subsystem (`core_ai`, the `generate_text` action) to the
provider an administrator configured; the plugin never contacts an external service itself.
The prompt holds:

- the plugin's fixed instruction text (`promptinstruction`, in the requesting user's language);
- the activity's description as it is in the editor at that moment, HTML markup included, cut
  to the first 20,000 characters. It is read from the open form, so it may not have been saved
  yet;
- the short names of the candidate competencies of the chosen framework or branches, numbered,
  at most 200 of them (`prompt::DEFAULT_BUDGET`). Competency ids and idnumbers are not sent.

The plugin puts no user name, email or other profile data in the prompt. `core_ai` hands the
request's user id and context to the provider plugin, and what that plugin adds to the call it
makes is its own (core's OpenAI provider, for example, sends a hash of the site identifier and
the user id): see that provider's privacy declaration. The answer is a list of positions in the
numbered list, each with a confidence and a one-sentence reason.

## Data stored

The plugin has no database tables and stores nothing itself. Suggestions live only on the page
until the teacher adds them. Adding one links the competency to the course through
`local_dimensions`' `local_dimensions_link_competency_course` service (core's
`competency_coursecomp` table, a course setting rather than personal data), and core makes the
activity link when the activity form is saved.

`core_ai` keeps a record of each request, as it does for every placement: `ai_action_register`
(the user, context, provider, model, outcome and time) and `ai_action_generate_text` (the
prompt above and the generated answer). Those rows are core's and follow core's rules: nothing
expires them, a data request exports them through `core_ai`'s privacy provider, and a deletion
request blanks the prompt and the answer. The plugin's own privacy provider is therefore a null
provider, as core's own placements are, and a data request finds nothing under it.

## Installation

Place this directory at `ai/placement/dimensions` inside a Moodle 5.1+ codebase, install
`local_dimensions` first, then visit *Site administration > Notifications* to complete the
install. Afterwards, enable the placement and configure an AI provider as described above — the
button will not appear until both are done.

## Translations

The English strings ship in the package. The Brazilian Portuguese translation is maintained in
this repository (`lang/pt_br`) and reaches sites through [AMOS](https://lang.moodle.org/), as the
plugins directory requires; it is left out of the release package.

## License

This plugin is licensed under the [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html);
the text is in [`LICENSE`](LICENSE) (and `COPYING.txt`).

Copyright: 2026 Anderson Blaine
