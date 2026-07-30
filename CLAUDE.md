# CLAUDE.md — aiplacement_dimensions

Guidance for working in this repository. Everything here was learned the expensive way; the
"why" matters more than the rule.

## What this plugin is

A teacher editing an activity asks an AI model which competencies from a chosen framework the
activity covers. The plugin owns exactly three things: **building the prompt, calling the model,
and resolving the model's answer to competency IDs.** Linking, searching and tree navigation are
delegated to web services `local_dimensions` already ships.

It hard-depends on `local_dimensions >= 2026072801` and requires Moodle 5.1
(`$plugin->supported = [501, 503]`). The 5.1 floor is not negotiable: it is when
`is_action_enabled_in_context()` landed, and without it the per-course and per-activity AI
opt-outs are silently ignored.

Design: `local_dimensions/docs/superpowers/specs/2026-07-29-aiplacement-dimensions-design.md`.

## The five invariants

Break one of these and the plugin is quietly wrong rather than visibly broken.

### 1. Never write the module competency link

`tool_lp_coursemodule_edit_post_actions` (`admin/tool/lp/lib.php:190-207`) diffs the submitted
form value against the module's existing competencies and **removes anything absent from the
form**. So writing that link while the form is open is always undone by the next Save.

Apply links to the **course** only, then appends `<option value="ID" selected>` to the hidden
`select[name="competencies[]"]`. The form submits that select, and core's own save path creates
the module link.

The ordering is load-bearing, not incidental: `MoodleQuickForm_select::exportValue`
(`lib/form/select.php:167-197`) whitelist-filters submitted values against options rebuilt from
the DB on the POST request. Appending **before** the course link commits would silently drop the
value, and the feature would appear to work in the browser while doing nothing on save.

There must never be a `window.location.reload()` in this plugin — it would discard the unsaved
content that produced the classification.

### 2. The model returns positions, never names

`prompt::build()` sends a candidate list numbered `1..N`; `resolver::resolve()` maps position `n`
back to `$candidates[n - 1]`. Matching the wrong competency is therefore structurally impossible.
Any change that has the model emit or the server parse competency *names* reintroduces the defect
this plugin was built to eliminate.

`decode()` tries candidates in order — whole text, every line-anchored fence via `preg_match_all`,
then successive brace spans — and accepts the first whose `picks` value **is an array**. Checking
the key's presence rather than its value let a decoy `{"picks":null}` mask a real answer in a
later fence.

### 3. The context is derived from `cmid`, never accepted from the caller

`validate_context()` constrains nothing about context *level*, and `is_action_enabled_in_context()`
(`ai/classes/manager.php:349-370`) returns `true` outright for levels outside course, category and
module, and only consults a module's `enabledaiactions` at `CONTEXT_MODULE`. A caller-supplied
`contextid` therefore let a teacher dodge the per-activity opt-out by sending the course's context,
or bypass the check entirely by sending a block's.

`cmid = 0` (new activity) is evaluated at course level and is a **known residual hole**, narrowed by
requiring `moodle/course:manageactivities`. The comment in the code says so. Do not "simplify" that
comment into a claim of completeness.

### 4. There are two AI switches, not one

`manager::is_action_enabled()` reads only the per-action toggle and **defaults to true when unset**.
Whether the placement is enabled at all is a separate setting read by
`\core\plugininfo\aiplacement::is_plugin_enabled()`, which **defaults to false**. Check both, in
`lib.php` and in the web service. Checking only the first means turning the plugin off in Site
administration does not turn it off.

The framework is authorized in **its own context**, not the activity's — frameworks are
context-scoped, and `local_dimensions`' own picker refuses a framework the user cannot read. Not
checking it would gate this service more weakly than the UI calling it, and make `candidatecount`
an enumeration oracle. "Not found" and "not permitted" deliberately raise the same error.

### 5. Every state the contract carries must reach the screen

The service returns `success`, `errorcode`, `errormessage`, `suggestions`, `discarded`,
`undecodable`, `contenttruncated`, `candidatecount`, `sentcount`, `truncated`. A state that exists
in the contract and never renders is this codebase's dominant defect: `undecodable`,
`contenttruncated` and `nocandidates` each shipped invisible before review caught them.

When you add a key, render it or delete it. When you touch the response, enumerate all ten against
the template and the JS before you hand the work over.

Watch for combinations that contradict: `contenttruncated` is computed before the candidate fetch,
so it is gated on `{{^nocandidates}}` — otherwise the UI claims content "was sent to the model" on
a path where the provider is never called.

## APIs verified against core — do not re-derive, do not probe

- `\core_ai\manager::get_user_policy_status(int)` and `::user_policy_accepted(int, int)` are
  **`public static`** (`ai/classes/manager.php:242`, `:219`). They cannot be mocked through the DI
  container; tests accept the policy for real.
- `response_base::get_errorcode()` returns **`int`** (`:109`), which is why the wire type is
  `PARAM_INT` and success returns `0`.
- `response_base::__construct()` throws when `!$success` and either the errorcode is 0 or the error
  **name** is empty (`:57-59`). A failure-response fixture needs `error:`.
- `competency::get_descendants_ids()` **excludes the root it is given** — `candidates::fetch()`
  merges the root back in, or narrowing to a branch drops the branch itself and a flat framework
  becomes unusable.
