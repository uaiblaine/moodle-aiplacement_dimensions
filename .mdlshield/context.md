# Review context for aiplacement_dimensions

`aiplacement_dimensions` is a Moodle AI placement. On the activity edit form it adds a
"Suggest competencies" drawer: a teacher picks a competency framework (or some branches of
it), the plugin asks an AI model which of those competencies the activity description covers,
and the teacher applies the ones they accept. It owns three things only: building the prompt,
calling the model through core's AI subsystem, and resolving the answer to competency ids.
Linking and framework browsing are delegated to web services of `local_dimensions`, on which
it depends. It declares `$plugin->supported = [501, 503]` on one branch and is alpha. **It has
no database tables of its own.**

## Who is trusted

- Site administrators decide whether any of this runs. The placement must be enabled in
  Site administration (`\core\plugininfo\aiplacement::is_plugin_enabled()`, which defaults to
  off), the `generate_text` action must be enabled for it, a provider must be configured for
  that action, and the user must have accepted the AI usage policy. The per-course and
  per-activity AI switches (`is_action_enabled_in_context()`) are honoured.
- The one plugin capability is `aiplacement/dimensions:suggest` (`captype` read, module
  context, default `manager` and `editingteacher`, no `riskbitmask`). Every call also needs
  `moodle/competency:coursecompetencymanage` in the activity, and
  `moodle/competency:competencyview` in the chosen framework's own context.
- The activity description a teacher types, and any text the model returns, are untrusted.
  Competency shortnames come from a framework the caller can already read.

## Surfaces and what leaves the site

- 1 web service function, `aiplacement_dimensions_suggest_competencies` (`write`, `ajax`,
  declares `aiplacement/dimensions:suggest`). It takes `cmid`, `courseid`, `frameworkid`, up to
  50 `rootids` and the description as `PARAM_RAW`, truncated to 20,000 characters.
- **Outbound data:** one prompt per click, sent through `core_ai\manager::process_action()`
  with a `generate_text` action to whatever provider the administrator configured. The prompt
  holds the numbered competency **shortnames** (at most 200) and the **unsaved activity
  description as the editor holds it**: the HTML source of the intro field, so it can carry
  links and file URLs that name the site. The plugin puts no user data in the prompt text, and
  ids and idnumbers stay server-side; anything core adds when it calls a provider is core's
  concern. The plugin makes no HTTP call itself and holds no key; core's AI subsystem owns the
  provider, its credentials and the stored action record.
- Output: the model must answer with JSON `picks` of positions in the numbered list. The
  resolver maps each position back to a server-built candidate and drops anything else;
  `why` is cleaned as `PARAM_TEXT` and rendered through a double-stash Mustache tag.
- `lib.php` hooks `coursemodule_definition_after_data` and shows the button only when every
  gate above passes. No page scripts, tasks, observers or file serving.
- Applying a suggestion calls `local_dimensions_link_competency_course` (course link, checked
  there) and selects the option in the form's competency list; the module link is saved by
  core's own form handling.
- Privacy: `null_provider`. The plugin stores nothing; the prompt is recorded by core's AI
  subsystem under its own privacy provider.

## Facts that look like findings but are by design

- **The model returns positions, never names or ids.** A hostile description can at most
  select among candidates the caller may already read. A change that lets the model emit or
  the server parse a competency name or id is a finding.
- **The context is derived from `cmid`, never accepted from the caller**, because
  `is_action_enabled_in_context()` returns true for context levels it does not know. For a new
  activity (`cmid` 0) the check runs at course level, narrowed by
  `moodle/course:manageactivities`; the code states this as a known limit.
- **Two AI switches are checked on purpose** (placement enabled, and action enabled); the
  action check alone defaults to on.
- **The framework is authorised in its own context**, and "not found" and "not permitted"
  raise the same error, so the candidate count is not an enumeration oracle.
- **The module competency link is never written by this plugin**, because saving the form
  would remove it. No page reload is used, to keep the teacher's unsaved content.
- **A provider failure is returned as data** (`success` false, `errorcode`, `errormessage`),
  not thrown, so the drawer can show it; the message is written with `textContent` because it
  can carry provider-supplied text.
- **The prompt template is a language string**, so translations of it change what is sent.
- Return structures are an allowlist: a new key must be declared in `execute_returns()`.

## De-emphasise

- `amd/build/**` is minified output of `amd/src/**`; review the source.
- `lang/**` and `tests/**` carry no production behaviour.
- Template markup and visual details of the drawer, unless they show data the viewer should
  not see.
