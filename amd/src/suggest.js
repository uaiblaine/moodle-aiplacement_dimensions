// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Competency suggestions on the activity form.
 *
 * local_dimensions_browse_structure browses one already-chosen framework's competency
 * tree: it requires a frameworkid (there is no contextid parameter) and returns
 * {items, total}, not a list of frameworks. The framework list itself comes from
 * core_competency_list_competency_frameworks instead, the same external function
 * tool_lp's own competency picker calls from a course or activity form
 * (see admin/tool/lp/amd/src/form_competency_element.js), with includes: 'parents'
 * because frameworks live above the course/module context this placement runs in.
 *
 * @module     aiplacement_dimensions/suggest
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/templates', 'core/notification', 'core/str'],
function(Ajax, Templates, Notification, Str) {

    var SELECTORS = {
        LAUNCH: '[data-action="aiplacement-dimensions-suggest"]',
        CLOSE: '[data-action="aiplacement-dimensions-close"]',
        RUN: '[data-action="aiplacement-dimensions-run"]',
        APPLY: '[data-action="aiplacement-dimensions-apply"]',
        DRAWER: '#aiplacement-dimensions-drawer',
        BODY: '#aiplacement-dimensions-drawer .aiplacement-dimensions-body',
        FRAMEWORK: '[data-region="framework"]',
        BRANCH: '[data-region="branch"]',
        PICK: 'input[data-region="aiplacement-dimensions-pick"]',
        COMPETENCIES: 'select[name="competencies[]"]'
    };

    /*
     * Two monotonically increasing request tokens. Each guarded function bumps its
     * own counter when it starts and captures that value; when its response
     * arrives it only touches the DOM if the captured value still matches the
     * counter, i.e. no newer call of the same kind has started since. This is
     * what stops a slow, now-superseded response from overwriting what a later,
     * faster response already rendered.
     *
     * branchRequestToken closes the gap the Task 6 review found in
     * reloadBranches(): two rapid framework changes could let the slower
     * response win and show one framework's branches under the other's
     * selection. suggestRequestToken guards runSuggestion() the same way, since
     * nothing disables the Suggest button while a request is in flight and a
     * second click before the first response lands is the same race.
     */
    var branchRequestToken = 0;
    var suggestRequestToken = 0;

    /**
     * Fetch the competency frameworks readable from the given context.
     *
     * @param {Number} contextId The activity or course context id.
     * @return {Promise} Resolves to the array of framework records (id, shortname, ...).
     */
    var loadFrameworks = function(contextId) {
        return Ajax.call([{
            methodname: 'core_competency_list_competency_frameworks',
            args: {
                context: {contextid: contextId},
                includes: 'parents'
            }
        }])[0];
    };

    /**
     * Fetch the root competencies (branches) of one framework.
     *
     * The server paginates at STRUCTURE_PAGE_SIZE (25) and reports the true count in
     * `total`, so callers that only look at `items` would silently truncate a framework
     * with more than 25 roots. Both fields are returned so the caller can tell.
     *
     * @param {Number} frameworkId The competency framework id.
     * @return {Promise} Resolves to {items: Array, total: Number} of the root competencies.
     */
    var loadBranches = function(frameworkId) {
        return Ajax.call([{
            methodname: 'local_dimensions_browse_structure',
            args: {frameworkid: frameworkId, parentid: 0}
        }])[0];
    };

    /**
     * Build the template context shared by the initial render and the branch reload,
     * including the truncation notice fields consumed by the pickers template.
     *
     * @param {Array} frameworks The framework records to list in the select.
     * @param {Array} branches The root competency nodes of the selected framework.
     * @param {Number} branchesTotal The true count of root competencies on the server.
     * @return {Object} The pickers template context.
     */
    var buildPickersContext = function(frameworks, branches, branchesTotal) {
        return {
            frameworks: frameworks,
            branches: branches,
            branchesshown: branches.length,
            branchestotal: branchesTotal,
            branchestruncated: branchesTotal > branches.length
        };
    };

    /**
     * Render the framework and branch pickers into the drawer body and reveal the drawer.
     *
     * @param {Array} frameworks The framework records to list in the select.
     * @param {Array} branches The root competency nodes of the selected framework.
     * @param {Number} branchesTotal The true count of root competencies on the server.
     * @return {Promise} Resolves once the pickers are in the drawer.
     */
    var renderPickers = function(frameworks, branches, branchesTotal) {
        return Templates.renderForPromise(
            'aiplacement_dimensions/pickers',
            buildPickersContext(frameworks, branches, branchesTotal)
        ).then(function(rendered) {
            document.querySelector(SELECTORS.DRAWER).hidden = false;
            document.querySelector(SELECTORS.BODY).innerHTML = rendered.html;
            Templates.runTemplateJS(rendered.js);
            return rendered;
        });
    };

    /**
     * Re-render only the branch fieldset for the framework just chosen in the select,
     * leaving the framework select itself untouched so the user's choice and scroll
     * position survive the reload.
     *
     * Bumps branchRequestToken and only writes to the DOM if no later call has
     * started in the meantime, so two rapid framework changes can never let the
     * slower response win and mismatch the select (see the module-level comment
     * on the token variables).
     *
     * @param {Number} frameworkId The competency framework id chosen in the select.
     * @return {Promise} Resolves once the branch fieldset has been re-rendered, or
     *                    resolves without touching the DOM if superseded.
     */
    var reloadBranches = function(frameworkId) {
        var token = ++branchRequestToken;
        return loadBranches(frameworkId).then(function(structure) {
            return Templates.renderForPromise(
                'aiplacement_dimensions/pickers',
                buildPickersContext([], structure.items, structure.total)
            );
        }).then(function(rendered) {
            if (token !== branchRequestToken) {
                // A newer framework change started while this request was in flight.
                return rendered;
            }
            var container = document.createElement('div');
            container.innerHTML = rendered.html;
            var newfieldset = container.querySelector('fieldset');
            var body = document.querySelector(SELECTORS.BODY);
            var oldfieldset = body.querySelector('fieldset');
            if (oldfieldset) {
                oldfieldset.remove();
            }
            if (newfieldset) {
                body.querySelector(SELECTORS.RUN).insertAdjacentElement('beforebegin', newfieldset);
            }
            Templates.runTemplateJS(rendered.js);
            return rendered;
        });
    };

    /**
     * Open the drawer and render the framework and branch pickers.
     *
     * Defaults the branch checkboxes to the first framework in the list. Picking a
     * different framework from the select re-fetches and re-renders its branches
     * through the change listener bound once in init().
     *
     * @param {Number} contextId The activity or course context id.
     * @return {Promise} Resolves once the pickers are in the drawer.
     */
    var openPickers = function(contextId) {
        var frameworks = [];
        return loadFrameworks(contextId).then(function(loadedframeworks) {
            frameworks = loadedframeworks;
            return frameworks.length ? loadBranches(frameworks[0].id) : {items: [], total: 0};
        }).then(function(structure) {
            return renderPickers(frameworks, structure.items, structure.total);
        });
    };

    /**
     * Read the unsaved activity description from the form.
     *
     * @return {String} The content to classify.
     */
    var readContent = function() {
        var textarea = document.querySelector('#id_introeditor, [name="intro[text]"]');
        if (textarea && typeof textarea.value === 'string' && textarea.value.trim()) {
            return textarea.value.trim();
        }
        var editable = document.querySelector('[id^="id_introeditor"][contenteditable="true"]');
        return editable ? editable.textContent.trim() : '';
    };

    /**
     * Build the aiplacement_dimensions/suggestions template context shared by the
     * success and provider-failure paths of runSuggestion().
     *
     * Rendering both paths through the same template, rather than the failure path
     * building its own ad hoc error markup, is what lets a provider failure still
     * surface the truncation and content-truncation notices: the request content was
     * genuinely cut, or the candidate list genuinely truncated, before the call that
     * then failed, and the user should still be told that.
     *
     * @param {Object} response The web service response.
     * @return {Object} The template context for aiplacement_dimensions/suggestions.
     */
    var buildSuggestionsContext = function(response) {
        return {
            suggestions: (response.suggestions || []).map(function(suggestion) {
                return Object.assign({}, suggestion, {json: JSON.stringify(suggestion)});
            }),
            discarded: response.discarded,
            undecodable: response.undecodable,
            contenttruncated: response.contenttruncated,
            /*
             * A candidatecount of 0 is exactly and only the "nothing was ever in
             * scope" case: the service returns before calling the provider. Without
             * this flag the template would tell the user the model found no clear
             * match, when no model was consulted at all.
             */
            nocandidates: response.candidatecount === 0,
            truncated: response.truncated,
            candidatecount: response.candidatecount,
            sentcount: response.sentcount,
            // True only on a provider failure; suppresses the "no suggestions" text
            // so the template's own error region (populated below) is what shows.
            providererror: !response.success
        };
    };

    /**
     * Ask the model and render the resolved suggestions.
     *
     * Bumps suggestRequestToken and only writes to the DOM if no later Suggest
     * click has started in the meantime, for the same reason reloadBranches()
     * guards itself: nothing here disables the Run button while the request is
     * in flight, so a second click before the first response lands is a real race.
     *
     * On a provider failure, the message shown is the provider's own errormessage
     * when the service returned one (e.g. the translated core_ai default message,
     * ai/classes/manager.php:156-160, on a fresh install with no provider
     * configured), falling back to the generic error_provider string only when
     * errormessage is empty. The text is always written with textContent, never
     * innerHTML, since it can carry provider-supplied content.
     *
     * @param {Number} cmId The course module id, or 0 for an activity not yet created.
     * @param {Number} courseId The course id.
     * @return {Promise} Resolves when the suggestions are rendered, or resolves
     *                    without touching the DOM if superseded.
     */
    var runSuggestion = function(cmId, courseId) {
        var token = ++suggestRequestToken;
        var frameworkSelect = document.querySelector(SELECTORS.FRAMEWORK);
        var branches = Array.prototype.slice.call(
            document.querySelectorAll(SELECTORS.BRANCH + ':checked')
        ).map(function(input) {
            return parseInt(input.value, 10);
        });

        return Ajax.call([{
            methodname: 'aiplacement_dimensions_suggest_competencies',
            args: {
                cmid: cmId,
                courseid: courseId,
                frameworkid: parseInt(frameworkSelect.value, 10),
                rootids: branches,
                content: readContent()
            }
        }])[0].then(function(response) {
            if (token !== suggestRequestToken) {
                // A newer Suggest click started while this request was in flight.
                return response;
            }

            var messagePromise;
            if (response.success) {
                messagePromise = Promise.resolve('');
            } else if (response.errormessage) {
                messagePromise = Promise.resolve(response.errormessage);
            } else {
                messagePromise = Str.get_string('error_provider', 'aiplacement_dimensions', response.errorcode);
            }

            // Captured by both .then() below so the second can use what the first resolved,
            // without nesting one promise chain inside another.
            var errormessage = '';

            return messagePromise.then(function(resolvedmessage) {
                errormessage = resolvedmessage;
                return Templates.renderForPromise(
                    'aiplacement_dimensions/suggestions',
                    buildSuggestionsContext(response)
                );
            }).then(function(rendered) {
                if (token !== suggestRequestToken) {
                    return rendered;
                }
                var body = document.querySelector(SELECTORS.BODY);
                body.innerHTML = rendered.html;
                Templates.runTemplateJS(rendered.js);
                if (!response.success) {
                    var errorRegion = body.querySelector(
                        '[data-region="aiplacement-dimensions-providererror"]'
                    );
                    if (errorRegion) {
                        errorRegion.textContent = errormessage;
                    }
                }
                return rendered;
            });
        });
    };

    /**
     * Append a competency to the form's own competencies select.
     *
     * The form submits this select, not the visible chips, so appending here is
     * what makes tool_lp create the module link on save. We never write the
     * module link ourselves: tool_lp_coursemodule_edit_post_actions diffs this
     * element and would remove anything absent from it.
     *
     * @param {Object} suggestion The resolved suggestion.
     * @return {void}
     */
    var appendToForm = function(suggestion) {
        var select = document.querySelector(SELECTORS.COMPETENCIES);
        if (!select) {
            return;
        }
        var existing = select.querySelector('option[value="' + suggestion.id + '"]');
        if (existing) {
            existing.selected = true;
            return;
        }
        select.appendChild(new Option(suggestion.shortname, suggestion.id, true, true));
    };

    /**
     * Link the checked suggestions to the course and select them in the form.
     *
     * @param {Number} courseId The course id.
     * @return {Promise} Resolves with one outcome record per suggestion.
     */
    var applyPicks = function(courseId) {
        var picks = Array.prototype.slice.call(
            document.querySelectorAll(SELECTORS.PICK + ':checked')
        ).map(function(input) {
            return JSON.parse(input.dataset.suggestion);
        });

        /*
         * One call per competency. A single Ajax.call batch aborts the
         * remainder on the first exception, losing the rest silently.
         */
        return Promise.all(picks.map(function(pick) {
            return Ajax.call([{
                methodname: 'local_dimensions_link_competency_course',
                args: {competencyid: pick.id, courseid: courseId}
            }])[0].then(function() {
                appendToForm(pick);
                return {pick: pick, ok: true};
            }).catch(function() {
                return {pick: pick, ok: false};
            });
        }));
    };

    /**
     * Report the outcome by replacing the drawer body.
     *
     * The form-autocomplete widget that renders the visible chips for the
     * competencies select has no listener for external changes to the select,
     * and re-running core/form-autocomplete's enhanceField() on an
     * already-enhanced select is a no-op: it checks originalSelect.data('enhanced')
     * and returns immediately (lib/amd/src/form-autocomplete.js:1184-1189), before
     * it would ever refresh the chip list. So the chips are left exactly as they
     * were, and the applied competencies are still correctly present in the
     * select's option list either way.
     *
     * The notice replaces the drawer body rather than being inserted after the
     * launch button. Inserting after meant a user scrolled down a long
     * suggestion list might never see the confirmation, and a second Apply
     * stacked a duplicate notice. Replacing keeps the result where the user is
     * already looking and makes a repeat click impossible, since the Apply
     * button goes away with the list it belonged to.
     *
     * @param {Array} results The outcome records from applyPicks.
     * @return {Promise} Resolves once the notice is rendered.
     */
    var showOutcome = function(results) {
        return Templates.renderForPromise('aiplacement_dimensions/applied', {
            added: results.filter(function(r) {
                return r.ok;
            }).map(function(r) {
                return r.pick;
            }),
            failed: results.filter(function(r) {
                return !r.ok;
            }).map(function(r) {
                return r.pick;
            })
        }).then(function(rendered) {
            document.querySelector(SELECTORS.BODY).innerHTML = rendered.html;
            Templates.runTemplateJS(rendered.js);
            return rendered;
        });
    };

    return {
        /**
         * Initialise the placement.
         *
         * @param {Number} cmId The course module id, or 0 for an activity not yet created.
         * @param {Number} courseId The course id.
         * @param {Number} contextId The activity or course context id.
         * @return {void}
         */
        init: function(cmId, courseId, contextId) {
            /*
             * CmId, courseId and contextId are captured in this closure on purpose.
             * The click handler below is a plain function, so `this` inside it
             * is the document, not the module — reading this.contextId there
             * would silently yield undefined.
             */
            document.addEventListener('click', function(e) {
                if (e.target.closest(SELECTORS.LAUNCH)) {
                    e.preventDefault();
                    openPickers(contextId).catch(Notification.exception);
                    return;
                }

                if (e.target.closest(SELECTORS.CLOSE)) {
                    e.preventDefault();
                    document.querySelector(SELECTORS.DRAWER).hidden = true;
                    return;
                }

                if (e.target.closest(SELECTORS.RUN)) {
                    e.preventDefault();
                    runSuggestion(cmId, courseId).catch(Notification.exception);
                    return;
                }

                if (e.target.closest(SELECTORS.APPLY)) {
                    e.preventDefault();
                    applyPicks(courseId).then(function(results) {
                        return showOutcome(results);
                    }).catch(Notification.exception);
                    return;
                }
            }, false);

            /*
             * Delegated and bound once, like the click handler above, so re-rendering
             * the drawer body never accumulates duplicate listeners on the select.
             */
            document.addEventListener('change', function(e) {
                var select = e.target.closest(SELECTORS.FRAMEWORK);
                if (!select) {
                    return;
                }
                var frameworkId = parseInt(select.value, 10);
                if (!frameworkId) {
                    return;
                }
                reloadBranches(frameworkId).catch(Notification.exception);
            }, false);
        }
    };
});
