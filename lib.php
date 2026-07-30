<?php
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

defined('MOODLE_INTERNAL') || die();

/**
 * Add the AI suggestion button to the activity settings form.
 *
 * This runs on coursemodule_definition_after_data, NOT coursemodule_standard_elements.
 * Both hooks iterate plugins in components.json order, where aiplacement is index 0 and
 * tool is index 35 — so on standard_elements our callback fires before tool_lp has
 * created the competencies section at all, and the button lands under whatever header
 * happens to precede it. definition_after_data runs after every standard_elements
 * callback, so the anchor element exists and insertElementBefore can place the button
 * next to the field it fills.
 *
 * Returns silently when any availability gate fails.
 *
 * @param moodleform_mod $formwrapper The form wrapper.
 * @param MoodleQuickForm $mform The form.
 * @return void
 */
function aiplacement_dimensions_coursemodule_definition_after_data($formwrapper, $mform): void {
    global $PAGE, $OUTPUT, $COURSE;

    if (!get_config('core_competency', 'enabled')) {
        return;
    }

    $context = $formwrapper->get_context();

    if (!has_capability('moodle/competency:coursecompetencymanage', $context)) {
        return;
    }

    if (!has_capability('aiplacement/dimensions:suggest', $context)) {
        return;
    }

    $manager = \core\di::get(\core_ai\manager::class);
    $actionclass = \core_ai\aiactions\generate_text::class;

    if (!$manager->is_action_enabled('aiplacement_dimensions', $actionclass)) {
        return;
    }

    $providers = $manager->get_providers_for_actions([$actionclass], true);
    if (empty($providers[$actionclass])) {
        return;
    }

    if (!$manager->is_action_enabled_in_context($context, $actionclass)) {
        return;
    }

    /*
     * The competencies element is our anchor. Gate 2 already required the capability
     * tool_lp itself requires to create it, so it should exist — but guard rather than
     * let insertElementBefore throw if core ever changes that condition.
     */
    if (!$mform->elementExists('competencies')) {
        return;
    }

    $element = $mform->createElement(
        'static',
        'aiplacementdimensions',
        '',
        $OUTPUT->render_from_template('aiplacement_dimensions/button', []) .
        $OUTPUT->render_from_template('aiplacement_dimensions/drawer', [])
    );
    $mform->insertElementBefore($element, 'competencies');

    $cm = $formwrapper->get_coursemodule();

    $PAGE->requires->js_call_amd('aiplacement_dimensions/suggest', 'init', [
        $cm ? (int) $cm->id : 0,
        (int) $COURSE->id,
        (int) $context->id,
    ]);
}
