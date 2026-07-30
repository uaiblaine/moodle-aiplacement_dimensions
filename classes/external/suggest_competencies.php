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

namespace aiplacement_dimensions\external;

use aiplacement_dimensions\local\candidates;
use aiplacement_dimensions\local\prompt;
use aiplacement_dimensions\local\resolver;
use core_ai\aiactions\generate_text;
use core_competency\competency_framework;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Suggest competencies for the given activity content.
 *
 * @package    aiplacement_dimensions
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class suggest_competencies extends external_api {
    /** @var int Maximum characters of activity content sent to the model. */
    public const MAX_CONTENT = 20000;

    /** @var int Maximum subtree roots a caller may scope a request to. */
    public const MAX_ROOTS = 50;

    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id, or 0 for an activity not yet created'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'frameworkid' => new external_value(PARAM_INT, 'Competency framework id'),
            'rootids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Competency id whose subtree is in scope'),
                'Chosen subtree roots; empty means the whole framework',
                VALUE_DEFAULT,
                []
            ),
            'content' => new external_value(PARAM_RAW, 'Activity content to classify'),
        ]);
    }

    /**
     * Suggest competencies.
     *
     * @param int $cmid Course module id, or 0 when the activity does not exist yet.
     * @param int $courseid Course id.
     * @param int $frameworkid Competency framework id.
     * @param array $rootids Chosen subtree roots.
     * @param string $content Activity content.
     * @return array The structure described by execute_returns().
     */
    public static function execute(
        int $cmid,
        int $courseid,
        int $frameworkid,
        array $rootids,
        string $content
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'courseid' => $courseid,
            'frameworkid' => $frameworkid,
            'rootids' => $rootids,
            'content' => $content,
        ]);

        if (count($params['rootids']) > self::MAX_ROOTS) {
            throw new \moodle_exception('error_toomanyroots', 'aiplacement_dimensions');
        }

        /*
         * The context is derived, never accepted from the caller. A caller-supplied
         * contextid would make the AI opt-out gate a no-op the caller chooses: core's
         * is_action_enabled_in_context() only consults a module's enabledaiactions at
         * CONTEXT_MODULE, and returns true outright for levels outside course, category
         * and module, so any block's contextid would have short-circuited the check.
         *
         * The course context is resolved and authorized FIRST so that resolving the
         * course module cannot be used as a pre-authentication membership probe.
         */
        $coursecontext = \context_course::instance($params['courseid'], MUST_EXIST);
        self::validate_context($coursecontext);

        if ($params['cmid'] > 0) {
            $cm = get_coursemodule_from_id('', $params['cmid'], $params['courseid'], false, MUST_EXIST);
            $context = \context_module::instance($cm->id);
            self::validate_context($context);
        } else {
            /*
             * cmid 0 means the activity does not exist yet, so there is no per-activity
             * enabledaiactions to consult and this call is evaluated at course level.
             * That is a real residual hole: a caller editing an EXISTING activity whose
             * action is switched off can send cmid 0 and be evaluated at course level
             * instead. It is not a privilege escalation, because the same editing
             * teacher may flip that switch on the module form anyway, but it does mean
             * the ai_action_register row records the course context rather than the
             * module's. Requiring manageactivities is what being on the add-activity
             * form actually implies, and narrows who can take that path.
             */
            $context = $coursecontext;
            require_capability('moodle/course:manageactivities', $coursecontext);
        }

        require_capability('moodle/competency:coursecompetencymanage', $context);
        require_capability('aiplacement/dimensions:suggest', $context);

        \core_competency\api::require_enabled();

        /*
         * The placement's own on/off toggle, set at Site administration -> AI -> AI placements.
         * This is a DIFFERENT switch from the is_action_enabled() call just below it, and the
         * two are easy to conflate. \core_ai\manager::is_action_enabled() (ai/classes/manager.php)
         * reads only the per-action config key, keyed by the action class's own basename; it
         * never asks whether the placement PLUGIN itself is enabled. That plugin-level state is
         * checked here, separately, through \core\plugininfo\aiplacement::is_plugin_enabled()
         * (lib/classes/plugininfo/aiplacement.php), which reads config key "enabled" on the
         * aiplacement_dimensions component. Do not fold this back into the is_action_enabled()
         * check below: they read different config, and dropping this call means disabling the
         * plugin in the admin UI no longer disables this web service, only the per-action
         * toggle would.
         */
        if (!\core\plugininfo\aiplacement::is_plugin_enabled('dimensions')) {
            throw new \moodle_exception('error_actiondisabled', 'aiplacement_dimensions');
        }

        $manager = \core\di::get(\core_ai\manager::class);

        if (!$manager->is_action_enabled('aiplacement_dimensions', generate_text::class)) {
            throw new \moodle_exception('error_actiondisabled', 'aiplacement_dimensions');
        }

        if (!$manager->is_action_enabled_in_context($context, generate_text::class)) {
            throw new \moodle_exception('error_actiondisabled', 'aiplacement_dimensions');
        }

        /*
         * Static, not an instance call: get_user_policy_status() is a public static
         * method (ai/classes/manager.php:242) reading the core/ai_policy cache. It is
         * therefore not reachable through the DI container, which is also why the test
         * accepts the policy for real instead of mocking it.
         */
        if (!\core_ai\manager::get_user_policy_status((int) $USER->id)) {
            throw new \moodle_exception('error_policynotaccepted', 'aiplacement_dimensions');
        }

        /*
         * The framework is authorized in its OWN context, not the activity's. Frameworks
         * are context-scoped and every core read path checks that context; without this
         * an editing teacher could name any framework id and have its competencies read
         * and shipped to the provider, with candidatecount acting as an enumeration
         * oracle. local_dimensions' own picker refuses such a framework, so omitting the
         * check would gate this service more weakly than the UI that calls it.
         */
        $framework = competency_framework::get_record(['id' => $params['frameworkid']]);
        if (!$framework || !has_capability('moodle/competency:competencyview', $framework->get_context())) {
            /*
             * One error for both cases on purpose. Distinguishing "does not exist" from
             * "you may not read it" would let any editing teacher walk the id space and
             * learn which frameworks exist in categories they cannot see. The sibling
             * local_dimensions picker collapses the same two cases for the same reason.
             */
            throw new \moodle_exception(
                'error_nosuchframework',
                'aiplacement_dimensions',
                '',
                null,
                $framework
                    ? 'framework not readable in context ' . $framework->get('contextid')
                    : 'no such framework id'
            );
        }

        $trimmed = \core_text::substr($params['content'], 0, self::MAX_CONTENT);
        $contenttruncated = \core_text::strlen($params['content']) > self::MAX_CONTENT;

        $rows = candidates::fetch($params['frameworkid'], $params['rootids']);
        $built = prompt::build($rows, $trimmed);

        if (empty($built['candidates'])) {
            return self::result(true, 0, '', [], 0, false, $contenttruncated, $built);
        }

        $action = new generate_text($context->id, (int) $USER->id, $built['text']);
        $response = $manager->process_action($action);

        if (!$response->get_success()) {
            return self::result(
                false,
                $response->get_errorcode(),
                $response->get_errormessage(),
                [],
                0,
                false,
                $contenttruncated,
                $built
            );
        }

        $data = $response->get_response_data();
        $resolved = resolver::resolve((string) ($data['generatedcontent'] ?? ''), $built['candidates']);

        return self::result(
            true,
            0,
            '',
            $resolved['suggestions'],
            $resolved['discarded'],
            $resolved['undecodable'],
            $contenttruncated,
            $built
        );
    }

    /**
     * Assemble the return structure.
     *
     * @param bool $success Whether the provider call succeeded.
     * @param int $errorcode Provider error code, zero when successful.
     * @param string $errormessage Provider error message.
     * @param array $suggestions Resolved suggestions.
     * @param int $discarded Model answers that could not be resolved.
     * @param bool $undecodable Whether the model answer could not be parsed at all.
     * @param bool $contenttruncated Whether the submitted content was cut to the cap.
     * @param array $built The output of prompt::build().
     * @return array The structure described by execute_returns().
     */
    private static function result(
        bool $success,
        int $errorcode,
        string $errormessage,
        array $suggestions,
        int $discarded,
        bool $undecodable,
        bool $contenttruncated,
        array $built
    ): array {
        return [
            'success' => $success,
            'errorcode' => $errorcode,
            'errormessage' => $errormessage,
            'suggestions' => $suggestions,
            'discarded' => $discarded,
            'undecodable' => $undecodable,
            'contenttruncated' => $contenttruncated,
            'candidatecount' => $built['candidatecount'],
            'truncated' => $built['truncated'],
            /*
             * The number of competencies actually placed in the prompt sent to the
             * model, i.e. count($built['candidates']) after prompt::build() applied its
             * budget. This is NOT the number of suggestions the model returned: a
             * client cannot compute this value itself, because it never sees the
             * candidate list, only the resolved suggestions. Without this field the
             * truncation notice ("Only the first {$a->sent} of {$a->total}
             * competencies were sent to the model") had nothing correct to report and
             * was fed the suggestion count instead.
             */
            'sentcount' => count($built['candidates']),
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the model was reached successfully'),
            'errorcode' => new external_value(PARAM_INT, 'Provider error code, zero when successful'),
            'errormessage' => new external_value(PARAM_TEXT, 'Provider error message, empty when successful'),
            'suggestions' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Competency id'),
                    'idnumber' => new external_value(PARAM_TEXT, 'Competency idnumber'),
                    'shortname' => new external_value(PARAM_TEXT, 'Competency shortname'),
                    'confidence' => new external_value(PARAM_FLOAT, 'Model confidence between 0 and 1'),
                    'why' => new external_value(PARAM_TEXT, 'One-sentence rationale'),
                ]),
                'Resolved suggestions'
            ),
            'discarded' => new external_value(PARAM_INT, 'Model answers that could not be resolved'),
            'undecodable' => new external_value(PARAM_BOOL, 'True when the model answer could not be parsed at all'),
            'contenttruncated' => new external_value(PARAM_BOOL, 'True when the submitted content was cut to the cap'),
            'candidatecount' => new external_value(PARAM_INT, 'Competencies in scope before truncation'),
            'truncated' => new external_value(PARAM_BOOL, 'Whether the candidate list was truncated'),
            'sentcount' => new external_value(PARAM_INT, 'Competencies actually sent to the model'),
        ]);
    }
}