- `form-autocomplete.js` exports only `enhance`/`enhanceField`, and `enhanceField()` returns `false`
  on an already-enhanced select (`:1184-1189`) — a second call is a no-op, so the chips cannot be
  refreshed that way.
- `local_dimensions_browse_structure` **cannot enumerate frameworks**: it requires a `frameworkid`
  and returns `{items, total}`. Frameworks come from `core_competency_list_competency_frameworks`
  with the real context and `includes: 'parents'`, which is what `tool_lp`'s own picker uses.

Never wrap a core API that exists in `method_exists()` or `class_exists()`. Defensive probing of
present APIs was an audited anti-pattern of the plugin this one replaces.

## Hook choice

`lib.php` hooks **`coursemodule_definition_after_data`**, not `coursemodule_standard_elements`.
Both iterate in `components.json` order, where `aiplacement` is index 0 and `tool` is index 35 — so
on the earlier hook the callback fires before `tool_lp` has created the competencies section, and
the button cannot be placed relative to a field that does not exist. `definition_after_data`
(`course/moodleform_mod.php:861-862`, called at `:336`) runs as a separate later phase, so
`insertElementBefore` has an anchor.

## House rules

- **Never push without an explicit instruction.** Commit locally; the user decides when to publish.
- All code, comments, commit messages and docs in **English**. Only chat is bilingual.
- **Never write to-do or merge-conflict marker tokens literally** in any file — CI's
  development-leftover checker fails the build on them.
- Docblocks on every class, method, property and constant; JSDoc on every JS function. `@param`
  array types are the bare word `array`, with the shape in prose.
- Lines under 132 characters. Core's ESLint enforces this on `amd/src` and it is the most common
  cause of a red `grunt` job.
- `lang/en` and `lang/pt_br` stay alphabetically sorted **and in parity**. CI's `validate` step
  enforces the ordering.
- Every `amd/src` edit ships its rebuilt `amd/build/*.min.js` and `.map` **in the same commit**.
  Grunt compiles the main checkout, not a worktree, and can report success without rebuilding —
  check the artifact mtimes.
- Every CSS class namespaced `aiplacement-dimensions-*`. Never `.ai-drawer` or `.course-assist-*`:
  `aiplacement_courseassist` resolves those document-wide and the two would cross-bind handlers.
- No new database tables. Suggestions are ephemeral.

## Testing discipline

Two habits earned their keep repeatedly, and both exist because a test looked green while being
unable to fail:

- **Prove ordering with a script, never by eye.** Extract the lang file's keys in file order and
  compare against the same list through PHP's `sort()`. A key placed by eye shipped out of order
  and only review caught it.
- **Mutation-check every gate.** Delete the gate, confirm exactly one test goes red, restore it.
  `nopermissions` is the errorcode for all three `require_capability` calls, so an errorcode
  assertion cannot prove *which* fired — only mutation can.

Tests go through `\core_external\external_api::call_external_function()`, never `execute()`
directly: that is what exercises `execute_returns()` and the `db/services.php` wiring.

`prompt` and `resolver` touch neither `$DB` nor `core_ai` and extend `\basic_testcase`. Keep them
that way — it is why they are testable without a site.

## Local environment

This checkout is **PHPUnit-only** — no web server, no `mdl_` tables. Behat is not initialised;
its scenarios run in CI.

```bash
docker start moodle-phpunit-pg
mkdir -p /tmp/phpini && printf 'max_input_vars=5000\nmemory_limit=512M\n' > /tmp/phpini/99-moodle.ini
cd /Volumes/N1TB/dev/github/moodle
PHP_INI_SCAN_DIR="/opt/homebrew/etc/php/8.5/conf.d:/tmp/phpini" php vendor/bin/phpunit --testsuite aiplacement_dimensions_testsuite
```

Three traps:

- `init.php` needs `--disable-composer`: PHP 8.5 exposes a `lib-curl-boringssl` platform package
  Composer 2.10 rejects.
- The `PHP_INI_SCAN_DIR` prefix is required on **every** Moodle CLI command — `-d` does not reach
  the subprocesses Moodle spawns. Point it at the real `conf.d` **plus** the override dir, or you
  lose every extension.
- A new `db/access.php` or `db/services.php` is **not** synced by re-running `init.php` — Moodle
  syncs them only when the plugin version changes. Use `util.php --drop` then a clean `init.php`.
  A fresh environment also leaves `enabled` unset, which the plugin-enabled gate treats as
  disabled; the test helper sets it.

## Known gaps

Recorded so nobody rediscovers them as bugs:

- **The apply-then-save path has no automated browser coverage.** `\core\di::set()` cannot cross
  the boundary between the Behat CLI process and the browser-driven site process, and core did not
  solve this for its own placement either. Covered at the service level by PHPUnit; the browser
  path awaits verification against a real site with a real provider.
- `confidence` is returned, prompted for and tested, but nothing displays it or orders by it.
  Display it or stop asking for it.
- No empty state when the site has no readable frameworks, or when the activity has no content.
- `readContent()` carries two selectors that cannot match. It works because TinyMCE writes the
  textarea on blur and the Suggest click blurs it — a load-bearing dependency worth a comment.
- `MAX_SPANS = 20` bounds the unfenced brace-scan only; fenced paths are uncapped.
