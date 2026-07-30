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
define(['core/ajax', 'core/templates', 'core/notification'],
function(Ajax, Templates, Notification) {

    var SELECTORS = {
        LAUNCH: '[data-action="aiplacement-dimensions-suggest"]',
        CLOSE: '[data-action="aiplacement-dimensions-close"]',
        RUN: '[data-action="aiplacement-dimensions-run"]',
        DRAWER: '#aiplacement-dimensions-drawer',
        BODY: '#aiplacement-dimensions-drawer .aiplacement-dimensions-body',
        FRAMEWORK: '[data-region="framework"]',
        BRANCH: '[data-region="branch"]',
        COMPETENCIES: 'select[name="competencies[]"]'
    };

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
     * @param {Number} frameworkId The competency framework id chosen in the select.
     * @return {Promise} Resolves once the branch fieldset has been re-rendered.
     */
    var reloadBranches = function(frameworkId) {
        return loadBranches(frameworkId).then(function(structure) {
            return Templates.renderForPromise(
                'aiplacement_dimensions/pickers',
                buildPickersContext([], structure.items, structure.total)
            );
        }).then(function(rendered) {
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
